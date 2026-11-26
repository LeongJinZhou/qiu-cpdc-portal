<?php

declare(strict_types=1);

// Local and e2e seed: fake people only, never real data. Refuses to run in production.
// The fake Google accounts (api/tests/fakes/handler.php) use example.test, so that domain is allowed here.

use Cpdc\Db\Connection;
use Cpdc\Env;

require __DIR__ . '/../vendor/autoload.php';

Env::load(dirname(__DIR__, 2) . '/.env');
if (!in_array(getenv('APP_ENV'), ['local', 'development', 'test'], true)) {
    fwrite(STDERR, "seed.php runs only with APP_ENV local, development or test\n");
    exit(1);
}
$pdo = Connection::fromEnv();
$pdo->exec(
    "UPDATE setting SET value = '[\"qiu.edu.my\", \"example.test\"]' WHERE setting_key = 'google_hosted_domains'"
);
// The e2e specs sign in many times a minute from one address; production keeps the migration's 10.
$pdo->exec("UPDATE setting SET value = '120' WHERE setting_key = 'rate_login_per_ip_per_min'");

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (
    ['graduate_registration', 'career_skill', 'career_education', 'career_experience', 'job_confirmation',
    'career_profile', 'github_account', 'linkedin_account', 'user_role_grant', 'login_log', 'audit_log', 'user',
    'rate_limit',
    // Rows owned by a person in slices 2-3 (CPDC's own lists such as skill areas and question banks stay).
    'logbook_review_link', 'logbook_entry', 'internship', 'showcase_tech', 'showcase_project', 'assessment_answer',
    'assessment_attempt', 'job_application', 'skill_rating', 'skill_assessment', 'learning_record', 'workshop_rsvp',
    'cv_document', 'career_path_progress', 'mentor_request', 'mentor_offer', 'network_profile', 'interview_item',
    'interview_session', 'php_session',
    // slice B: survey rounds are re-created below, so local runs never pile them up
    'survey_response', 'survey_cycle'] as $table
) {
    $pdo->exec("DELETE FROM `$table`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$user = $pdo->prepare(
    'INSERT INTO `user` (organisation_id, email, display_name, matric_no, department, role, onboarded_at, consent_at,'
    . ' consent_version, alumni_since) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$linkedin = $pdo->prepare(
    'INSERT INTO linkedin_account (user_id, organisation_id, linkedin_sub, name, profile_url, follow_confirmed_at,'
    . ' linked_at) VALUES (?, 1, ?, ?, ?, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
);
$profile = $pdo->prepare(
    'INSERT INTO career_profile (user_id, organisation_id, faculty, programme, graduation_year, employment_status,'
    . ' current_title, current_company, job_confirmed_at) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?)'
);
$now = gmdate('Y-m-d H:i:s');

// The alumna who signs in with the fake LinkedIn persona "alumni".
$user->execute(['alumna@example.test', 'Fake Alumna', 'FAKE-S-1001', 'Faculty of Fake Studies', 'alumni', $now, $now,
    '2026-11-25', $now]);
$id = (int) $pdo->lastInsertId();
$linkedin->execute([$id, 'li-sub-alumni', 'Fake Alumna', 'https://www.linkedin.com/in/fake-alumna']);
$profile->execute([$id, 'Faculty of Fake Studies', 'Bachelor of Fake Studies', 2024, 'employed', 'Data Analyst',
    'Fake Analytics Sdn Bhd', $now]);

// More fake alumni for the tracker: different faculties, years and statuses; the last one has no LinkedIn and never
// confirmed a job. Columns: name, matric no, faculty, year, status, job title, company, LinkedIn linked + confirmed.
$fakeAlumni = [
    ['Fake Graduate Two', 'FAKE-S-1002', 'Faculty of Fake Studies', 2024, 'seeking', null, null, true],
    ['Fake Graduate Three', 'FAKE-S-1003', 'Faculty of Other Studies', 2025, 'further_study', 'Master student',
        'Fake University', true],
    ['Fake Graduate Four', 'FAKE-S-1004', 'Faculty of Other Studies', 2025, 'unknown', null, null, false],
];
foreach ($fakeAlumni as $i => [$name, $matric, $faculty, $year, $status, $title, $company, $active]) {
    $user->execute([null, $name, $matric, $faculty, 'alumni', $now, $now, '2026-11-25', $now]);
    $id = (int) $pdo->lastInsertId();
    if ($active) {
        $linkedin->execute([$id, 'li-sub-fake-' . $i, $name, 'https://www.linkedin.com/in/fake-graduate-' . $i]);
    }
    $profile->execute([$id, $faculty, 'Bachelor of ' . substr($faculty, 11), $year, $status, $title, $company,
        $active ? $now : null]);
}

// --- slice B: one open survey round, fake students with answers, and fake self-assessment results (begin) ---
$pdo->exec(
    "INSERT INTO survey_cycle (organisation_id, title, academic_year, status, opens_at)"
    . " VALUES (1, 'University and Programme Survey 2026/2027', '2026/2027', 'open', UTC_TIMESTAMP(3))"
);
$cycle = (int) $pdo->lastInsertId();
$items = array_keys(\Cpdc\Survey\SurveyForm::items());
$response = $pdo->prepare(
    'INSERT INTO survey_response (organisation_id, cycle_id, user_id, status, faculty, programme, semester,'
    . ' answers_json, comments_json, submitted_at) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$find = $pdo->prepare('SELECT id, bands_json FROM assessment WHERE title = ? AND organisation_id = 1');
$find->execute(['Student Characteristics Self-Assessment']);
$found = $find->fetch(PDO::FETCH_ASSOC);
$characteristics = is_array($found) ? (int) $found['id'] : 0;
/** @var list<array{min_percent: int, label: string}> $bands */
$bands = is_array($found) ? json_decode((string) $found['bands_json'], true) : [];
$options = $pdo->prepare(
    'SELECT q.id AS qid, o.id AS oid, o.points FROM assessment_question q'
    . ' JOIN assessment_option o ON o.question_id = q.id WHERE q.assessment_id = ? ORDER BY q.position, o.position'
);
$options->execute([$characteristics]);
$byQuestion = [];
foreach ($options->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $byQuestion[(int) $o['qid']][(int) $o['points']] = (int) $o['oid'];
}
$attempt = $pdo->prepare(
    'INSERT INTO assessment_attempt (organisation_id, assessment_id, user_id, score, max_score, band_label,'
    . ' reflection_answers, faculty, semester) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$answer = $pdo->prepare('INSERT INTO assessment_answer (attempt_id, question_id, option_id) VALUES (?, ?, ?)');
$student = $pdo->prepare(
    'INSERT INTO `user` (organisation_id, email, display_name, matric_no, department, role, onboarded_at, consent_at,'
    . " consent_version) VALUES (1, ?, ?, ?, ?, 'student', ?, ?, '2026-11-25')"
);
$studentProfile = $pdo->prepare(
    "INSERT INTO career_profile (user_id, organisation_id, faculty, programme, employment_status)"
    . " VALUES (?, 1, ?, ?, 'unknown')"
);
// name, matric, faculty (programme: "Bachelor of" + the rest), semester, survey status ('' = none),
// base survey rating, trait level (0 = no self-assessment)
$fakeStudents = [
    ['Fake Student Five', 'FAKE-S-2001', 'Faculty of Fake Studies', 3, 'submitted', 4, 4],
    ['Fake Student Six', 'FAKE-S-2002', 'Faculty of Fake Studies', 5, 'submitted', 3, 3],
    ['Fake Student Seven', 'FAKE-S-2003', 'Faculty of Other Studies', 2, 'submitted', 5, 5],
    ['Fake Student Eight', 'FAKE-S-2004', 'Faculty of Other Studies', 4, 'draft', 2, 2],
    ['Fake Student Nine', 'FAKE-S-2005', 'Faculty of Other Studies', 1, '', 0, 0],
];
foreach ($fakeStudents as $i => $fake) {
    [$name, $matric, $faculty, $semester] = [(string) $fake[0], (string) $fake[1], (string) $fake[2], (int) $fake[3]];
    [$status, $base, $level] = [(string) $fake[4], (int) $fake[5], (int) $fake[6]];
    $programme = 'Bachelor of ' . substr($faculty, 11);
    $email = strtolower(str_replace(' ', '.', $name)) . '@example.test';
    $student->execute([$email, $name, $matric, $faculty, $now, $now]);
    $id = (int) $pdo->lastInsertId();
    $studentProfile->execute([$id, $faculty, $programme]);
    if ($status !== '') {
        $answers = [];
        foreach ($items as $n => $key) {
            $answers[$key] = $key === 'ss_hostel' && $i % 2 === 0 ? 'na' : max(1, min(5, $base + ($n % 3) - 1));
        }
        if ($status === 'draft') {
            $answers = array_slice($answers, 0, 12, true);
        }
        $comments = ['programme' => 'Fake comment: more industry projects, please.',
            'support' => 'Fake: longer library hours.'];
        $response->execute([$cycle, $id, $status, $faculty, $programme, $semester, json_encode($answers),
            json_encode($comments), $status === 'submitted' ? $now : null]);
    }
    if ($level > 0 && $characteristics > 0) {
        $score = 0;
        $chosen = [];
        foreach ($byQuestion as $qid => $points) {
            $p = max(1, min(5, $level + (($qid + $i) % 3) - 1));
            $chosen[$qid] = $points[$p];
            $score += $p;
        }
        $max = 5 * count($byQuestion);
        $percent = intdiv($score * 100, max(1, $max));
        $band = \Cpdc\Assessment\Scoring::band($bands, $percent);
        $attempt->execute([$characteristics, $id, $score, $max, $band,
            json_encode(['Fake: I will join one club and practise presenting.', 'Fake: more mentoring sessions.']),
            $faculty, $semester]);
        $attemptId = (int) $pdo->lastInsertId();
        foreach ($chosen as $qid => $oid) {
            $answer->execute([$attemptId, $qid, $oid]);
        }
    }
}
// --- slice B (end) ---
echo "Seeded fake alumni, fake students, an open survey round; example.test sign-in allowed.\n";

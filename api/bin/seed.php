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
    'interview_session', 'php_session'] as $table
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
echo "Seeded fake alumni; example.test sign-in allowed.\n";

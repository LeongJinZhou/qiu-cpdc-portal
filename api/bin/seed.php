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
    // slice A: placement history and satisfaction answers
    'internship_change_log', 'internship_satisfaction'] as $table
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
// --- slice A: fake interns with placements, one change and survey answers, so /internships shows data ---
// The student behind the fake Google persona "graduate" is onboarded with no internship: the e2e placement spec
// starts from there.
$user->execute(['graduate@example.test', 'Fake Graduate', 'FAKE-S-0003', 'Faculty of Fake Studies', 'student', $now,
    $now, '2026-11-25', null]);
$linkedin->execute([(int) $pdo->lastInsertId(), 'li-sub-seed-graduate', 'Fake Graduate',
    'https://www.linkedin.com/in/fake-graduate']);
$placement = $pdo->prepare(
    'INSERT INTO internship (organisation_id, student_id, company, business_nature, company_address_1,'
    . ' company_postcode, company_state, company_country, supervisor_name, supervisor_email, supervisor_phone,'
    . ' start_date, end_date, batch_month, batch_year, roles_responsibilities, monthly_allowance_rm, has_hr_contact,'
    . ' hr_name, hr_phone, hr_email, placement_submitted_at)'
    . " VALUES (1, ?, ?, ?, '1 Jalan Contoh', '30000', ?, 'Malaysia', 'Fake Supervisor', ?, '012-000 0000', ?, ?, ?, ?,"
    . " 'Fake tasks for the seed.', ?, ?, ?, ?, ?, ?)"
);
$survey = $pdo->prepare(
    'INSERT INTO internship_satisfaction (organisation_id, internship_id, user_id, recommend_juniors, overall_rating,'
    . ' offered_job, feedback) VALUES (1, ?, ?, ?, ?, ?, ?)'
);
// name, matric, faculty, company, nature, state, start, end, allowance, HR?
$interns = [
    ['Fake Intern One', 'FAKE-S-2001', 'Faculty of Fake Studies', 'Fake Data Sdn Bhd', 'Information and communication',
        'Perak', '2026-07-06', '2026-09-25', 900, true],
    ['Fake Intern Two', 'FAKE-S-2002', 'Faculty of Fake Studies', 'Fake Data Sdn Bhd', 'Information and communication',
        'Perak', '2026-07-06', '2026-09-25', 900, true],
    ['Fake Intern Three', 'FAKE-S-2003', 'Faculty of Other Studies', 'Fake Clinic', 'Human health and social work',
        'Selangor', '2026-07-13', '2026-10-02', 0, false],
    ['Fake Intern Four', 'FAKE-S-2004', 'Faculty of Other Studies', 'Fake Bank Bhd',
        'Financial and insurance or takaful', 'Kuala Lumpur', '2027-01-04', '2027-03-26', 1200, true],
];
// Survey answers per intern above: recommend, rating, offered a job. The fourth has not answered.
$surveys = [[1, 5, 1], [1, 4, 0], [0, 2, 0]];
foreach ($interns as $i => [$name, $matric, $faculty, $company, $nature, $state, $start, $end, $rm, $hr]) {
    $answers = $surveys[$i] ?? null;
    $user->execute(["intern$i@example.test", $name, $matric, $faculty, 'student', $now, $now, '2026-11-25', null]);
    $studentId = (int) $pdo->lastInsertId();
    $placement->execute([$studentId, $company, $nature, $state, "supervisor$i@company.example", $start, $end,
        (int) substr($start, 5, 2), (int) substr($start, 0, 4), $rm, (int) $hr, $hr ? 'Fake HR' : null,
        $hr ? '05-000 0000' : null, $hr ? "hr$i@company.example" : null, $now]);
    $internshipId = (int) $pdo->lastInsertId();
    if (is_array($answers)) {
        $survey->execute([$internshipId, $studentId, $answers[0], $answers[1], $answers[2], 'Fake feedback.']);
    }
    if ($i === 0) {
        $pdo->prepare(
            'INSERT INTO internship_change_log (organisation_id, internship_id, user_id, change_reason, remarks,'
            . " old_snapshot, new_snapshot) VALUES (1, ?, ?, 'allowance_role_update', 'Fake raise.', ?, ?)"
        )->execute([$internshipId, $studentId, json_encode(['monthly_allowance_rm' => '700.00']),
            json_encode(['monthly_allowance_rm' => '900.00'])]);
    }
}
echo "Seeded fake alumni and interns; example.test sign-in allowed.\n";

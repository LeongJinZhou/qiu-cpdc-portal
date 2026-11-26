<?php

declare(strict_types=1);

namespace Cpdc\Tests\Integration;

/**
 * CPDC analytics: the university-wide dashboard with filters, the two CSV reports and one person's 360 view
 * (docs/SPEC.md section 15). The test clock (1_800_000_000) is Friday 15 January 2027.
 */
final class AnalyticsTest extends HttpTestCase
{
    use SignsInAs;

    private const PLACEMENT = [
        'company' => '=Fake Data Sdn Bhd',
        'business_nature' => 'Information and communication',
        'company_address_1' => '1 Jalan Fake',
        'company_postcode' => '30000',
        'company_state' => 'Perak',
        'company_country' => 'Malaysia',
        'start_date' => '2027-01-04',
        'end_date' => '2027-03-26',
        'batch_month' => 1,
        'batch_year' => 2027,
        'monthly_allowance_rm' => 900,
        'roles_responsibilities' => 'Fake dashboards.',
        'has_hr_contact' => false,
        'supervisor_name' => 'Fake Supervisor',
        'supervisor_email' => 'supervisor@company.example',
        'supervisor_phone' => '012-345 6789',
    ];

    /** @return array{student: int, other: int, alumna: int} */
    private function seedPeople(): array
    {
        $student = $this->signInAs('student');
        $this->assertSame(200, $this->json('PUT', '/api/placement', self::PLACEMENT)->status);
        $week = $this->json('POST', '/api/logbook/entries', ['week_start' => '2027-01-04', 'hours' => 40,
            'activities' => 'Set up the dashboards.', 'learnings' => 'SQL joins.']);
        $this->assertSame(201, $week->status, $week->body);
        $entry = (int) $this->body($week)['id'];
        $this->assertSame(200, $this->json('POST', "/api/logbook/entries/$entry/submit")->status);
        $this->json('PUT', '/api/placement/satisfaction', ['recommend_juniors' => true, 'overall_rating' => 4,
            'offered_job' => true]);
        $other = $this->signInAs('student-b');
        $alumna = $this->signInAs('graduate', 'alumni');
        $this->pdo->prepare(
            'INSERT INTO career_profile (user_id, organisation_id, faculty, graduation_year, employment_status,'
            . " current_title, job_confirmed_at) VALUES (?, 1, 'Faculty of Fake Studies', 2025, 'employed',"
            . " 'Fake Analyst', '2026-12-01 00:00:00') ON DUPLICATE KEY UPDATE graduation_year = 2025,"
            . " employment_status = 'employed', current_title = 'Fake Analyst',"
            . " job_confirmed_at = '2026-12-01 00:00:00'"
        )->execute([$alumna]);
        $this->pdo->prepare(
            "INSERT INTO job_confirmation (organisation_id, user_id, employment_status, title, confirmed_at)"
            . " VALUES (1, ?, 'employed', 'Fake Analyst', '2026-12-01 00:00:00')"
        )->execute([$alumna]);

        return ['student' => $student, 'other' => $other, 'alumna' => $alumna];
    }

    public function testOnlyCpdcSeesAnalytics(): void
    {
        $ids = $this->seedPeople();
        foreach (['student' => null, 'staff' => null] as $persona => $role) {
            $this->signInAs($persona, $role);
            $this->assertError($this->send('GET', '/api/analytics/overview'), 403, 'FORBIDDEN');
            $this->assertError($this->send('GET', '/api/analytics/export'), 403, 'FORBIDDEN');
            $this->assertError($this->send('GET', '/api/people/' . $ids['student'] . '/analytics'), 403, 'FORBIDDEN');
        }
        $this->signInAs('admin', 'admin');
        $this->assertSame(200, $this->send('GET', '/api/analytics/overview')->status);
        $this->assertError($this->send('GET', '/api/analytics/overview', '', ['sort' => 'x']), 400, 'VALIDATION');
        $this->assertError($this->send('GET', '/api/analytics/overview', '', ['role' => 'staff']), 400, 'VALIDATION');
        $badDate = $this->send('GET', '/api/analytics/overview', '', ['from' => '2027-13-01']);
        $this->assertError($badDate, 400, 'VALIDATION');
        $this->assertError($this->send('GET', '/api/people/999999/analytics'), 404, 'NOT_FOUND');
    }

    public function testTheDashboardCountsFromTheStoredRows(): void
    {
        $this->seedPeople();
        $this->signInAs('admin', 'admin');
        $all = $this->body($this->send('GET', '/api/analytics/overview'));
        $k = $all['kpis'];
        $this->assertSame(2, $k['students']);
        $this->assertSame(1, $k['alumni']);
        // JSON drops ".0", so whole rates come back as integers.
        $this->assertEquals(1, $k['alumni_employment_rate']);
        $this->assertSame(0.5, $k['internship_placement_rate'], 'one of two students placed');
        $this->assertEquals(1, $k['job_offer_rate']);
        $this->assertEquals(0, $k['student_linkedin_rate'], 'the test personas have no LinkedIn');
        $this->assertNotNull($k['student_linkedin_rate']);

        $i = $all['internship'];
        $this->assertSame(1, $i['placed']);
        $this->assertSame([['label' => 'Information and communication', 'count' => 1]], $i['by_nature']);
        $this->assertSame(900.0, (float) $i['allowance_median']);
        $this->assertSame(2, $i['weeks_expected'], 'weeks of 4 and 11 January are due');
        $this->assertSame(1, $i['weeks_waiting']);
        $this->assertEquals(0, $i['logbook_completion_rate'], 'the week is sent, not approved');
        $this->assertNotNull($i['logbook_completion_rate']);
        $this->assertSame(4.0, (float) $i['satisfaction_average']);

        $this->assertSame(1, $all['alumni']['confirmed_recently']);
        $this->assertSame(1, $all['alumni']['without_linkedin']);
        $this->assertSame('employed', $all['alumni']['status'][0]['label']);
        $this->assertSame(1, $all['alumni']['status'][0]['count']);
        $this->assertContains('Faculty of Fake Studies', $all['options']['faculties']);

        $fake = array_column($all['faculties'], null, 'faculty')['Faculty of Fake Studies'];
        $this->assertSame(1, $fake['students']);
        $this->assertSame(1, $fake['interns']);
        $this->assertSame(1, $fake['alumni']);

        $students = $this->body($this->send('GET', '/api/analytics/overview', '', ['role' => 'student']));
        $this->assertSame(0, $students['kpis']['alumni']);
        $this->assertNull($students['kpis']['alumni_employment_rate']);
        $other = $this->body($this->send('GET', '/api/analytics/overview', '', [
            'faculty' => 'Faculty of Other Studies']));
        $this->assertSame(1, $other['kpis']['students']);
        $this->assertSame(0, $other['internship']['placed']);
        $cohort = $this->body($this->send('GET', '/api/analytics/overview', '', ['cohort' => '2025']));
        $this->assertSame(['students' => 0, 'alumni' => 1], ['students' => $cohort['kpis']['students'],
            'alumni' => $cohort['kpis']['alumni']]);
        $later = $this->body($this->send('GET', '/api/analytics/overview', '', ['from' => '2027-02-01']));
        $this->assertSame(0, $later['internship']['placed'], 'the internship started before the range');
    }

    public function testBothReportsAreFormulaSafeMalaysiaTimeAndAudited(): void
    {
        $this->seedPeople();
        $this->signInAs('admin', 'admin');
        $readiness = $this->send('GET', '/api/analytics/export', '', ['report' => 'readiness']);
        $this->assertSame(200, $readiness->status);
        $this->assertStringContainsString('"\'=Fake Data Sdn Bhd"', $readiness->body, 'a formula cell is escaped');
        $this->assertStringContainsString('"Fake Other Student"', $readiness->body);
        $this->assertStringNotContainsString('Fake Graduate', $readiness->body, 'alumni are not in readiness');

        $outcomes = $this->send('GET', '/api/analytics/export', '', ['report' => 'outcomes']);
        $this->assertStringContainsString('"Fake Graduate"', $outcomes->body);
        $this->assertStringContainsString('"2026-12-01 08:00"', $outcomes->body, 'confirmed at 08:00 Malaysia time');
        $this->assertStringContainsString('"recent"', $outcomes->body);
        $this->assertSame(2, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'analytics.export'"));
    }

    public function testThePersonViewCoversEveryModuleAndIsAudited(): void
    {
        $ids = $this->seedPeople();
        $this->signInAs('admin', 'admin');
        $view = $this->body($this->send('GET', '/api/people/' . $ids['student'] . '/analytics'));
        $checklist = array_column($view['checklist'], 'done', 'key');
        $this->assertTrue($checklist['placement']);
        $this->assertFalse($checklist['linkedin']);
        $this->assertSame('=Fake Data Sdn Bhd', $view['internship']['company']);
        $this->assertSame(
            ['started' => 1, 'approved' => 0, 'waiting' => 1, 'returned' => 0, 'hours_approved' => 0],
            $view['internship']['logbook']
        );
        $this->assertTrue($view['internship']['satisfaction']['offered_job']);
        $this->assertNull($view['alumni']);
        $this->assertSame(['done' => 0, 'total' => 0], $view['career_path']);

        $alumna = $this->body($this->send('GET', '/api/people/' . $ids['alumna'] . '/analytics'));
        $this->assertFalse($alumna['alumni']['confirmation_overdue']);
        $this->assertSame('Fake Analyst', $alumna['alumni']['confirmations'][0]['title']);
        $this->assertTrue(array_column($alumna['checklist'], 'done', 'key')['placement'], 'employed');
        $this->assertSame(2, $this->countRows(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'person.analytics_view'"
        ));
    }
}

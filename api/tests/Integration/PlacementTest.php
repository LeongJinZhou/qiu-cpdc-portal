<?php

declare(strict_types=1);

namespace Cpdc\Tests\Integration;

/**
 * Placement form, change history, satisfaction survey and CPDC's placement view with CSV exports (docs/SPEC.md
 * section 13). The test clock (1_800_000_000) is Friday 15 January 2027.
 */
final class PlacementTest extends HttpTestCase
{
    use SignsInAs;

    private const PLACEMENT = [
        'company' => 'Fake Engineering Sdn Bhd',
        'business_nature' => 'Information and communication',
        'company_address_1' => '1 Jalan Fake',
        'company_address_2' => 'Taman Contoh',
        'company_postcode' => '30000',
        'company_state' => 'Perak',
        'company_country' => 'Malaysia',
        'start_date' => '2027-01-04',
        'end_date' => '2027-03-26',
        'batch_month' => 1,
        'batch_year' => 2027,
        'monthly_allowance_rm' => 800,
        'roles_responsibilities' => 'Build and test internal dashboards.',
        'has_hr_contact' => true,
        'hr_name' => 'Fake HR Officer',
        'hr_phone' => '+60 5-123 4567',
        'hr_email' => 'HR@company.example',
        'supervisor_name' => 'Fake Supervisor',
        'supervisor_email' => 'supervisor@company.example',
        'supervisor_phone' => '012-345 6789',
    ];
    private const SURVEY = ['recommend_juniors' => true, 'overall_rating' => 4, 'offered_job' => false,
        'feedback' => 'Good mentors.'];

    /**
     * @param array<string, mixed> $changes
     * @return array<mixed>
     */
    private function savePlacement(array $changes = [], int $status = 200): array
    {
        $saved = $this->json('PUT', '/api/placement', $changes + self::PLACEMENT);
        $this->assertSame($status, $saved->status, $saved->body);

        return $this->body($saved);
    }

    public function testAStudentSubmitsAPlacementAndTheLogbookUsesIt(): void
    {
        $this->signInAs('student');
        $this->assertNull($this->body($this->send('GET', '/api/placement'))['placement']);

        $mine = $this->savePlacement();
        $p = $mine['placement'];
        $this->assertSame('Fake Engineering Sdn Bhd', $p['company']);
        $this->assertSame('800.00', $p['monthly_allowance_rm']);
        $this->assertSame('hr@company.example', $p['hr_email'], 'emails are kept lower case');
        $this->assertNull($p['company_address_3']);
        $this->assertTrue($p['has_hr_contact']);
        $this->assertSame('2027-01-15 08:00:00.000', $p['placement_submitted_at']);
        $this->assertSame([], $mine['changes'], 'the first save is not a change');
        $this->assertContains('Other', $mine['business_natures']);

        $logbook = $this->body($this->send('GET', '/api/logbook'));
        $this->assertSame('supervisor@company.example', $logbook['internship']['supervisor_email']);
        $week = $this->json('POST', '/api/logbook/entries', ['week_start' => '2027-01-04', 'hours' => 40,
            'activities' => 'Set up the dashboards.', 'learnings' => 'SQL joins.']);
        $this->assertSame(201, $week->status, $week->body);
        // The short logbook form cannot change a submitted placement behind the change history.
        $this->refused(
            'PUT',
            '/api/logbook/internship',
            ['company' => 'Other Co', 'supervisor_name' => 'X Y',
            'supervisor_email' => 'x@company.example', 'start_date' => '2027-01-04', 'end_date' => '2027-03-26'],
            409,
            'PLACEMENT_SUBMITTED'
        );
    }

    public function testAnInternshipFromTheShortFormBecomesAPlacementWithoutAReason(): void
    {
        $this->signInAs('student');
        $short = $this->json('PUT', '/api/logbook/internship', ['company' => 'Fake Co', 'supervisor_name' => 'X Y',
            'supervisor_email' => 'x@company.example', 'start_date' => '2027-01-04', 'end_date' => '2027-03-26']);
        $this->assertSame(200, $short->status, $short->body);
        $mine = $this->savePlacement();
        $this->assertNotNull($mine['placement']['placement_submitted_at']);
        $this->assertSame([], $mine['changes']);
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM internship'));
    }

    public function testNoHrPersonnelClearsTheHrDetailsAndHrNeedsAllThreeOtherwise(): void
    {
        $this->signInAs('student');
        $mine = $this->savePlacement(['has_hr_contact' => false]);
        $this->assertFalse($mine['placement']['has_hr_contact']);
        $this->assertNull($mine['placement']['hr_name']);
        $this->assertNull($mine['placement']['hr_email']);

        $this->signInAs('student-b');
        $this->refused('PUT', '/api/placement', ['hr_name' => ''] + self::PLACEMENT, 400, 'VALIDATION');
        $this->refused('PUT', '/api/placement', ['hr_phone' => 'call me'] + self::PLACEMENT, 400, 'VALIDATION');
        $this->refused('PUT', '/api/placement', ['hr_email' => 'not-an-email'] + self::PLACEMENT, 400, 'VALIDATION');
    }

    public function testTheFormRefusesBadValuesAndUnknownFields(): void
    {
        $this->signInAs('student');
        $bad = [
            ['business_nature' => 'Space mining'],
            ['end_date' => '2026-12-01'],
            ['start_date' => '2027-02-30'],
            ['batch_month' => 13],
            ['batch_year' => 1999],
            ['monthly_allowance_rm' => -1],
            ['monthly_allowance_rm' => 10.555],
            ['supervisor_phone' => 'abc'],
            ['supervisor_email' => 'student@example.test'],
            ['company_postcode' => '1'],
            ['roles_responsibilities' => 'short'],
            ['nric' => '900101-08-1234'],
        ];
        foreach ($bad as $change) {
            $this->refused('PUT', '/api/placement', $change + self::PLACEMENT, 400, 'VALIDATION');
        }
        $missing = self::PLACEMENT;
        unset($missing['company_state']);
        $this->refused('PUT', '/api/placement', $missing, 400, 'VALIDATION');
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM internship'));
    }

    public function testChangingASubmittedPlacementNeedsAReasonAndKeepsBothSnapshots(): void
    {
        $this->signInAs('student');
        $this->savePlacement();
        $this->refused('PUT', '/api/placement', ['monthly_allowance_rm' => 1000] + self::PLACEMENT, 400, 'VALIDATION');
        $this->refused('PUT', '/api/placement', ['change_reason' => 'bored'] + self::PLACEMENT, 400, 'VALIDATION');

        $mine = $this->savePlacement(['monthly_allowance_rm' => 1000.5, 'change_reason' => 'allowance_role_update',
            'remarks' => 'Raise after probation.']);
        $this->assertSame('1000.50', $mine['placement']['monthly_allowance_rm']);
        $this->assertCount(1, $mine['changes']);
        $change = $mine['changes'][0];
        $this->assertSame('allowance_role_update', $change['change_reason']);
        $this->assertSame('Raise after probation.', $change['remarks']);
        $this->assertSame(
            [['field' => 'monthly_allowance_rm', 'old' => '800.00', 'new' => '1000.50']],
            $change['fields']
        );
        $row = $this->sql('SELECT old_snapshot, new_snapshot, user_id FROM internship_change_log')
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('Fake Engineering Sdn Bhd', json_decode((string) $row['old_snapshot'], true)['company']);
        $this->assertSame('1000.50', json_decode((string) $row['new_snapshot'], true)['monthly_allowance_rm']);

        // The same values again with a reason: nothing changed, no history row.
        $this->savePlacement(['monthly_allowance_rm' => 1000.5, 'change_reason' => 'correction']);
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM internship_change_log'));
    }

    public function testANewSupervisorEmailVoidsUnusedReviewLinks(): void
    {
        $this->signInAs('student');
        $this->savePlacement();
        $week = $this->body($this->json('POST', '/api/logbook/entries', ['week_start' => '2027-01-04', 'hours' => 40,
            'activities' => 'Set up the dashboards.', 'learnings' => 'SQL joins.']));
        $this->assertSame(200, $this->json('POST', '/api/logbook/entries/' . $week['id'] . '/submit')->status);
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM logbook_review_link'));
        $this->savePlacement(['supervisor_email' => 'new.boss@company.example',
            'change_reason' => 'supervisor_hr_update']);
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM logbook_review_link'));
    }

    public function testTheSurveyNeedsAnInternshipAndIsTheOwnersToChange(): void
    {
        $this->signInAs('student');
        $this->refused('PUT', '/api/placement/satisfaction', self::SURVEY, 409, 'NO_INTERNSHIP');
        $this->savePlacement();
        foreach (
            [['overall_rating' => 0], ['overall_rating' => 6], ['overall_rating' => '5'],
            ['recommend_juniors' => 'yes'], ['user_id' => 1]] as $change
        ) {
            $this->refused('PUT', '/api/placement/satisfaction', $change + self::SURVEY, 400, 'VALIDATION');
        }
        $saved = $this->body($this->json('PUT', '/api/placement/satisfaction', self::SURVEY));
        $this->assertSame(4, $saved['satisfaction']['overall_rating']);
        $this->assertTrue($saved['satisfaction']['recommend_juniors']);
        $changed = $this->body($this->json(
            'PUT',
            '/api/placement/satisfaction',
            ['overall_rating' => 5, 'offered_job' => true, 'feedback' => ''] + self::SURVEY
        ));
        $this->assertSame(5, $changed['satisfaction']['overall_rating']);
        $this->assertTrue($changed['satisfaction']['offered_job']);
        $this->assertNull($changed['satisfaction']['feedback']);
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM internship_satisfaction'));

        // Another student sees only their own (empty) placement and cannot touch this survey.
        $this->signInAs('student-b');
        $other = $this->body($this->send('GET', '/api/placement'));
        $this->assertNull($other['placement']);
        $this->assertNull($other['satisfaction']);
        $this->refused('PUT', '/api/placement/satisfaction', self::SURVEY, 409, 'NO_INTERNSHIP');
    }

    public function testTheHomeToDoAsksForTheSurveyOnlyAfterTheInternshipEnded(): void
    {
        $this->signInAs('student');
        $satisfactionTask = function (): ?bool {
            $tasks = array_column($this->body($this->send('GET', '/api/home'))['tasks'], 'done', 'key');

            return $tasks['satisfaction'] ?? null;
        };
        $this->assertNull($satisfactionTask(), 'no internship, no task');
        $this->savePlacement();
        $this->assertNull($satisfactionTask(), 'the internship ends in March 2027: not asked yet');
        $this->savePlacement(['end_date' => '2027-01-08', 'change_reason' => 'date_change']);
        $this->assertFalse($satisfactionTask(), 'ended a week ago without an answer');
        $this->json('PUT', '/api/placement/satisfaction', self::SURVEY);
        $this->assertTrue($satisfactionTask(), 'answered');
    }

    public function testOnlyStudentsKeepAPlacementAndOnlyCpdcSeesEveryone(): void
    {
        $this->signInAs('staff');
        $this->assertError($this->send('GET', '/api/placement'), 403, 'FORBIDDEN');
        $this->assertError($this->send('GET', '/api/placements'), 403, 'FORBIDDEN');
        $this->signInAs('student');
        $this->assertError($this->send('GET', '/api/placements'), 403, 'FORBIDDEN');
        $this->assertError($this->send('GET', '/api/placements/export/visits'), 403, 'FORBIDDEN');
        $this->assertError($this->send('GET', '/api/placements/satisfaction'), 403, 'FORBIDDEN');
        $this->signInAs('admin', 'admin');
        $this->assertError($this->json('PUT', '/api/placement', self::PLACEMENT), 403, 'FORBIDDEN');
        $this->assertSame(200, $this->send('GET', '/api/placements')->status);
    }

    public function testCpdcFiltersPlacementsAndReadsTheChangeHistory(): void
    {
        $this->signInAs('student');
        $this->savePlacement();
        $this->savePlacement(['company' => 'Fake Bank Bhd', 'change_reason' => 'company_change']);
        $this->signInAs('student-b');
        $this->savePlacement(['company' => 'Fake Clinic', 'business_nature' => 'Human health and social work',
            'batch_month' => 6, 'monthly_allowance_rm' => 0, 'supervisor_email' => 'doc@clinic.example']);

        $this->signInAs('admin', 'admin');
        $all = $this->body($this->send('GET', '/api/placements'));
        $this->assertSame(2, $all['total']);
        $filters = $this->body($this->send('GET', '/api/placements/filters'));
        $this->assertContains('Faculty of Fake Studies', $filters['faculties']);
        $this->assertSame([['year' => 2027, 'month' => 6], ['year' => 2027, 'month' => 1]], $filters['batches']);

        $pick = fn (array $q): array => array_column(
            $this->body($this->send('GET', '/api/placements', '', $q))['items'],
            'company'
        );
        $this->assertSame(['Fake Clinic'], $pick(['batch_month' => '6', 'batch_year' => '2027']));
        $this->assertSame(['Fake Bank Bhd'], $pick(['faculty' => 'Faculty of Fake Studies']));
        $this->assertSame(['Fake Clinic'], $pick(['business_nature' => 'Human health and social work']));
        $this->assertSame(['Fake Clinic'], $pick(['allowance_max' => '0']));
        $this->assertSame(['Fake Bank Bhd'], $pick(['allowance_min' => '500', 'allowance_max' => '900']));
        $this->assertError($this->send('GET', '/api/placements', '', ['allowance_min' => '900',
            'allowance_max' => '100']), 400, 'VALIDATION');
        $this->assertError($this->send('GET', '/api/placements', '', ['business_nature' => 'Nope']), 400, 'VALIDATION');
        $this->assertError($this->send('GET', '/api/placements', '', ['sort' => 'x']), 400, 'VALIDATION');

        $bank = $this->body($this->send('GET', '/api/placements', '', ['q' => 'Bank']))['items'][0];
        $this->assertSame(1, $bank['changes']);
        $history = $this->body($this->send('GET', '/api/placements/' . $bank['internship_id'] . '/changes'));
        $this->assertSame('company_change', $history['changes'][0]['change_reason']);
        $this->assertSame('Fake Student', $history['changes'][0]['changed_by']);
        $this->assertSame('Fake Engineering Sdn Bhd', $history['changes'][0]['fields'][0]['old']);
        $this->assertSame(1, $this->countRows(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'placement.changes_view'"
        ));
        $this->assertError($this->send('GET', '/api/placements/999999/changes'), 404, 'NOT_FOUND');
    }

    public function testSatisfactionPerCompanyAndBothCsvExportsAreFormulaSafeAndAudited(): void
    {
        $this->signInAs('student');
        $this->savePlacement(['company' => '=HYPERLINK("http://evil")', 'roles_responsibilities' => '＠SUM(A1) roles']);
        $this->json('PUT', '/api/placement/satisfaction', self::SURVEY);
        $this->signInAs('student-b');
        $this->savePlacement(['company' => '=hyperlink("http://evil")', 'supervisor_email' => 'b@company.example']);
        $this->json('PUT', '/api/placement/satisfaction', ['overall_rating' => 2, 'offered_job' => true,
            'recommend_juniors' => false] + self::SURVEY);

        $this->signInAs('admin', 'admin');
        $result = $this->body($this->send('GET', '/api/placements/satisfaction'));
        $this->assertCount(1, $result['companies'], 'company names group without case');
        $this->assertSame(2, $result['companies'][0]['responses']);
        $this->assertSame(3.0, (float) $result['companies'][0]['avg_rating']);
        $this->assertSame(50, $result['companies'][0]['recommend_pct']);
        $this->assertSame(50, $result['companies'][0]['offer_pct']);
        $this->assertSame(2, $result['total']['responses']);
        $none = $this->body($this->send('GET', '/api/placements/satisfaction', '', ['faculty' => 'Nobody']));
        $this->assertSame([], $none['companies']);
        $this->assertNull($none['total']['avg_rating']);

        $visits = $this->send('GET', '/api/placements/export/visits');
        $this->assertSame(200, $visits->status);
        $this->assertSame('text/csv; charset=utf-8', $visits->headers['Content-Type']);
        $this->assertStringContainsString('"\'=HYPERLINK(""http://evil"")"', $visits->body);
        $this->assertStringContainsString('"\'＠SUM(A1) roles"', $visits->body);
        $this->assertStringContainsString('"2027-01-15 16:00"', $visits->body, 'times in Malaysia');
        $this->assertStringContainsString('"Jan 2027"', $visits->body);
        $fake = ['faculty' => 'Faculty of Fake Studies'];
        $survey = $this->send('GET', '/api/placements/export/satisfaction', '', $fake);
        $this->assertSame(200, $survey->status);
        $this->assertStringContainsString('"Very good"', $survey->body);
        $this->assertStringNotContainsString('Fake Other Student', $survey->body, 'filters apply to the export');
        $audit = $this->sql(
            "SELECT action, meta_json FROM audit_log WHERE action LIKE 'placement.export%' ORDER BY id"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame(['placement.export_visits', 'placement.export_satisfaction'], array_column($audit, 'action'));
        $this->assertSame(['rows' => 2], json_decode((string) $audit[0]['meta_json'], true));
        $this->assertSame(['rows' => 1], json_decode((string) $audit[1]['meta_json'], true));
    }
}

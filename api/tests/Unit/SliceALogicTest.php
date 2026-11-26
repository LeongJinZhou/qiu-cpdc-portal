<?php

declare(strict_types=1);

namespace Cpdc\Tests\Unit;

use Cpdc\Http\ApiError;
use Cpdc\Http\Csv;
use Cpdc\Jobs\JobDetails;
use Cpdc\Placement\BusinessNature;
use Cpdc\Placement\PlacementForm;
use PHPUnit\Framework\TestCase;

/** Slice A rules without a database: the placement form's snapshot, job details, CSV formula escaping. */
final class SliceALogicTest extends TestCase
{
    private const BODY = [
        'company' => ' Fake Co ', 'business_nature' => 'Education', 'company_address_1' => '1 Jalan Fake',
        'company_address_2' => '', 'company_postcode' => '30000', 'company_state' => 'Perak',
        'company_country' => 'Malaysia', 'start_date' => '2027-01-04', 'end_date' => '2027-03-26',
        'batch_month' => 1, 'batch_year' => 2027, 'monthly_allowance_rm' => 0, 'roles_responsibilities' => 'Teach',
        'has_hr_contact' => false, 'hr_name' => 'Ignored', 'supervisor_name' => 'Boss',
        'supervisor_email' => 'Boss@Co.example', 'supervisor_phone' => '012 345 6789',
    ];

    public function testABodyBecomesAComparableSnapshot(): void
    {
        $snap = PlacementForm::fromBody(self::BODY, 'student@example.test');
        $this->assertSame(PlacementForm::FIELDS, array_keys($snap), 'every field, in order');
        $this->assertSame('Fake Co', $snap['company']);
        $this->assertNull($snap['company_address_2']);
        $this->assertSame('0.00', $snap['monthly_allowance_rm']);
        $this->assertSame(0, $snap['has_hr_contact']);
        $this->assertNull($snap['hr_name'], 'no HR person: no HR details');
        $this->assertSame('boss@co.example', $snap['supervisor_email']);

        $row = $snap;
        $row['monthly_allowance_rm'] = '0.00';
        $row['batch_month'] = '1'; // as PDO returns it
        $this->assertSame([], PlacementForm::changed(PlacementForm::fromRow($row), $snap));
        $this->assertSame(['company'], PlacementForm::changed($snap, ['company' => 'Other'] + $snap));
    }

    public function testTheRulesBetweenFieldsAreChecked(): void
    {
        $cases = [
            ['business_nature' => 'Unknown'],
            ['end_date' => '2027-01-01'],
            ['start_date' => '2027-13-01'],
            ['monthly_allowance_rm' => 1.001],
            ['has_hr_contact' => true, 'hr_name' => 'A', 'hr_phone' => '0123456', 'hr_email' => 'a@b.example'],
            ['has_hr_contact' => true, 'hr_name' => 'HR Person', 'hr_phone' => 'x', 'hr_email' => 'a@b.example'],
            ['has_hr_contact' => true, 'hr_name' => 'HR Person', 'hr_phone' => '0123456', 'hr_email' => 'nope'],
            ['supervisor_email' => 'STUDENT@example.test'],
        ];
        foreach ($cases as $change) {
            try {
                PlacementForm::fromBody($change + self::BODY, 'student@example.test');
                $this->fail('accepted ' . json_encode($change));
            } catch (ApiError $e) {
                $this->assertSame(400, $e->status);
            }
        }
        $hr = PlacementForm::fromBody(['has_hr_contact' => true, 'hr_name' => 'HR Person', 'hr_phone' => '+60 5-1234',
            'hr_email' => 'HR@Co.example'] + self::BODY, null);
        $this->assertSame('hr@co.example', $hr['hr_email']);
        $this->assertCount(22, BusinessNature::ALL, '21 MSIC sections and Other');
    }

    public function testJobDetailsAreCleanedAndShaped(): void
    {
        $out = JobDetails::fromBody(['specializations' => ' IT,, it , Law ', 'salary_min' => 1000, 'salary_max' => 1000,
            'pay_frequency' => 'monthly', 'state' => '  ', 'contact_email' => '']);
        $this->assertSame('IT, Law', $out['specializations']);
        $this->assertSame('1000.00', $out['salary_max']);
        $this->assertNull($out['state']);
        $this->assertNull($out['contact_email']);
        $shaped = JobDetails::shape($out + ['attachment_file' => null]);
        $this->assertSame(['IT', 'Law'], $shaped['specializations']);
        $this->assertSame(1000.0, $shaped['salary_min']);
        $this->assertFalse($shaped['has_brochure']);
        $this->assertSame([], JobDetails::split(''));
        $this->expectException(ApiError::class);
        JobDetails::fromBody(['salary_min' => 0, 'salary_max' => 10, 'pay_frequency' => 'unpaid']);
    }

    public function testCsvQuotesFormulasIncludingFullWidthForms(): void
    {
        $body = Csv::response('x.csv', ['h'], [['=1'], ['＠SUM(A1)'], ['＋1'], ['-2'], ["\tx"], ['ok'], [null]])->body;
        foreach (["\"'=1\"", "\"'＠SUM(A1)\"", "\"'＋1\"", "\"'-2\"", "\"'\tx\"", '"ok"', '""'] as $cell) {
            $this->assertStringContainsString($cell, $body);
        }
    }
}

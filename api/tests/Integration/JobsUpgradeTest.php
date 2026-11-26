<?php

declare(strict_types=1);

namespace Cpdc\Tests\Integration;

/** Slice A on jobs: the extra job details, the new filters and a job's PDF brochure. */
final class JobsUpgradeTest extends ModuleTestCase
{
    private const DETAILS = [
        'specializations' => 'Data Science, Computer Science,  data science , ',
        'vacancy_count' => 3,
        'duration_months' => 6,
        'salary_min' => 1200,
        'salary_max' => 1500.5,
        'pay_frequency' => 'monthly',
        'min_qualification' => 'Diploma',
        'work_experience' => 'None',
        'other_requirements' => 'Own laptop.',
        'benefits' => 'Medical cover.',
        'country' => 'Malaysia',
        'state' => 'Perak',
        'contact_email' => 'HR@Employer.example',
    ];

    /** @param array<string, mixed> $details */
    private function jobWith(array $details, string $title = 'Analyst Intern'): int
    {
        $employer = $this->body($this->json('POST', '/api/employers', ['name' => 'Fake Employer ' . $title]));
        $job = $this->json('POST', '/api/jobs/manage', $details + [
            'employer_id' => $employer['id'],
            'title' => $title,
            'job_type' => 'internship',
            'location' => 'Ipoh',
            'closing_date' => '2030-12-31',
            'description' => 'Fake job for tests.',
        ]);
        $this->assertSame(201, $job->status, $job->body);

        return (int) $this->body($job)['id'];
    }

    public function testCpdcSavesTheDetailsAndStudentsSeeAndFilterByThem(): void
    {
        $this->signInAs('admin', 'admin');
        $id = $this->jobWith(self::DETAILS);
        $this->jobWith(['state' => 'Selangor', 'specializations' => 'Accounting'], 'Audit Intern');
        $edit = $this->body($this->send('GET', "/api/jobs/manage/$id"));
        $this->assertSame(['Data Science', 'Computer Science'], $edit['specializations'], 'trimmed, no repeats');
        $this->assertSame(1500.5, $edit['salary_max']);
        $this->assertSame('hr@employer.example', $edit['contact_email']);
        $this->assertFalse($edit['has_brochure']);

        $this->signInAs('student', 'student');
        $job = $this->body($this->send('GET', "/api/jobs/$id"));
        $this->assertSame(3, $job['vacancy_count']);
        $this->assertSame('Medical cover.', $job['benefits']);
        $this->assertSame('Perak', $job['state']);
        $this->assertSame('monthly', $job['pay_frequency']);
        $titles = fn (array $q): array
            => array_column($this->body($this->send('GET', '/api/jobs', '', $q))['items'], 'title');
        $this->assertSame(['Analyst Intern'], $titles(['state' => 'Perak']));
        $this->assertSame(['Analyst Intern'], $titles(['specialization' => 'computer science']));
        $this->assertSame([], $titles(['specialization' => 'Science']), 'a whole specialization, not part of one');
        $this->assertSame(['Audit Intern'], $titles(['specialization' => 'Accounting', 'type' => 'internship']));
        $filters = $this->body($this->send('GET', '/api/jobs/filters'));
        $this->assertSame(['Perak', 'Selangor'], $filters['states']);
        $this->assertSame(['Accounting', 'Computer Science', 'Data Science'], $filters['specializations']);
        $this->assertSame([1200, null], [ // JSON writes 1200.0 as 1200
            $this->body($this->send('GET', '/api/jobs', '', ['state' => 'Perak']))['items'][0]['salary_min'],
            $this->body($this->send('GET', '/api/jobs', '', ['state' => 'Selangor']))['items'][0]['salary_min'],
        ]);
    }

    public function testThePayRulesAndUnknownFieldsAreChecked(): void
    {
        $this->signInAs('admin', 'admin');
        $employer = $this->body($this->json('POST', '/api/employers', ['name' => 'Fake Pay Co']))['id'];
        $base = ['employer_id' => $employer, 'title' => 'T', 'job_type' => 'contract', 'location' => 'Ipoh',
            'closing_date' => '2030-12-31', 'description' => 'D'];
        $tooLong = implode(', ', array_map(static fn (int $n): string => "Field number $n", range(1, 25)));
        foreach (
            [
            ['salary_min' => 2000, 'salary_max' => 1000, 'pay_frequency' => 'monthly'],
            ['salary_min' => 500, 'pay_frequency' => 'unpaid'],
            ['salary_min' => 500],
            ['pay_frequency' => 'weekly'],
            ['contact_email' => 'not an email'],
            ['vacancy_count' => 0],
            ['duration_months' => 121],
            ['specializations' => $tooLong],
            ['attachment_file' => str_repeat('a', 32)],
            ] as $change
        ) {
            $this->assertError($this->json('POST', '/api/jobs/manage', $change + $base), 400, 'VALIDATION');
        }
        $unpaid = $this->json('POST', '/api/jobs/manage', ['pay_frequency' => 'unpaid', 'salary_min' => null] + $base);
        $this->assertSame(201, $unpaid->status, $unpaid->body);
    }

    public function testABrochureIsAPdfAndOnlyThoseWhoMaySeeTheJobDownloadIt(): void
    {
        $this->signInAs('admin', 'admin');
        $id = $this->jobWith([]);
        $this->assertError(
            $this->upload("/api/jobs/manage/$id/brochure", [], ['file' => 'bytes:<html>hi</html>']),
            400,
            'VALIDATION'
        );
        $missing = $this->upload('/api/jobs/manage/999999/brochure', [], ['file' => self::PDF]);
        $this->assertError($missing, 404, 'NOT_FOUND');
        $up = $this->upload("/api/jobs/manage/$id/brochure", [], ['file' => self::PDF]);
        $this->assertSame(200, $up->status, $up->body);
        $first = (string) $this->sql("SELECT attachment_file FROM job_posting WHERE id = $id")->fetchColumn();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first);
        $this->assertFileExists($this->storage . "/uploads/job/$first.pdf");
        $this->assertSame(200, $this->upload("/api/jobs/manage/$id/brochure", [], ['file' => self::PDF])->status);
        $this->assertFileDoesNotExist($this->storage . "/uploads/job/$first.pdf", 'a replaced brochure is deleted');
        $this->assertSame(2, $this->countAudit('job.brochure_attach'));

        $this->signInAs('student', 'student');
        $this->assertTrue($this->body($this->send('GET', "/api/jobs/$id"))['has_brochure']);
        $pdf = $this->send('GET', "/api/jobs/$id/brochure");
        $this->assertSame(200, $pdf->status);
        $this->assertSame('application/pdf', $pdf->headers['Content-Type']);
        $this->assertSame('attachment; filename="file.pdf"', $pdf->headers['Content-Disposition']);
        $this->assertStringStartsWith('%PDF-', $pdf->body);
        $this->assertError($this->upload("/api/jobs/manage/$id/brochure", [], ['file' => self::PDF]), 403, 'FORBIDDEN');
        $this->assertError($this->send('DELETE', "/api/jobs/manage/$id/brochure"), 403, 'FORBIDDEN');

        $this->signInAs('admin', 'admin');
        $this->pdo->exec("UPDATE job_posting SET status = 'closed' WHERE id = $id");
        $this->assertSame(200, $this->send('GET', "/api/jobs/$id/brochure")->status, 'CPDC sees any job');
        $this->signInAs('student', 'student');
        $this->assertError($this->send('GET', "/api/jobs/$id/brochure"), 404, 'NOT_FOUND');
        $this->signInAs('staff', 'staff');
        $this->assertError($this->send('GET', "/api/jobs/$id/brochure"), 403, 'FORBIDDEN');

        $this->signInAs('admin', 'admin');
        $this->assertSame(204, $this->send('DELETE', "/api/jobs/manage/$id/brochure")->status);
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM job_posting WHERE attachment_file IS NOT NULL'));
        $this->assertSame([], glob($this->storage . '/uploads/job/*.pdf') ?: []);
        $this->assertError($this->send('GET', "/api/jobs/$id/brochure"), 404, 'NOT_FOUND');
    }

    private function countAudit(string $action): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = ?');
        $stmt->execute([$action]);

        return (int) $stmt->fetchColumn();
    }

    private function countRows(string $query): int
    {
        return (int) $this->sql($query)->fetchColumn();
    }
}

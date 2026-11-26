<?php

declare(strict_types=1);

namespace Cpdc\Tests\Unit;

use Cpdc\Analytics\AnalyticsFilters;
use Cpdc\Analytics\AnalyticsService;
use Cpdc\Http\ApiError;
use PHPUnit\Framework\TestCase;

/** The analytics filters as SQL and the small sums the dashboard is built from (docs/SPEC.md section 15). */
final class AnalyticsMathTest extends TestCase
{
    public function testTheFilterCountsActiveStudentsAndAlumniAndNarrowsByEveryField(): void
    {
        [$sql, $args] = (new AnalyticsFilters(1))->where();
        $this->assertStringContainsString('u.role IN (?, ?)', $sql);
        $this->assertSame([1, 'student', 'alumni'], $args);

        $f = new AnalyticsFilters(2, 'Faculty of Fake Studies', 'Bachelor of Fake', 'alumni', 2025);
        [$sql, $args] = $f->where();
        $this->assertStringContainsString('COALESCE(p.faculty, u.department) = ?', $sql);
        $this->assertStringContainsString('p.programme = ?', $sql);
        $this->assertStringContainsString('p.graduation_year = ?', $sql);
        $this->assertSame([2, 'alumni', 'Faculty of Fake Studies', 'Bachelor of Fake', 2025], $args);
        $this->assertSame(['1 = 0', []], $f->where('student'), 'alumni filter: no students counted');
    }

    public function testDatesAreMalaysiaDaysWithBothEndsIncluded(): void
    {
        $f = AnalyticsFilters::fromQuery(1, ['from' => '2027-01-01', 'to' => '2027-01-31'], 'Asia/Kuala_Lumpur');
        $this->assertSame('2026-12-31 16:00:00', $f->fromUtc);
        $this->assertSame('2027-01-31 16:00:00', $f->toUtc, 'the end of 31 January, Malaysia time');
        $this->assertSame(
            [' AND a.created_at >= ? AND a.created_at < ?', [$f->fromUtc, $f->toUtc]],
            $f->period('a.created_at')
        );
        $this->assertSame(['', []], (new AnalyticsFilters(1))->period('a.created_at'));
        $blank = AnalyticsFilters::fromQuery(1, ['faculty' => '  ', 'role' => 'all'], 'Asia/Kuala_Lumpur');
        $this->assertNull($blank->faculty);
        $this->expectException(ApiError::class);
        AnalyticsFilters::fromQuery(1, ['from' => '2027-02-30'], 'Asia/Kuala_Lumpur');
    }

    public function testRateAndMedian(): void
    {
        $this->assertNull(AnalyticsService::rate(0, 0));
        $this->assertSame(0.667, AnalyticsService::rate(2, 3));
        $this->assertSame(1.0, AnalyticsService::rate(3, 3));
        $this->assertNull(AnalyticsService::median([]));
        $this->assertSame(800.0, AnalyticsService::median([1200.0, 0.0, 800.0]));
        $this->assertSame(450.0, AnalyticsService::median([900.0, 0.0]));
    }

    public function testAnEndDateBeforeTheStartIsRefused(): void
    {
        $same = AnalyticsFilters::fromQuery(1, ['from' => '2027-01-31', 'to' => '2027-01-31'], 'Asia/Kuala_Lumpur');
        $this->assertSame('2027-01-30 16:00:00', $same->fromUtc, 'one whole Malaysia day');
        $this->assertSame('2027-01-31 16:00:00', $same->toUtc);
        $this->expectException(ApiError::class);
        AnalyticsFilters::fromQuery(1, ['from' => '2027-02-01', 'to' => '2027-01-31'], 'Asia/Kuala_Lumpur');
    }
}

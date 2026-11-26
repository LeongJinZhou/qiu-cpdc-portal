<?php

declare(strict_types=1);

namespace Cpdc\Analytics;

use Cpdc\App;
use Cpdc\Audit\Audit;
use Cpdc\Http\Csv;
use Cpdc\Http\Request;
use Cpdc\Http\Response;
use Cpdc\Http\Router;
use Cpdc\Http\Validator;
use Cpdc\Logbook\Weeks;
use Cpdc\Profile\ProfileController;
use Cpdc\Workshops\WorkshopTime;

/**
 * CPDC analytics (analytics.view, exports analytics.export): the dashboard, the two person-level CSV reports and one
 * person's 360 view. Opening a 360 view and every export are written to the audit log.
 */
final class AnalyticsController
{
    private const STATUS = ['employed' => 'Employed', 'self_employed' => 'Self-employed',
        'further_study' => 'Further study', 'seeking' => 'Seeking work', 'not_seeking' => 'Not seeking',
        'unknown' => 'Unknown'];

    public static function routes(Router $router): Router
    {
        $export = ['name' => 'export', 'setting' => 'rate_export_per_user_per_min', 'by' => 'user'];

        return $router
            ->add('GET', '/api/analytics/overview', self::overview(...))
            ->add('GET', '/api/analytics/export', self::export(...), rate: $export)
            ->add('GET', '/api/people/{id}/analytics', self::person(...));
    }

    /** @param array<string, string> $params */
    public static function overview(Request $req, array $params, App $app): Response
    {
        $admin = self::admin($req, $app, 'analytics.view');
        $filters = self::filters($req, $app, $admin['organisation_id']);

        return Response::json(self::service($app, $admin['organisation_id'])->overview($filters));
    }

    /** @param array<string, string> $params */
    public static function export(Request $req, array $params, App $app): Response
    {
        $admin = self::admin($req, $app, 'analytics.export');
        $org = $admin['organisation_id'];
        $filters = self::filters($req, $app, $org);
        $zone = $app->settings($org)->string('display_timezone');
        $time = static fn (mixed $utc): string => WorkshopTime::readable(is_string($utc) ? $utc : null, $zone);
        $yes = static fn (mixed $v): string => $v === null || (int) $v === 0 ? 'No' : 'Yes';
        $reports = new AnalyticsReports($app->pdo(), $app->now(), self::confirmDays($app, $org));
        $date = Weeks::today($app->now(), $zone); // the Malaysia date in the file name
        if (($req->query['report'] ?? '') === 'outcomes') {
            $rows = $reports->outcomes($filters);
            Audit::log($app->pdo(), $admin, 'analytics.export', 'alumni_outcomes', ['rows' => count($rows),
                'filters' => $req->query]);

            return Csv::response("alumni-outcomes-$date.csv", ['Name', 'Matric no', 'Faculty', 'Programme',
                'Graduation year', 'Status', 'Job title', 'Company', 'Job confirmed (Malaysia time)', 'Confirmation',
                'LinkedIn', 'In alumni network', 'Mentor', 'Last sign-in (Malaysia time)'], array_map(
                    static fn (array $r): array => [$r['display_name'], $r['matric_no'], $r['faculty'], $r['programme'],
                        $r['graduation_year'], self::STATUS[(string) $r['employment_status']] ?? 'Unknown',
                        $r['current_title'], $r['current_company'], $time($r['job_confirmed_at']),
                        $r['confirmation'], $r['linkedin_url'], $yes($r['network_visible']), $yes($r['mentor_active']),
                        $time($r['last_sign_in_at'])],
                    $rows
                ));
        }
        $rows = $reports->readiness($filters);
        // The query passed analytics.query.json in filters() above: known keys only.
        Audit::log($app->pdo(), $admin, 'analytics.export', 'student_readiness', ['rows' => count($rows),
            'filters' => $req->query]);

        return Csv::response("student-readiness-$date.csv", ['Name', 'Matric no', 'Faculty', 'Programme',
            'Onboarded', 'LinkedIn', 'LinkedIn PDF imported', 'CV updated (Malaysia time)', 'Shares CV with employers',
            'Skill ratings', 'Self-assessments', 'Verified learning', 'Internship company', 'Placement submitted',
            'Job applications', 'Last sign-in (Malaysia time)'], array_map(
                static fn (array $r): array => [$r['display_name'], $r['matric_no'], $r['faculty'], $r['programme'],
                    $yes($r['onboarded_at'] === null ? null : 1), $r['linkedin_url'],
                    $yes($r['last_pdf_import_at'] === null ? null : 1), $time($r['cv_updated_at']),
                    $yes($r['employer_sharing_consent_at'] === null ? null : 1), (int) $r['skill_ratings'],
                    (int) $r['assessments'], (int) $r['verified'], $r['internship_company'],
                    $yes($r['placement_submitted_at'] === null ? null : 1), (int) $r['applications'],
                    $time($r['last_sign_in_at'])],
                $rows
            ));
    }

    /** @param array<string, string> $params */
    public static function person(Request $req, array $params, App $app): Response
    {
        $admin = self::admin($req, $app, 'analytics.view');
        $org = $admin['organisation_id'];
        $id = (int) $params['id'];
        $view = (new PersonAnalytics($app->pdo(), $app->now(), self::confirmDays($app, $org)))->of($org, $id);
        Audit::log($app->pdo(), $admin, 'person.analytics_view', "user:$id");

        return Response::json($view);
    }

    private static function service(App $app, int $org): AnalyticsService
    {
        return new AnalyticsService(
            $app->pdo(),
            $app->now(),
            self::confirmDays($app, $org),
            $app->settings($org)->string('display_timezone')
        );
    }

    private static function confirmDays(App $app, int $org): int
    {
        return $app->settings($org)->int('alumni_job_confirm_days');
    }

    private static function filters(Request $req, App $app, int $org): AnalyticsFilters
    {
        $query = (new Validator())->query(ProfileController::SCHEMAS . 'analytics.query.json', $req->query);

        return AnalyticsFilters::fromQuery($org, $query, $app->settings($org)->string('display_timezone'));
    }

    /**
     * @return array{id: int, organisation_id: int, email: string|null, display_name: string, role: string,
     *     active: int, onboarded_at: string|null, provider: string}
     */
    private static function admin(Request $req, App $app, string $permission): array
    {
        $user = $req->user ?? throw new \LogicException('route is not public');
        $app->access()->require($user, $permission);

        return $user;
    }
}

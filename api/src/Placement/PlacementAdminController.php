<?php

declare(strict_types=1);

namespace Cpdc\Placement;

use Cpdc\App;
use Cpdc\Audit\Audit;
use Cpdc\Http\Csv;
use Cpdc\Http\Paginator;
use Cpdc\Http\Request;
use Cpdc\Http\Response;
use Cpdc\Http\Validator;
use Cpdc\Profile\ProfileController;
use Cpdc\Workshops\WorkshopTime;

/**
 * CPDC (placement.manage): every placement with filters, its change history, satisfaction per company and the two
 * CSV exports. Opening a change history and every export are audited.
 */
final class PlacementAdminController
{
    private const RATING = [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very good', 5 => 'Excellent'];

    /** @param array<string, string> $params */
    public static function list(Request $req, array $params, App $app): Response
    {
        $admin = self::admin($req, $app);
        $org = $admin['organisation_id'];
        $query = self::query($req);
        $page = Paginator::fromQuery($query, $app->settings($org));

        return Response::json((new PlacementReport($app->pdo()))->list($org, $query, $page));
    }

    /** @param array<string, string> $params */
    public static function filters(Request $req, array $params, App $app): Response
    {
        $admin = self::admin($req, $app);

        return Response::json((new PlacementReport($app->pdo()))->filters($admin['organisation_id']));
    }

    /** @param array<string, string> $params */
    public static function changes(Request $req, array $params, App $app): Response
    {
        $admin = self::admin($req, $app);
        $id = (int) $params['id'];
        $history = (new PlacementReport($app->pdo()))->changes($admin['organisation_id'], $id);
        Audit::log($app->pdo(), $admin, 'placement.changes_view', "internship:$id");

        return Response::json($history);
    }

    /** @param array<string, string> $params */
    public static function satisfaction(Request $req, array $params, App $app): Response
    {
        $admin = self::admin($req, $app);

        $report = new PlacementReport($app->pdo());

        return Response::json($report->satisfaction($admin['organisation_id'], self::query($req)));
    }

    /** @param array<string, string> $params */
    public static function exportVisits(Request $req, array $params, App $app): Response
    {
        $admin = self::admin($req, $app);
        $org = $admin['organisation_id'];
        $rows = (new PlacementReport($app->pdo()))->visitRows($org, self::query($req));
        Audit::log($app->pdo(), $admin, 'placement.export_visits', 'placements', ['rows' => count($rows)]);
        $zone = $app->settings($org)->string('display_timezone');
        $header = ['Student', 'Matric no', 'Faculty', 'Company', 'Nature of business', 'Address line 1',
            'Address line 2', 'Address line 3', 'Postcode', 'State', 'Country', 'Start date', 'End date', 'Batch',
            'Monthly allowance (RM)', 'Roles and responsibilities', 'HR name', 'HR phone', 'HR email',
            'Industry supervisor', 'Supervisor email', 'Supervisor phone', 'Submitted (Malaysia time)', 'Changes'];

        return Csv::response('industry-visit-' . gmdate('Y-m-d', $app->now()) . '.csv', $header, array_map(
            static fn (array $r): array => [
                $r['display_name'], $r['matric_no'], $r['faculty'], $r['company'], $r['business_nature'],
                $r['company_address_1'], $r['company_address_2'], $r['company_address_3'], $r['company_postcode'],
                $r['company_state'], $r['company_country'], $r['start_date'], $r['end_date'],
                self::batch($r['batch_month'], $r['batch_year']), $r['monthly_allowance_rm'],
                $r['roles_responsibilities'],
                (int) $r['has_hr_contact'] === 1 ? $r['hr_name'] : 'No HR personnel', $r['hr_phone'], $r['hr_email'],
                $r['supervisor_name'], $r['supervisor_email'], $r['supervisor_phone'],
                WorkshopTime::readable(
                    is_string($r['placement_submitted_at']) ? $r['placement_submitted_at'] : null,
                    $zone
                ),
                (int) $r['changes'],
            ],
            $rows
        ));
    }

    /** @param array<string, string> $params */
    public static function exportSatisfaction(Request $req, array $params, App $app): Response
    {
        $admin = self::admin($req, $app);
        $org = $admin['organisation_id'];
        $rows = (new PlacementReport($app->pdo()))->surveyRows($org, self::query($req));
        Audit::log($app->pdo(), $admin, 'placement.export_satisfaction', 'placements', ['rows' => count($rows)]);
        $zone = $app->settings($org)->string('display_timezone');
        $header = ['Student', 'Matric no', 'Faculty', 'Company', 'Nature of business', 'Batch',
            'Would recommend to QIU juniors', 'Overall rating (1-5)', 'Rating', 'Offered a job', 'Feedback',
            'Answered (Malaysia time)'];

        return Csv::response('industry-satisfaction-' . gmdate('Y-m-d', $app->now()) . '.csv', $header, array_map(
            static fn (array $r): array => [
                $r['display_name'], $r['matric_no'], $r['faculty'], $r['company'], $r['business_nature'],
                self::batch($r['batch_month'], $r['batch_year']), (int) $r['recommend_juniors'] === 1 ? 'Yes' : 'No',
                (int) $r['overall_rating'], self::RATING[(int) $r['overall_rating']] ?? '',
                (int) $r['offered_job'] === 1 ? 'Yes' : 'No', $r['feedback'],
                WorkshopTime::readable((string) $r['updated_at'], $zone),
            ],
            $rows
        ));
    }

    /** "Jan 2027", or empty when the student has not given a batch. */
    private static function batch(mixed $month, mixed $year): string
    {
        if ($month === null || $year === null) {
            return '';
        }

        return gmdate('M', (int) gmmktime(0, 0, 0, (int) $month, 1, 2000)) . ' ' . (int) $year;
    }

    /** @return array<mixed> */
    private static function query(Request $req): array
    {
        return (new Validator())->query(ProfileController::SCHEMAS . 'placements.query.json', $req->query);
    }

    /**
     * @return array{id: int, organisation_id: int, email: string|null, display_name: string, role: string,
     *     active: int, onboarded_at: string|null, provider: string}
     */
    private static function admin(Request $req, App $app): array
    {
        $user = $req->user ?? throw new \LogicException('route is not public');
        $app->access()->require($user, 'placement.manage');

        return $user;
    }
}

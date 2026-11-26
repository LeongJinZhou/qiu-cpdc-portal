<?php

declare(strict_types=1);

namespace Cpdc\Survey;

use Cpdc\App;
use Cpdc\Http\Csv;
use Cpdc\Http\Request;
use Cpdc\Http\Response;
use Cpdc\Http\Validator;
use Cpdc\Profile\ProfileController;

/**
 * The yearly University and Programme Survey: students answer (survey.take); CPDC runs rounds and reads results
 * (survey.manage).
 *
 * @phpstan-import-type Body from SurveyService
 */
final class SurveyController
{
    /** @param array<string, string> $params the form and the rounds this student can answer or has answered */
    public static function mine(Request $req, array $params, App $app): Response
    {
        $user = self::user($req, $app, 'survey.take');

        return Response::json([
            'form' => SurveyForm::definition(),
            'cycles' => self::service($app, $user)->forStudent($user, $app->now()),
        ]);
    }

    /** @param array<string, string> $params */
    public static function response(Request $req, array $params, App $app): Response
    {
        $user = self::user($req, $app, 'survey.take');

        return Response::json(self::service($app, $user)->response($user, (int) $params['id'], $app->now()));
    }

    /** @param array<string, string> $params */
    public static function save(Request $req, array $params, App $app): Response
    {
        $user = self::user($req, $app, 'survey.take');
        /** @var Body $body */
        $body = (new Validator())->validate(ProfileController::SCHEMAS . 'survey.response.json', $req->json());
        $saved = self::service($app, $user)->save($user, (int) $params['id'], $body, $app->now());

        return Response::json($saved);
    }

    /** @param array<string, string> $params */
    public static function manageList(Request $req, array $params, App $app): Response
    {
        $admin = self::user($req, $app, 'survey.manage');

        return Response::json(['items' => self::service($app, $admin)->manageList(
            $admin['organisation_id'],
            $app->now()
        )]);
    }

    /** @param array<string, string> $params */
    public static function create(Request $req, array $params, App $app): Response
    {
        $admin = self::user($req, $app, 'survey.manage');
        /** @var array{title: string, academic_year: string, opens_at?: string|null, closes_at?: string|null} $body */
        $body = (new Validator())->validate(ProfileController::SCHEMAS . 'survey.cycle.json', $req->json());
        $service = self::service($app, $admin);
        $id = $service->createCycle($admin, $body);

        return Response::json($service->report($admin['organisation_id'], $id, $app->now())['cycle'], 201);
    }

    /** @param array<string, string> $params */
    public static function status(Request $req, array $params, App $app): Response
    {
        $admin = self::user($req, $app, 'survey.manage');
        $body = (new Validator())->validate(ProfileController::SCHEMAS . 'survey.status.json', $req->json());
        $service = self::service($app, $admin);
        $service->setStatus($admin, (int) $params['id'], $body['status'] === 'open' ? 'open' : 'closed');

        return Response::json($service->report($admin['organisation_id'], (int) $params['id'], $app->now())['cycle']);
    }

    /** @param array<string, string> $params */
    public static function report(Request $req, array $params, App $app): Response
    {
        $admin = self::user($req, $app, 'survey.manage');

        return Response::json(self::service($app, $admin)->report(
            $admin['organisation_id'],
            (int) $params['id'],
            $app->now()
        ));
    }

    /** @param array<string, string> $params */
    public static function comments(Request $req, array $params, App $app): Response
    {
        $admin = self::user($req, $app, 'survey.manage');
        $query = (new Validator())->query(ProfileController::SCHEMAS . 'survey-comments.query.json', $req->query);
        $items = self::service($app, $admin)->comments($admin, (int) $params['id'], (string) $query['section']);

        return Response::json(['items' => $items]);
    }

    /** @param array<string, string> $params */
    public static function export(Request $req, array $params, App $app): Response
    {
        $admin = self::user($req, $app, 'survey.manage');
        $csv = self::service($app, $admin)->export($admin, (int) $params['id']);

        return Csv::response($csv['filename'], $csv['header'], $csv['rows']);
    }

    /** @param array{id: int, organisation_id: int, ...} $user */
    private static function service(App $app, array $user): SurveyService
    {
        return new SurveyService($app->pdo(), $app->settings($user['organisation_id'])->string('display_timezone'));
    }

    /**
     * @return array{id: int, organisation_id: int, email: string|null, display_name: string, role: string,
     *     active: int, onboarded_at: string|null, provider: string}
     */
    private static function user(Request $req, App $app, string $permission): array
    {
        $user = $req->user ?? throw new \LogicException('route is not public');
        $app->access()->require($user, $permission);

        return $user;
    }
}

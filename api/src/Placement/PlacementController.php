<?php

declare(strict_types=1);

namespace Cpdc\Placement;

use Cpdc\App;
use Cpdc\Http\Request;
use Cpdc\Http\Response;
use Cpdc\Http\Validator;
use Cpdc\Profile\ProfileController;

/** The student's own placement form and satisfaction survey (logbook.own). */
final class PlacementController
{
    /** @param array<string, string> $params */
    public static function mine(Request $req, array $params, App $app): Response
    {
        $user = self::student($req, $app);

        return Response::json((new PlacementService($app->pdo(), $app->now()))
            ->mine($user['organisation_id'], $user['id']));
    }

    /** @param array<string, string> $params */
    public static function save(Request $req, array $params, App $app): Response
    {
        $user = self::student($req, $app);
        $body = (new Validator())->validate(ProfileController::SCHEMAS . 'placement.save.json', $req->json());

        return Response::json((new PlacementService($app->pdo(), $app->now()))->save($user, $body));
    }

    /** @param array<string, string> $params */
    public static function saveSurvey(Request $req, array $params, App $app): Response
    {
        $user = self::student($req, $app);
        $body = (new Validator())->validate(ProfileController::SCHEMAS . 'placement.satisfaction.json', $req->json());

        return Response::json((new PlacementService($app->pdo(), $app->now()))->saveSurvey($user, $body));
    }

    /**
     * @return array{id: int, organisation_id: int, email: string|null, display_name: string, role: string,
     *     active: int, onboarded_at: string|null, provider: string}
     */
    private static function student(Request $req, App $app): array
    {
        $user = $req->user ?? throw new \LogicException('route is not public');
        $app->access()->require($user, 'logbook.own');

        return $user;
    }
}

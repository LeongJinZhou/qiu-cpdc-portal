<?php

declare(strict_types=1);

namespace Cpdc\Home;

use Cpdc\App;
use Cpdc\Http\Request;
use Cpdc\Http\Response;
use Cpdc\Logbook\Weeks;
use Cpdc\Placement\PlacementService;

/** GET /api/home: the signed-in person's overview (home.view). */
final class HomeController
{
    /** @param array<string, string> $params */
    public static function show(Request $req, array $params, App $app): Response
    {
        $user = $req->user ?? throw new \LogicException('route is not public');
        $app->access()->require($user, 'home.view');
        $settings = $app->settings($user['organisation_id']);
        $placement = new PlacementService($app->pdo(), $app->now());
        $today = Weeks::today($app->now(), $settings->string('display_timezone'));
        $service = new HomeService(
            $app->pdo(),
            $app->access(),
            $settings,
            static fn (array $u): ?bool => $placement->satisfactionDue($u['organisation_id'], $u['id'], $today)
        );

        return Response::json($service->overview($user, $app->now()));
    }
}

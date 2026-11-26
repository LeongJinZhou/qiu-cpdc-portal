<?php

declare(strict_types=1);

namespace Cpdc\Home;

use Cpdc\App;
use Cpdc\Http\Request;
use Cpdc\Http\Response;

/** GET /api/home: the signed-in person's overview (home.view). */
final class HomeController
{
    /** @param array<string, string> $params */
    public static function show(Request $req, array $params, App $app): Response
    {
        $user = $req->user ?? throw new \LogicException('route is not public');
        $app->access()->require($user, 'home.view');
        $service = new HomeService($app->pdo(), $app->access(), $app->settings($user['organisation_id']));

        return Response::json($service->overview($user, $app->now()));
    }
}

<?php

declare(strict_types=1);

namespace Cpdc\Jobs;

use Cpdc\Http\Router;

/** Routes slice A adds to jobs: the filter choices and a job's PDF brochure. */
final class JobsUpgradeRoutes
{
    public static function add(Router $router): Router
    {
        $upload = ['name' => 'pdf-upload', 'setting' => 'rate_upload_per_user_per_min', 'by' => 'user'];

        return $router
            ->add('GET', '/api/jobs/filters', JobsController::filters(...))
            ->add('GET', '/api/jobs/{id}/brochure', BrochureController::download(...))
            ->add('POST', '/api/jobs/manage/{id}/brochure', BrochureController::upload(...), rate: $upload)
            ->add('DELETE', '/api/jobs/manage/{id}/brochure', BrochureController::remove(...));
    }
}

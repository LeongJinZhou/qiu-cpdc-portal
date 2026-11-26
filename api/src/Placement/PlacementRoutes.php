<?php

declare(strict_types=1);

namespace Cpdc\Placement;

use Cpdc\Http\Router;

/** Routes of slice A's placement part: the student's form and survey, CPDC's list, history and exports. */
final class PlacementRoutes
{
    public static function add(Router $router): Router
    {
        $export = ['name' => 'export', 'setting' => 'rate_export_per_user_per_min', 'by' => 'user'];

        return $router
            ->add('GET', '/api/placement', PlacementController::mine(...))
            ->add('PUT', '/api/placement', PlacementController::save(...))
            ->add('PUT', '/api/placement/satisfaction', PlacementController::saveSurvey(...))
            ->add('GET', '/api/placements', PlacementAdminController::list(...))
            ->add('GET', '/api/placements/filters', PlacementAdminController::filters(...))
            ->add('GET', '/api/placements/satisfaction', PlacementAdminController::satisfaction(...))
            ->add('GET', '/api/placements/export/visits', PlacementAdminController::exportVisits(...), rate: $export)
            ->add(
                'GET',
                '/api/placements/export/satisfaction',
                PlacementAdminController::exportSatisfaction(...),
                rate: $export
            )
            ->add('GET', '/api/placements/{id}/changes', PlacementAdminController::changes(...));
    }
}

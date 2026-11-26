<?php

declare(strict_types=1);

namespace Cpdc\Survey;

use Cpdc\Assessment\AssessmentController;
use Cpdc\Home\HomeController;
use Cpdc\Http\Router;

/** Routes of slice B: home overview, yearly survey, self-assessment reports and insights. Added by App::routes. */
final class SliceBRoutes
{
    public static function add(Router $router): Router
    {
        $export = ['name' => 'export', 'setting' => 'rate_export_per_user_per_min', 'by' => 'user'];

        return $router
            ->add('GET', '/api/home', HomeController::show(...))
            ->add('GET', '/api/surveys', SurveyController::mine(...))
            ->add('GET', '/api/surveys/manage', SurveyController::manageList(...))
            ->add('POST', '/api/surveys/manage', SurveyController::create(...))
            ->add('GET', '/api/surveys/manage/{id}', SurveyController::report(...))
            ->add('PATCH', '/api/surveys/manage/{id}/status', SurveyController::status(...))
            ->add('GET', '/api/surveys/manage/{id}/comments', SurveyController::comments(...))
            ->add('GET', '/api/surveys/manage/{id}/export', SurveyController::export(...), rate: $export)
            ->add('GET', '/api/surveys/{id}/response', SurveyController::response(...))
            ->add('PUT', '/api/surveys/{id}/response', SurveyController::save(...))
            ->add('GET', '/api/assessments/attempts/{id}', AssessmentController::report(...))
            ->add('GET', '/api/assessments/manage/{id}/traits', AssessmentController::traits(...))
            ->add('GET', '/api/assessments/manage/{id}/people', AssessmentController::people(...))
            ->add('GET', '/api/assessments/manage/attempts/{id}', AssessmentController::reflections(...));
    }
}

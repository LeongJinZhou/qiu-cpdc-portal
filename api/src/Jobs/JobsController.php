<?php

declare(strict_types=1);

namespace Cpdc\Jobs;

use Cpdc\App;
use Cpdc\Http\ApiError;
use Cpdc\Http\Paginator;
use Cpdc\Http\Request;
use Cpdc\Http\Response;
use Cpdc\Http\Validator;
use Cpdc\Profile\ProfileController;

/** Students and alumni (jobs.browse): open jobs, applying with a note and an optional CV, own applications. */
final class JobsController
{
    /** @param array<string, string> $params */
    public static function list(Request $req, array $params, App $app): Response
    {
        $user = self::user($req, $app);
        $query = (new Validator())->query(ProfileController::SCHEMAS . 'jobs.query.json', $req->query);
        $page = Paginator::fromQuery($query, $app->settings($user['organisation_id']));
        /** @var array{q?: string, type?: string, location?: string, state?: string, specialization?: string} $filters */
        $filters = array_intersect_key(
            $query,
            ['q' => 1, 'type' => 1, 'location' => 1, 'state' => 1, 'specialization' => 1]
        );

        return Response::json((new JobService($app->pdo()))
            ->listOpen($user['organisation_id'], $user['id'], self::today($app, $user), $filters, $page));
    }

    /**
     * The states and specializations open jobs name, for the filter bar.
     *
     * @param array<string, string> $params
     */
    public static function filters(Request $req, array $params, App $app): Response
    {
        $user = self::user($req, $app);

        return Response::json((new JobService($app->pdo()))
            ->filters($user['organisation_id'], self::today($app, $user)));
    }

    /** @param array<string, string> $params */
    public static function show(Request $req, array $params, App $app): Response
    {
        $user = self::user($req, $app);

        return Response::json((new JobService($app->pdo()))
            ->show($user['organisation_id'], $user['id'], (int) $params['id'], self::today($app, $user)));
    }

    /**
     * Multipart: text field "note", optional PDF file field "cv". The CV is checked before anything is saved and
     * removed again when the application is refused.
     *
     * @param array<string, string> $params
     */
    public static function apply(Request $req, array $params, App $app): Response
    {
        $user = self::user($req, $app);
        $form = (new Validator())->validate(ProfileController::SCHEMAS . 'job.apply.json', (object) $req->form);
        $note = trim((string) ($form['note'] ?? ''));
        $service = new JobService($app->pdo());
        $jobId = (int) $params['id'];
        $today = self::today($app, $user);
        $store = new PdfStore($app->storageDir, PdfStore::CV);
        $file = $req->file('cv');
        $cv = $file === null
            ? null
            : $store->keep($file, $app->settings($user['organisation_id'])->int('upload_pdf_max_bytes'), 'cv');
        try {
            $service->apply($user, $jobId, $today, $note === '' ? null : $note, $cv);
        } catch (\Throwable $e) {
            $store->delete($cv['key'] ?? null);
            throw $e;
        }

        // The job as GET /api/jobs/{id} shows it, now with my_application.
        return Response::json($service->show($user['organisation_id'], $user['id'], $jobId, $today), 201);
    }

    /** @param array<string, string> $params */
    public static function mine(Request $req, array $params, App $app): Response
    {
        $user = self::user($req, $app);

        return Response::json(['items' => (new JobService($app->pdo()))->mine($user['organisation_id'], $user['id'])]);
    }

    /** @param array<string, string> $params */
    public static function withdraw(Request $req, array $params, App $app): Response
    {
        $user = self::user($req, $app);
        (new JobService($app->pdo()))->withdraw($user, (int) $params['id']);

        return new Response(204);
    }

    /**
     * The CV of an application: the applicant's own, or any for CPDC (jobs.manage, audited).
     *
     * @param array<string, string> $params
     */
    public static function cv(Request $req, array $params, App $app): Response
    {
        $user = $req->user ?? throw new \LogicException('route is not public');
        $id = (int) $params['id'];
        if ($app->access()->can($user, 'jobs.manage')) {
            return ManageController::cv($user, $id, $app);
        }
        $app->access()->require($user, 'jobs.browse');
        $cv = (new JobService($app->pdo()))->ownCv($user['organisation_id'], $user['id'], $id);
        if ($cv['cv_file'] === null) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }

        return (new PdfStore($app->storageDir, PdfStore::CV))->download($cv['cv_file'], (string) $cv['cv_name']);
    }

    /** @param array{organisation_id: int, ...} $user */
    public static function today(App $app, array $user): string
    {
        return LocalDate::today($app->now(), $app->settings($user['organisation_id'])->string('display_timezone'));
    }

    /**
     * @return array{id: int, organisation_id: int, email: string|null, display_name: string, role: string,
     *     active: int, onboarded_at: string|null, provider: string}
     */
    private static function user(Request $req, App $app): array
    {
        $user = $req->user ?? throw new \LogicException('route is not public');
        $app->access()->require($user, 'jobs.browse');

        return $user;
    }
}

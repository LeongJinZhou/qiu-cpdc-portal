<?php

declare(strict_types=1);

namespace Cpdc\Jobs;

use Cpdc\App;
use Cpdc\Http\ApiError;
use Cpdc\Http\Request;
use Cpdc\Http\Response;

/**
 * A job's PDF brochure: CPDC (jobs.manage) uploads or removes it; anyone who may see the job downloads it. Served
 * only through the API, as an attachment, from outside the web root.
 */
final class BrochureController
{
    /**
     * Multipart, one PDF in field "file". Checked by content (%PDF-) and size before anything is recorded.
     *
     * @param array<string, string> $params
     */
    public static function upload(Request $req, array $params, App $app): Response
    {
        $admin = self::admin($req, $app);
        $file = $req->file('file') ?? throw ApiError::validation('file', 'Choose a PDF file.');
        $store = new PdfStore($app->storageDir, PdfStore::BROCHURE);
        $kept = $store->keep($file, $app->settings($admin['organisation_id'])->int('upload_pdf_max_bytes'), 'file');
        try {
            $old = (new Brochures($app->pdo()))->attach($admin, (int) $params['id'], $kept);
        } catch (\Throwable $e) {
            $store->delete($kept['key']);
            throw $e;
        }
        $store->delete($old);

        return Response::json(['attachment_name' => $kept['name'], 'has_brochure' => true]);
    }

    /** @param array<string, string> $params */
    public static function remove(Request $req, array $params, App $app): Response
    {
        $admin = self::admin($req, $app);
        $old = (new Brochures($app->pdo()))->detach($admin, (int) $params['id']);
        (new PdfStore($app->storageDir, PdfStore::BROCHURE))->delete($old);

        return new Response(204);
    }

    /**
     * The brochure as a download: any job's for CPDC, a visible job's for students and graduates.
     *
     * @param array<string, string> $params
     */
    public static function download(Request $req, array $params, App $app): Response
    {
        $user = $req->user ?? throw new \LogicException('route is not public');
        $org = $user['organisation_id'];
        $id = (int) $params['id'];
        $brochures = new Brochures($app->pdo());
        if ($app->access()->can($user, 'jobs.manage')) {
            $file = $brochures->forManager($org, $id);
        } else {
            $app->access()->require($user, 'jobs.browse');
            $file = $brochures->forViewer($org, $user['id'], $id, JobsController::today($app, $user));
        }

        return (new PdfStore($app->storageDir, PdfStore::BROCHURE))->download($file['key'], $file['name']);
    }

    /**
     * @return array{id: int, organisation_id: int, email: string|null, display_name: string, role: string,
     *     active: int, onboarded_at: string|null, provider: string}
     */
    private static function admin(Request $req, App $app): array
    {
        $user = $req->user ?? throw new \LogicException('route is not public');
        $app->access()->require($user, 'jobs.manage');

        return $user;
    }
}

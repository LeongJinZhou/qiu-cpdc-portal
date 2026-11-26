<?php

declare(strict_types=1);

namespace Cpdc\Jobs;

use Cpdc\Http\ApiError;
use Cpdc\Http\Response;
use Cpdc\Http\UploadedFile;

/**
 * Uploaded PDFs that are kept (application CVs, learning proofs, job brochures), under STORAGE_DIR/uploads/<kind>/,
 * outside the web
 * root. A file is stored under a random 32-hex key; the client's file name is display-only. Only PDFs (by content,
 * not by name or type header) up to setting upload_pdf_max_bytes.
 */
final class PdfStore
{
    public const CV = 'cv';
    public const LEARNING = 'learning';
    public const BROCHURE = 'job'; // a job's PDF brochure (slice A)
    private const KEY = '/^[0-9a-f]{32}$/D';

    public function __construct(private readonly string $storageDir, private readonly string $kind)
    {
        if (!in_array($kind, [self::CV, self::LEARNING, self::BROCHURE], true)) {
            throw new \LogicException('unknown upload kind');
        }
    }

    /**
     * Checks the upload and keeps it. The temporary upload is removed either way.
     *
     * @return array{key: string, name: string, size: int}
     */
    public function keep(UploadedFile $file, int $maxBytes, string $field): array
    {
        try {
            self::check($file, $maxBytes, $field);
            $key = bin2hex(random_bytes(16));
            $dir = $this->dir();
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('cannot create upload folder');
            }
            if (!copy($file->path, $this->path($key))) {
                throw new \RuntimeException('cannot store upload');
            }
        } finally {
            if ($file->path !== '') {
                @unlink($file->path); // the temporary upload, never served
            }
        }

        return ['key' => $key, 'name' => self::displayName($file->name), 'size' => $file->size];
    }

    /** Throws the person-facing error when the upload is not a PDF within the size limit. */
    public static function check(UploadedFile $file, int $maxBytes, string $field): void
    {
        $phpRefused = in_array($file->error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        if ($phpRefused || $file->size > $maxBytes) {
            $mb = max(1, intdiv($maxBytes, 1048576));
            throw new ApiError(413, 'FILE_TOO_LARGE', "The PDF must be smaller than $mb MB.");
        }
        if ($file->error !== UPLOAD_ERR_OK || $file->size === 0 || $file->path === '') {
            throw ApiError::validation($field, 'The file could not be uploaded. Please try again.');
        }
        $head = (string) file_get_contents($file->path, false, null, 0, 5);
        if ($head !== '%PDF-') {
            throw ApiError::validation($field, 'Choose a PDF file.');
        }
    }

    /** The PDF as a download. Attachment only: a PDF is never shown inside the portal's own origin. */
    public function download(string $key, string $name): Response
    {
        $path = preg_match(self::KEY, $key) === 1 ? $this->path($key) : '';
        $bytes = $path !== '' && is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '';
        $ascii = trim($ascii, '-.') === '' ? 'document.pdf' : $ascii;
        if (!str_ends_with(strtolower($ascii), '.pdf')) {
            $ascii .= '.pdf';
        }

        return new Response(200, $bytes, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $ascii . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function delete(?string $key): void
    {
        if ($key !== null && preg_match(self::KEY, $key) === 1) {
            @unlink($this->path($key)); // already gone is fine
        }
    }

    /** The client's file name, cleaned for display: no path, no control characters, at most 200 characters. */
    public static function displayName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $base));

        return $clean === '' ? 'document.pdf' : mb_substr($clean, 0, 200);
    }

    private function dir(): string
    {
        return $this->storageDir . '/uploads/' . $this->kind;
    }

    private function path(string $key): string
    {
        return $this->dir() . '/' . $key . '.pdf';
    }
}

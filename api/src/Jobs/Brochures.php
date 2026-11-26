<?php

declare(strict_types=1);

namespace Cpdc\Jobs;

use Cpdc\Audit\Audit;
use Cpdc\Db\Transaction;
use Cpdc\Http\ApiError;
use PDO;

/**
 * A job's optional PDF brochure: CPDC attaches, replaces or removes it (audited); a student or graduate downloads it
 * only while they may see the job (open, or they applied). The file itself lives in PdfStore (kind "job").
 *
 * @phpstan-type User array{id: int, organisation_id: int, ...}
 */
final class Brochures
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Records a stored PDF as the job's brochure.
     *
     * @param User $actor
     * @param array{key: string, name: string, size: int} $file
     * @return string|null the replaced file's key, for the caller to delete after the commit
     */
    public function attach(array $actor, int $jobId, array $file): ?string
    {
        return Transaction::run($this->pdo, function () use ($actor, $jobId, $file): ?string {
            $old = $this->lockedKey($actor['organisation_id'], $jobId);
            $this->pdo->prepare(
                'UPDATE job_posting SET attachment_file = ?, attachment_name = ?, updated_at = UTC_TIMESTAMP(3)'
                . ' WHERE id = ? AND organisation_id = ?'
            )->execute([$file['key'], mb_substr($file['name'], 0, 255), $jobId, $actor['organisation_id']]);
            Audit::log($this->pdo, $actor, 'job.brochure_attach', 'job:' . $jobId, ['bytes' => $file['size']]);

            return $old;
        });
    }

    /**
     * @param User $actor
     * @return string|null the removed file's key
     */
    public function detach(array $actor, int $jobId): ?string
    {
        return Transaction::run($this->pdo, function () use ($actor, $jobId): ?string {
            $old = $this->lockedKey($actor['organisation_id'], $jobId);
            if ($old === null) {
                return null;
            }
            $this->pdo->prepare(
                'UPDATE job_posting SET attachment_file = NULL, attachment_name = NULL, updated_at = UTC_TIMESTAMP(3)'
                . ' WHERE id = ? AND organisation_id = ?'
            )->execute([$jobId, $actor['organisation_id']]);
            Audit::log($this->pdo, $actor, 'job.brochure_remove', 'job:' . $jobId);

            return $old;
        });
    }

    /**
     * The brochure of a job a student or graduate may see: open, or one they applied to.
     *
     * @return array{key: string, name: string}
     */
    public function forViewer(int $org, int $userId, int $jobId, string $today): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT j.status, j.closing_date, e.active, j.attachment_file, j.attachment_name, a.id AS application_id'
            . ' FROM job_posting j JOIN employer e ON e.id = j.employer_id'
            . ' LEFT JOIN job_application a ON a.job_id = j.id AND a.user_id = ?'
            . ' WHERE j.id = ? AND j.organisation_id = ?'
        );
        $stmt->execute([$userId, $jobId, $org]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $visible = is_array($row) && ($row['application_id'] !== null || JobService::isOpen($row, $today));

        return $visible ? self::file($row) : throw new ApiError(404, 'NOT_FOUND', 'Not found.');
    }

    /** @return array{key: string, name: string} any job's brochure, for CPDC */
    public function forManager(int $org, int $jobId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT attachment_file, attachment_name FROM job_posting WHERE id = ? AND organisation_id = ?'
        );
        $stmt->execute([$jobId, $org]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return self::file(is_array($row) ? $row : []);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{key: string, name: string}
     */
    private static function file(array $row): array
    {
        $key = $row['attachment_file'] ?? null;
        if (!is_string($key)) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }

        return ['key' => $key, 'name' => (string) ($row['attachment_name'] ?? 'brochure.pdf')];
    }

    private function lockedKey(int $org, int $jobId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT attachment_file FROM job_posting WHERE id = ? AND organisation_id = ? FOR UPDATE'
        );
        $stmt->execute([$jobId, $org]);
        $key = $stmt->fetch(PDO::FETCH_COLUMN);
        if ($key === false) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }

        return is_string($key) ? $key : null;
    }
}

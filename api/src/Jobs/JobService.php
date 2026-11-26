<?php

declare(strict_types=1);

namespace Cpdc\Jobs;

use Cpdc\Db\Transaction;
use Cpdc\Http\ApiError;
use Cpdc\Http\Paginator;
use PDO;

/**
 * Students and alumni: open jobs, applying, and their own applications only. A job is open when its status is
 * "open" and its closing date (in the organisation's time zone) is today or later.
 *
 * @phpstan-type User array{id: int, organisation_id: int, role: string, ...}
 */
final class JobService
{
    private const JOB_COLUMNS = 'j.id, j.title, j.job_type, j.location, j.closing_date, j.status, j.employer_id,'
        . ' e.name AS employer_name, j.state, j.specializations, j.salary_min, j.salary_max, j.pay_frequency';
    private const OPEN = "j.organisation_id = ? AND j.status = 'open' AND j.closing_date >= ? AND e.active = 1";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{q?: string, type?: string, location?: string, state?: string, specialization?: string} $filters
     * @return array{items: list<mixed>, page: int, per_page: int, total: int}
     */
    public function listOpen(int $org, int $userId, string $today, array $filters, Paginator $page): array
    {
        $where = self::OPEN;
        $args = [$org, $today];
        if (($filters['q'] ?? '') !== '') {
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $where .= ' AND (j.title LIKE ? OR e.name LIKE ? OR j.description LIKE ? OR j.location LIKE ?)';
            array_push($args, $like, $like, $like, $like);
        }
        if (isset($filters['type'])) {
            $where .= ' AND j.job_type = ?';
            $args[] = $filters['type'];
        }
        if (($filters['location'] ?? '') !== '') {
            $where .= ' AND j.location LIKE ?';
            $args[] = '%' . addcslashes($filters['location'], '%_\\') . '%';
        }
        if (($filters['state'] ?? '') !== '') {
            $where .= ' AND j.state = ?';
            $args[] = $filters['state'];
        }
        if (($filters['specialization'] ?? '') !== '') {
            // One whole item of the comma-separated list, any case (the column's collation).
            $where .= " AND CONCAT(', ', j.specializations, ',') LIKE ?";
            $args[] = '%, ' . addcslashes(trim($filters['specialization']), '%_\\') . ',%';
        }
        $from = ' FROM job_posting j JOIN employer e ON e.id = j.employer_id';
        $count = $this->pdo->prepare("SELECT COUNT(*) $from WHERE $where");
        $count->execute($args);
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::JOB_COLUMNS . ', a.status AS my_status' . $from
            . ' LEFT JOIN job_application a ON a.job_id = j.id AND a.user_id = ?'
            . " WHERE $where ORDER BY j.closing_date, j.id LIMIT " . $page->perPage . ' OFFSET ' . $page->offset()
        );
        $stmt->execute([$userId, ...$args]);
        $items = array_values(array_map(self::job(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));

        return $page->envelope($items, (int) $count->fetchColumn());
    }

    /**
     * One job with its employer, the skills it asks for and the person's own application. A closed job stays
     * visible to someone who applied to it.
     *
     * @return array<string, mixed>
     */
    public function show(int $org, int $userId, int $jobId, string $today): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::JOB_COLUMNS . ', j.description, j.' . implode(', j.', JobDetails::COLUMNS) . ','
            . ' j.attachment_file, j.attachment_name, e.industry AS employer_industry,'
            . ' e.website AS employer_website, e.location AS employer_location, e.about AS employer_about, e.active,'
            . ' a.id AS application_id'
            . ' FROM job_posting j JOIN employer e ON e.id = j.employer_id'
            . ' LEFT JOIN job_application a ON a.job_id = j.id AND a.user_id = ?'
            . ' WHERE j.id = ? AND j.organisation_id = ?'
        );
        $stmt->execute([$userId, $jobId, $org]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || ($row['application_id'] === null && !self::isOpen($row, $today))) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }
        $job = JobDetails::shape($row) + self::job($row);
        $job['open'] = self::isOpen($row, $today);
        $job['description'] = (string) $row['description'];
        $job['employer'] = [
            'id' => (int) $row['employer_id'],
            'name' => (string) $row['employer_name'],
            'industry' => $row['employer_industry'],
            'website' => $row['employer_website'],
            'location' => $row['employer_location'],
            'about' => $row['employer_about'],
        ];
        $job['skills'] = (new SkillRequirements($this->pdo))->forJob($jobId);
        $job['my_application'] = $row['application_id'] === null
            ? null : $this->ownApplication($org, $userId, (int) $row['application_id']);
        unset($job['my_status']);

        return $job;
    }

    /**
     * @param User $user
     * @param array{key: string, name: string, size: int}|null $cv an already stored PDF; the caller deletes it if
     *     this throws
     */
    public function apply(array $user, int $jobId, string $today, ?string $note, ?array $cv): int
    {
        return Transaction::run($this->pdo, function () use ($user, $jobId, $today, $note, $cv): int {
            $stmt = $this->pdo->prepare(
                'SELECT j.status, j.closing_date, e.active FROM job_posting j JOIN employer e ON e.id = j.employer_id'
                // LOCK IN SHARE MODE, not FOR SHARE: MariaDB (Exabytes) knows only this spelling, MySQL 8 both.
                . ' WHERE j.id = ? AND j.organisation_id = ? LOCK IN SHARE MODE'
            );
            $stmt->execute([$jobId, $user['organisation_id']]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                throw new ApiError(404, 'NOT_FOUND', 'Not found.');
            }
            if (!self::isOpen($job, $today)) {
                throw new ApiError(409, 'JOB_CLOSED', 'This job no longer takes applications.');
            }
            try {
                $this->pdo->prepare(
                    'INSERT INTO job_application (organisation_id, job_id, user_id, note, cv_file, cv_name, cv_size)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $user['organisation_id'], $jobId, $user['id'], $note, $cv['key'] ?? null, $cv['name'] ?? null,
                    $cv['size'] ?? null,
                ]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) { // duplicate key only, not other constraint errors
                    throw new ApiError(409, 'ALREADY_APPLIED', 'You have already applied for this job.');
                }
                throw $e;
            }

            return (int) $this->pdo->lastInsertId();
        });
    }

    /**
     * @param int|null $onlyId one application instead of all
     * @return list<array<string, mixed>> the person's own applications, newest first
     */
    public function mine(int $org, int $userId, ?int $onlyId = null): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.job_id, a.status, a.status_changed_at, a.created_at, a.note, a.cv_name,'
            . ' j.title, j.job_type, j.closing_date, e.name AS employer_name'
            . ' FROM job_application a JOIN job_posting j ON j.id = a.job_id JOIN employer e ON e.id = j.employer_id'
            . ' WHERE a.user_id = ? AND a.organisation_id = ? AND (? IS NULL OR a.id = ?)'
            . ' ORDER BY a.created_at DESC, a.id DESC LIMIT 500'
        );
        $stmt->execute([$userId, $org, $onlyId, $onlyId]);

        return array_values(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'job_id' => (int) $r['job_id'],
            'title' => (string) $r['title'],
            'employer_name' => (string) $r['employer_name'],
            'job_type' => (string) $r['job_type'],
            'closing_date' => (string) $r['closing_date'],
            'status' => (string) $r['status'],
            'status_changed_at' => (string) $r['status_changed_at'],
            'created_at' => (string) $r['created_at'],
            'note' => $r['note'],
            'cv_name' => $r['cv_name'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @param User $user */
    public function withdraw(array $user, int $applicationId): void
    {
        Transaction::run($this->pdo, function () use ($user, $applicationId): void {
            $stmt = $this->pdo->prepare(
                'SELECT status FROM job_application WHERE id = ? AND user_id = ? AND organisation_id = ? FOR UPDATE'
            );
            $stmt->execute([$applicationId, $user['id'], $user['organisation_id']]);
            $status = $stmt->fetchColumn();
            if ($status === false) {
                throw new ApiError(404, 'NOT_FOUND', 'Not found.');
            }
            if ($status === 'withdrawn') {
                return; // already done: repeatable
            }
            if (!ApplicationStatus::applicantMayWithdraw((string) $status)) {
                throw new ApiError(409, 'ALREADY_DECIDED', 'CPDC has already decided on this application.');
            }
            $this->pdo->prepare(
                "UPDATE job_application SET status = 'withdrawn', status_changed_at = UTC_TIMESTAMP(3),"
                . ' updated_at = UTC_TIMESTAMP(3) WHERE id = ?'
            )->execute([$applicationId]);
        });
    }

    /** @return array{cv_file: string|null, cv_name: string|null} the person's own application's CV */
    public function ownCv(int $org, int $userId, int $applicationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cv_file, cv_name FROM job_application WHERE id = ? AND user_id = ? AND organisation_id = ?'
        );
        $stmt->execute([$applicationId, $userId, $org]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }

        return ['cv_file' => $row['cv_file'], 'cv_name' => $row['cv_name']];
    }

    /** @param array<string, mixed> $job a row with status, closing_date and the employer's active flag */
    public static function isOpen(array $job, string $today): bool
    {
        return $job['status'] === 'open' && (string) $job['closing_date'] >= $today && (int) $job['active'] === 1;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function job(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'employer_id' => (int) $r['employer_id'],
            'employer_name' => (string) $r['employer_name'],
            'job_type' => (string) $r['job_type'],
            'location' => (string) $r['location'],
            'closing_date' => (string) $r['closing_date'],
            'state' => $r['state'],
            'specializations' => JobDetails::split((string) ($r['specializations'] ?? '')),
            'salary_min' => $r['salary_min'] === null ? null : (float) $r['salary_min'],
            'salary_max' => $r['salary_max'] === null ? null : (float) $r['salary_max'],
            'pay_frequency' => $r['pay_frequency'],
            'my_status' => $r['my_status'] ?? null,
        ];
    }

    /**
     * The filter choices for open jobs: the states and specializations they name.
     *
     * @return array{states: list<string>, specializations: list<string>}
     */
    public function filters(int $org, string $today): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT j.state, j.specializations FROM job_posting j JOIN employer e ON e.id = j.employer_id WHERE '
            . self::OPEN . ' LIMIT 2000'
        );
        $stmt->execute([$org, $today]);
        $states = [];
        $specializations = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (is_string($r['state']) && $r['state'] !== '') {
                $states[$r['state']] = true;
            }
            foreach (JobDetails::split((string) ($r['specializations'] ?? '')) as $s) {
                $specializations[mb_strtolower($s)] ??= $s;
            }
        }
        $states = array_map('strval', array_keys($states));
        sort($states, SORT_NATURAL | SORT_FLAG_CASE);
        $specializations = array_values($specializations);
        sort($specializations, SORT_NATURAL | SORT_FLAG_CASE);

        return ['states' => $states, 'specializations' => $specializations];
    }

    /** @return array<string, mixed> */
    private function ownApplication(int $org, int $userId, int $id): array
    {
        return $this->mine($org, $userId, $id)[0] ?? throw new \LogicException('application vanished');
    }
}

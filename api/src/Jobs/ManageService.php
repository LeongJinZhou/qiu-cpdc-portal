<?php

declare(strict_types=1);

namespace Cpdc\Jobs;

use Cpdc\Audit\Audit;
use Cpdc\Db\Transaction;
use Cpdc\Http\ApiError;
use Cpdc\Http\Paginator;
use PDO;

/**
 * CPDC: the employer directory, jobs and every application. Every change is in the audit log.
 *
 * @phpstan-type User array{id: int, organisation_id: int, role: string, ...}
 */
final class ManageService
{
    private const EMPLOYER_TEXT = ['industry' => 200, 'website' => 300, 'location' => 200, 'about' => 3000];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{items: list<mixed>, page: int, per_page: int, total: int} */
    public function employers(int $org, string $q, Paginator $page): array
    {
        $where = 'e.organisation_id = ?';
        $args = [$org];
        if ($q !== '') {
            $where .= ' AND (e.name LIKE ? OR e.industry LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($args, $like, $like);
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM employer e WHERE $where");
        $count->execute($args);
        $stmt = $this->pdo->prepare(
            'SELECT e.id, e.name, e.industry, e.website, e.location, e.about, e.active,'
            . ' (SELECT COUNT(*) FROM job_posting j WHERE j.employer_id = e.id) AS jobs'
            . " FROM employer e WHERE $where ORDER BY e.active DESC, e.name, e.id"
            . ' LIMIT ' . $page->perPage . ' OFFSET ' . $page->offset()
        );
        $stmt->execute($args);
        $items = array_values(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'industry' => $r['industry'],
            'website' => $r['website'],
            'location' => $r['location'],
            'about' => $r['about'],
            'active' => (bool) $r['active'],
            'jobs' => (int) $r['jobs'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));

        return $page->envelope($items, (int) $count->fetchColumn());
    }

    /**
     * Creates ($id null) or updates an employer.
     *
     * @param User $actor
     * @param array<string, mixed> $data validated by employer.save.json
     */
    public function saveEmployer(array $actor, ?int $id, array $data): int
    {
        $org = $actor['organisation_id'];
        $name = trim((string) $data['name']);
        $values = [];
        foreach (self::EMPLOYER_TEXT as $field => $max) {
            $text = trim((string) ($data[$field] ?? ''));
            $values[$field] = $text === '' ? null : mb_substr($text, 0, $max);
        }
        $active = (int) ($data['active'] ?? true);

        return Transaction::run($this->pdo, function () use ($actor, $org, $id, $name, $values, $active): int {
            try {
                if ($id === null) {
                    $this->pdo->prepare(
                        'INSERT INTO employer (organisation_id, name, industry, website, location, about, active)'
                        . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$org, $name, ...array_values($values), $active]);
                    $id = (int) $this->pdo->lastInsertId();
                    Audit::log($this->pdo, $actor, 'employer.create', 'employer:' . $id);

                    return $id;
                }
                $stmt = $this->pdo->prepare(
                    'UPDATE employer SET name = ?, industry = ?, website = ?, location = ?, about = ?, active = ?,'
                    . ' updated_at = UTC_TIMESTAMP(3) WHERE id = ? AND organisation_id = ?'
                );
                $stmt->execute([$name, ...array_values($values), $active, $id, $org]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[0] ?? null) === '23000') {
                    throw ApiError::validation('name', 'An employer with this name is already in the directory.');
                }
                throw $e;
            }
            $this->requireEmployer($org, $id);
            Audit::log($this->pdo, $actor, 'employer.update', 'employer:' . $id, ['active' => (bool) $active]);

            return $id;
        });
    }

    /**
     * Every job of the organisation, any status, with its application counts.
     *
     * @param array{q?: string, status?: string} $filters
     * @return array{items: list<mixed>, page: int, per_page: int, total: int}
     */
    public function jobs(int $org, array $filters, Paginator $page): array
    {
        $where = 'j.organisation_id = ?';
        $args = [$org];
        if (($filters['q'] ?? '') !== '') {
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $where .= ' AND (j.title LIKE ? OR e.name LIKE ?)';
            array_push($args, $like, $like);
        }
        if (in_array($filters['status'] ?? null, ['draft', 'open', 'closed'], true)) {
            $where .= ' AND j.status = ?';
            $args[] = $filters['status'];
        }
        $from = ' FROM job_posting j JOIN employer e ON e.id = j.employer_id';
        $count = $this->pdo->prepare("SELECT COUNT(*) $from WHERE $where");
        $count->execute($args);
        $stmt = $this->pdo->prepare(
            'SELECT j.id, j.title, j.job_type, j.location, j.closing_date, j.status, j.employer_id,'
            . ' e.name AS employer_name, COUNT(a.id) AS applications,'
            . " COALESCE(SUM(a.status = 'submitted'), 0) AS new_applications" . $from
            . ' LEFT JOIN job_application a ON a.job_id = j.id'
            . " WHERE $where GROUP BY j.id ORDER BY j.closing_date DESC, j.id DESC"
            . ' LIMIT ' . $page->perPage . ' OFFSET ' . $page->offset()
        );
        $stmt->execute($args);
        $items = array_values(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'employer_id' => (int) $r['employer_id'],
            'employer_name' => (string) $r['employer_name'],
            'job_type' => (string) $r['job_type'],
            'location' => (string) $r['location'],
            'closing_date' => (string) $r['closing_date'],
            'status' => (string) $r['status'],
            'applications' => (int) $r['applications'],
            'new_applications' => (int) $r['new_applications'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));

        return $page->envelope($items, (int) $count->fetchColumn());
    }

    /** @return array<string, mixed> one job as the edit form needs it */
    public function job(int $org, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT j.id, j.employer_id, e.name AS employer_name, j.title, j.job_type, j.location, j.closing_date,'
            . ' j.description, j.status, j.' . implode(', j.', JobDetails::COLUMNS) . ', j.attachment_file,'
            . ' j.attachment_name FROM job_posting j JOIN employer e ON e.id = j.employer_id'
            . ' WHERE j.id = ? AND j.organisation_id = ?'
        );
        $stmt->execute([$id, $org]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }
        $job = [
            'id' => (int) $row['id'],
            'employer_id' => (int) $row['employer_id'],
            'employer_name' => (string) $row['employer_name'],
            'title' => (string) $row['title'],
            'job_type' => (string) $row['job_type'],
            'location' => (string) $row['location'],
            'closing_date' => (string) $row['closing_date'],
            'description' => (string) $row['description'],
            'status' => (string) $row['status'],
        ] + JobDetails::shape($row);
        $job['skills'] = (new SkillRequirements($this->pdo))->forJob($id);

        return $job;
    }

    /**
     * Creates ($id null) or updates a job and the skills it asks for. A new job needs an active employer.
     *
     * @param User $actor
     * @param array<string, mixed> $data validated by job.save.json
     */
    public function saveJob(array $actor, ?int $id, array $data): int
    {
        $org = $actor['organisation_id'];
        $closing = LocalDate::parse((string) $data['closing_date'], 'closing_date');
        /** @var list<array{area_id: int, level: int}> $skills */
        $skills = $data['skills'] ?? [];
        $values = [
            (int) $data['employer_id'],
            trim((string) $data['title']),
            (string) $data['job_type'],
            trim((string) $data['location']),
            $closing,
            trim((string) $data['description']),
            (string) ($data['status'] ?? 'open'),
            ...array_values(JobDetails::fromBody($data)),
        ];
        $details = implode(', ', JobDetails::COLUMNS);
        $detailMarks = implode(', ', array_fill(0, count(JobDetails::COLUMNS), '?'));
        $detailSet = implode(', ', array_map(static fn (string $c): string => "$c = ?", JobDetails::COLUMNS));

        return Transaction::run($this->pdo, function () use (
            $actor,
            $org,
            $id,
            $values,
            $skills,
            $details,
            $detailMarks,
            $detailSet
        ): int {
            $employer = $this->pdo->prepare('SELECT active FROM employer WHERE id = ? AND organisation_id = ?');
            $employer->execute([$values[0], $org]);
            $active = $employer->fetchColumn();
            if ($active === false || ($id === null && (int) $active !== 1)) {
                throw ApiError::validation('employer_id', 'Choose an employer from the directory.');
            }
            if ($id === null) {
                $this->pdo->prepare(
                    'INSERT INTO job_posting (organisation_id, employer_id, title, job_type, location, closing_date,'
                    . " description, status, $details, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, $detailMarks, ?)"
                )->execute([$org, ...$values, $actor['id']]);
                $id = (int) $this->pdo->lastInsertId();
                $action = 'job.create';
            } else {
                $this->job($org, $id); // 404 for another organisation's job
                $this->pdo->prepare(
                    'UPDATE job_posting SET employer_id = ?, title = ?, job_type = ?, location = ?, closing_date = ?,'
                    . " description = ?, status = ?, $detailSet, updated_at = UTC_TIMESTAMP(3)"
                    . ' WHERE id = ? AND organisation_id = ?'
                )->execute([...$values, $id, $org]);
                $action = 'job.update';
            }
            (new SkillRequirements($this->pdo))->replace($org, $id, $skills);
            Audit::log($this->pdo, $actor, $action, 'job:' . $id, ['status' => $values[6]]);

            return $id;
        });
    }

    /** @return array{items: list<mixed>, page: int, per_page: int, total: int} */
    public function applications(int $org, int $jobId, ?string $status, Paginator $page): array
    {
        $this->job($org, $jobId);
        $where = 'a.job_id = ? AND a.organisation_id = ?';
        $args = [$jobId, $org];
        if ($status !== null) {
            $where .= ' AND a.status = ?';
            $args[] = $status;
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM job_application a WHERE $where");
        $count->execute($args);
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.status, a.created_at, a.status_changed_at, (a.cv_file IS NOT NULL) AS has_cv,'
            . ' u.id AS user_id, u.display_name, u.picture_url, u.role, p.programme, p.faculty, u.department,'
            . ' l.profile_url AS linkedin_url'
            . ' FROM job_application a JOIN `user` u ON u.id = a.user_id'
            . ' LEFT JOIN career_profile p ON p.user_id = u.id LEFT JOIN linkedin_account l ON l.user_id = u.id'
            . " WHERE $where ORDER BY a.created_at, a.id LIMIT " . $page->perPage . ' OFFSET ' . $page->offset()
        );
        $stmt->execute($args);
        $items = array_values(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'status' => (string) $r['status'],
            'created_at' => (string) $r['created_at'],
            'status_changed_at' => (string) $r['status_changed_at'],
            'has_cv' => (bool) $r['has_cv'],
            'user_id' => (int) $r['user_id'],
            'display_name' => (string) $r['display_name'],
            'picture_url' => $r['picture_url'],
            'role' => (string) $r['role'],
            'programme' => $r['programme'],
            'faculty' => $r['faculty'] ?? $r['department'],
            'linkedin_url' => $r['linkedin_url'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));

        return $page->envelope($items, (int) $count->fetchColumn());
    }

    /** @return array{id: int, job_id: int, user_id: int, note: string|null, status: string, created_at: string,
     *     status_changed_at: string, cv_file: string|null, cv_name: string|null, cv_size: int|null} */
    public function application(int $org, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, job_id, user_id, note, status, created_at, status_changed_at, cv_file, cv_name, cv_size'
            . ' FROM job_application WHERE id = ? AND organisation_id = ?'
        );
        $stmt->execute([$id, $org]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }

        return [
            'id' => (int) $row['id'],
            'job_id' => (int) $row['job_id'],
            'user_id' => (int) $row['user_id'],
            'note' => $row['note'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'status_changed_at' => (string) $row['status_changed_at'],
            'cv_file' => $row['cv_file'],
            'cv_name' => $row['cv_name'],
            'cv_size' => $row['cv_size'] === null ? null : (int) $row['cv_size'],
        ];
    }

    /** @param User $actor */
    public function setStatus(array $actor, int $id, string $status): void
    {
        Transaction::run($this->pdo, function () use ($actor, $id, $status): void {
            $stmt = $this->pdo->prepare(
                'SELECT status FROM job_application WHERE id = ? AND organisation_id = ? FOR UPDATE'
            );
            $stmt->execute([$id, $actor['organisation_id']]);
            $from = $stmt->fetchColumn();
            if ($from === false) {
                throw new ApiError(404, 'NOT_FOUND', 'Not found.');
            }
            if ($from === $status) {
                return;
            }
            if (!ApplicationStatus::managerMaySet((string) $from, $status)) {
                throw new ApiError(409, 'WITHDRAWN', 'The applicant withdrew this application.');
            }
            $this->pdo->prepare(
                'UPDATE job_application SET status = ?, status_changed_at = UTC_TIMESTAMP(3),'
                . ' updated_at = UTC_TIMESTAMP(3) WHERE id = ?'
            )->execute([$status, $id]);
            Audit::log($this->pdo, $actor, 'application.status', 'application:' . $id, [
                'from' => $from,
                'to' => $status,
            ]);
        });
    }

    private function requireEmployer(int $org, int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM employer WHERE id = ? AND organisation_id = ?');
        $stmt->execute([$id, $org]);
        if ($stmt->fetchColumn() === false) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }
    }
}

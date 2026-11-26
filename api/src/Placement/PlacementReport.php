<?php

declare(strict_types=1);

namespace Cpdc\Placement;

use Cpdc\Http\ApiError;
use Cpdc\Http\Paginator;
use PDO;

/**
 * CPDC's view of every placement (placement.manage): the filtered list, one placement's change history, the
 * satisfaction results per company, and the rows of both CSV exports. Every query is scoped to the organisation.
 *
 * A Query is a validated placements.query.json.
 *
 * @phpstan-type Query array<mixed>
 */
final class PlacementReport
{
    private const EXPORT_LIMIT = 10000;
    private const NUMBER_FILTERS = ['batch_year' => 'i.batch_year = ?', 'batch_month' => 'i.batch_month = ?',
        'allowance_min' => 'i.monthly_allowance_rm >= ?', 'allowance_max' => 'i.monthly_allowance_rm <= ?'];
    private const FACULTY = 'COALESCE(p.faculty, u.department)';
    private const FROM = ' FROM internship i JOIN `user` u ON u.id = i.student_id'
        . ' LEFT JOIN career_profile p ON p.user_id = u.id LEFT JOIN linkedin_account l ON l.user_id = u.id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The choices of the filter bar: faculties and batches that have interns, and the business natures.
     *
     * @return array{faculties: list<string>, batches: list<array{year: int, month: int}>,
     *     business_natures: list<string>}
     */
    public function filters(int $org): array
    {
        $faculties = $this->pdo->prepare(
            'SELECT DISTINCT ' . self::FACULTY . ' AS faculty' . self::FROM
            . ' WHERE i.organisation_id = ? AND ' . self::FACULTY . ' IS NOT NULL ORDER BY faculty LIMIT 200'
        );
        $faculties->execute([$org]);
        $batches = $this->pdo->prepare(
            'SELECT DISTINCT batch_year, batch_month FROM internship WHERE organisation_id = ?'
            . ' AND batch_year IS NOT NULL ORDER BY batch_year DESC, batch_month DESC LIMIT 200'
        );
        $batches->execute([$org]);

        return [
            'faculties' => array_values(array_map('strval', $faculties->fetchAll(PDO::FETCH_COLUMN))),
            'batches' => array_values(array_map(static fn (array $r): array => [
                'year' => (int) $r['batch_year'],
                'month' => (int) $r['batch_month'],
            ], $batches->fetchAll(PDO::FETCH_ASSOC))),
            'business_natures' => BusinessNature::ALL,
        ];
    }

    /**
     * @param Query $query
     * @return array{items: list<mixed>, page: int, per_page: int, total: int}
     */
    public function list(int $org, array $query, Paginator $page): array
    {
        [$where, $args] = $this->where($org, $query);
        $count = $this->pdo->prepare('SELECT COUNT(*)' . self::FROM . " WHERE $where");
        $count->execute($args);
        $stmt = $this->pdo->prepare(
            'SELECT i.id AS internship_id, u.id AS student_id, u.display_name, u.matric_no, u.picture_url,'
            . ' ' . self::FACULTY . ' AS faculty, l.profile_url AS linkedin_url, i.company, i.business_nature,'
            . ' i.company_state, i.company_country, i.start_date, i.end_date, i.batch_month, i.batch_year,'
            . ' i.monthly_allowance_rm, i.has_hr_contact, i.supervisor_name, i.placement_submitted_at,'
            . ' (SELECT COUNT(*) FROM internship_change_log c WHERE c.internship_id = i.id) AS changes,'
            . ' (SELECT s.overall_rating FROM internship_satisfaction s WHERE s.internship_id = i.id) AS rating'
            . self::FROM . " WHERE $where ORDER BY i.batch_year IS NULL, i.batch_year DESC, i.batch_month DESC,"
            . ' u.display_name, i.id LIMIT ' . $page->perPage . ' OFFSET ' . $page->offset()
        );
        $stmt->execute($args);
        $items = array_values(array_map(static function (array $r): array {
            foreach (['internship_id', 'student_id', 'changes'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            foreach (['batch_month', 'batch_year', 'rating'] as $k) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }
            $r['monthly_allowance_rm'] = (float) $r['monthly_allowance_rm'];
            $r['has_hr_contact'] = (bool) $r['has_hr_contact'];

            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC)));

        return $page->envelope($items, (int) $count->fetchColumn());
    }

    /**
     * One placement's change history, newest first, with who the intern is.
     *
     * @return array{internship_id: int, display_name: string, company: string, changes: list<array<string, mixed>>}
     */
    public function changes(int $org, int $internshipId): array
    {
        $head = $this->pdo->prepare(
            'SELECT i.id, u.display_name, i.company FROM internship i JOIN `user` u ON u.id = i.student_id'
            . ' WHERE i.id = ? AND i.organisation_id = ?'
        );
        $head->execute([$internshipId, $org]);
        $row = $head->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.change_reason, c.remarks, c.old_snapshot, c.new_snapshot, c.created_at,'
            . ' u.display_name AS changed_by FROM internship_change_log c LEFT JOIN `user` u ON u.id = c.user_id'
            . ' WHERE c.internship_id = ? AND c.organisation_id = ? ORDER BY c.created_at DESC, c.id DESC LIMIT 200'
        );
        $stmt->execute([$internshipId, $org]);

        return [
            'internship_id' => (int) $row['id'],
            'display_name' => (string) $row['display_name'],
            'company' => (string) $row['company'],
            'changes' => array_values(array_map(PlacementService::shapeChange(...), $stmt->fetchAll(PDO::FETCH_ASSOC))),
        ];
    }

    /**
     * Satisfaction per company: answers, average rating, % who recommend it to juniors, % offered a job.
     * Companies group case-insensitively (the column's collation).
     *
     * @param Query $query
     * @return array{companies: list<array<string, mixed>>, total: array<string, mixed>}
     */
    public function satisfaction(int $org, array $query): array
    {
        [$where, $args] = $this->where($org, $query);
        $measures = 'COUNT(*) AS responses, AVG(s.overall_rating) AS avg_rating,'
            . ' AVG(s.recommend_juniors) AS recommend_rate, AVG(s.offered_job) AS offer_rate';
        $from = self::FROM . ' JOIN internship_satisfaction s ON s.internship_id = i.id';
        $stmt = $this->pdo->prepare(
            "SELECT MIN(i.company) AS company, $measures $from WHERE $where GROUP BY i.company"
            . ' ORDER BY responses DESC, company LIMIT 500'
        );
        $stmt->execute($args);
        $total = $this->pdo->prepare("SELECT $measures $from WHERE $where");
        $total->execute($args);
        $sum = $total->fetch(PDO::FETCH_ASSOC);

        return [
            'companies' => array_values(array_map(self::measures(...), $stmt->fetchAll(PDO::FETCH_ASSOC))),
            'total' => self::measures(is_array($sum) ? $sum : ['responses' => 0]),
        ];
    }

    /**
     * Every placement that matches, with every form field, for the Industry Visit CSV.
     *
     * @param Query $query
     * @return list<array<string, mixed>>
     */
    public function visitRows(int $org, array $query): array
    {
        [$where, $args] = $this->where($org, $query);
        $stmt = $this->pdo->prepare(
            'SELECT u.display_name, u.matric_no, ' . self::FACULTY . ' AS faculty, i.'
            . implode(', i.', PlacementForm::FIELDS) . ', i.placement_submitted_at,'
            . ' (SELECT COUNT(*) FROM internship_change_log c WHERE c.internship_id = i.id) AS changes'
            . self::FROM . " WHERE $where ORDER BY i.batch_year, i.batch_month, u.display_name, i.id"
            . ' LIMIT ' . self::EXPORT_LIMIT
        );
        $stmt->execute($args);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Every survey answer that matches, for the Industry Satisfaction CSV.
     *
     * @param Query $query
     * @return list<array<string, mixed>>
     */
    public function surveyRows(int $org, array $query): array
    {
        [$where, $args] = $this->where($org, $query);
        $stmt = $this->pdo->prepare(
            'SELECT u.display_name, u.matric_no, ' . self::FACULTY . ' AS faculty, i.company, i.business_nature,'
            . ' i.batch_month, i.batch_year, s.recommend_juniors, s.overall_rating, s.offered_job, s.feedback,'
            . ' s.updated_at' . self::FROM . ' JOIN internship_satisfaction s ON s.internship_id = i.id'
            . " WHERE $where ORDER BY i.company, u.display_name, i.id LIMIT " . self::EXPORT_LIMIT
        );
        $stmt->execute($args);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The WHERE clause and its parameters for the shared filters.
     *
     * @param Query $query
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(int $org, array $query): array
    {
        $where = 'i.organisation_id = ?';
        $args = [$org];
        if (($query['q'] ?? '') !== '') {
            $like = self::like((string) $query['q']);
            $where .= ' AND (u.display_name LIKE ? OR u.matric_no LIKE ? OR i.company LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        if (($query['company'] ?? '') !== '') {
            $where .= ' AND i.company LIKE ?';
            $args[] = self::like((string) $query['company']);
        }
        if (($query['faculty'] ?? '') !== '') {
            $where .= ' AND ' . self::FACULTY . ' = ?';
            $args[] = (string) $query['faculty'];
        }
        if (($query['business_nature'] ?? '') !== '') {
            if (!BusinessNature::isValid((string) $query['business_nature'])) {
                throw ApiError::validation('business_nature');
            }
            $where .= ' AND i.business_nature = ?';
            $args[] = (string) $query['business_nature'];
        }
        foreach (self::NUMBER_FILTERS as $key => $sql) {
            if (isset($query[$key])) {
                $where .= " AND $sql";
                $args[] = (int) $query[$key];
            }
        }
        $min = $query['allowance_min'] ?? null;
        $max = $query['allowance_max'] ?? null;
        if ($min !== null && $max !== null && $min > $max) {
            throw ApiError::validation('allowance_max', 'The highest allowance must be at least the lowest.');
        }

        return [$where, $args];
    }

    private static function like(string $text): string
    {
        return '%' . addcslashes($text, '%_\\') . '%';
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed> responses, average rating (2 decimals) and the two rates as whole percent
     */
    private static function measures(array $r): array
    {
        $responses = (int) ($r['responses'] ?? 0);
        $pct = static fn (mixed $rate): ?int => $responses === 0 ? null : (int) round((float) $rate * 100);
        $out = isset($r['company']) ? ['company' => (string) $r['company']] : [];

        return $out + [
            'responses' => $responses,
            'avg_rating' => $responses === 0 ? null : round((float) ($r['avg_rating'] ?? 0), 2),
            'recommend_pct' => $pct($r['recommend_rate'] ?? 0),
            'offer_pct' => $pct($r['offer_rate'] ?? 0),
        ];
    }
}

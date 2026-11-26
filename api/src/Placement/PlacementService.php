<?php

declare(strict_types=1);

namespace Cpdc\Placement;

use Cpdc\Db\Transaction;
use Cpdc\Http\ApiError;
use Cpdc\Logbook\ReviewLink;
use PDO;

/**
 * The student's own placement (the internship row of the logbook, with the industry visit details), its change
 * history and the satisfaction survey (docs/SPEC.md section 13). Every query is scoped to the student and
 * organisation. Once submitted, a placement changes only with a reason, and each change keeps an old and a new
 * snapshot in internship_change_log.
 *
 * @phpstan-type Student array{id: int, organisation_id: int, email: string|null, ...}
 */
final class PlacementService
{
    private const SURVEY_COLUMNS = 'recommend_juniors, overall_rating, offered_job, feedback, submitted_at, updated_at';

    /** @param int $now unix time (the App clock) */
    public function __construct(private readonly PDO $pdo, private readonly int $now)
    {
    }

    /**
     * The placement (null before the first save), the survey answer (null until sent) and the change history.
     *
     * @return array{placement: array<string, mixed>|null, satisfaction: array<string, mixed>|null,
     *     changes: list<array<string, mixed>>, business_natures: list<string>}
     */
    public function mine(int $org, int $studentId): array
    {
        $placement = $this->placement($org, $studentId);
        $id = $placement === null ? null : (int) $placement['id'];

        return [
            'placement' => $placement,
            'satisfaction' => $id === null ? null : $this->survey($id),
            'changes' => $id === null ? [] : $this->changes($id),
            'business_natures' => BusinessNature::ALL,
        ];
    }

    /**
     * Whether the student still owes the post-internship satisfaction survey: null while there is nothing to ask
     * (no internship, or it has not ended yet), true once it ended without an answer, false once answered.
     *
     * @param string $today the Malaysia date (Weeks::today)
     */
    public function satisfactionDue(int $org, int $studentId, string $today): ?bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.end_date, s.id AS answered FROM internship i'
            . ' LEFT JOIN internship_satisfaction s ON s.internship_id = i.id'
            . ' WHERE i.student_id = ? AND i.organisation_id = ?'
        );
        $stmt->execute([$studentId, $org]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (string) $row['end_date'] > $today) {
            return null;
        }

        return $row['answered'] === null;
    }

    /**
     * Saves the placement form. The first save (or the first full form on an internship from the short logbook form)
     * marks it submitted; later saves need a change reason and are logged. A new supervisor email voids the
     * supervisor links not yet used, as in the logbook.
     *
     * @param Student $student
     * @param array<string, mixed> $body validated placement.save.json
     * @return array<string, mixed> as mine()
     */
    public function save(array $student, array $body): array
    {
        $new = PlacementForm::fromBody($body, $student['email']);
        $reason = $body['change_reason'] ?? null;
        $remarks = trim((string) ($body['remarks'] ?? ''));
        $org = $student['organisation_id'];
        $id = $student['id'];

        Transaction::run($this->pdo, function () use ($org, $id, $new, $reason, $remarks): void {
            $old = $this->placement($org, $id, true);
            if ($old === null) {
                $columns = implode(', ', PlacementForm::FIELDS);
                $marks = implode(', ', array_fill(0, count(PlacementForm::FIELDS), '?'));
                $this->pdo->prepare(
                    "INSERT INTO internship (organisation_id, student_id, $columns, placement_submitted_at)"
                    . " VALUES (?, ?, $marks, ?)"
                )->execute([$org, $id, ...array_values($new), $this->dbNow()]);

                return;
            }
            $before = PlacementForm::fromRow($old);
            $submitted = $old['placement_submitted_at'] !== null;
            if ($submitted && !in_array($reason, PlacementForm::CHANGE_REASONS, true)) {
                throw ApiError::validation('change_reason', 'Choose why you are changing your placement.');
            }
            if ($submitted && PlacementForm::changed($before, $new) === []) {
                return; // nothing changed: no history row
            }
            $set = implode(', ', array_map(static fn (string $f): string => "$f = ?", PlacementForm::FIELDS));
            $this->pdo->prepare(
                "UPDATE internship SET $set, placement_submitted_at = COALESCE(placement_submitted_at, ?),"
                . ' updated_at = UTC_TIMESTAMP(3) WHERE id = ? AND student_id = ?'
            )->execute([...array_values($new), $this->dbNow(), $old['id'], $id]);
            if ($submitted) {
                $this->pdo->prepare(
                    'INSERT INTO internship_change_log (organisation_id, internship_id, user_id, change_reason,'
                    . ' remarks, old_snapshot, new_snapshot, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$org, $old['id'], $id, $reason, $remarks === '' ? null : mb_substr($remarks, 0, 1000),
                    json_encode($before, JSON_THROW_ON_ERROR), json_encode($new, JSON_THROW_ON_ERROR),
                    $this->dbNow()]);
            }
            if ($before['supervisor_email'] !== $new['supervisor_email']) {
                ReviewLink::voidUnused($this->pdo, (int) $old['id']);
            }
        });

        return $this->mine($org, $id);
    }

    /**
     * Saves (or changes) the satisfaction survey of the student's own internship.
     *
     * @param Student $student
     * @param array<string, mixed> $body validated placement.satisfaction.json
     * @return array<string, mixed> as mine()
     */
    public function saveSurvey(array $student, array $body): array
    {
        $rating = $body['overall_rating'] ?? null;
        if (!is_int($rating) || $rating < 1 || $rating > 5) {
            throw ApiError::validation('overall_rating', 'Rate the internship from 1 (Poor) to 5 (Excellent).');
        }
        $feedback = trim((string) ($body['feedback'] ?? ''));
        $values = [
            ($body['recommend_juniors'] ?? false) === true ? 1 : 0,
            $rating,
            ($body['offered_job'] ?? false) === true ? 1 : 0,
            $feedback === '' ? null : mb_substr($feedback, 0, 2000),
        ];
        $org = $student['organisation_id'];
        $id = $student['id'];
        Transaction::run($this->pdo, function () use ($org, $id, $values): void {
            $placement = $this->placement($org, $id, true)
                ?? throw new ApiError(409, 'NO_INTERNSHIP', 'Add your internship first.');
            $this->pdo->prepare(
                'INSERT INTO internship_satisfaction (organisation_id, internship_id, user_id, recommend_juniors,'
                . ' overall_rating, offered_job, feedback, submitted_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE recommend_juniors = VALUES(recommend_juniors),'
                . ' overall_rating = VALUES(overall_rating), offered_job = VALUES(offered_job),'
                . ' feedback = VALUES(feedback), updated_at = VALUES(updated_at)'
            )->execute([$org, $placement['id'], $id, ...$values, $this->dbNow(), $this->dbNow()]);
        });

        return $this->mine($org, $id);
    }

    /** @return array<string, mixed>|null */
    private function placement(int $org, int $studentId, bool $lock = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, ' . implode(', ', PlacementForm::FIELDS) . ', placement_submitted_at, updated_at'
            . ' FROM internship WHERE student_id = ? AND organisation_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$studentId, $org]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return array_merge(['id' => (int) $row['id']], PlacementForm::fromRow($row), [
            'has_hr_contact' => (bool) $row['has_hr_contact'],
            'placement_submitted_at' => $row['placement_submitted_at'],
            'updated_at' => $row['updated_at'],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function survey(int $internshipId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SURVEY_COLUMNS . ' FROM internship_satisfaction WHERE internship_id = ?'
        );
        $stmt->execute([$internshipId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::shapeSurvey($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function shapeSurvey(array $row): array
    {
        foreach (['recommend_juniors', 'offered_job'] as $flag) {
            $row[$flag] = (bool) $row[$flag];
        }
        $row['overall_rating'] = (int) $row['overall_rating'];

        return $row;
    }

    /** @return list<array<string, mixed>> the student's own change history, newest first */
    private function changes(int $internshipId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, change_reason, remarks, old_snapshot, new_snapshot, created_at FROM internship_change_log'
            . ' WHERE internship_id = ? ORDER BY created_at DESC, id DESC LIMIT 100'
        );
        $stmt->execute([$internshipId]);

        return array_values(array_map(self::shapeChange(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * A change log row with its snapshots decoded and the names of the fields that changed.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function shapeChange(array $row): array
    {
        $old = json_decode((string) $row['old_snapshot'], true);
        $new = json_decode((string) $row['new_snapshot'], true);
        $old = is_array($old) ? $old : [];
        $new = is_array($new) ? $new : [];
        unset($row['old_snapshot'], $row['new_snapshot']);
        $row['id'] = (int) $row['id'];
        $row['fields'] = array_map(static fn (string $f): array => [
            'field' => $f,
            'old' => $old[$f] ?? null,
            'new' => $new[$f] ?? null,
        ], PlacementForm::changed($old, $new));

        return $row;
    }

    private function dbNow(): string
    {
        return gmdate('Y-m-d H:i:s', $this->now);
    }
}

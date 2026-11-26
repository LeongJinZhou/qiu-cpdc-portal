<?php

declare(strict_types=1);

namespace Cpdc\Survey;

use Cpdc\Audit\Audit;
use Cpdc\Db\Transaction;
use Cpdc\Http\ApiError;
use Cpdc\Workshops\WorkshopTime;
use PDO;

/**
 * The yearly University and Programme Survey (docs/SPEC.md section 13). Students answer open rounds, saving drafts
 * as they go; a submitted response is final. CPDC opens and closes rounds and reads the results. Every query is
 * scoped to the organisation, and a student only ever reaches their own response.
 *
 * @phpstan-type Actor array{id: int, organisation_id: int, ...}
 * @phpstan-type Body array{programme?: string, semester?: int|null, answers: array<string, mixed>,
 *     comments: array<string, mixed>, submit: bool}
 * @phpstan-import-type Answers from SurveyForm
 */
final class SurveyService
{
    private const CYCLE = 'SELECT id, title, academic_year, status, opens_at, closes_at, created_at FROM survey_cycle';

    public function __construct(private readonly PDO $pdo, private readonly string $zone)
    {
    }

    /**
     * Rounds the student can answer now, plus any they already answered, with their own status.
     *
     * @param Actor $user
     * @return list<array<string, mixed>>
     */
    public function forStudent(array $user, int $now): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.title, c.academic_year, c.status, c.opens_at, c.closes_at, c.created_at,'
            . ' r.status AS my_status, r.submitted_at, r.updated_at AS my_updated_at'
            . ' FROM survey_cycle c LEFT JOIN survey_response r ON r.cycle_id = c.id AND r.user_id = ?'
            . ' WHERE c.organisation_id = ? AND (r.id IS NOT NULL OR (' . self::openSql('c') . '))'
            . ' ORDER BY c.created_at DESC, c.id DESC LIMIT 20'
        );
        $at = WorkshopTime::sql($now);
        $stmt->execute([$user['id'], $user['organisation_id'], $at, $at]);

        return array_values(array_map(function (array $r) use ($now): array {
            $cycle = $this->presentCycle($r, $now);
            $cycle['my_status'] = $r['my_status'] ?? 'none';
            $submitted = $r['submitted_at'];
            $cycle['submitted_at'] = $submitted === null ? null : WorkshopTime::iso((string) $submitted);

            return $cycle;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * One round with the student's own response (null before the first save) and what the form starts with.
     *
     * @param Actor $user
     * @return array<string, mixed>
     */
    public function response(array $user, int $cycleId, int $now): array
    {
        $cycle = $this->cycle($user['organisation_id'], $cycleId);
        $response = $this->own($user, $cycleId);
        if ($response === null && !$this->isOpen($cycle, $now)) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }
        $who = $this->pdo->prepare(
            'SELECT u.department, p.programme FROM `user` u LEFT JOIN career_profile p ON p.user_id = u.id'
            . ' WHERE u.id = ? AND u.organisation_id = ?'
        );
        $who->execute([$user['id'], $user['organisation_id']]);
        $row = $who->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'cycle' => $this->presentCycle($cycle, $now),
            'response' => $response,
            'defaults' => ['faculty' => $row['department'] ?? null, 'programme' => $row['programme'] ?? null],
        ];
    }

    /**
     * Saves a draft, or submits. Submitting needs every item (N/A counts as answered), the programme and the
     * semester. A submitted response cannot change.
     *
     * @param Actor $user
     * @param Body $body
     * @return array<string, mixed> the saved response
     */
    public function save(array $user, int $cycleId, array $body, int $now): array
    {
        $answers = SurveyForm::cleanAnswers($body['answers']);
        $comments = SurveyForm::cleanComments($body['comments']);
        $programme = trim($body['programme'] ?? '');
        $semester = $body['semester'] ?? null;
        if ($body['submit']) {
            $missing = SurveyForm::missing($answers);
            if ($missing !== []) {
                $message = 'Answer every item first. Pick N/A where it does not apply.';
                throw new ApiError(400, 'SURVEY_INCOMPLETE', $message, [
                    'field' => 'answers.' . $missing[0], 'missing' => count($missing),
                ]);
            }
            if ($programme === '') {
                throw ApiError::validation('programme', 'Enter your programme.');
            }
            if ($semester === null) {
                throw ApiError::validation('semester', 'Pick your semester.');
            }
        }

        return Transaction::run($this->pdo, function () use (
            $user,
            $cycleId,
            $answers,
            $comments,
            $programme,
            $semester,
            $body,
            $now
): array {
            $cycle = $this->cycle($user['organisation_id'], $cycleId, true);
            if (!$this->isOpen($cycle, $now)) {
                throw new ApiError(409, 'SURVEY_CLOSED', 'This survey is not open.');
            }
            $existing = $this->own($user, $cycleId, true);
            if ($existing !== null && $existing['status'] === 'submitted') {
                throw new ApiError(409, 'SURVEY_SUBMITTED', 'You have already submitted this survey.');
            }
            $faculty = $this->pdo->prepare('SELECT department FROM `user` WHERE id = ? AND organisation_id = ?');
            $faculty->execute([$user['id'], $user['organisation_id']]);
            $department = $faculty->fetchColumn();
            $values = [
                $body['submit'] ? 'submitted' : 'draft',
                is_string($department) && $department !== '' ? $department : null,
                $programme === '' ? null : $programme,
                $semester,
                json_encode((object) $answers, JSON_THROW_ON_ERROR),
                json_encode((object) $comments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $body['submit'] ? WorkshopTime::sql($now) : null,
            ];
            $this->pdo->prepare(
                'INSERT INTO survey_response (organisation_id, cycle_id, user_id, status, faculty, programme, semester,'
                . ' answers_json, comments_json, submitted_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE status = VALUES(status), faculty = VALUES(faculty),'
                . ' programme = VALUES(programme), semester = VALUES(semester), answers_json = VALUES(answers_json),'
                . ' comments_json = VALUES(comments_json), submitted_at = VALUES(submitted_at),'
                . ' updated_at = VALUES(updated_at)'
            )->execute([$user['organisation_id'], $cycleId, $user['id'], ...$values, WorkshopTime::sql($now)]);

            return $this->own($user, $cycleId) ?? throw new \LogicException('response just saved');
        });
    }

    /** @return list<array<string, mixed>> every round with its counts, newest first */
    public function manageList(int $org, int $now): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.title, c.academic_year, c.status, c.opens_at, c.closes_at, c.created_at,'
            . " SUM(r.status = 'submitted') AS submitted_count, SUM(r.status = 'draft') AS draft_count"
            . ' FROM survey_cycle c LEFT JOIN survey_response r ON r.cycle_id = c.id'
            . ' WHERE c.organisation_id = ?'
            . ' GROUP BY c.id, c.title, c.academic_year, c.status, c.opens_at, c.closes_at, c.created_at'
            . ' ORDER BY c.created_at DESC, c.id DESC'
        );
        $stmt->execute([$org]);
        $invited = $this->invitedTotal($org);

        return array_values(array_map(function (array $r) use ($now, $invited): array {
            $cycle = $this->presentCycle($r, $now);
            $cycle['submitted_count'] = (int) $r['submitted_count'];
            $cycle['draft_count'] = (int) $r['draft_count'];
            $cycle['invited_count'] = $invited;
            $cycle['response_rate'] = SurveyStats::rate((int) $r['submitted_count'], $invited);

            return $cycle;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * A new round as a draft. Times are typed in local time ("2026-12-01T09:00") and stored in UTC.
     *
     * @param Actor $admin
     * @param array{title: string, academic_year: string, opens_at?: string|null, closes_at?: string|null} $body
     */
    public function createCycle(array $admin, array $body): int
    {
        $opens = isset($body['opens_at']) ? WorkshopTime::toUtc($body['opens_at'], $this->zone, 'opens_at') : null;
        $closes = isset($body['closes_at']) ? WorkshopTime::toUtc($body['closes_at'], $this->zone, 'closes_at') : null;
        if ($opens !== null && $closes !== null && $closes <= $opens) {
            throw ApiError::validation('closes_at', 'The survey must close after it opens.');
        }
        [$start, $end] = array_map('intval', explode('/', $body['academic_year']));
        if ($end !== $start + 1) {
            throw ApiError::validation('academic_year', 'Write the academic year as 2026/2027.');
        }

        return Transaction::run($this->pdo, function () use ($admin, $body, $opens, $closes): int {
            $this->pdo->prepare(
                'INSERT INTO survey_cycle (organisation_id, title, academic_year, opens_at, closes_at, created_by)'
                . ' VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$admin['organisation_id'], trim($body['title']), $body['academic_year'], $opens, $closes,
                $admin['id']]);
            $id = (int) $this->pdo->lastInsertId();
            Audit::log($this->pdo, $admin, 'survey.cycle.create', "survey_cycle:$id");

            return $id;
        });
    }

    /**
     * Opens or closes a round. A draft opens; an open round closes; a closed round may open again. Nothing goes
     * back to draft once it was open.
     *
     * @param Actor $admin
     * @param 'open'|'closed' $status
     */
    public function setStatus(array $admin, int $id, string $status): void
    {
        Transaction::run($this->pdo, function () use ($admin, $id, $status): void {
            $cycle = $this->cycle($admin['organisation_id'], $id, true);
            if ($cycle['status'] === $status || ($cycle['status'] === 'draft' && $status === 'closed')) {
                throw new ApiError(409, 'SURVEY_STATUS', 'This survey cannot change to that status.');
            }
            $this->pdo->prepare('UPDATE survey_cycle SET status = ? WHERE id = ? AND organisation_id = ?')
                ->execute([$status, $id, $admin['organisation_id']]);
            Audit::log($this->pdo, $admin, 'survey.cycle.status', "survey_cycle:$id", ['from' => $cycle['status'],
                'to' => $status]);
        });
    }

    /**
     * Response rate per faculty (invited = active students of that directory department) and per programme
     * (invited = active students whose career profile names it), and section and item means without N/A.
     *
     * @return array<string, mixed>
     */
    public function report(int $org, int $id, int $now): array
    {
        $cycle = $this->cycle($org, $id);
        $submitted = $this->submitted($org, $id);
        $byFaculty = [];
        foreach ($this->invited($org, 'u.department') as $name => $count) {
            $byFaculty[$name] = ['name' => $name, 'invited' => $count, 'submitted' => 0, 'drafts' => 0];
        }
        $byProgramme = [];
        foreach ($this->invited($org, 'p.programme') as $name => $count) {
            $byProgramme[$name] = ['name' => $name, 'invited' => $count, 'submitted' => 0, 'drafts' => 0];
        }
        $counts = $this->pdo->prepare(
            'SELECT faculty, programme, status, COUNT(*) AS n FROM survey_response'
            . ' WHERE organisation_id = ? AND cycle_id = ? GROUP BY faculty, programme, status'
        );
        $counts->execute([$org, $id]);
        $add = static function (array &$list, mixed $name, string $column, int $n): void {
            $name = $name === null || $name === '' ? 'Not given' : (string) $name;
            $list[$name] ??= ['name' => $name, 'invited' => 0, 'submitted' => 0, 'drafts' => 0];
            $list[$name][$column] += $n;
        };
        foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $column = $r['status'] === 'submitted' ? 'submitted' : 'drafts';
            $add($byFaculty, $r['faculty'], $column, (int) $r['n']);
            $add($byProgramme, $r['programme'], $column, (int) $r['n']);
        }
        $rate = static function (array $rows): array {
            ksort($rows);

            return array_values(array_map(static fn (array $row): array => $row + [
                'rate' => SurveyStats::rate($row['submitted'], $row['invited']),
            ], $rows));
        };
        $invited = $this->invitedTotal($org);

        return [
            'cycle' => $this->presentCycle($cycle, $now),
            'form' => SurveyForm::definition(),
            'totals' => ['invited' => $invited, 'submitted' => count($submitted),
                'rate' => SurveyStats::rate(count($submitted), $invited)],
            'by_faculty' => $rate($byFaculty),
            'by_programme' => $rate($byProgramme),
            'stats' => SurveyStats::summarise(array_map(static fn (array $s): array => $s['answers'], $submitted)),
        ];
    }

    /**
     * Comments of submitted responses for one section (or the skills wish list), without names. Audited.
     *
     * @param Actor $admin
     * @return list<array{faculty: string|null, programme: string|null, semester: int|null, text: string}>
     */
    public function comments(array $admin, int $id, string $key): array
    {
        if (!in_array($key, SurveyForm::textKeys(), true)) {
            throw ApiError::validation('section');
        }
        $this->cycle($admin['organisation_id'], $id);
        $out = [];
        foreach ($this->submitted($admin['organisation_id'], $id) as $s) {
            if (isset($s['comments'][$key])) {
                $out[] = ['faculty' => $s['faculty'], 'programme' => $s['programme'], 'semester' => $s['semester'],
                    'text' => $s['comments'][$key]];
            }
        }
        Audit::log($this->pdo, $admin, 'survey.comments.view', "survey_cycle:$id", ['section' => $key]);

        return $out;
    }

    /**
     * The CSV of submitted responses: one row each, no names or emails, times in local time. Audited.
     *
     * @param Actor $admin
     * @return array{filename: string, header: list<string>, rows: list<list<string|int|null>>}
     */
    public function export(array $admin, int $id): array
    {
        $cycle = $this->cycle($admin['organisation_id'], $id);
        $items = array_keys(SurveyForm::items());
        $texts = SurveyForm::textKeys();
        $rows = [];
        foreach ($this->submitted($admin['organisation_id'], $id) as $s) {
            $row = [$s['id'], WorkshopTime::readable($s['submitted_at'], $this->zone), $s['faculty'], $s['programme'],
                $s['semester']];
            foreach ($items as $key) {
                $value = $s['answers'][$key] ?? '';
                $row[] = $value === SurveyForm::NA ? 'N/A' : $value;
            }
            foreach ($texts as $key) {
                $row[] = $s['comments'][$key] ?? '';
            }
            $rows[] = $row;
        }
        Audit::log($this->pdo, $admin, 'survey.export', "survey_cycle:$id", ['rows' => count($rows)]);
        $header = ['Response', 'Submitted (Malaysia time)', 'Faculty', 'Programme', 'Semester', ...$items,
            ...array_map(static fn (string $k): string => "comment_$k", $texts)];

        return ['filename' => 'survey-' . str_replace('/', '-', (string) $cycle['academic_year']) . "-$id.csv",
            'header' => $header, 'rows' => $rows];
    }

    /**
     * Whether the person still has to answer an open round (none started, or a draft).
     *
     * @param Actor $user
     */
    public function pendingFor(array $user, int $now): bool
    {
        foreach ($this->forStudent($user, $now) as $cycle) {
            if ($cycle['is_open'] && $cycle['my_status'] !== 'submitted') {
                return true;
            }
        }

        return false;
    }

    private static function openSql(string $alias): string
    {
        return "$alias.status = 'open' AND ($alias.opens_at IS NULL OR $alias.opens_at <= ?)"
            . " AND ($alias.closes_at IS NULL OR $alias.closes_at > ?)";
    }

    /** @param array<string, mixed> $cycle */
    private function isOpen(array $cycle, int $now): bool
    {
        // Unix times, not strings: the database answers "09:00:00.000", which would sort after "09:00:00".
        $opens = $cycle['opens_at'] === null ? null : WorkshopTime::unix((string) $cycle['opens_at']);
        $closes = $cycle['closes_at'] === null ? null : WorkshopTime::unix((string) $cycle['closes_at']);

        return $cycle['status'] === 'open' && ($opens === null || $opens <= $now)
            && ($closes === null || $closes > $now);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function presentCycle(array $r, int $now): array
    {
        $iso = static fn (mixed $v): ?string => $v === null ? null : WorkshopTime::iso((string) $v);

        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'academic_year' => (string) $r['academic_year'],
            'status' => (string) $r['status'],
            'opens_at' => $iso($r['opens_at']),
            'closes_at' => $iso($r['closes_at']),
            'is_open' => $this->isOpen($r, $now),
        ];
    }

    /** @return array<string, mixed> */
    private function cycle(int $org, int $id, bool $lock = false): array
    {
        $stmt = $this->pdo->prepare(
            self::CYCLE . ' WHERE id = ? AND organisation_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$id, $org]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }

        return $row;
    }

    /**
     * @param Actor $user
     * @return array<string, mixed>|null
     */
    private function own(array $user, int $cycleId, bool $lock = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, faculty, programme, semester, answers_json, comments_json, submitted_at, updated_at'
            . ' FROM survey_response WHERE cycle_id = ? AND user_id = ? AND organisation_id = ?'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$cycleId, $user['id'], $user['organisation_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'faculty' => $row['faculty'],
            'programme' => $row['programme'],
            'semester' => $row['semester'] === null ? null : (int) $row['semester'],
            'answers' => (object) self::decodeMap($row['answers_json']),
            'comments' => (object) self::decodeMap($row['comments_json']),
            'submitted_at' => $row['submitted_at'] === null ? null : WorkshopTime::iso((string) $row['submitted_at']),
            'updated_at' => WorkshopTime::iso((string) $row['updated_at']),
        ];
    }

    /**
     * @return list<array{id: int, faculty: string|null, programme: string|null, semester: int|null,
     *     submitted_at: string|null, answers: Answers, comments: array<string, string>}>
     */
    private function submitted(int $org, int $cycleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, faculty, programme, semester, answers_json, comments_json, submitted_at FROM survey_response'
            . " WHERE organisation_id = ? AND cycle_id = ? AND status = 'submitted' ORDER BY submitted_at, id"
        );
        $stmt->execute([$org, $cycleId]);

        return array_values(array_map(static function (array $r): array {
            /** @var Answers $answers */
            $answers = array_filter(
                self::decodeMap($r['answers_json']),
                static fn (mixed $v): bool => is_int($v) || $v === SurveyForm::NA
            );
            /** @var array<string, string> $comments */
            $comments = array_filter(self::decodeMap($r['comments_json']), 'is_string');

            return [
                'id' => (int) $r['id'],
                'faculty' => $r['faculty'] === null ? null : (string) $r['faculty'],
                'programme' => $r['programme'] === null ? null : (string) $r['programme'],
                'semester' => $r['semester'] === null ? null : (int) $r['semester'],
                'submitted_at' => $r['submitted_at'] === null ? null : (string) $r['submitted_at'],
                'answers' => $answers,
                'comments' => $comments,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Active students per directory department or career-profile programme.
     *
     * @param 'u.department'|'p.programme' $column
     * @return array<string, int>
     */
    private function invited(int $org, string $column): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT $column AS name, COUNT(*) AS n FROM `user` u LEFT JOIN career_profile p ON p.user_id = u.id"
            . " WHERE u.organisation_id = ? AND u.role = 'student' AND u.active = 1"
            . " AND $column IS NOT NULL AND $column <> '' GROUP BY $column"
        );
        $stmt->execute([$org]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['name']] = (int) $r['n'];
        }

        return $out;
    }

    private function invitedTotal(int $org): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM `user` WHERE organisation_id = ? AND role = 'student' AND active = 1"
        );
        $stmt->execute([$org]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> a stored JSON object; anything else counts as empty (MariaDB keeps it as text) */
    private static function decodeMap(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true, 4) : null;

        return is_array($value) && !array_is_list($value) ? $value : [];
    }
}

<?php

declare(strict_types=1);

namespace Cpdc\Assessment;

use Cpdc\Audit\Audit;
use Cpdc\Db\Transaction;
use Cpdc\Http\ApiError;
use PDO;

/**
 * Self-assessments (docs/SPEC.md section 9): CPDC writes and publishes them, students and alumni take them, the
 * server scores every attempt. Points never leave the server for a taker.
 *
 * @phpstan-type Actor array{id: int, organisation_id: int, ...}
 * @phpstan-type Body array{title: string, description: string, bands: list<array{min_percent: int, label: string}>,
 *     questions: list<array{prompt: string, options: list<array{label: string, points: int}>}>,
 *     answer_style?: 'choice'|'likert', reflection_prompts?: list<string>}
 */
final class AssessmentService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> every assessment of the organisation with its use */
    public function manageList(int $org): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.title, a.status, a.updated_at,'
            . ' (SELECT COUNT(*) FROM assessment_question q WHERE q.assessment_id = a.id) AS question_count,'
            . ' COUNT(t.id) AS attempt_count, COUNT(DISTINCT t.user_id) AS people_count,'
            . ' ROUND(AVG(IF(t.max_score = 0, 0, t.score * 100 / t.max_score))) AS average_percent'
            . ' FROM assessment a LEFT JOIN assessment_attempt t ON t.assessment_id = a.id'
            . " WHERE a.organisation_id = ? GROUP BY a.id ORDER BY FIELD(a.status, 'published', 'draft', 'archived'),"
            . ' a.updated_at DESC'
        );
        $stmt->execute([$org]);

        return array_values(array_map(static function (array $r): array {
            foreach (['id', 'question_count', 'attempt_count', 'people_count'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['average_percent'] = $r['average_percent'] === null ? null : (int) $r['average_percent'];

            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return array<string, mixed> one assessment with points, for its editor */
    public function manageGet(int $org, int $id): array
    {
        $a = $this->assessment($org, $id);
        $a['questions'] = $this->questions($id);
        $a['locked'] = $this->attemptCount($id) > 0;

        return $a;
    }

    /**
     * @param Actor $admin
     * @param Body $body
     */
    public function create(array $admin, array $body): int
    {
        $bands = Scoring::checkBands($body['bands']);
        $style = $body['answer_style'] ?? 'choice';
        $prompts = self::promptsJson($body);

        return Transaction::run($this->pdo, function () use ($admin, $body, $bands, $style, $prompts): int {
            $this->pdo->prepare(
                'INSERT INTO assessment (organisation_id, title, description, answer_style, bands_json,'
                . ' reflection_prompts, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$admin['organisation_id'], trim($body['title']), trim($body['description']), $style,
                json_encode($bands, JSON_THROW_ON_ERROR), $prompts, $admin['id']]);
            $id = (int) $this->pdo->lastInsertId();
            $this->writeQuestions($admin['organisation_id'], $id, $body['questions'], $style);
            Audit::log($this->pdo, $admin, 'assessment.create', "assessment:$id");

            return $id;
        });
    }

    /**
     * Replaces title, bands and questions. Refused once anyone has taken it: old results would stop matching.
     *
     * @param Actor $admin
     * @param Body $body
     */
    public function replace(array $admin, int $id, array $body): void
    {
        $bands = Scoring::checkBands($body['bands']);
        $style = $body['answer_style'] ?? 'choice';
        $prompts = self::promptsJson($body);
        Transaction::run($this->pdo, function () use ($admin, $id, $body, $bands, $style, $prompts): void {
            $this->assessment($admin['organisation_id'], $id, true);
            if ($this->attemptCount($id) > 0) {
                throw new ApiError(
                    409,
                    'ASSESSMENT_LOCKED',
                    'People have taken this assessment. Archive it and write a new one.'
                );
            }
            $this->pdo->prepare(
                'UPDATE assessment SET title = ?, description = ?, answer_style = ?, bands_json = ?,'
                . ' reflection_prompts = ?, updated_at = UTC_TIMESTAMP(3) WHERE id = ? AND organisation_id = ?'
            )->execute([trim($body['title']), trim($body['description']), $style,
                json_encode($bands, JSON_THROW_ON_ERROR), $prompts, $id, $admin['organisation_id']]);
            $this->pdo->prepare('DELETE FROM assessment_question WHERE assessment_id = ?')->execute([$id]);
            $this->writeQuestions($admin['organisation_id'], $id, $body['questions'], $style);
            Audit::log($this->pdo, $admin, 'assessment.update', "assessment:$id");
        });
    }

    /**
     * @param Actor $admin
     * @param 'draft'|'published'|'archived' $status
     */
    public function setStatus(array $admin, int $id, string $status): void
    {
        Transaction::run($this->pdo, function () use ($admin, $id, $status): void {
            $old = $this->assessment($admin['organisation_id'], $id, true);
            if ($status === 'draft' && $this->attemptCount($id) > 0) {
                throw new ApiError(409, 'ASSESSMENT_LOCKED', 'People have taken this assessment. Archive it instead.');
            }
            $this->pdo->prepare(
                'UPDATE assessment SET status = ?, updated_at = UTC_TIMESTAMP(3) WHERE id = ? AND organisation_id = ?'
            )->execute([$status, $id, $admin['organisation_id']]);
            Audit::log($this->pdo, $admin, 'assessment.status', "assessment:$id", ['from' => $old['status'],
                'to' => $status]);
        });
    }

    /** @return list<array<string, mixed>> published assessments with the person's latest result */
    public function available(int $org, int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.title, a.description,'
            . ' (SELECT COUNT(*) FROM assessment_question q WHERE q.assessment_id = a.id) AS question_count'
            . " FROM assessment a WHERE a.organisation_id = ? AND a.status = 'published' ORDER BY a.title, a.id"
        );
        $stmt->execute([$org]);
        $latest = [];
        foreach ($this->myResults($org, $userId) as $r) {
            $latest[$r['assessment_id']] = $r;
        }

        return array_values(array_map(static function (array $r) use ($latest): array {
            $r['id'] = (int) $r['id'];
            $r['question_count'] = (int) $r['question_count'];
            $r['latest'] = $latest[$r['id']] ?? null;

            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return array<string, mixed> a published assessment as a taker sees it: no points */
    public function forTaking(int $org, int $id): array
    {
        $a = $this->assessment($org, $id);
        if ($a['status'] !== 'published') {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }
        unset($a['bands'], $a['status']);
        $a['questions'] = array_map(static fn (array $q): array => [
            'id' => $q['id'],
            'prompt' => $q['prompt'],
            'options' => array_map(
                static fn (array $o): array => ['id' => $o['id'], 'label' => $o['label']],
                $q['options']
            ),
        ], $this->questions($id));

        return $a;
    }

    /**
     * Scores and stores an attempt with its written reflections. Faculty is the directory department, read here.
     *
     * @param Actor $user
     * @param list<array{question_id: int, option_id: int}> $answers
     * @param list<string> $reflections one per reflection prompt, in order
     * @param int|null $semester the person's semester, 1-12, when they gave it
     * @return array<string, mixed> the result
     */
    public function attempt(array $user, int $id, array $answers, array $reflections = [], ?int $semester = null): array
    {
        $org = $user['organisation_id'];
        $written = [$reflections, $semester];

        return Transaction::run($this->pdo, function () use ($user, $org, $id, $answers, $written): array {
            [$reflections, $semester] = $written;
            // Locked like replace(): an edit cannot swap the questions while this attempt is scored and stored.
            $a = $this->assessment($org, $id, true);
            if ($a['status'] !== 'published') {
                throw new ApiError(404, 'NOT_FOUND', 'Not found.');
            }
            $points = [];
            foreach ($this->questions($id) as $q) {
                foreach ($q['options'] as $o) {
                    $points[$q['id']][$o['id']] = $o['points'];
                }
            }
            /** @var list<array{min_percent: int, label: string}> $bands */
            $bands = $a['bands'];
            $result = Scoring::score($points, $answers, $bands);
            /** @var list<string> $prompts */
            $prompts = $a['reflection_prompts'];
            $written = Reflections::checkAnswers($prompts, $reflections);
            $faculty = $this->pdo->prepare('SELECT department FROM `user` WHERE id = ? AND organisation_id = ?');
            $faculty->execute([$user['id'], $org]);
            $department = $faculty->fetchColumn();
            $this->pdo->prepare(
                'INSERT INTO assessment_attempt (organisation_id, assessment_id, user_id, score, max_score, band_label,'
                . ' reflection_answers, faculty, semester) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$org, $id, $user['id'], $result['score'], $result['max_score'], $result['band'],
                $written === null ? null : json_encode($written, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                is_string($department) && $department !== '' ? $department : null, $semester]);
            $attemptId = (int) $this->pdo->lastInsertId();
            $insert = $this->pdo->prepare(
                'INSERT INTO assessment_answer (attempt_id, question_id, option_id) VALUES (?, ?, ?)'
            );
            foreach ($result['chosen'] as $q => $o) {
                $insert->execute([$attemptId, $q, $o]);
            }

            return ['attempt_id' => $attemptId, 'assessment_id' => $id, 'title' => $a['title'],
                'score' => $result['score'],
                'max_score' => $result['max_score'], 'percent' => $result['percent'], 'band' => $result['band']];
        });
    }

    /** @return list<array{attempt_id: int, assessment_id: int, title: string, score: int, max_score: int,
     *     percent: int, band: string|null, taken_at: string}> the latest result of each assessment the person took */
    public function myResults(int $org, int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.assessment_id, a.title, t.score, t.max_score, t.band_label, t.created_at'
            . ' FROM assessment_attempt t JOIN assessment a ON a.id = t.assessment_id'
            . " WHERE t.user_id = ? AND t.organisation_id = ? AND a.status <> 'draft' AND t.id = ("
            . 'SELECT MAX(t2.id) FROM assessment_attempt t2 WHERE t2.user_id = t.user_id'
            . ' AND t2.assessment_id = t.assessment_id) ORDER BY t.created_at DESC, t.id DESC'
        );
        $stmt->execute([$userId, $org]);

        return array_values(array_map(static function (array $r): array {
            $score = (int) $r['score'];
            $max = (int) $r['max_score'];

            return ['attempt_id' => (int) $r['id'], 'assessment_id' => (int) $r['assessment_id'],
                'title' => (string) $r['title'], 'score' => $score,
                'max_score' => $max, 'percent' => $max === 0 ? 0 : intdiv($score * 100, $max),
                'band' => $r['band_label'] === null ? null : (string) $r['band_label'],
                'taken_at' => (string) $r['created_at']];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return array<string, mixed> */
    private function assessment(int $org, int $id, bool $lock = false): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, title, description, answer_style, bands_json, reflection_prompts, status, updated_at'
            . ' FROM assessment WHERE id = ? AND organisation_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$id, $org]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }
        $row['id'] = (int) $row['id'];
        $row['bands'] = json_decode((string) $row['bands_json'], true, 8, JSON_THROW_ON_ERROR);
        $row['reflection_prompts'] = Reflections::decodeList($row['reflection_prompts']);
        unset($row['bands_json']);

        return $row;
    }

    /**
     * Questions in order with their options and points. Callers that serve a taker drop the points.
     *
     * @return list<array{id: int, prompt: string, options: list<array{id: int, label: string, points: int}>}>
     */
    private function questions(int $assessmentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT q.id AS qid, q.prompt, o.id AS oid, o.label, o.points FROM assessment_question q'
            . ' JOIN assessment_option o ON o.question_id = q.id WHERE q.assessment_id = ?'
            . ' ORDER BY q.position, q.id, o.position, o.id'
        );
        $stmt->execute([$assessmentId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $qid = (int) $r['qid'];
            $out[$qid] ??= ['id' => $qid, 'prompt' => (string) $r['prompt'], 'options' => []];
            $out[$qid]['options'][] = ['id' => (int) $r['oid'], 'label' => (string) $r['label'],
                'points' => (int) $r['points']];
        }

        return array_values($out);
    }

    /**
     * A likert assessment ignores the options sent and gives every question the five standard ratings.
     *
     * @param list<array{prompt: string, options: list<array{label: string, points: int}>}> $questions
     */
    private function writeQuestions(int $org, int $assessmentId, array $questions, string $style): void
    {
        $q = $this->pdo->prepare(
            'INSERT INTO assessment_question (organisation_id, assessment_id, position, prompt) VALUES (?, ?, ?, ?)'
        );
        $o = $this->pdo->prepare(
            'INSERT INTO assessment_option (question_id, position, label, points) VALUES (?, ?, ?, ?)'
        );
        foreach ($questions as $i => $question) {
            $q->execute([$org, $assessmentId, $i, trim($question['prompt'])]);
            $questionId = (int) $this->pdo->lastInsertId();
            foreach ($style === 'likert' ? Reflections::LIKERT : $question['options'] as $j => $option) {
                $o->execute([$questionId, $j, trim($option['label']), $option['points']]);
            }
        }
    }

    /** @param Body $body */
    private static function promptsJson(array $body): ?string
    {
        $prompts = Reflections::checkPrompts($body['reflection_prompts'] ?? []);

        return $prompts === [] ? null : json_encode($prompts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function attemptCount(int $assessmentId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM assessment_attempt WHERE assessment_id = ?');
        $stmt->execute([$assessmentId]);

        return (int) $stmt->fetchColumn();
    }
}

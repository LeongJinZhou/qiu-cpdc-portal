<?php

declare(strict_types=1);

namespace Cpdc\Assessment;

use Cpdc\Audit\Audit;
use Cpdc\Http\ApiError;
use PDO;

/**
 * What a completed attempt says, for its taker's print view and for CPDC: trait averages per faculty and semester,
 * the people who took it, and one person's written reflections (every CPDC read is audited). Each person counts once,
 * with their latest attempt.
 *
 * @phpstan-type Actor array{id: int, organisation_id: int, ...}
 */
final class AssessmentInsights
{
    private const LATEST = 't.id = (SELECT MAX(t2.id) FROM assessment_attempt t2'
        . ' WHERE t2.user_id = t.user_id AND t2.assessment_id = t.assessment_id)';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Average points per question, overall and per faculty and semester group (faculty or semester may be null).
     *
     * @return array{assessment: array{id: int, title: string}, questions: list<array{id: int, prompt: string,
     *     max_points: int}>, overall: array{people: int, means: array<int, float>},
     *     groups: list<array{faculty: string|null, semester: int|null, people: int, means: array<int, float>}>}
     */
    public function traits(int $org, int $assessmentId): array
    {
        $assessment = $this->assessment($org, $assessmentId);
        $questions = $this->pdo->prepare(
            'SELECT q.id, q.prompt, MAX(o.points) AS max_points FROM assessment_question q'
            . ' JOIN assessment_option o ON o.question_id = q.id WHERE q.assessment_id = ?'
            . ' GROUP BY q.id, q.prompt, q.position ORDER BY q.position, q.id'
        );
        $questions->execute([$assessmentId]);
        $rows = $this->pdo->prepare(
            'SELECT t.faculty, t.semester, a.question_id, AVG(o.points) AS mean, COUNT(DISTINCT t.user_id) AS people'
            . ' FROM assessment_attempt t JOIN assessment_answer a ON a.attempt_id = t.id'
            . ' JOIN assessment_option o ON o.id = a.option_id'
            . ' WHERE t.assessment_id = ? AND t.organisation_id = ? AND ' . self::LATEST
            . ' GROUP BY t.faculty, t.semester, a.question_id ORDER BY t.faculty, t.semester'
        );
        $rows->execute([$assessmentId, $org]);
        $groups = [];
        $sum = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $faculty = $r['faculty'] === null ? null : (string) $r['faculty'];
            $semester = $r['semester'] === null ? null : (int) $r['semester'];
            $key = ($faculty ?? '') . '|' . ($semester ?? '');
            $people = (int) $r['people'];
            $question = (int) $r['question_id'];
            $groups[$key] ??= ['faculty' => $faculty, 'semester' => $semester, 'people' => 0, 'means' => []];
            $groups[$key]['people'] = max($groups[$key]['people'], $people);
            $groups[$key]['means'][$question] = round((float) $r['mean'], 2);
            $sum[$question] ??= [0.0, 0];
            $sum[$question][0] += (float) $r['mean'] * $people;
            $sum[$question][1] += $people;
        }
        $overall = [];
        foreach ($sum as $question => [$total, $count]) {
            $overall[$question] = $count === 0 ? 0.0 : round($total / $count, 2);
        }
        $people = array_sum(array_map(static fn (array $g): int => $g['people'], $groups));

        return [
            'assessment' => $assessment,
            'questions' => array_values(array_map(
                static fn (array $q): array => ['id' => (int) $q['id'],
                'prompt' => (string) $q['prompt'], 'max_points' => (int) $q['max_points']],
                $questions->fetchAll(PDO::FETCH_ASSOC)
            )),
            'overall' => ['people' => $people, 'means' => $overall],
            'groups' => array_values($groups),
        ];
    }

    /**
     * The people who took it, latest attempt each, newest first. No reflection text here.
     *
     * @return list<array<string, mixed>>
     */
    public function people(int $org, int $assessmentId, ?string $faculty, ?int $semester): array
    {
        $this->assessment($org, $assessmentId);
        $stmt = $this->pdo->prepare(
            'SELECT t.id AS attempt_id, u.id AS user_id, u.display_name, u.matric_no, t.faculty, t.semester, t.score,'
            . ' t.max_score, t.band_label, t.created_at, t.reflection_answers IS NOT NULL AS has_reflections,'
            . ' l.profile_url AS linkedin_url'
            . ' FROM assessment_attempt t JOIN `user` u ON u.id = t.user_id'
            . ' LEFT JOIN linkedin_account l ON l.user_id = u.id'
            . ' WHERE t.assessment_id = ? AND t.organisation_id = ? AND ' . self::LATEST
            . ' AND (? IS NULL OR t.faculty = ?) AND (? IS NULL OR t.semester = ?)'
            . ' ORDER BY t.created_at DESC, t.id DESC LIMIT 500'
        );
        $stmt->execute([$assessmentId, $org, $faculty, $faculty, $semester, $semester]);

        return array_values(array_map(static function (array $r): array {
            $max = (int) $r['max_score'];

            return [
                'attempt_id' => (int) $r['attempt_id'],
                'user_id' => (int) $r['user_id'],
                'display_name' => (string) $r['display_name'],
                'matric_no' => $r['matric_no'],
                'linkedin_url' => $r['linkedin_url'],
                'faculty' => $r['faculty'],
                'semester' => $r['semester'] === null ? null : (int) $r['semester'],
                'percent' => $max === 0 ? 0 : intdiv((int) $r['score'] * 100, $max),
                'band' => $r['band_label'],
                'taken_at' => (string) $r['created_at'],
                'has_reflections' => (bool) $r['has_reflections'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * One attempt as its print view shows it. Only the taker may read their own attempt here.
     *
     * @param Actor $user
     * @return array<string, mixed>
     */
    public function ownReport(array $user, int $attemptId): array
    {
        return $this->report($user['organisation_id'], $attemptId, $user['id']);
    }

    /**
     * One person's attempt with their reflections, for CPDC. Every read is written to the audit log.
     *
     * @param Actor $admin
     * @return array<string, mixed>
     */
    public function readReflections(array $admin, int $attemptId): array
    {
        $report = $this->report($admin['organisation_id'], $attemptId, null);
        Audit::log($this->pdo, $admin, 'assessment.reflections.view', "assessment_attempt:$attemptId", [
            'assessment_id' => $report['assessment_id'], 'user_id' => $report['person']['user_id'],
        ]);

        return $report;
    }

    /** @return array<string, mixed> */
    private function report(int $org, int $attemptId, ?int $ownerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.assessment_id, t.user_id, t.score, t.max_score, t.band_label, t.reflection_answers,'
            . ' t.faculty, t.semester, t.created_at, a.title, a.answer_style, a.reflection_prompts,'
            . ' u.display_name, u.matric_no, p.programme'
            . ' FROM assessment_attempt t JOIN assessment a ON a.id = t.assessment_id JOIN `user` u ON u.id = t.user_id'
            . ' LEFT JOIN career_profile p ON p.user_id = t.user_id'
            . ' WHERE t.id = ? AND t.organisation_id = ? AND (? IS NULL OR t.user_id = ?)'
        );
        $stmt->execute([$attemptId, $org, $ownerId, $ownerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }
        $answers = $this->pdo->prepare(
            'SELECT q.prompt, o.label, o.points,'
            . ' (SELECT MAX(o2.points) FROM assessment_option o2 WHERE o2.question_id = q.id) AS max_points'
            . ' FROM assessment_answer x JOIN assessment_question q ON q.id = x.question_id'
            . ' JOIN assessment_option o ON o.id = x.option_id WHERE x.attempt_id = ? ORDER BY q.position, q.id'
        );
        $answers->execute([$attemptId]);
        $prompts = Reflections::decodeList($row['reflection_prompts']);
        $written = Reflections::decodeList($row['reflection_answers']);
        $max = (int) $row['max_score'];

        return [
            'attempt_id' => (int) $row['id'],
            'assessment_id' => (int) $row['assessment_id'],
            'title' => (string) $row['title'],
            'answer_style' => (string) $row['answer_style'],
            'taken_at' => (string) $row['created_at'],
            'person' => [
                'user_id' => (int) $row['user_id'],
                'display_name' => (string) $row['display_name'],
                'matric_no' => $row['matric_no'],
                'faculty' => $row['faculty'],
                'programme' => $row['programme'],
                'semester' => $row['semester'] === null ? null : (int) $row['semester'],
            ],
            'traits' => array_values(array_map(static fn (array $r): array => ['prompt' => (string) $r['prompt'],
                'answer' => (string) $r['label'], 'points' => (int) $r['points'],
                'max_points' => (int) $r['max_points']], $answers->fetchAll(PDO::FETCH_ASSOC))),
            'score' => (int) $row['score'],
            'max_score' => $max,
            'percent' => $max === 0 ? 0 : intdiv((int) $row['score'] * 100, $max),
            'band' => $row['band_label'],
            'reflections' => array_map(
                static fn (string $prompt, ?string $answer): array => ['prompt' => $prompt, 'answer' => $answer ?? ''],
                $prompts,
                array_pad(array_slice($written, 0, count($prompts)), count($prompts), null)
            ),
        ];
    }

    /** @return array{id: int, title: string} */
    private function assessment(int $org, int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, title FROM assessment WHERE id = ? AND organisation_id = ?');
        $stmt->execute([$id, $org]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }

        return ['id' => (int) $row['id'], 'title' => (string) $row['title']];
    }
}

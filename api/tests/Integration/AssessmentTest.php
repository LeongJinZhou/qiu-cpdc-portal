<?php

declare(strict_types=1);

namespace Cpdc\Tests\Integration;

/** Self-assessments: CPDC writes and publishes, students take, the server scores (docs/SPEC.md section 9). */
final class AssessmentTest extends HttpTestCase
{
    use SignsInAs;

    private const ASSESSMENT = [
        'title' => 'Fake Interview Readiness',
        'description' => 'Five minutes on how ready you are for interviews.',
        'bands' => [['min_percent' => 70, 'label' => 'Ready'], ['min_percent' => 0, 'label' => 'Getting there']],
        'questions' => [
            ['prompt' => 'Have you practised answering common questions?', 'options' => [
                ['label' => 'Not yet', 'points' => 0], ['label' => 'Once or twice', 'points' => 2],
                ['label' => 'Often', 'points' => 4],
            ]],
            ['prompt' => 'Do you research the employer first?', 'options' => [
                ['label' => 'No', 'points' => 0], ['label' => 'Yes', 'points' => 4],
            ]],
        ],
    ];

    /** @return array<string, mixed> the published assessment as its editor sees it */
    private function published(): array
    {
        $this->signInAs('admin', 'admin');
        $created = $this->json('POST', '/api/assessments/manage', self::ASSESSMENT);
        $this->assertSame(201, $created->status, $created->body);
        $id = $this->body($created)['id'];
        $this->assertSame('draft', $this->body($created)['status']);
        $published = $this->json('PATCH', "/api/assessments/manage/$id/status", ['status' => 'published']);
        $this->assertSame('published', $this->body($published)['status']);

        return $this->body($published);
    }

    /**
     * Published assessments the signed-in person can take, without the one migration 0505 seeds.
     *
     * @return list<array<string, mixed>>
     */
    private function available(): array
    {
        $items = $this->body($this->send('GET', '/api/assessments'))['items'];

        return array_values(array_filter(
            $items,
            static fn (array $a): bool => $a['title'] !== 'Student Characteristics Self-Assessment'
        ));
    }

    /**
     * @param array<string, mixed> $assessment
     * @param list<int> $picks option index per question
     * @return list<array{question_id: int, option_id: int}>
     */
    private function answers(array $assessment, array $picks): array
    {
        $out = [];
        foreach ($picks as $i => $pick) {
            $q = $assessment['questions'][$i];
            $out[] = ['question_id' => $q['id'], 'option_id' => $q['options'][$pick]['id']];
        }

        return $out;
    }

    public function testAStudentTakesAPublishedAssessmentAndTheServerScoresIt(): void
    {
        $a = $this->published();
        $id = $a['id'];
        $this->assertSame(1, $this->countRows(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'assessment.create' AND target = 'assessment:$id'"
        ));

        $this->signInAs('student');
        $list = $this->available();
        $shown = array_map(fn ($x) => [$x['id'], $x['question_count'], $x['latest']], $list);
        $this->assertSame([[$id, 2, null]], $shown);
        $taking = $this->send('GET', "/api/assessments/$id");
        $this->assertSame(200, $taking->status);
        $this->assertStringNotContainsString('points', $taking->body, 'a taker never sees the points');
        $this->assertStringNotContainsString('Ready', $taking->body, 'nor the bands');

        $result = $this->json('POST', "/api/assessments/$id/attempts", ['answers' => $this->answers($a, [1, 1])]);
        $this->assertSame(201, $result->status, $result->body);
        $this->assertSame([6, 8, 75, 'Ready'], array_values(array_intersect_key(
            $this->body($result),
            array_flip(['score', 'max_score', 'percent', 'band'])
        )));
        $again = $this->json('POST', "/api/assessments/$id/attempts", ['answers' => $this->answers($a, [0, 1])]);
        $this->assertSame('Getting there', $this->body($again)['band']);

        $results = $this->body($this->send('GET', '/api/assessments/results'))['items'];
        $this->assertCount(1, $results, 'only the latest attempt per assessment');
        $this->assertSame(50, $results[0]['percent']);
        $this->assertSame(50, $this->available()[0]['latest']['percent']);

        $this->signInAs('admin', 'admin');
        $row = $this->body($this->send('GET', '/api/assessments/manage'))['items'][0];
        $this->assertSame([2, 1, 63], [$row['attempt_count'], $row['people_count'], $row['average_percent']]);
    }

    public function testEveryQuestionNeedsExactlyOneOfItsOwnOptions(): void
    {
        $a = $this->published();
        $id = $a['id'];
        $this->signInAs('student');
        $q = $a['questions'];
        $cases = [
            [['question_id' => $q[0]['id'], 'option_id' => $q[0]['options'][0]['id']]],
            [['question_id' => $q[0]['id'], 'option_id' => $q[1]['options'][0]['id']],
                ['question_id' => $q[1]['id'], 'option_id' => $q[1]['options'][0]['id']]],
            [['question_id' => $q[0]['id'], 'option_id' => $q[0]['options'][0]['id']],
                ['question_id' => $q[0]['id'], 'option_id' => $q[0]['options'][1]['id']]],
            [['question_id' => 999999, 'option_id' => 1],
                ['question_id' => $q[1]['id'], 'option_id' => $q[1]['options'][0]['id']]],
        ];
        foreach ($cases as $i => $answers) {
            $this->refused('POST', "/api/assessments/$id/attempts", ['answers' => $answers], 400, 'VALIDATION');
            $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM assessment_attempt'), "case $i");
        }
        $withScore = ['answers' => $this->answers($a, [0, 0]), 'score' => 8];
        $this->refused('POST', "/api/assessments/$id/attempts", $withScore, 400, 'VALIDATION');
    }

    public function testATakenAssessmentIsFrozenAndArchivingHidesItButKeepsResults(): void
    {
        $a = $this->published();
        $id = $a['id'];
        $v2 = ['title' => 'Fake Interview Readiness v2'] + self::ASSESSMENT;
        $edited = $this->json('PUT', "/api/assessments/manage/$id", $v2);
        $this->assertSame($v2['title'], $this->body($edited)['title'], 'editable before anyone took it');
        $a = $this->body($edited);
        $this->signInAs('student');
        $this->json('POST', "/api/assessments/$id/attempts", ['answers' => $this->answers($a, [2, 1])]);

        $this->signInAs('admin', 'admin');
        $this->assertTrue($this->body($this->send('GET', "/api/assessments/manage/$id"))['locked']);
        $this->refused('PUT', "/api/assessments/manage/$id", self::ASSESSMENT, 409, 'ASSESSMENT_LOCKED');
        $this->refused('PATCH', "/api/assessments/manage/$id/status", ['status' => 'draft'], 409, 'ASSESSMENT_LOCKED');
        $this->json('PATCH', "/api/assessments/manage/$id/status", ['status' => 'archived']);

        $this->signInAs('student');
        $this->assertSame([], $this->available());
        $this->assertError($this->send('GET', "/api/assessments/$id"), 404, 'NOT_FOUND');
        $late = ['answers' => $this->answers($a, [0, 0])];
        $this->refused('POST', "/api/assessments/$id/attempts", $late, 404, 'NOT_FOUND');
        $this->assertSame(100, $this->body($this->send('GET', '/api/assessments/results'))['items'][0]['percent']);
    }

    public function testBandsNeedAStartAtZeroAndQuestionsNeedTwoOptions(): void
    {
        $this->signInAs('admin', 'admin');
        $noZero = ['bands' => [['min_percent' => 50, 'label' => 'Half']]] + self::ASSESSMENT;
        $this->assertError($this->json('POST', '/api/assessments/manage', $noZero), 400, 'VALIDATION');
        $twice = ['bands' => [['min_percent' => 0, 'label' => 'A'], ['min_percent' => 0, 'label' => 'B']]]
            + self::ASSESSMENT;
        $this->assertError($this->json('POST', '/api/assessments/manage', $twice), 400, 'VALIDATION');
        $one = ['questions' => [['prompt' => 'Only one?', 'options' => [['label' => 'Yes', 'points' => 1]]]]]
            + self::ASSESSMENT;
        $this->assertError($this->json('POST', '/api/assessments/manage', $one), 400, 'VALIDATION');
        $tooMany = self::ASSESSMENT;
        $tooMany['questions'][0]['options'][0]['points'] = 11;
        $this->assertError($this->json('POST', '/api/assessments/manage', $tooMany), 400, 'VALIDATION');
        $this->assertError($this->json('GET', '/api/assessments/manage/999999'), 404, 'NOT_FOUND');
    }

    public function testOnlyCpdcWritesAndOnlyStudentsAndAlumniTake(): void
    {
        $this->assertError($this->send('GET', '/api/assessments'), 401, 'UNAUTHENTICATED');
        $a = $this->published();
        $this->signInAs('student');
        $this->assertError($this->send('GET', '/api/assessments/manage'), 403, 'FORBIDDEN');
        $this->assertError($this->json('POST', '/api/assessments/manage', self::ASSESSMENT), 403, 'FORBIDDEN');
        $status = "/api/assessments/manage/{$a['id']}/status";
        $this->refused('PATCH', $status, ['status' => 'archived'], 403, 'FORBIDDEN');
        $this->signInAs('staff');
        $this->assertError($this->send('GET', '/api/assessments'), 403, 'FORBIDDEN');
        $this->assertError($this->send('GET', '/api/assessments/results'), 403, 'FORBIDDEN');
        $this->signInAs('graduate', 'alumni');
        $this->assertSame(1, count($this->available()));
    }
}

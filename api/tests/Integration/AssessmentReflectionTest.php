<?php

declare(strict_types=1);

namespace Cpdc\Tests\Integration;

use Cpdc\Http\Response;

/**
 * Slice B self-assessment upgrade: 1-5 ratings, written reflections, the print report, CPDC trait averages and
 * audited reads of reflections. Uses the Student Characteristics Self-Assessment seeded by migration 0505.
 */
final class AssessmentReflectionTest extends HttpTestCase
{
    use SignsInAs;

    private const REFLECTIONS = ['Fake: I will join one club this semester.', 'Fake: more mentoring, please.'];

    private function characteristics(): int
    {
        return (int) $this->sql(
            "SELECT id FROM assessment WHERE title = 'Student Characteristics Self-Assessment'"
        )->fetchColumn();
    }

    /**
     * One rating per trait, by points 1-5.
     *
     * @param array<string, mixed> $taking
     * @param list<int> $points
     * @return list<array{question_id: int, option_id: int}>
     */
    private function ratings(array $taking, array $points): array
    {
        $out = [];
        foreach ($taking['questions'] as $i => $q) {
            $out[] = ['question_id' => $q['id'], 'option_id' => $q['options'][$points[$i] - 1]['id']];
        }

        return $out;
    }

    /**
     * @param list<int> $points
     * @param list<string> $reflections
     */
    private function take(array $points, array $reflections = self::REFLECTIONS, ?int $semester = 3): Response
    {
        $id = $this->characteristics();
        $taking = $this->body($this->send('GET', "/api/assessments/$id"));

        return $this->json('POST', "/api/assessments/$id/attempts", [
            'answers' => $this->ratings($taking, $points), 'reflections' => $reflections, 'semester' => $semester,
        ]);
    }

    public function testTheSeededAssessmentRatesNineTraitsOneToFiveWithTwoReflections(): void
    {
        $this->signInAs('student', 'student');
        $taking = $this->body($this->send('GET', '/api/assessments/' . $this->characteristics()));
        $this->assertSame('likert', $taking['answer_style']);
        $this->assertCount(9, $taking['questions']);
        $this->assertSame(['Very Poor', 'Poor', 'Average', 'Good', 'Excellent'], array_column(
            $taking['questions'][0]['options'],
            'label'
        ));
        $this->assertSame([
            'How will you commit to improving your characteristics?', 'How can QIU assist you on this journey?',
        ], $taking['reflection_prompts']);
        $this->assertStringContainsString('Resilience', $taking['questions'][0]['prompt']);
    }

    public function testReflectionsAreRequiredAndSavedWithTheAttemptWhileScoringStaysOnTheServer(): void
    {
        $this->signInAs('student', 'student');
        $this->assertError($this->take([5, 5, 5, 5, 5, 5, 5, 5, 5], []), 400, 'VALIDATION');
        $this->assertError($this->take([5, 5, 5, 5, 5, 5, 5, 5, 5], ['Only one']), 400, 'VALIDATION');
        $this->assertError($this->take([5, 5, 5, 5, 5, 5, 5, 5, 5], ['Fine', '  ']), 400, 'VALIDATION');
        $this->assertError($this->take([5, 5, 5, 5, 5, 5, 5, 5, 5], self::REFLECTIONS, 13), 400, 'VALIDATION');
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM assessment_attempt'));

        $result = $this->take([5, 4, 4, 3, 5, 4, 4, 3, 4]);
        $this->assertSame(201, $result->status, $result->body);
        $body = $this->body($result);
        $this->assertSame([36, 45, 80, 'Good'], [$body['score'], $body['max_score'], $body['percent'], $body['band']]);
        $row = $this->sql('SELECT reflection_answers, faculty, semester FROM assessment_attempt')
            ->fetch(\PDO::FETCH_ASSOC) ?: [];
        $this->assertSame(self::REFLECTIONS, json_decode((string) $row['reflection_answers'], true));
        $this->assertSame(['Faculty of Fake Studies', 3], [$row['faculty'], (int) $row['semester']]);
        $results = $this->body($this->send('GET', '/api/assessments/results'))['items'];
        $this->assertSame($body['attempt_id'], $results[0]['attempt_id']);
    }

    public function testThePrintReportIsTheTakersOwnOnly(): void
    {
        $this->signInAs('student', 'student');
        $attempt = $this->body($this->take([1, 2, 3, 4, 5, 1, 2, 3, 4]))['attempt_id'];
        $report = $this->send('GET', "/api/assessments/attempts/$attempt");
        $this->assertSame(200, $report->status, $report->body);
        $r = $this->body($report);
        $this->assertSame(['Fake Student', 'FAKE-S-0001', 'Faculty of Fake Studies', 3], [$r['person']['display_name'],
            $r['person']['matric_no'], $r['person']['faculty'], $r['person']['semester']]);
        $this->assertSame(['Very Poor', 1, 5], [$r['traits'][0]['answer'], $r['traits'][0]['points'],
            $r['traits'][0]['max_points']]);
        $this->assertSame([25, 45, 55, 'Developing'], [$r['score'], $r['max_score'], $r['percent'], $r['band']]);
        $this->assertSame(self::REFLECTIONS, array_column($r['reflections'], 'answer'));

        $this->signInAs('student-b', 'student');
        $this->assertError($this->send('GET', "/api/assessments/attempts/$attempt"), 404, 'NOT_FOUND');
        $this->signInAs('admin', 'admin');
        $this->assertError($this->send('GET', "/api/assessments/attempts/$attempt"), 403, 'FORBIDDEN');
    }

    public function testCpdcSeesTraitAveragesPerFacultyAndSemesterAndEachReflectionReadIsAudited(): void
    {
        $this->signInAs('student', 'student');
        $this->take([1, 1, 1, 1, 1, 1, 1, 1, 1], self::REFLECTIONS, 2);
        $this->take([5, 5, 5, 5, 5, 5, 5, 5, 5], self::REFLECTIONS, 2); // only the latest attempt counts
        $this->signInAs('student-b', 'student');
        $attempt = $this->body($this->take([3, 3, 3, 3, 3, 3, 3, 3, 3], self::REFLECTIONS, 4))['attempt_id'];

        $this->signInAs('admin', 'admin');
        $id = $this->characteristics();
        $traits = $this->body($this->send('GET', "/api/assessments/manage/$id/traits"));
        $this->assertCount(9, $traits['questions']);
        $this->assertSame(2, $traits['overall']['people']);
        $first = $traits['questions'][0]['id'];
        $this->assertEquals(4.0, $traits['overall']['means'][$first]);
        $groups = array_map(static fn (array $g): array => [$g['faculty'], $g['semester'], $g['people'],
            $g['means'][$first]], $traits['groups']);
        $this->assertEquals([['Faculty of Fake Studies', 2, 1, 5.0], ['Faculty of Other Studies', 4, 1, 3.0]], $groups);

        $people = $this->body($this->send('GET', "/api/assessments/manage/$id/people", '', [
            'faculty' => 'Faculty of Other Studies',
        ]))['items'];
        $this->assertSame([['Fake Other Student', 4, 60, true]], array_map(static fn (array $p): array => [
            $p['display_name'], $p['semester'], $p['percent'], $p['has_reflections'],
        ], $people));
        $this->assertStringNotContainsString('mentoring', json_encode($people) ?: '', 'no reflection text in lists');
        $reads = "SELECT COUNT(*) FROM audit_log WHERE action = 'assessment.reflections.view'";
        $this->assertSame(0, $this->countRows($reads));

        $read = $this->body($this->send('GET', "/api/assessments/manage/attempts/$attempt"));
        $this->assertSame(self::REFLECTIONS, array_column($read['reflections'], 'answer'));
        $this->assertSame(1, $this->countRows(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'assessment.reflections.view'"
            . " AND target = 'assessment_attempt:$attempt'"
        ));
        $this->assertError($this->send('GET', '/api/assessments/manage/attempts/999999'), 404, 'NOT_FOUND');

        $this->signInAs('student', 'student');
        $this->assertError($this->send('GET', "/api/assessments/manage/$id/traits"), 403, 'FORBIDDEN');
        $this->assertError($this->send('GET', "/api/assessments/manage/attempts/$attempt"), 403, 'FORBIDDEN');
    }

    public function testCpdcWritesALikertAssessmentWithReflectionPrompts(): void
    {
        $this->signInAs('admin', 'admin');
        $created = $this->json('POST', '/api/assessments/manage', [
            'title' => 'Fake Rating Check', 'description' => '', 'answer_style' => 'likert',
            'reflection_prompts' => [' What next? '],
            'bands' => [['min_percent' => 0, 'label' => 'Start']],
            'questions' => [['prompt' => 'Fake trait one', 'options' => [['label' => 'x', 'points' => 9],
                ['label' => 'y', 'points' => 0]]]],
        ]);
        $this->assertSame(201, $created->status, $created->body);
        $a = $this->body($created);
        $this->assertSame(['likert', ['What next?']], [$a['answer_style'], $a['reflection_prompts']]);
        $this->assertSame([1, 2, 3, 4, 5], array_column($a['questions'][0]['options'], 'points'), 'standard ratings');
        $same = ['title' => 'Fake Twice', 'description' => '', 'reflection_prompts' => ['A?', 'A?'],
            'bands' => [['min_percent' => 0, 'label' => 'Start']],
            'questions' => [['prompt' => 'Fake trait', 'options' => [['label' => 'x', 'points' => 1],
                ['label' => 'y', 'points' => 0]]]]];
        $this->assertError($this->json('POST', '/api/assessments/manage', $same), 400, 'VALIDATION');
        $style = ['answer_style' => 'stars'] + $same;
        $this->assertError($this->json('POST', '/api/assessments/manage', $style), 400, 'VALIDATION');
    }
}

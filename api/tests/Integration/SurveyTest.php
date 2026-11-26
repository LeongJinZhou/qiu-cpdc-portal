<?php

declare(strict_types=1);

namespace Cpdc\Tests\Integration;

use Cpdc\Http\Response;
use Cpdc\Survey\SurveyForm;

/** The yearly University and Programme Survey (docs/SPEC.md section 13): drafts, submit, N/A, CPDC results. */
final class SurveyTest extends HttpTestCase
{
    use SignsInAs;

    /** CPDC creates a round and opens it. Returns its id. */
    private function openRound(): int
    {
        $this->signInAs('admin', 'admin');
        $created = $this->json('POST', '/api/surveys/manage', [
            'title' => 'Fake Survey 2026/2027', 'academic_year' => '2026/2027',
        ]);
        $this->assertSame(201, $created->status, $created->body);
        $id = (int) $this->body($created)['id'];
        $this->assertSame('draft', $this->body($created)['status']);
        $opened = $this->json('PATCH', "/api/surveys/manage/$id/status", ['status' => 'open']);
        $this->assertSame(200, $opened->status, $opened->body);
        $this->assertTrue($this->body($opened)['is_open']);

        return $id;
    }

    /**
     * Every item rated $value; support items N/A where $naKeys says so.
     *
     * @param list<string> $naKeys
     * @return array<string, int|string>
     */
    private function allAnswers(int $value = 4, array $naKeys = ['ss_hostel']): array
    {
        $answers = [];
        foreach (array_keys(SurveyForm::items()) as $key) {
            $answers[$key] = in_array($key, $naKeys, true) ? 'na' : $value;
        }

        return $answers;
    }

    /**
     * @param array<string, mixed> $answers
     * @param array<string, string> $comments
     */
    private function save(int $id, array $answers, bool $submit, array $comments = []): Response
    {
        return $this->json('PUT', "/api/surveys/$id/response", [
            'programme' => 'Bachelor of Fake Studies', 'semester' => 3, 'answers' => $answers ?: new \stdClass(),
            'comments' => $comments ?: new \stdClass(), 'submit' => $submit,
        ]);
    }

    public function testAStudentSavesADraftResumesItAndSubmitsWithNaOnTheHostel(): void
    {
        $id = $this->openRound();
        $this->signInAs('student', 'student');
        $mine = $this->body($this->send('GET', '/api/surveys'));
        $this->assertCount(7, $mine['form']['sections']);
        $this->assertSame([[$id, 'none', true]], array_map(
            static fn (array $c): array => [$c['id'], $c['my_status'], $c['is_open']],
            $mine['cycles']
        ));
        $start = $this->body($this->send('GET', "/api/surveys/$id/response"));
        $this->assertNull($start['response']);
        $this->assertSame('Faculty of Fake Studies', $start['defaults']['faculty']);

        $draft = $this->save($id, ['pc1' => 5, 'pc2' => 4], false, ['programme' => ' More labs ']);
        $this->assertSame(200, $draft->status, $draft->body);
        $this->assertSame('draft', $this->body($draft)['status']);
        $resumed = $this->body($this->send('GET', "/api/surveys/$id/response"))['response'];
        $this->assertSame(['pc1' => 5, 'pc2' => 4], $resumed['answers']);
        $this->assertSame(['programme' => 'More labs'], $resumed['comments']);
        $this->assertSame('Faculty of Fake Studies', $resumed['faculty'], 'faculty comes from the directory');

        $incomplete = $this->save($id, ['pc1' => 5], true);
        $this->assertError($incomplete, 400, 'SURVEY_INCOMPLETE');
        $this->assertSame('answers.pc2', $this->body($incomplete)['error']['field']);
        $this->assertSame(61, $this->body($incomplete)['error']['missing']);

        $done = $this->save($id, $this->allAnswers(), true, ['support' => 'Fake: no hostel for me']);
        $this->assertSame(200, $done->status, $done->body);
        $this->assertSame('submitted', $this->body($done)['status']);
        $this->assertSame('na', $this->body($done)['answers']['ss_hostel']);
        $this->assertNotNull($this->body($done)['submitted_at']);
        $this->assertError($this->save($id, ['pc1' => 1], false), 409, 'SURVEY_SUBMITTED');
        $this->assertSame('submitted', $this->body($this->send('GET', '/api/surveys'))['cycles'][0]['my_status']);
    }

    public function testSubmittingNeedsTheProgrammeAndSemesterAndNaOnlyFitsSupportServices(): void
    {
        $id = $this->openRound();
        $this->signInAs('student', 'student');
        $noSemester = $this->json('PUT', "/api/surveys/$id/response", [
            'programme' => 'Bachelor of Fake Studies', 'semester' => null, 'answers' => $this->allAnswers(),
            'comments' => new \stdClass(), 'submit' => true,
        ]);
        $this->assertError($noSemester, 400, 'VALIDATION');
        $this->assertSame('semester', $this->body($noSemester)['error']['field']);
        $noProgramme = $this->json('PUT', "/api/surveys/$id/response", [
            'programme' => ' ', 'semester' => 2, 'answers' => $this->allAnswers(),
            'comments' => new \stdClass(), 'submit' => true,
        ]);
        $this->assertSame('programme', $this->body($noProgramme)['error']['field']);
        $this->assertError($this->save($id, ['pc1' => 'na'], false), 400, 'VALIDATION');
        $this->assertError($this->save($id, ['zz1' => 3], false), 400, 'VALIDATION');
        $this->assertError($this->save($id, ['pc1' => 0], false), 400, 'VALIDATION');
        $this->assertError($this->save($id, [], false, ['nickname' => 'x']), 400, 'VALIDATION');
        $this->assertError($this->save($id, [], false, ['programme' => str_repeat('a', 2001)]), 400, 'VALIDATION');
        $extra = $this->json('PUT', "/api/surveys/$id/response", [
            'answers' => new \stdClass(), 'comments' => new \stdClass(), 'submit' => false, 'faculty' => 'Mine',
        ]);
        $this->assertError($extra, 400, 'VALIDATION');
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM survey_response'));
        // Every support item may be N/A: nobody is blocked by services they never used.
        $support = array_column(SurveyForm::SECTIONS[4]['items'], 'key');
        $this->assertSame(200, $this->save($id, $this->allAnswers(3, $support), true)->status);
    }

    public function testADraftOrClosedRoundTakesNoAnswersAndStatusRulesHold(): void
    {
        $this->signInAs('admin', 'admin');
        $draft = (int) $this->body($this->json('POST', '/api/surveys/manage', [
            'title' => 'Fake Draft Round', 'academic_year' => '2027/2028',
        ]))['id'];
        $this->refused('PATCH', "/api/surveys/manage/$draft/status", ['status' => 'closed'], 409, 'SURVEY_STATUS');
        $this->signInAs('student', 'student');
        $this->assertSame([], $this->body($this->send('GET', '/api/surveys'))['cycles']);
        $this->assertError($this->send('GET', "/api/surveys/$draft/response"), 404, 'NOT_FOUND');
        $this->assertError($this->save($draft, ['pc1' => 3], false), 409, 'SURVEY_CLOSED');

        $id = $this->openRound();
        $this->signInAs('student', 'student');
        $this->save($id, ['pc1' => 3], false);
        $this->signInAs('admin', 'admin');
        $this->json('PATCH', "/api/surveys/manage/$id/status", ['status' => 'closed']);
        $this->refused('PATCH', "/api/surveys/manage/$id/status", ['status' => 'closed'], 409, 'SURVEY_STATUS');
        $this->assertSame(2, $this->countRows(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'survey.cycle.status' AND target = 'survey_cycle:$id'"
        ));
        $this->signInAs('student', 'student');
        $this->assertError($this->save($id, ['pc1' => 4], false), 409, 'SURVEY_CLOSED');
        $kept = $this->body($this->send('GET', "/api/surveys/$id/response"));
        $this->assertSame(['pc1' => 3], $kept['response']['answers'], 'a started draft stays readable');
        $this->assertFalse($kept['cycle']['is_open']);

        $this->signInAs('admin', 'admin');
        $closesFirst = $this->json('POST', '/api/surveys/manage', ['title' => 'Fake Bad Dates',
            'academic_year' => '2026/2027', 'opens_at' => '2026-12-02T09:00', 'closes_at' => '2026-12-01T09:00']);
        $this->assertError($closesFirst, 400, 'VALIDATION');
        $badYear = $this->json('POST', '/api/surveys/manage', [
            'title' => 'Fake Bad Year', 'academic_year' => '2026/2030',
        ]);
        $this->assertError($badYear, 400, 'VALIDATION');
    }

    public function testARoundOpensAndClosesOnItsDatesWhenSet(): void
    {
        $this->signInAs('admin', 'admin');
        // $this->now is 2027-01-15 08:00 UTC (16:00 in Malaysia).
        $id = (int) $this->body($this->json('POST', '/api/surveys/manage', [
            'title' => 'Fake Dated Round', 'academic_year' => '2026/2027',
            'opens_at' => '2027-01-15T17:00', 'closes_at' => '2027-01-20T17:00',
        ]))['id'];
        $this->json('PATCH', "/api/surveys/manage/$id/status", ['status' => 'open']);
        $this->signInAs('student', 'student');
        $this->assertError($this->save($id, ['pc1' => 3], false), 409, 'SURVEY_CLOSED');
        $this->now += 3600;
        $this->assertSame(200, $this->save($id, ['pc1' => 3], false)->status);
        $this->now += 6 * 86400;
        $this->signInAs('student', 'student'); // the session from six days ago has expired
        $this->assertError($this->save($id, ['pc1' => 3], false), 409, 'SURVEY_CLOSED');
    }

    public function testCpdcSeesResponseRatesMeansWithoutNaCommentsAndAnAuditedCsv(): void
    {
        $id = $this->openRound();
        $this->signInAs('student', 'student');
        $this->save($id, $this->allAnswers(4), true, ['programme' => '=HYPERLINK("http://evil.example")']);
        $this->signInAs('student-b', 'student');
        $this->save($id, $this->allAnswers(2, []), true, ['skills' => 'Fake: more AI']);
        $this->signInAs('graduate', 'student');
        $this->save($id, ['pc1' => 1], false);

        $this->signInAs('admin', 'admin');
        $row = $this->body($this->send('GET', '/api/surveys/manage'))['items'][0];
        $this->assertSame([2, 1, 3, 67], [$row['submitted_count'], $row['draft_count'], $row['invited_count'],
            $row['response_rate']]);
        $report = $this->body($this->send('GET', "/api/surveys/manage/$id"));
        $this->assertSame(['invited' => 3, 'submitted' => 2, 'rate' => 67], $report['totals']);
        $faculties = array_column($report['by_faculty'], null, 'name');
        $this->assertSame([2, 1, 1, 50], array_values(array_intersect_key(
            $faculties['Faculty of Fake Studies'],
            array_flip(['invited', 'submitted', 'drafts', 'rate'])
        )));
        $this->assertSame(100, $faculties['Faculty of Other Studies']['rate']);
        $this->assertEquals(['n' => 1, 'na' => 1, 'mean' => 2.0], $report['stats']['items']['ss_hostel']);
        $this->assertEquals(['n' => 2, 'na' => 0, 'mean' => 3.0], $report['stats']['items']['pc1']);
        $this->assertEquals(3.0, $report['stats']['sections']['programme']['mean']);

        $comments = $this->body($this->send('GET', "/api/surveys/manage/$id/comments", '', ['section' => 'skills']));
        $this->assertSame(['Fake: more AI'], array_column($comments['items'], 'text'));
        $this->assertStringNotContainsString('Fake Other Student', json_encode($comments) ?: '', 'no names');
        $this->assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'survey.comments.view'"));
        $unknown = $this->send('GET', "/api/surveys/manage/$id/comments", '', ['section' => 'nope']);
        $this->assertError($unknown, 400, 'VALIDATION');

        $csv = $this->send('GET', "/api/surveys/manage/$id/export");
        $this->assertSame(200, $csv->status);
        $this->assertStringContainsString('"\'=HYPERLINK', $csv->body, 'formula escaped');
        $this->assertStringContainsString('"N/A"', $csv->body);
        $this->assertStringNotContainsString('student@example.test', $csv->body);
        $this->assertSame(3, substr_count($csv->body, "\r\n"), 'header and the two submitted responses only');
        $this->assertSame(1, $this->countRows(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'survey.export' AND target = 'survey_cycle:$id'"
        ));
    }

    public function testOnlyStudentsAnswerOnlyCpdcManagesAndEachSeesOnlyTheirOwnResponse(): void
    {
        $this->assertError($this->send('GET', '/api/surveys'), 401, 'UNAUTHENTICATED');
        $id = $this->openRound();
        $this->signInAs('student', 'student');
        $this->save($id, ['pc1' => 5], false);
        $this->assertError($this->send('GET', '/api/surveys/manage'), 403, 'FORBIDDEN');
        $this->assertError($this->send('GET', "/api/surveys/manage/$id/export"), 403, 'FORBIDDEN');
        $round = ['title' => 'Mine', 'academic_year' => '2026/2027'];
        $this->refused('POST', '/api/surveys/manage', $round, 403, 'FORBIDDEN');
        $this->signInAs('student-b', 'student');
        $this->assertNull($this->body($this->send('GET', "/api/surveys/$id/response"))['response'], 'own only');
        foreach (['staff' => 'staff', 'graduate' => 'alumni'] as $persona => $role) {
            $this->signInAs($persona, $role);
            $this->assertError($this->send('GET', '/api/surveys'), 403, 'FORBIDDEN');
            $this->assertError($this->save($id, ['pc1' => 5], false), 403, 'FORBIDDEN');
        }
        $this->signInAs('graduate', 'pending');
        $this->assertError($this->send('GET', '/api/surveys'), 403, 'FORBIDDEN');
    }
}

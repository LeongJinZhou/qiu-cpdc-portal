<?php

declare(strict_types=1);

namespace Cpdc\Tests\Integration;

/** Slice B: the home overview (GET /api/home) and the CV builder upgrades. */
final class HomeAndCvTest extends ModuleTestCase
{
    /** @return int a published workshop starting tomorrow (as the signed-in CPDC user) */
    private function workshop(string $title, string $day = '2027-01-16'): int
    {
        $made = $this->json('POST', '/api/workshops', [
            'title' => $title, 'description' => 'Fake.', 'starts_at' => $day . 'T10:00', 'ends_at' => $day . 'T12:00',
            'mode' => 'in_person', 'venue' => 'Fake Hall', 'capacity' => 30, 'audience' => ['student'],
        ]);
        $this->assertSame(201, $made->status, $made->body);

        return (int) $this->body($made)['id'];
    }

    /**
     * @param array<string, mixed> $home
     * @return array<string, bool> task key => done
     */
    private function tasks(array $home): array
    {
        return array_column($home['tasks'], 'done', 'key');
    }

    public function testAStudentSeesWorkshopsTasksAndApplicationsInOneAnswer(): void
    {
        $this->signInAs('admin', 'admin');
        $talk = $this->workshop('Fake CV clinic');
        $this->workshop('Fake later talk', '2027-01-20');
        $job = $this->openJob('Fake Data Intern');
        $this->signInAs('student', 'student');
        $this->json('POST', "/api/workshops/$talk/rsvp");
        $this->assertSame(201, $this->upload("/api/jobs/$job/apply", ['note' => 'Fake note'])->status);

        $home = $this->body($this->send('GET', '/api/home'));
        $this->assertSame(['Fake CV clinic', 'Fake later talk'], array_column($home['workshops'], 'title'));
        $this->assertSame('going', $home['workshops'][0]['my_rsvp']['status']);
        $this->assertNull($home['workshops'][1]['my_rsvp']);
        $this->assertSame(1, $home['applications']['active']);
        $this->assertSame([['Fake Data Intern', 'submitted']], array_map(
            static fn (array $a): array => [$a['title'], $a['status']],
            $home['applications']['latest']
        ));
        $this->assertSame(
            ['linkedin' => false, 'profile' => false, 'cv' => false, 'assessment' => false],
            $this->tasks($home),
            'no survey open, no internship, satisfaction survey not wired yet'
        );

        $this->json('PUT', '/api/cv', $this->cvBody(['summary' => 'Fake summary']));
        $profile = $this->json('PUT', '/api/profile', ['fields' => ['headline' => 'Fake headline']]);
        $this->assertSame(200, $profile->status);
        $tasks = $this->tasks($this->body($this->send('GET', '/api/home')));
        $this->assertTrue($tasks['cv']);
        $this->assertTrue($tasks['profile']);
    }

    public function testOpenWorkWaitingAppearsAsTasks(): void
    {
        $this->signInAs('admin', 'admin');
        $round = (int) $this->body($this->json('POST', '/api/surveys/manage', [
            'title' => 'Fake Round', 'academic_year' => '2026/2027',
        ]))['id'];
        $this->json('PATCH', "/api/surveys/manage/$round/status", ['status' => 'open']);
        $this->signInAs('student', 'student');
        $this->json('PUT', '/api/logbook/internship', ['company' => 'Fake Co', 'supervisor_name' => 'Fake Boss',
            'supervisor_email' => 'boss@fakeco.example', 'start_date' => '2027-01-01', 'end_date' => '2027-03-31']);
        $home = $this->body($this->send('GET', '/api/home'));
        $tasks = array_column($home['tasks'], null, 'key');
        $this->assertFalse($tasks['survey']['done']);
        $this->assertFalse($tasks['logbook']['done']);
        $this->assertSame('3 weeks not sent yet.', $tasks['logbook']['detail'], 'weeks of 29 Dec, 5 and 12 Jan');
        $this->assertSame('/surveys', $tasks['survey']['link']);
    }

    public function testTheSatisfactionSurveyTaskAppearsOnlyOnceItIsWired(): void
    {
        $id = $this->signInAs('student', 'student');
        $user = ['id' => $id, 'organisation_id' => 1, 'role' => 'student'];
        $settings = \Cpdc\Settings\Settings::load($this->pdo, 1);
        $access = new \Cpdc\Auth\Access($this->pdo);
        $unwired = new \Cpdc\Home\HomeService($this->pdo, $access, $settings);
        $this->assertNull($unwired->satisfactionSurveyPending($user));
        $wired = new \Cpdc\Home\HomeService($this->pdo, $access, $settings, static fn (array $u): bool => true);
        $this->assertTrue($wired->satisfactionSurveyPending($user));
        $tasks = array_column($wired->overview($user, $this->now)['tasks'], 'done', 'key');
        $this->assertFalse($tasks['satisfaction']);
        $staff = ['id' => $id, 'organisation_id' => 1, 'role' => 'staff'];
        $this->assertNull((new \Cpdc\Home\HomeService(
            $this->pdo,
            new \Cpdc\Auth\Access($this->pdo),
            $settings,
            static fn (array $u): bool => true
        ))->satisfactionSurveyPending($staff), 'interns only');
    }

    public function testStaffGetAnOverviewWithoutStudentTasksAndPendingGetsNothing(): void
    {
        $this->signInAs('staff', 'staff');
        $home = $this->body($this->send('GET', '/api/home'));
        $this->assertNull($home['applications']);
        $this->assertSame([], $home['workshops']);
        $this->assertSame(['linkedin', 'profile', 'cv'], array_keys($this->tasks($home)));
        $this->signInAs('graduate', 'pending');
        $this->assertError($this->send('GET', '/api/home'), 403, 'FORBIDDEN');
    }

    public function testTheCvKeepsCgpaCoursesAwardsActivitiesReferencesAndConsent(): void
    {
        $this->signInAs('student', 'student');
        $own = $this->learning('Fake verified course', 'verified');
        $pending = $this->learning('Fake unchecked course', 'submitted');
        $this->signInAs('student-b', 'student');
        $foreign = $this->learning('Fake other course', 'verified');

        $this->signInAs('student', 'student');
        $cv = $this->body($this->send('GET', '/api/cv'));
        $this->assertSame([$own], array_column($cv['verified_learning'], 'id'), 'own verified records only');
        $this->assertSame([], $cv['cv']['awards']);
        $this->assertFalse($cv['cv']['employer_sharing_consent']);

        $saved = $this->json('PUT', '/api/cv', $this->cvBody([
            'academic' => ['show_cgpa' => true, 'cgpa' => '3.75', 'intake_period' => ' September 2024 '],
            'courses_taken' => ['Data Structures', ' ', 'Databases'],
            'awards' => [
                ['kind' => 'deans_list', 'title' => "Dean's List", 'period' => 'Sem 2 2025'],
                ['kind' => 'certificate', 'title' => 'Fake verified course', 'learning_id' => $own],
                ['kind' => 'certificate', 'title' => 'Fake unchecked', 'learning_id' => $pending],
                ['kind' => 'certificate', 'title' => 'Fake other', 'learning_id' => $foreign],
            ],
            'cocurricular' => [
                ['activity' => 'Fake Robotics Club', 'role_and_details' => 'Treasurer', 'period' => '2025'],
            ],
            'references' => [['name' => 'Fake Lecturer', 'designation' => 'Senior Lecturer', 'company' => 'QIU',
                'phone' => '+60 5-000 0000', 'email' => 'lecturer@example.test']],
            'employer_sharing_consent' => true,
        ]));
        $this->assertSame(200, $saved->status, $saved->body);
        $body = $this->body($saved);
        $this->assertEquals(
            ['show_cgpa' => true, 'cgpa' => '3.75', 'intake_period' => 'September 2024'],
            $body['cv']['academic'],
            'MySQL may reorder JSON keys'
        );
        $this->assertSame(['Data Structures', 'Databases'], $body['cv']['courses_taken']);
        $this->assertSame([null, $own, null, null], array_map(
            static fn (array $a): ?int => $a['learning_id'] ?? null,
            $body['cv']['awards']
        ), 'only an own verified record stays linked');
        $this->assertTrue($body['cv']['employer_sharing_consent']);
        $consentAt = $body['employer_sharing_consent_at'];
        $this->assertNotNull($consentAt);

        $this->now += 60;
        $again = $this->body($this->json('PUT', '/api/cv', $this->cvBody(['employer_sharing_consent' => true])));
        $this->assertSame($consentAt, $again['employer_sharing_consent_at'], 'the first consent time is kept');
        $off = $this->body($this->json('PUT', '/api/cv', $this->cvBody(['employer_sharing_consent' => false])));
        $this->assertNull($off['employer_sharing_consent_at']);
    }

    public function testTheCvRefusesUnknownKeysAndOverLongLists(): void
    {
        $this->signInAs('student', 'student');
        $bad = [
            ['awards' => [['kind' => 'medal', 'title' => 'x']]],
            ['awards' => [['kind' => 'award', 'title' => 'x', 'html' => '<b>']]],
            ['academic' => ['show_cgpa' => true, 'cgpa' => '4.5x', 'intake_period' => '']],
            ['courses_taken' => array_fill(0, 11, 'Course')],
            ['references' => array_fill(0, 3, ['name' => 'A', 'designation' => '', 'company' => '', 'phone' => '',
                'email' => ''])],
            ['references' => [['name' => 'A', 'designation' => '', 'company' => '', 'phone' => 'call me',
                'email' => '']]],
            ['cocurricular' => [['activity' => 'Club', 'role' => 'x']]],
            ['employer_sharing_consent' => 'yes'],
        ];
        foreach ($bad as $i => $override) {
            $this->assertError($this->json('PUT', '/api/cv', $this->cvBody($override)), 400, 'VALIDATION');
            $this->assertSame(0, $this->countCv(), "case $i");
        }
    }

    private function countCv(): int
    {
        return (int) $this->sql('SELECT COUNT(*) FROM cv_document')->fetchColumn();
    }

    /** @return int a learning record of the signed-in person, with its status set directly */
    private function learning(string $name, string $status): int
    {
        $made = $this->upload('/api/learning', ['kind' => 'course', 'name' => $name, 'provider' => 'Fake Provider',
            'completed_on' => '2026-06-01', 'link_url' => 'https://example.test/fake']);
        $this->assertSame(201, $made->status, $made->body);
        $id = (int) $this->body($made)['id'];
        $this->pdo->prepare('UPDATE learning_record SET status = ? WHERE id = ?')->execute([$status, $id]);

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function cvBody(array $overrides = []): array
    {
        return $overrides + [
            'contact' => ['email' => '', 'phone' => '', 'location' => '', 'website' => ''],
            'headline' => '', 'summary' => '', 'hidden_experience' => [], 'hidden_education' => [],
            'show_skills' => true, 'sections' => [],
        ];
    }
}

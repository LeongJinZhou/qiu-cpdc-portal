<?php

declare(strict_types=1);

namespace Cpdc\Home;

use Cpdc\Auth\Access;
use Cpdc\Http\Paginator;
use Cpdc\Jobs\JobService;
use Cpdc\Logbook\Weeks;
use Cpdc\Settings\Settings;
use Cpdc\Survey\SurveyService;
use Cpdc\Workshops\WorkshopService;
use PDO;

/**
 * The home page in one answer: upcoming workshops, what the person still has to do, and their job applications.
 * Each part appears only when the person holds the permission of its module, so staff and alumni get an overview
 * that fits them. Everything read here is the person's own.
 *
 * @phpstan-type User array{id: int, organisation_id: int, role: string, ...}
 * @phpstan-type Task array{key: string, label: string, done: bool, detail: string, link: string}
 */
final class HomeService
{
    private const WORKSHOPS = 5;
    private const APPLICATIONS = 3;

    /**
     * @param (\Closure(User): bool)|null $satisfactionPending whether the person still owes the post-internship
     *     satisfaction survey; null until slice A's table is wired in (see satisfactionSurveyPending)
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Access $access,
        private readonly Settings $settings,
        private readonly ?\Closure $satisfactionPending = null
    ) {
    }

    /**
     * @param User $user
     * @return array{workshops: list<array<string, mixed>>|null, tasks: list<Task>,
     *     applications: array{active: int, latest: list<array<string, mixed>>}|null}
     */
    public function overview(array $user, int $now): array
    {
        return [
            'workshops' => $this->can($user, 'workshops.view') ? $this->workshops($user, $now) : null,
            'tasks' => $this->tasks($user, $now),
            'applications' => $this->can($user, 'jobs.browse') ? $this->applications($user) : null,
        ];
    }

    /**
     * Slice A adds the post-internship satisfaction survey (table internship_satisfaction). After the merge the lead
     * passes $satisfactionPending (HomeController); until then this answers null, "not known", and the home page
     * leaves the task out.
     *
     * @param User $user
     */
    public function satisfactionSurveyPending(array $user): ?bool
    {
        return $this->satisfactionPending === null || !$this->can($user, 'logbook.own')
            ? null
            : ($this->satisfactionPending)($user);
    }

    /**
     * @param User $user
     * @return list<array<string, mixed>>
     */
    private function workshops(array $user, int $now): array
    {
        $zone = $this->settings->string('display_timezone');
        $page = Paginator::fromQuery(['page' => 1, 'per_page' => self::WORKSHOPS], $this->settings);
        $list = (new WorkshopService($this->pdo, $zone))->list($user, false, false, $page, $now);

        return array_map(static fn (mixed $w): array => is_array($w) ? [
            'id' => $w['id'], 'title' => $w['title'], 'starts_at' => $w['starts_at'], 'ends_at' => $w['ends_at'],
            'mode' => $w['mode'], 'venue' => $w['venue'], 'spots_left' => $w['spots_left'],
            'started' => $w['started'], 'my_rsvp' => $w['my_rsvp'],
        ] : [], $list['items']);
    }

    /**
     * @param User $user
     * @return list<Task>
     */
    private function tasks(array $user, int $now): array
    {
        $tasks = [];
        if ($this->can($user, 'profile.own')) {
            $profile = $this->row(
                'SELECT l.user_id IS NOT NULL AS linked, p.last_pdf_import_at, p.headline,'
                . ' (SELECT COUNT(*) FROM career_experience e WHERE e.user_id = u.id) AS jobs'
                . ' FROM `user` u LEFT JOIN linkedin_account l ON l.user_id = u.id'
                . ' LEFT JOIN career_profile p ON p.user_id = u.id WHERE u.id = ? AND u.organisation_id = ?',
                [$user['id'], $user['organisation_id']]
            );
            $imported = $profile['last_pdf_import_at'] !== null;
            $filled = $imported || trim((string) $profile['headline']) !== '' || (int) $profile['jobs'] > 0;
            $tasks[] = $this->task('linkedin', 'Link your LinkedIn account', (bool) $profile['linked'], '', '/profile');
            $hint = $filled ? 'Filled in by hand.' : 'Save your LinkedIn profile as PDF, then import it.';
            $tasks[] = $this->task(
                'profile',
                'Import your LinkedIn PDF into your profile',
                $filled,
                $imported ? '' : $hint,
                '/profile'
            );
        }
        if ($this->can($user, 'cv.own')) {
            $cv = $this->row(
                'SELECT content_json FROM cv_document WHERE user_id = ? AND organisation_id = ?',
                [$user['id'], $user['organisation_id']]
            );
            $content = is_string($cv['content_json'] ?? null) ? json_decode($cv['content_json'], true) : null;
            $done = is_array($content) && trim((string) ($content['summary'] ?? '')) !== '';
            $tasks[] = $this->task('cv', 'Complete your CV', $done, $cv === [] ? 'Not started yet.' : '', '/cv');
        }
        if ($this->can($user, 'logbook.own')) {
            $logbook = $this->logbookTask($user, $now);
            if ($logbook !== null) {
                $tasks[] = $logbook;
            }
        }
        $satisfaction = $this->satisfactionSurveyPending($user);
        if ($satisfaction !== null) {
            $tasks[] = $this->task('satisfaction', 'Rate your internship placement', !$satisfaction, '', '/logbook');
        }
        if ($this->can($user, 'assessment.take')) {
            $open = $this->row(
                "SELECT COUNT(*) AS n FROM assessment a WHERE a.organisation_id = ? AND a.status = 'published'"
                . ' AND NOT EXISTS (SELECT 1 FROM assessment_attempt t WHERE t.assessment_id = a.id AND t.user_id = ?)',
                [$user['organisation_id'], $user['id']]
            );
            $n = (int) ($open['n'] ?? 0);
            $tasks[] = $this->task(
                'assessment',
                'Take your self-assessments',
                $n === 0,
                $n === 0 ? '' : ($n === 1 ? '1 not taken yet.' : "$n not taken yet."),
                '/assessments'
            );
        }
        if ($this->can($user, 'survey.take')) {
            $zone = $this->settings->string('display_timezone');
            $pending = (new SurveyService($this->pdo, $zone))->pendingFor($user, $now);
            if ($pending) {
                $label = 'Answer the University and Programme Survey';
                $tasks[] = $this->task('survey', $label, false, 'Open now.', '/surveys');
            }
        }

        return $tasks;
    }

    /**
     * Started internship weeks not yet sent to the supervisor (or returned); null without an internship.
     *
     * @param User $user
     * @return Task|null
     */
    private function logbookTask(array $user, int $now): ?array
    {
        $internship = $this->row(
            'SELECT i.id, i.start_date, i.end_date,'
            . ' (SELECT COUNT(*) FROM logbook_entry e WHERE e.internship_id = i.id'
            . " AND e.status IN ('submitted', 'approved')) AS sent"
            . ' FROM internship i WHERE i.student_id = ? AND i.organisation_id = ?',
            [$user['id'], $user['organisation_id']]
        );
        if ($internship === []) {
            return null;
        }
        $today = Weeks::today($now, $this->settings->string('display_timezone'));
        $expected = Weeks::expected((string) $internship['start_date'], (string) $internship['end_date'], $today);
        $open = max(0, $expected - (int) $internship['sent']);

        return $this->task(
            'logbook',
            'Send your logbook weeks',
            $open === 0,
            $open === 0 ? '' : ($open === 1 ? '1 week not sent yet.' : "$open weeks not sent yet."),
            '/logbook'
        );
    }

    /**
     * @param User $user
     * @return array{active: int, latest: list<array<string, mixed>>}
     */
    private function applications(array $user): array
    {
        $all = array_values(array_filter(
            (new JobService($this->pdo))->mine($user['organisation_id'], $user['id']),
            static fn (array $a): bool => $a['status'] !== 'withdrawn'
        ));
        $active = array_filter(
            $all,
            static fn (array $a): bool => !in_array($a['status'], ['hired', 'rejected'], true)
        );

        return [
            'active' => count($active),
            'latest' => array_map(static fn (array $a): array => [
                'id' => $a['id'], 'job_id' => $a['job_id'], 'title' => $a['title'],
                'employer_name' => $a['employer_name'], 'status' => $a['status'],
                'status_changed_at' => $a['status_changed_at'],
            ], array_slice($all, 0, self::APPLICATIONS)),
        ];
    }

    /**
     * @param list<int|string> $args
     * @return array<string, mixed> the first row, or [] when there is none
     */
    private function row(string $sql, array $args): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /** @return Task */
    private function task(string $key, string $label, bool $done, string $detail, string $link): array
    {
        return ['key' => $key, 'label' => $label, 'done' => $done, 'detail' => $detail, 'link' => $link];
    }

    /** @param User $user */
    private function can(array $user, string $permission): bool
    {
        return $this->access->can($user, $permission);
    }
}

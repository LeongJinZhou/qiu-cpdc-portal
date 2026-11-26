<?php

declare(strict_types=1);

namespace Cpdc\Analytics;

use Cpdc\Alumni\AlumniService;
use Cpdc\Survey\SurveyForm;
use PDO;

/**
 * CPDC's university-wide numbers (docs/SPEC.md section 15), all computed here from the stored rows: the KPI strip,
 * career readiness and activity, the faculty table and alumni outcomes. Rates are 0-1 with 3 decimals, null when
 * nothing is counted. Every query is scoped to the organisation through AnalyticsFilters.
 */
final class AnalyticsService
{
    private const TOP = 8;
    private const RISK_LIST = 20;
    private const STATUSES = ['employed', 'self_employed', 'further_study', 'seeking', 'not_seeking', 'unknown'];
    private const STAGES = ['submitted', 'reviewing', 'shortlisted', 'interview', 'offered', 'hired', 'rejected',
        'withdrawn'];
    private const JOB_EMPLOYER = ' FROM job_application a JOIN job_posting j ON j.id = a.job_id'
        . ' JOIN employer e ON e.id = j.employer_id';

    /** @param int $now unix time (the App clock) */
    public function __construct(private readonly PDO $pdo, private readonly int $now, private readonly int $confirmDays)
    {
    }

    /** @return array<string, mixed> */
    public function overview(AnalyticsFilters $f): array
    {
        return [
            'kpis' => $this->kpis($f),
            'readiness' => $this->readiness($f),
            'internship' => $this->internship($f),
            'jobs' => $this->jobs($f),
            'growth' => $this->growth($f),
            'faculties' => $this->faculties($f),
            'alumni' => $this->alumni($f),
            'options' => $this->options($f->org),
        ];
    }

    /** @return array<string, int|float|null> */
    private function kpis(AnalyticsFilters $f): array
    {
        $students = $this->people($f, 'student', 'COUNT(*)');
        $alumni = $this->people($f, 'alumni', 'COUNT(*)');
        $linked = 'SUM(EXISTS (SELECT 1 FROM linkedin_account l WHERE l.user_id = u.id))';
        [$people, $args] = $f->people('alumni');
        $work = $this->row(
            "SELECT SUM(COALESCE(p.employment_status, 'unknown') <> 'unknown') AS known,"
            . ' SUM(p.employment_status IN (' . self::inList(AlumniService::EMPLOYED) . ')) AS in_work,'
            . " SUM(COALESCE(p.employment_status, 'unknown') = 'unknown') AS unknown"
            . " FROM `user` u LEFT JOIN career_profile p ON p.user_id = u.id WHERE u.id IN ($people)",
            $args
        );
        [$students2, $sArgs] = $f->people('student');
        [$range, $rArgs] = $f->period('i.start_date');
        $placed = $this->count(
            "SELECT COUNT(*) FROM internship i WHERE i.student_id IN ($students2)$range",
            [...$sArgs, ...$rArgs]
        );
        [$all, $aArgs] = $f->people();
        $offers = $this->row(
            'SELECT COUNT(*) AS answers, SUM(s.offered_job = 1) AS offered FROM internship_satisfaction s'
            . " JOIN internship i ON i.id = s.internship_id WHERE i.student_id IN ($all)$range",
            [...$aArgs, ...$rArgs]
        );

        return [
            'students' => $students,
            'alumni' => $alumni,
            'student_linkedin_rate' => self::rate($this->people($f, 'student', $linked), $students),
            'alumni_linkedin_rate' => self::rate($this->people($f, 'alumni', $linked), $alumni),
            'onboarding_rate' => self::rate($this->people($f, 'student', 'SUM(u.onboarded_at IS NOT NULL)'), $students),
            'alumni_employment_rate' => self::rate((int) $work['in_work'], (int) $work['known']),
            'alumni_known' => (int) $work['known'],
            'alumni_unknown' => (int) $work['unknown'],
            'internship_placement_rate' => self::rate($placed, $students),
            'job_offer_rate' => self::rate((int) $offers['offered'], (int) $offers['answers']),
        ];
    }

    /** @return array<string, int|float|null> */
    private function readiness(AnalyticsFilters $f): array
    {
        $students = $this->people($f, 'student', 'COUNT(*)');
        $sum = fn (string $expr): ?float => self::rate($this->people($f, 'student', "SUM($expr)"), $students);

        return [
            'students' => $students,
            'pdf_import_rate' => $sum('p.last_pdf_import_at IS NOT NULL'),
            'cv_rate' => $sum('EXISTS (SELECT 1 FROM cv_document c WHERE c.user_id = u.id)'),
            'consent_rate' => $sum('EXISTS (SELECT 1 FROM cv_document c WHERE c.user_id = u.id'
                . ' AND c.employer_sharing_consent_at IS NOT NULL)'),
            'skills_rate' => $sum('EXISTS (SELECT 1 FROM skill_assessment s WHERE s.user_id = u.id)'),
        ];
    }

    /** @return array<string, mixed> */
    private function internship(AnalyticsFilters $f): array
    {
        [$people, $args] = $f->people();
        [$range, $rArgs] = $f->period('i.start_date');
        $from = " FROM internship i WHERE i.student_id IN ($people)$range";
        $bind = [...$args, ...$rArgs];
        $rows = $this->all('SELECT i.id, i.start_date, i.end_date, i.monthly_allowance_rm' . $from, $bind);
        $allowances = array_map(static fn (array $r): float => (float) $r['monthly_allowance_rm'], $rows);
        $weeks = $this->all(
            "SELECT e.internship_id, SUM(e.status = 'approved') AS approved,"
            . " SUM(e.status = 'submitted') AS waiting, SUM(e.status = 'returned') AS returned"
            . " FROM logbook_entry e WHERE e.internship_id IN (SELECT i.id$from)"
            . ' GROUP BY e.internship_id',
            $bind
        );
        $byInternship = array_column($weeks, null, 'internship_id');
        $expected = 0;
        $approvedInTime = 0;
        foreach ($rows as $r) {
            $end = $r['end_date'] === null ? null : (string) $r['end_date'];
            $due = self::weeksDue((string) $r['start_date'], $end, $this->now);
            $expected += $due;
            $approvedInTime += min($due, (int) ($byInternship[$r['id']]['approved'] ?? 0));
        }
        $survey = $this->row(
            'SELECT COUNT(*) AS answers, AVG(s.overall_rating) AS rating, SUM(s.recommend_juniors = 1) AS recommend,'
            . ' SUM(s.offered_job = 1) AS offered FROM internship_satisfaction s'
            . " WHERE s.internship_id IN (SELECT i.id$from)",
            $bind
        );
        $answers = (int) $survey['answers'];

        return [
            'placed' => count($rows),
            'by_nature' => $this->counts("SELECT COALESCE(i.business_nature, 'Not given') AS label, COUNT(*) AS n$from"
                . ' GROUP BY 1 ORDER BY n DESC, label', $bind),
            'top_companies' => $this->counts('SELECT i.company AS label, COUNT(*) AS n' . $from
                . ' GROUP BY 1 ORDER BY n DESC, label LIMIT ' . self::TOP, $bind),
            'allowance_average' => $allowances === [] ? null : round(array_sum($allowances) / count($allowances), 2),
            'allowance_median' => self::median($allowances),
            'unpaid' => count(array_filter($allowances, static fn (float $a): bool => $a == 0.0)),
            'logbook_completion_rate' => self::rate($approvedInTime, $expected),
            'weeks_expected' => $expected,
            'weeks_waiting' => array_sum(array_map(static fn (array $w): int => (int) $w['waiting'], $weeks)),
            'weeks_returned' => array_sum(array_map(static fn (array $w): int => (int) $w['returned'], $weeks)),
            'satisfaction_answers' => $answers,
            'satisfaction_average' => $survey['rating'] === null ? null : round((float) $survey['rating'], 2),
            'recommend_rate' => self::rate((int) $survey['recommend'], $answers),
            'job_offer_rate' => self::rate((int) $survey['offered'], $answers),
        ];
    }

    /** @return array<string, mixed> */
    private function jobs(AnalyticsFilters $f): array
    {
        [$people, $args] = $f->people();
        [$range, $rArgs] = $f->period('a.created_at');
        $from = " FROM job_application a WHERE a.user_id IN ($people)$range";
        $bind = [...$args, ...$rArgs];
        $stages = array_column(
            $this->counts('SELECT a.status AS label, COUNT(*) AS n' . $from . ' GROUP BY 1', $bind),
            'count',
            'label'
        );
        $funnel = [];
        foreach (self::STAGES as $s) {
            $funnel[] = ['label' => $s, 'count' => (int) ($stages[$s] ?? 0)];
        }

        return [
            'open_jobs' => $this->count(
                "SELECT COUNT(*) FROM job_posting WHERE organisation_id = ? AND status = 'open'"
                . ' AND (closing_date IS NULL OR closing_date >= ?)',
                [$f->org, gmdate('Y-m-d', $this->now)]
            ),
            'applications' => array_sum(array_column($funnel, 'count')),
            'funnel' => $funnel,
            'top_employers' => $this->all(
                'SELECT e.name AS label, COUNT(*) AS applications, SUM(a.status = \'hired\') AS hired'
                . self::JOB_EMPLOYER . " WHERE a.user_id IN ($people)$range GROUP BY e.id, e.name"
                . ' ORDER BY hired DESC, applications DESC, e.name LIMIT ' . self::TOP,
                $bind,
                static fn (array $r): array => ['label' => (string) $r['label'],
                    'applications' => (int) $r['applications'], 'hired' => (int) $r['hired']]
            ),
        ];
    }

    /**
     * Skills, learning, assessments, the yearly survey, interview practice, the showcase and workshops.
     *
     * @return array<string, mixed>
     */
    private function growth(AnalyticsFilters $f): array
    {
        [$people, $args] = $f->people();
        [$students, $sArgs] = $f->people('student');
        $studentCount = $this->people($f, 'student', 'COUNT(*)');
        [$learnRange, $lArgs] = $f->period('r.created_at');
        $learning = array_column($this->counts(
            "SELECT r.status AS label, COUNT(*) AS n FROM learning_record r WHERE r.user_id IN ($people)$learnRange"
            . ' GROUP BY 1',
            [...$args, ...$lArgs]
        ), 'count', 'label');
        [$tryRange, $tArgs] = $f->period('t.created_at');
        $assessed = $this->count(
            "SELECT COUNT(DISTINCT t.user_id) FROM assessment_attempt t WHERE t.user_id IN ($students)$tryRange",
            [...$sArgs, ...$tArgs]
        );
        [$talkRange, $iArgs] = $f->period('s.started_at');
        $interview = $this->row(
            'SELECT COUNT(DISTINCT s.id) AS sessions,'
            . " AVG(CAST(JSON_EXTRACT(i.feedback_json, '$.score') AS DECIMAL(5,2))) AS average"
            . ' FROM interview_session s LEFT JOIN interview_item i ON i.session_id = s.id'
            . ' AND i.feedback_json IS NOT NULL'
            . " WHERE s.user_id IN ($people)$talkRange",
            [...$args, ...$iArgs]
        );
        [$shopRange, $wArgs] = $f->period('w.starts_at');
        $workshops = $this->row(
            "SELECT SUM(r.status = 'going') AS going, SUM(r.checked_in_at IS NOT NULL) AS attended FROM workshop_rsvp r"
            . " JOIN workshop w ON w.id = r.workshop_id WHERE r.user_id IN ($people)$shopRange"
            . " AND w.status = 'published' AND w.starts_at < ?",
            [...$args, ...$wArgs, gmdate('Y-m-d H:i:s', $this->now)]
        );
        $survey = $this->survey($f, $studentCount);

        return [
            'skills' => $this->all(
                'SELECT a.name AS label, AVG(r.level) AS average, COUNT(*) AS people FROM skill_rating r'
                . ' JOIN skill_area a ON a.id = r.area_id JOIN skill_assessment s ON s.id = r.assessment_id'
                . ' WHERE s.id = (SELECT MAX(s2.id) FROM skill_assessment s2 WHERE s2.user_id = s.user_id)'
                . " AND s.user_id IN ($people) GROUP BY a.id, a.name, a.position ORDER BY a.position, a.name",
                $args,
                static fn (array $r): array => ['label' => (string) $r['label'],
                    'average' => round((float) $r['average'], 2), 'people' => (int) $r['people']]
            ),
            'learning_verified' => (int) ($learning['verified'] ?? 0),
            'learning_pending' => (int) ($learning['submitted'] ?? 0),
            'learning_rejected' => (int) ($learning['rejected'] ?? 0),
            'assessment_completion_rate' => self::rate($assessed, $studentCount),
            'traits' => $this->all(
                'SELECT q.prompt AS label, AVG(o.points) AS average, COUNT(*) AS answers FROM assessment_answer x'
                . ' JOIN assessment_attempt t ON t.id = x.attempt_id JOIN assessment a ON a.id = t.assessment_id'
                . ' JOIN assessment_question q ON q.id = x.question_id JOIN assessment_option o ON o.id = x.option_id'
                . " WHERE a.organisation_id = ? AND a.answer_style = 'likert' AND t.user_id IN ($people)$tryRange"
                . ' GROUP BY q.id, q.prompt, q.position ORDER BY q.position LIMIT 30',
                [$f->org, ...$args, ...$tArgs],
                static fn (array $r): array => ['label' => (string) $r['label'],
                    'average' => round((float) $r['average'], 2), 'answers' => (int) $r['answers']]
            ),
            'survey' => $survey,
            'interview_sessions' => (int) $interview['sessions'],
            'interview_average' => $interview['average'] === null ? null : round((float) $interview['average'], 1),
            'showcase_published' => $this->count(
                "SELECT COUNT(*) FROM showcase_project s WHERE s.published = 1 AND s.student_id IN ($people)",
                $args
            ),
            'workshop_rsvps' => (int) $workshops['going'],
            'workshop_attended' => (int) $workshops['attended'],
            'workshop_attendance_rate' => self::rate((int) $workshops['attended'], (int) $workshops['going']),
        ];
    }

    /**
     * The latest yearly survey round that was opened: response rate among the counted students and the section
     * means without N/A.
     *
     * @return array<string, mixed>|null
     */
    private function survey(AnalyticsFilters $f, int $students): ?array
    {
        $cycle = $this->row(
            "SELECT id, title FROM survey_cycle WHERE organisation_id = ? AND status <> 'draft'"
            . ' ORDER BY id DESC LIMIT 1',
            [$f->org]
        );
        if (!isset($cycle['id'])) {
            return null;
        }
        [$people, $args] = $f->people('student');
        $answers = $this->all(
            "SELECT r.answers_json FROM survey_response r WHERE r.cycle_id = ? AND r.status = 'submitted'"
            . " AND r.user_id IN ($people)",
            [(int) $cycle['id'], ...$args]
        );
        $sums = [];
        foreach ($answers as $row) {
            $decoded = json_decode((string) $row['answers_json'], true);
            foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
                $section = SurveyForm::sectionOf((string) $key);
                if ($section !== null && is_int($value)) {
                    $sums[$section][] = $value;
                }
            }
        }
        $sections = [];
        foreach (SurveyForm::SECTIONS as $s) {
            $values = $sums[$s['key']] ?? [];
            $sections[] = ['label' => $s['title'],
                'average' => $values === [] ? null : round(array_sum($values) / count($values), 2)];
        }

        return [
            'cycle_id' => (int) $cycle['id'],
            'title' => (string) $cycle['title'],
            'responses' => count($answers),
            'response_rate' => self::rate(count($answers), $students),
            'sections' => $sections,
        ];
    }

    /** @return list<array<string, mixed>> one row per faculty */
    private function faculties(AnalyticsFilters $f): array
    {
        [$where, $args] = $f->where();
        $employed = self::inList(AlumniService::EMPLOYED);
        [$range, $rArgs] = $f->period('i.start_date');

        return $this->all(
            "SELECT COALESCE(p.faculty, u.department, 'Not set') AS faculty, SUM(u.role = 'student') AS students,"
            . " SUM(u.role = 'student' AND l.user_id IS NOT NULL) AS students_linked,"
            . " SUM(u.role = 'student' AND c.user_id IS NOT NULL) AS students_cv,"
            . ' SUM(i.id IS NOT NULL) AS interns, COUNT(s.id) AS answers, SUM(s.offered_job = 1) AS offered,'
            . " SUM(u.role = 'alumni') AS alumni,"
            . " SUM(u.role = 'alumni' AND COALESCE(p.employment_status, 'unknown') <> 'unknown') AS known,"
            . " SUM(u.role = 'alumni' AND p.employment_status IN ($employed)) AS in_work"
            . ' FROM `user` u LEFT JOIN career_profile p ON p.user_id = u.id'
            . ' LEFT JOIN linkedin_account l ON l.user_id = u.id LEFT JOIN cv_document c ON c.user_id = u.id'
            . " LEFT JOIN internship i ON i.student_id = u.id$range"
            . " LEFT JOIN internship_satisfaction s ON s.internship_id = i.id WHERE $where GROUP BY 1 ORDER BY 1",
            [...$rArgs, ...$args],
            static fn (array $r): array => [
                'faculty' => (string) $r['faculty'],
                'students' => (int) $r['students'],
                'linkedin_rate' => self::rate((int) $r['students_linked'], (int) $r['students']),
                'cv_rate' => self::rate((int) $r['students_cv'], (int) $r['students']),
                'interns' => (int) $r['interns'],
                'job_offer_rate' => self::rate((int) $r['offered'], (int) $r['answers']),
                'alumni' => (int) $r['alumni'],
                'alumni_employment_rate' => self::rate((int) $r['in_work'], (int) $r['known']),
            ]
        );
    }

    /** @return array<string, mixed> */
    private function alumni(AnalyticsFilters $f): array
    {
        [$where, $args] = $f->where('alumni');
        $from = ' FROM `user` u LEFT JOIN career_profile p ON p.user_id = u.id';
        $status = "COALESCE(p.employment_status, 'unknown')";
        $breakdown = $this->all(
            "SELECT COALESCE(p.faculty, u.department, 'Not set') AS faculty, p.graduation_year, $status AS status,"
            . " COUNT(*) AS n$from WHERE $where GROUP BY 1, 2, 3 ORDER BY 2 DESC, 1",
            $args
        );
        $groups = [];
        $totals = array_fill_keys(self::STATUSES, 0);
        foreach ($breakdown as $r) {
            $key = $r['faculty'] . '|' . ($r['graduation_year'] ?? '');
            $groups[$key] ??= ['faculty' => (string) $r['faculty'],
                'graduation_year' => $r['graduation_year'] === null ? null : (int) $r['graduation_year'],
                'counts' => array_fill_keys(self::STATUSES, 0)];
            $groups[$key]['counts'][(string) $r['status']] = (int) $r['n'];
            $totals[(string) $r['status']] += (int) $r['n'];
        }
        $cutoff = gmdate('Y-m-d H:i:s', $this->now - $this->confirmDays * 86400);
        $fresh = $this->row(
            'SELECT SUM(p.job_confirmed_at >= ?) AS fresh, SUM(p.job_confirmed_at < ?) AS overdue,'
            . " SUM(p.job_confirmed_at IS NULL) AS never$from WHERE $where",
            [$cutoff, $cutoff, ...$args]
        );
        $noLinkedIn = " AND NOT EXISTS (SELECT 1 FROM linkedin_account l WHERE l.user_id = u.id)";
        [$people, $pArgs] = $f->people('alumni');

        return [
            'status' => array_map(
                static fn (string $s): array => ['label' => $s, 'count' => $totals[$s]],
                self::STATUSES
            ),
            'breakdown' => array_values($groups),
            'confirmed_recently' => (int) $fresh['fresh'],
            'confirmation_overdue' => (int) $fresh['overdue'],
            'never_confirmed' => (int) $fresh['never'],
            'confirm_days' => $this->confirmDays,
            'without_linkedin' => $this->count("SELECT COUNT(*)$from WHERE $where$noLinkedIn", $args),
            'without_linkedin_list' => $this->all(
                "SELECT u.id, u.display_name, COALESCE(p.faculty, u.department) AS faculty, p.graduation_year$from"
                . " WHERE $where$noLinkedIn ORDER BY p.graduation_year DESC, u.display_name LIMIT " . self::RISK_LIST,
                $args,
                static fn (array $r): array => ['id' => (int) $r['id'], 'display_name' => (string) $r['display_name'],
                    'faculty' => $r['faculty'], 'graduation_year' => $r['graduation_year'] === null
                        ? null : (int) $r['graduation_year']]
            ),
            'network_visible' => $this->count(
                "SELECT COUNT(*) FROM network_profile n WHERE n.visible = 1 AND n.user_id IN ($people)",
                $pArgs
            ),
            'mentors_active' => $this->count(
                "SELECT COUNT(*) FROM mentor_offer m WHERE m.active = 1 AND m.user_id IN ($people)",
                $pArgs
            ),
            'mentor_requests' => $this->counts(
                "SELECT r.status AS label, COUNT(*) AS n FROM mentor_request r WHERE r.mentor_id IN ($people)"
                . ' GROUP BY 1 ORDER BY 1',
                $pArgs
            ),
        ];
    }

    /** @return array{faculties: list<string>, programmes: list<string>, cohorts: list<int>} */
    private function options(int $org): array
    {
        $people = " FROM `user` u LEFT JOIN career_profile p ON p.user_id = u.id WHERE u.organisation_id = ?"
            . " AND u.role IN ('student', 'alumni')";

        return [
            'faculties' => array_map('strval', array_column($this->all(
                "SELECT DISTINCT COALESCE(p.faculty, u.department) AS v$people"
                . ' AND COALESCE(p.faculty, u.department) IS NOT NULL ORDER BY v',
                [$org]
            ), 'v')),
            'programmes' => array_map('strval', array_column($this->all(
                "SELECT DISTINCT p.programme AS v$people AND p.programme IS NOT NULL ORDER BY v",
                [$org]
            ), 'v')),
            'cohorts' => array_map('intval', array_column($this->all(
                "SELECT DISTINCT p.graduation_year AS v$people AND p.graduation_year IS NOT NULL ORDER BY v DESC",
                [$org]
            ), 'v')),
        ];
    }

    /**
     * Logbook weeks an internship should have by now: from its start to its end or today, whichever is first.
     */
    public static function weeksDue(string $start, ?string $end, int $now): int
    {
        $today = gmdate('Y-m-d', $now);
        $last = $end === null || $end > $today ? $today : $end;
        if ($start > $last) {
            return 0;
        }
        $days = (int) ((strtotime($last . ' UTC') - strtotime($start . ' UTC')) / 86400);

        return intdiv($days, 7) + 1;
    }

    /** @param list<float> $values */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $mid = intdiv(count($values), 2);

        return round(count($values) % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 2);
    }

    public static function rate(int $part, int $whole): ?float
    {
        return $whole <= 0 ? null : round($part / $whole, 3);
    }

    /** @param list<string> $values fixed constants only, never input */
    private static function inList(array $values): string
    {
        return "'" . implode("', '", $values) . "'";
    }

    /** An aggregate over the counted people of one role. */
    private function people(AnalyticsFilters $f, string $role, string $aggregate): int
    {
        [$where, $args] = $f->where($role === 'student' ? 'student' : 'alumni');

        $from = ' FROM `user` u LEFT JOIN career_profile p ON p.user_id = u.id';

        return $this->count("SELECT $aggregate$from WHERE $where", $args);
    }

    /**
     * @param list<mixed> $args
     * @return list<array{label: string, count: int}>
     */
    private function counts(string $sql, array $args): array
    {
        return $this->all(
            $sql,
            $args,
            static fn (array $r): array => ['label' => (string) $r['label'], 'count' => (int) $r['n']]
        );
    }

    /** @param list<mixed> $args */
    private function count(string $sql, array $args): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<mixed> $args
     * @return array<string, mixed>
     */
    private function row(string $sql, array $args): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /**
     * @template T
     * @param list<mixed> $args
     * @param (\Closure(array<string, mixed>): T)|null $shape
     * @return ($shape is null ? list<array<string, mixed>> : list<T>)
     */
    private function all(string $sql, array $args, ?\Closure $shape = null): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $shape === null ? $rows : array_map($shape, $rows);
    }
}

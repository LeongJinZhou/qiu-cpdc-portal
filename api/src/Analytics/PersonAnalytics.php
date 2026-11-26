<?php

declare(strict_types=1);

namespace Cpdc\Analytics;

use Cpdc\Alumni\AlumniService;
use Cpdc\Http\ApiError;
use PDO;

/**
 * One student's or alumnus's 360 view for CPDC (docs/SPEC.md section 15): readiness checklist, CV, internship and
 * logbook, applications, skills, assessments with reflections, learning, career path, interview practice, showcase,
 * workshops, the yearly survey and, for alumni, the job confirmation history and engagement. Private mentoring
 * messages are never read here. Every query is scoped to the person and the organisation.
 */
final class PersonAnalytics
{
    public function __construct(private readonly PDO $pdo, private readonly int $now, private readonly int $confirmDays)
    {
    }

    /** @return array<string, mixed> */
    public function of(int $org, int $userId): array
    {
        $person = $this->row(
            'SELECT u.id, u.role, p.last_pdf_import_at, p.employment_status, p.job_confirmed_at,'
            . ' (l.user_id IS NOT NULL) AS linkedin, c.updated_at AS cv_updated_at, c.employer_sharing_consent_at'
            . ' FROM `user` u LEFT JOIN career_profile p ON p.user_id = u.id'
            . ' LEFT JOIN linkedin_account l ON l.user_id = u.id LEFT JOIN cv_document c ON c.user_id = u.id'
            . " WHERE u.id = ? AND u.organisation_id = ? AND u.role IN ('student', 'alumni')",
            [$userId, $org]
        ) ?? throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        $skills = $this->skills($userId);
        $assessments = $this->assessments($userId);
        $internship = $this->internship($userId);
        $employed = in_array($person['employment_status'], AlumniService::EMPLOYED, true);
        $alumni = $person['role'] === 'alumni';

        return [
            'checklist' => [
                ['key' => 'linkedin', 'label' => 'LinkedIn linked', 'done' => (bool) $person['linkedin']],
                ['key' => 'pdf', 'label' => 'LinkedIn PDF imported', 'done' => $person['last_pdf_import_at'] !== null],
                ['key' => 'cv', 'label' => 'CV built', 'done' => $person['cv_updated_at'] !== null],
                ['key' => 'skills', 'label' => 'Skills rated', 'done' => $skills !== []],
                ['key' => 'assessment', 'label' => 'Self-assessment done', 'done' => $assessments !== []],
                ['key' => 'placement', 'label' => $alumni ? 'Employment recorded' : 'Internship recorded',
                    'done' => $alumni ? $employed : $internship !== null],
            ],
            'cv' => ['updated_at' => $person['cv_updated_at'],
                'employer_sharing_consent_at' => $person['employer_sharing_consent_at']],
            'internship' => $internship,
            'applications' => $this->all(
                'SELECT a.id, j.title, e.name AS employer, a.status, a.created_at, a.status_changed_at'
                . ' FROM job_application a JOIN job_posting j ON j.id = a.job_id'
                . ' JOIN employer e ON e.id = j.employer_id WHERE a.user_id = ? ORDER BY a.created_at DESC',
                [$userId]
            ),
            'skills' => $skills,
            'assessments' => $assessments,
            'learning' => $this->all(
                'SELECT id, kind, name, provider, completed_on, status FROM learning_record WHERE user_id = ?'
                . ' ORDER BY completed_on DESC, id DESC',
                [$userId]
            ),
            'career_path' => $this->careerPath($userId),
            'interview' => $this->interview($userId),
            'showcase' => $this->all(
                'SELECT id, title, project_year, published FROM showcase_project WHERE student_id = ? ORDER BY id DESC',
                [$userId],
                static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title'],
                    'project_year' => $r['project_year'] === null ? null : (int) $r['project_year'],
                    'published' => (bool) $r['published']]
            ),
            'workshops' => $this->workshops($userId),
            'survey' => $this->survey($org, $userId),
            'alumni' => $alumni ? $this->alumniView($userId, $person['job_confirmed_at']) : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function internship(int $userId): ?array
    {
        $row = $this->row(
            'SELECT id, company, business_nature, company_address_1, company_address_2, company_address_3,'
            . ' company_postcode, company_state, company_country, start_date, end_date, batch_month, batch_year,'
            . ' monthly_allowance_rm, has_hr_contact, hr_name, hr_phone, hr_email, supervisor_name, supervisor_email,'
            . ' supervisor_phone, placement_submitted_at FROM internship WHERE student_id = ?',
            [$userId]
        );
        if ($row === null) {
            return null;
        }
        $id = (int) $row['id'];
        $weeks = $this->row(
            "SELECT COUNT(*) AS started, SUM(status = 'approved') AS approved, SUM(status = 'submitted') AS waiting,"
            . " SUM(status = 'returned') AS returned, SUM(CASE WHEN status = 'approved' THEN hours ELSE 0 END) AS hours"
            . ' FROM logbook_entry WHERE internship_id = ?',
            [$id]
        ) ?? [];
        $survey = $this->row(
            'SELECT recommend_juniors, overall_rating, offered_job, updated_at FROM internship_satisfaction'
            . ' WHERE internship_id = ?',
            [$id]
        );

        return $row + [
            'logbook' => ['started' => (int) ($weeks['started'] ?? 0), 'approved' => (int) ($weeks['approved'] ?? 0),
                'waiting' => (int) ($weeks['waiting'] ?? 0), 'returned' => (int) ($weeks['returned'] ?? 0),
                'hours_approved' => (int) ($weeks['hours'] ?? 0)],
            'satisfaction' => $survey === null ? null : ['recommend_juniors' => (bool) $survey['recommend_juniors'],
                'overall_rating' => (int) $survey['overall_rating'], 'offered_job' => (bool) $survey['offered_job'],
                'updated_at' => $survey['updated_at']],
        ];
    }

    /**
     * Each skill area's latest rating and the one before it, for the trend.
     *
     * @return list<array{area: string, latest: int, previous: int|null}>
     */
    private function skills(int $userId): array
    {
        $rows = $this->all(
            'SELECT a.name, r.level FROM skill_rating r JOIN skill_assessment s ON s.id = r.assessment_id'
            . ' JOIN skill_area a ON a.id = r.area_id WHERE s.user_id = ? ORDER BY s.id DESC, a.position',
            [$userId]
        );
        $byArea = [];
        foreach ($rows as $r) {
            $byArea[(string) $r['name']][] = (int) $r['level'];
        }
        $out = [];
        foreach ($byArea as $area => $levels) {
            $out[] = ['area' => $area, 'latest' => $levels[0], 'previous' => $levels[1] ?? null];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> attempts, newest first, with the written reflections */
    private function assessments(int $userId): array
    {
        return $this->all(
            'SELECT t.id, a.title, t.score, t.max_score, t.band_label, t.semester, t.created_at, a.reflection_prompts,'
            . ' t.reflection_answers FROM assessment_attempt t JOIN assessment a ON a.id = t.assessment_id'
            . ' WHERE t.user_id = ? ORDER BY t.created_at DESC, t.id DESC',
            [$userId],
            static function (array $r): array {
                $prompts = json_decode((string) ($r['reflection_prompts'] ?? '[]'), true);
                $answers = json_decode((string) ($r['reflection_answers'] ?? '[]'), true);
                $reflections = [];
                foreach (is_array($prompts) ? array_values($prompts) : [] as $i => $prompt) {
                    $answer = is_array($answers) ? ($answers[$i] ?? null) : null;
                    if (is_string($prompt) && is_string($answer)) {
                        $reflections[] = ['prompt' => $prompt, 'answer' => $answer];
                    }
                }

                return ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'score' => (int) $r['score'],
                    'max_score' => (int) $r['max_score'], 'band_label' => $r['band_label'],
                    'semester' => $r['semester'] === null ? null : (int) $r['semester'],
                    'created_at' => $r['created_at'], 'reflections' => $reflections];
            }
        );
    }

    /** @return array{done: int, total: int} steps of the career paths of the person's programme */
    private function careerPath(int $userId): array
    {
        $row = $this->row(
            'SELECT COUNT(st.id) AS total, COUNT(pr.step_id) AS done FROM career_profile p'
            . ' JOIN career_path cp ON cp.programme = p.programme AND cp.organisation_id = p.organisation_id'
            . ' JOIN career_path_step st ON st.path_id = cp.id'
            . ' LEFT JOIN career_path_progress pr ON pr.step_id = st.id AND pr.user_id = p.user_id'
            . ' WHERE p.user_id = ?',
            [$userId]
        ) ?? [];

        return ['done' => (int) ($row['done'] ?? 0), 'total' => (int) ($row['total'] ?? 0)];
    }

    /** @return array{sessions: int, answered: int, average: float|null} */
    private function interview(int $userId): array
    {
        $row = $this->row(
            'SELECT (SELECT COUNT(*) FROM interview_session WHERE user_id = ?) AS sessions, COUNT(i.id) AS answered,'
            . " AVG(CAST(JSON_EXTRACT(i.feedback_json, '$.score') AS DECIMAL(5,2))) AS average"
            . ' FROM interview_item i WHERE i.user_id = ? AND i.feedback_json IS NOT NULL',
            [$userId, $userId]
        ) ?? [];

        return ['sessions' => (int) ($row['sessions'] ?? 0), 'answered' => (int) ($row['answered'] ?? 0),
            'average' => ($row['average'] ?? null) === null ? null : round((float) $row['average'], 1)];
    }

    /** @return array{going: int, attended: int, recent: list<array<string, mixed>>} */
    private function workshops(int $userId): array
    {
        $recent = $this->all(
            'SELECT w.id, w.title, w.starts_at, r.status, r.checked_in_at FROM workshop_rsvp r'
            . ' JOIN workshop w ON w.id = r.workshop_id WHERE r.user_id = ? ORDER BY w.starts_at DESC LIMIT 10',
            [$userId]
        );
        $counts = $this->row(
            "SELECT SUM(status = 'going') AS going, SUM(status = 'going' AND checked_in_at IS NOT NULL) AS attended"
            . ' FROM workshop_rsvp'
            . ' WHERE user_id = ?',
            [$userId]
        ) ?? [];

        return ['going' => (int) ($counts['going'] ?? 0), 'attended' => (int) ($counts['attended'] ?? 0),
            'recent' => $recent];
    }

    /** @return array{cycle: string, status: string}|null the latest opened round and the person's answer */
    private function survey(int $org, int $userId): ?array
    {
        $row = $this->row(
            'SELECT c.title, r.status FROM survey_cycle c LEFT JOIN survey_response r ON r.cycle_id = c.id'
            . " AND r.user_id = ? WHERE c.organisation_id = ? AND c.status <> 'draft' ORDER BY c.id DESC LIMIT 1",
            [$userId, $org]
        );

        return $row === null
            ? null
            : ['cycle' => (string) $row['title'], 'status' => (string) ($row['status'] ?? 'none')];
    }

    /** @return array<string, mixed> */
    private function alumniView(int $userId, mixed $confirmedAt): array
    {
        $mentor = $this->row(
            'SELECT m.active, (SELECT COUNT(*) FROM mentor_request r WHERE r.mentor_id = m.user_id'
            . " AND r.status = 'accepted') AS matches FROM mentor_offer m WHERE m.user_id = ?",
            [$userId]
        );
        $visible = $this->row('SELECT visible FROM network_profile WHERE user_id = ?', [$userId]);
        $cutoff = gmdate('Y-m-d H:i:s', $this->now - $this->confirmDays * 86400);

        return [
            'confirmations' => $this->all(
                'SELECT employment_status, title, company, confirmed_at FROM job_confirmation WHERE user_id = ?'
                . ' ORDER BY confirmed_at DESC',
                [$userId]
            ),
            'confirmation_overdue' => $confirmedAt === null || (string) $confirmedAt < $cutoff,
            'confirm_days' => $this->confirmDays,
            'network_visible' => $visible !== null && (bool) $visible['visible'],
            'mentor_active' => $mentor !== null && (bool) $mentor['active'],
            'mentor_matches' => (int) ($mentor['matches'] ?? 0),
        ];
    }

    /**
     * @param list<mixed> $args
     * @return array<string, mixed>|null
     */
    private function row(string $sql, array $args): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
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

<?php

declare(strict_types=1);

namespace Cpdc\Analytics;

use PDO;

/**
 * The two person-level CSV reports of the analytics page (the internship, satisfaction and survey CSVs already exist
 * in their modules): student readiness and alumni outcomes, one row per person counted by the filters.
 */
final class AnalyticsReports
{
    public function __construct(private readonly PDO $pdo, private readonly int $now, private readonly int $confirmDays)
    {
    }

    /** @return list<array<string, mixed>> */
    public function readiness(AnalyticsFilters $f): array
    {
        [$where, $args] = $f->where('student');
        [$range, $rArgs] = $f->period('a.created_at');

        return $this->rows(
            "SELECT u.display_name, u.matric_no, COALESCE(p.faculty, u.department) AS faculty, p.programme,"
            . ' u.onboarded_at, u.last_sign_in_at, l.profile_url AS linkedin_url, p.last_pdf_import_at,'
            . ' c.updated_at AS cv_updated_at, c.employer_sharing_consent_at,'
            . ' (SELECT COUNT(*) FROM skill_assessment s WHERE s.user_id = u.id) AS skill_ratings,'
            . ' (SELECT COUNT(*) FROM assessment_attempt t WHERE t.user_id = u.id) AS assessments,'
            . " (SELECT COUNT(*) FROM learning_record r WHERE r.user_id = u.id AND r.status = 'verified') AS verified,"
            . ' i.company AS internship_company, i.placement_submitted_at,'
            . " (SELECT COUNT(*) FROM job_application a WHERE a.user_id = u.id$range) AS applications"
            . ' FROM `user` u LEFT JOIN career_profile p ON p.user_id = u.id'
            . ' LEFT JOIN linkedin_account l ON l.user_id = u.id LEFT JOIN cv_document c ON c.user_id = u.id'
            . " LEFT JOIN internship i ON i.student_id = u.id WHERE $where ORDER BY faculty, u.display_name",
            [...$rArgs, ...$args]
        );
    }

    /** @return list<array<string, mixed>> */
    public function outcomes(AnalyticsFilters $f): array
    {
        [$where, $args] = $f->where('alumni');
        $cutoff = gmdate('Y-m-d H:i:s', $this->now - $this->confirmDays * 86400);

        return $this->rows(
            "SELECT u.display_name, u.matric_no, COALESCE(p.faculty, u.department) AS faculty, p.programme,"
            . " p.graduation_year, COALESCE(p.employment_status, 'unknown') AS employment_status, p.current_title,"
            . ' p.current_company, p.job_confirmed_at,'
            . " CASE WHEN p.job_confirmed_at IS NULL THEN 'never' WHEN p.job_confirmed_at < ? THEN 'overdue'"
            . " ELSE 'recent' END AS confirmation, l.profile_url AS linkedin_url,"
            . ' COALESCE(n.visible, 0) AS network_visible, COALESCE(m.active, 0) AS mentor_active, u.last_sign_in_at'
            . ' FROM `user` u LEFT JOIN career_profile p ON p.user_id = u.id'
            . ' LEFT JOIN linkedin_account l ON l.user_id = u.id LEFT JOIN network_profile n ON n.user_id = u.id'
            . " LEFT JOIN mentor_offer m ON m.user_id = u.id WHERE $where ORDER BY p.graduation_year DESC, faculty,"
            . ' u.display_name',
            [$cutoff, ...$args]
        );
    }

    /**
     * @param list<mixed> $args
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $args): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}

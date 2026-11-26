<?php

declare(strict_types=1);

namespace Cpdc\Analytics;

/**
 * The analytics filters as SQL. Who counts: active students and alumni of the organisation, narrowed by faculty
 * (career profile, else directory department), programme, role and cohort (graduation year). The date range (Malaysia
 * days, both ends included) narrows activity: applications, internships by start date, workshops, learning records,
 * attempts and interview sessions. Every value is a bound parameter.
 */
final class AnalyticsFilters
{
    public const ROLES = ['student', 'alumni'];

    /** @param 'all'|'student'|'alumni' $role */
    public function __construct(
        public readonly int $org,
        public readonly ?string $faculty = null,
        public readonly ?string $programme = null,
        public readonly string $role = 'all',
        public readonly ?int $cohort = null,
        public readonly ?string $fromUtc = null,
        public readonly ?string $toUtc = null
    ) {
    }

    /** @param array<string, mixed> $query validated analytics.query.json */
    public static function fromQuery(int $org, array $query, string $zone): self
    {
        $text = static fn (string $key): ?string => isset($query[$key]) && trim((string) $query[$key]) !== ''
            ? trim((string) $query[$key])
            : null;
        $role = $query['role'] ?? 'all';

        return new self(
            $org,
            $text('faculty'),
            $text('programme'),
            in_array($role, self::ROLES, true) ? $role : 'all',
            isset($query['cohort']) ? (int) $query['cohort'] : null,
            self::dayStart($text('from'), $zone, 0),
            self::dayStart($text('to'), $zone, 1)
        );
    }

    /** The UTC start of a local day ($plusDays 1: the end of that day, exclusive). Null for no date. */
    private static function dayStart(?string $day, string $zone, int $plusDays): ?string
    {
        if ($day === null) {
            return null;
        }
        $local = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone($zone));
        if ($local === false || $local->format('Y-m-d') !== $day) {
            throw \Cpdc\Http\ApiError::validation('date', 'Use a real date, like 2026-12-31.');
        }

        return $local->modify("+$plusDays day")->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Conditions on `user` u and career_profile p for the people counted.
     *
     * @param 'student'|'alumni'|null $only one role inside the filter (no one when the filter picked the other)
     * @return array{0: string, 1: list<mixed>}
     */
    public function where(?string $only = null): array
    {
        $roles = $this->role === 'all' ? self::ROLES : [$this->role];
        if ($only !== null) {
            $roles = array_values(array_intersect($roles, [$only]));
        }
        if ($roles === []) {
            return ['1 = 0', []];
        }
        $marks = implode(', ', array_fill(0, count($roles), '?'));
        $sql = "u.organisation_id = ? AND u.active = 1 AND u.role IN ($marks)";
        $args = [$this->org, ...$roles];
        if ($this->faculty !== null) {
            $sql .= ' AND COALESCE(p.faculty, u.department) = ?';
            $args[] = $this->faculty;
        }
        if ($this->programme !== null) {
            $sql .= ' AND p.programme = ?';
            $args[] = $this->programme;
        }
        if ($this->cohort !== null) {
            $sql .= ' AND p.graduation_year = ?';
            $args[] = $this->cohort;
        }

        return [$sql, $args];
    }

    /**
     * A subquery of the counted people's ids, for "x.user_id IN (...)".
     *
     * @param 'student'|'alumni'|null $only
     * @return array{0: string, 1: list<mixed>}
     */
    public function people(?string $only = null): array
    {
        [$where, $args] = $this->where($only);

        return ["SELECT u.id FROM `user` u LEFT JOIN career_profile p ON p.user_id = u.id WHERE $where", $args];
    }

    /**
     * " AND column in the date range", or nothing without a range.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function period(string $column): array
    {
        $sql = '';
        $args = [];
        if ($this->fromUtc !== null) {
            $sql .= " AND $column >= ?";
            $args[] = $this->fromUtc;
        }
        if ($this->toUtc !== null) {
            $sql .= " AND $column < ?";
            $args[] = $this->toUtc;
        }

        return [$sql, $args];
    }
}

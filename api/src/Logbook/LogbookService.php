<?php

declare(strict_types=1);

namespace Cpdc\Logbook;

use Cpdc\Audit\Audit;
use Cpdc\Db\Transaction;
use Cpdc\Http\ApiError;
use Cpdc\Logbook\Mail\Mailer;
use PDO;

/**
 * A student's internship and weekly logbook, and the supervisor's decision by single-use link (docs/SPEC.md
 * section 9). Every student query is scoped to the student's own id and organisation.
 *
 * @phpstan-type Student array{id: int, organisation_id: int, display_name: string, email: string|null, ...}
 */
final class LogbookService
{
    private const ENTRY_COLUMNS = 'e.id, e.week_start, e.hours, e.activities, e.learnings, e.status,'
        . ' e.supervisor_comment, e.submitted_at, e.decided_at';
    private const COUNT_COLUMNS = ['internship_id', 'student_id', 'approved', 'submitted', 'returned', 'draft',
        'approved_hours'];

    /**
     * @param int $now unix time (the App clock)
     * @param string $timezone the organisation's display time zone, for "today"
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Mailer $mailer,
        private readonly string $appUrl,
        private readonly int $now,
        private readonly string $timezone
    ) {
    }

    /**
     * The student's internship with all weeks, newest first; null internship when none is set up yet.
     *
     * @return array{internship: array<string, mixed>|null, entries: list<array<string, mixed>>, today: string,
     *     weeks_expected: int}
     */
    public function mine(int $org, int $studentId): array
    {
        $internship = $this->internship($org, $studentId);
        $today = $this->today();

        return [
            'internship' => $internship,
            'entries' => $internship === null ? [] : $this->entries((int) $internship['id']),
            'today' => $today,
            'weeks_expected' => $internship === null ? 0
                : Weeks::expected((string) $internship['start_date'], (string) $internship['end_date'], $today),
        ];
    }

    /**
     * Creates or changes the internship. A new supervisor email voids every unused review link: links go only to
     * the supervisor on record, so the student sends those weeks again.
     *
     * @param Student $student
     * @param array{company: string, supervisor_name: string, supervisor_email: string, start_date: string,
     *     end_date: string} $body
     * @return array<string, mixed>
     */
    public function saveInternship(array $student, array $body): array
    {
        foreach (['start_date', 'end_date'] as $field) {
            if (!Weeks::isDate($body[$field])) {
                throw ApiError::validation($field, 'Enter a real date.');
            }
        }
        if ($body['end_date'] < $body['start_date']) {
            throw ApiError::validation('end_date', 'The end date must be on or after the start date.');
        }
        $email = strtolower(trim($body['supervisor_email']));
        if ($student['email'] !== null && strtolower($student['email']) === $email) {
            throw ApiError::validation('supervisor_email', 'Your supervisor needs their own email address.');
        }
        $org = $student['organisation_id'];
        $id = $student['id'];
        Transaction::run($this->pdo, function () use ($org, $id, $body, $email): void {
            $old = $this->internship($org, $id, true);
            if ($old !== null && $old['placement_submitted_at'] !== null) {
                // A submitted placement changes only with a reason, kept in its change history (slice A).
                throw new ApiError(409, 'PLACEMENT_SUBMITTED', 'Change your internship in the placement form.');
            }
            $values = [trim($body['company']), trim($body['supervisor_name']), $email, $body['start_date'],
                $body['end_date']];
            if ($old === null) {
                $this->pdo->prepare(
                    'INSERT INTO internship (organisation_id, student_id, company, supervisor_name, supervisor_email,'
                    . ' start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$org, $id, ...$values]);

                return;
            }
            $this->pdo->prepare(
                'UPDATE internship SET company = ?, supervisor_name = ?, supervisor_email = ?, start_date = ?,'
                . ' end_date = ?, updated_at = UTC_TIMESTAMP(3) WHERE id = ? AND student_id = ?'
            )->execute([...$values, $old['id'], $id]);
            if ($old['supervisor_email'] !== $email) {
                ReviewLink::voidUnused($this->pdo, (int) $old['id']);
            }
        });

        return $this->mine($org, $id);
    }

    /**
     * Saves a new week as a draft, or changes a draft or returned week ($entryId given).
     *
     * @param Student $student
     * @param array{week_start: string, hours: int, activities: string, learnings: string} $body
     * @return array<string, mixed> the saved week
     */
    public function saveEntry(array $student, array $body, ?int $entryId = null): array
    {
        $org = $student['organisation_id'];
        $id = $student['id'];

        return Transaction::run($this->pdo, function () use ($org, $id, $body, $entryId): array {
            $internship = $this->internship($org, $id, true)
                ?? throw new ApiError(409, 'NO_INTERNSHIP', 'Add your internship details first.');
            $reason = Weeks::refusal(
                $body['week_start'],
                (string) $internship['start_date'],
                (string) $internship['end_date'],
                $this->today()
            );
            if ($reason !== null) {
                throw ApiError::validation('week_start', $reason);
            }
            $text = [$body['week_start'], $body['hours'], trim($body['activities']), trim($body['learnings'])];
            try {
                if ($entryId === null) {
                    $this->pdo->prepare(
                        'INSERT INTO logbook_entry (organisation_id, internship_id, student_id, week_start, hours,'
                        . ' activities, learnings) VALUES (?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$org, $internship['id'], $id, ...$text]);
                    $entryId = (int) $this->pdo->lastInsertId();
                } else {
                    $entry = $this->ownEntry($org, $id, $entryId);
                    if (!in_array($entry['status'], ['draft', 'returned'], true)) {
                        throw new ApiError(409, 'ENTRY_LOCKED', 'This week is with your supervisor or approved.');
                    }
                    $this->pdo->prepare(
                        'UPDATE logbook_entry SET week_start = ?, hours = ?, activities = ?, learnings = ?,'
                        . ' updated_at = UTC_TIMESTAMP(3) WHERE id = ? AND student_id = ?'
                    )->execute([...$text, $entryId, $id]);
                }
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new ApiError(409, 'WEEK_TAKEN', 'You already have an entry for this week.');
                }
                throw $e;
            }

            return $this->entry($entryId);
        });
    }

    /**
     * Sends a draft or returned week to the supervisor (submit), or sends a waiting week's link again (resend).
     * Earlier unused links of the week stop working. The email goes out inside the transaction: if it cannot be
     * handed over, nothing changes and the student can try again.
     *
     * @param Student $student
     * @return array<string, mixed> the week
     */
    public function send(array $student, int $entryId, int $linkDays, bool $resend): array
    {
        $org = $student['organisation_id'];
        $id = $student['id'];

        return Transaction::run($this->pdo, function () use ($student, $org, $id, $entryId, $linkDays, $resend) {
            $entry = $this->ownEntry($org, $id, $entryId);
            $allowed = $resend ? ['submitted'] : ['draft', 'returned'];
            if (!in_array($entry['status'], $allowed, true)) {
                throw new ApiError(409, 'ENTRY_LOCKED', $resend
                    ? 'Only a week waiting for your supervisor can be sent again.'
                    : 'This week is already with your supervisor or approved.');
            }
            $internship = $this->internship($org, $id, true) ?? throw new \LogicException('entry without internship');
            $this->pdo->prepare('DELETE FROM logbook_review_link WHERE entry_id = ? AND used_at IS NULL')
                ->execute([$entryId]);
            $link = ReviewLink::create();
            $expires = $this->now + $linkDays * 86400;
            $this->pdo->prepare(
                'INSERT INTO logbook_review_link (organisation_id, entry_id, token_hash, supervisor_email, expires_at)'
                . ' VALUES (?, ?, ?, ?, ?)'
            )->execute([$org, $entryId, $link['hash'], $internship['supervisor_email'], self::dbTime($expires)]);
            if (!$resend) {
                $this->pdo->prepare(
                    "UPDATE logbook_entry SET status = 'submitted', submitted_at = ?, supervisor_comment = NULL,"
                    . ' decided_at = NULL, updated_at = UTC_TIMESTAMP(3) WHERE id = ? AND student_id = ?'
                )->execute([self::dbTime($this->now), $entryId, $id]);
            }
            $this->mailer->send(
                (string) $internship['supervisor_email'],
                'Logbook for the week of ' . self::dateText((string) $entry['week_start']) . ' from '
                    . $student['display_name'],
                $this->supervisorText($student, $internship, $entry, $link['token'], $expires)
            );

            return $this->entry($entryId);
        });
    }

    /**
     * What the supervisor sees: the week and who wrote it. Never the student's email or matric number.
     *
     * @return array<string, mixed>
     */
    public function review(string $token): array
    {
        $link = $this->link($token, false);

        return $this->reviewView((int) $link['entry_id'], (string) $link['expires_at']);
    }

    /**
     * The supervisor approves or returns the week. Spends the link. The student gets an email.
     *
     * @param 'approve'|'return' $decision
     * @return array<string, mixed> the week as the supervisor now sees it
     */
    public function decide(string $token, string $decision, string $comment): array
    {
        $comment = trim($comment);
        if ($decision === 'return' && mb_strlen($comment) < 5) {
            throw ApiError::validation('comment', 'Tell the student what to change.');
        }

        [$view, $email, $status] = Transaction::run($this->pdo, function () use ($token, $decision, $comment): array {
            $link = $this->link($token, true);
            $entryId = (int) $link['entry_id'];
            $status = $decision === 'approve' ? 'approved' : 'returned';
            $at = self::dbTime($this->now);
            $this->pdo->prepare('UPDATE logbook_review_link SET used_at = ? WHERE id = ?')
                ->execute([$at, $link['id']]);
            $this->pdo->prepare(
                "UPDATE logbook_entry SET status = ?, supervisor_comment = ?, decided_at = ?,"
                . " updated_at = UTC_TIMESTAMP(3) WHERE id = ? AND status = 'submitted'"
            )->execute([$status, $comment === '' ? null : $comment, $at, $entryId]);
            // The supervisor has no account: the row names the link, which holds the address it was sent to.
            Audit::log(
                $this->pdo,
                ['id' => null, 'organisation_id' => (int) $link['organisation_id']],
                'logbook.supervisor_' . ($status === 'approved' ? 'approve' : 'return'),
                "logbook_entry:$entryId",
                ['link_id' => (int) $link['id']]
            );
            $student = $this->pdo->prepare(
                'SELECT u.email FROM logbook_entry e JOIN `user` u ON u.id = e.student_id WHERE e.id = ?'
            );
            $student->execute([$entryId]);

            return [$this->reviewView($entryId, (string) $link['expires_at']), $student->fetchColumn(), $status];
        });
        // After the commit: a failed notice must not undo the supervisor's decision.
        if (is_string($email) && $email !== '') {
            try {
                $this->mailer->send($email, 'Your logbook week of ' . self::dateText((string) $view['week_start'])
                    . ' was ' . ($status === 'approved' ? 'approved' : 'returned'), $this->studentText($view));
            } catch (\Throwable $e) {
                error_log('logbook: student notice not sent: ' . $e->getMessage());
            }
        }

        return $view;
    }

    /**
     * CPDC's list: every intern with their weeks by status.
     *
     * @param array<mixed> $query validated logbook.students.query.json
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function students(int $org, array $query, int $limit, int $offset): array
    {
        $where = 'i.organisation_id = ?';
        $args = [$org];
        if (($query['q'] ?? '') !== '') {
            $like = '%' . addcslashes((string) $query['q'], '%_\\') . '%';
            $where .= ' AND (u.display_name LIKE ? OR u.matric_no LIKE ? OR i.company LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        $having = ($query['attention'] ?? null) === '1' ? ' HAVING returned > 0' : '';
        $select = 'SELECT i.id AS internship_id, u.id AS student_id, u.display_name, u.matric_no, u.department,'
            . ' u.picture_url, l.profile_url AS linkedin_url, i.company, i.supervisor_name, i.supervisor_email,'
            . ' i.start_date, i.end_date,'
            . " COALESCE(SUM(e.status = 'approved'), 0) AS approved,"
            . " COALESCE(SUM(e.status = 'submitted'), 0) AS submitted,"
            . " COALESCE(SUM(e.status = 'returned'), 0) AS returned,"
            . " COALESCE(SUM(e.status = 'draft'), 0) AS draft,"
            . " COALESCE(SUM(IF(e.status = 'approved', e.hours, 0)), 0) AS approved_hours,"
            . ' MAX(e.submitted_at) AS last_submitted_at'
            . ' FROM internship i JOIN `user` u ON u.id = i.student_id'
            . ' LEFT JOIN linkedin_account l ON l.user_id = u.id'
            . " LEFT JOIN logbook_entry e ON e.internship_id = i.id WHERE $where"
            . ' GROUP BY i.id, u.id, l.user_id' . $having;
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM ($select) t");
        $count->execute($args);
        $stmt = $this->pdo->prepare(
            "$select ORDER BY returned DESC, u.display_name, i.id LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($args);
        $today = $this->today();
        $rows = array_map(static function (array $r) use ($today): array {
            foreach (self::COUNT_COLUMNS as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['weeks_expected'] = Weeks::expected((string) $r['start_date'], (string) $r['end_date'], $today);
            // A student could name a second mailbox of their own as supervisor: CPDC checks these first.
            $r['supervisor_email_personal'] = SupervisorEmail::isPersonal((string) $r['supervisor_email']);

            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        return ['rows' => array_values($rows), 'total' => (int) $count->fetchColumn()];
    }

    /**
     * One intern for CPDC: who, the internship and every week.
     *
     * @return array<string, mixed>
     */
    public function student(int $org, int $studentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.display_name, u.matric_no, u.department, u.picture_url, l.profile_url AS linkedin_url'
            . ' FROM `user` u LEFT JOIN linkedin_account l ON l.user_id = u.id WHERE u.id = ? AND u.organisation_id = ?'
        );
        $stmt->execute([$studentId, $org]);
        $person = $stmt->fetch(PDO::FETCH_ASSOC);
        $mine = is_array($person) ? $this->mine($org, $studentId) : null;
        if (!is_array($person) || $mine === null || $mine['internship'] === null) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }
        $person['id'] = (int) $person['id'];

        return ['student' => $person] + $mine;
    }

    /** @return array<string, mixed>|null */
    private function internship(int $org, int $studentId, bool $lock = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, company, supervisor_name, supervisor_email, start_date, end_date, placement_submitted_at,'
            . ' updated_at FROM internship'
            . ' WHERE student_id = ? AND organisation_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$studentId, $org]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['id'] = (int) $row['id'];

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function entries(int $internshipId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ENTRY_COLUMNS . ', (SELECT MAX(l.expires_at) FROM logbook_review_link l'
            . ' WHERE l.entry_id = e.id AND l.used_at IS NULL) AS link_expires_at'
            . ' FROM logbook_entry e WHERE e.internship_id = ? ORDER BY e.week_start DESC'
        );
        $stmt->execute([$internshipId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(fn (array $r): array => $this->shapeEntry($r), $rows));
    }

    /** @return array<string, mixed> */
    private function entry(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ENTRY_COLUMNS . ', (SELECT MAX(l.expires_at) FROM logbook_review_link l'
            . ' WHERE l.entry_id = e.id AND l.used_at IS NULL) AS link_expires_at FROM logbook_entry e WHERE e.id = ?'
        );
        $stmt->execute([$entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $this->shapeEntry(is_array($row) ? $row : throw new \LogicException('entry vanished'));
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function shapeEntry(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['hours'] = (int) $r['hours'];
        $expires = $r['link_expires_at'];
        // A waiting week whose link is gone or past its date needs "send again".
        $r['link_active'] = is_string($expires) && strtotime($expires . ' UTC') > $this->now;

        return $r;
    }

    /** @return array{id: int, status: string, week_start: string} the student's own week, locked */
    private function ownEntry(int $org, int $studentId, int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, week_start FROM logbook_entry WHERE id = ? AND student_id = ? AND organisation_id = ?'
            . ' FOR UPDATE'
        );
        $stmt->execute([$entryId, $studentId, $org]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiError(404, 'NOT_FOUND', 'Not found.');
        }

        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'week_start' => (string) $row['week_start'],
        ];
    }

    /**
     * A live link: known, unused, not expired, and its week still waiting.
     *
     * @return array<string, mixed>
     */
    private function link(string $token, bool $lock): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.organisation_id, l.entry_id, l.expires_at, l.used_at, e.status'
            . ' FROM logbook_review_link l'
            . ' JOIN logbook_entry e ON e.id = l.entry_id WHERE l.token_hash = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([ReviewLink::hash($token)]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($link)) {
            throw new ApiError(404, 'LINK_INVALID', 'This link is not valid. Ask the student to send the week again.');
        }
        if ($link['used_at'] !== null || $link['status'] !== 'submitted') {
            throw new ApiError(410, 'LINK_USED', 'This week has already been reviewed with this link.');
        }
        if (strtotime($link['expires_at'] . ' UTC') <= $this->now) {
            throw new ApiError(410, 'LINK_EXPIRED', 'This link has expired. Ask the student to send the week again.');
        }

        return $link;
    }

    /** @return array<string, mixed> */
    private function reviewView(int $entryId, string $expiresAt): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.week_start, e.hours, e.activities, e.learnings, e.status, e.supervisor_comment, e.decided_at,'
            . ' u.display_name AS student_name, i.company, i.supervisor_name FROM logbook_entry e'
            . ' JOIN internship i ON i.id = e.internship_id JOIN `user` u ON u.id = e.student_id WHERE e.id = ?'
        );
        $stmt->execute([$entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \LogicException('link without entry');
        }
        $row['hours'] = (int) $row['hours'];
        $row['expires_at'] = $expiresAt;

        return $row;
    }

    /**
     * @param Student $student
     * @param array<string, mixed> $internship
     * @param array{week_start: string, ...} $entry
     */
    private function supervisorText(
        array $student,
        array $internship,
        array $entry,
        string $token,
        int $expires
    ): string {
        return 'Dear ' . $internship['supervisor_name'] . ",\n\n"
            . $student['display_name'] . ' (intern at ' . $internship['company'] . ') has sent you their logbook for'
            . ' the week of ' . self::dateText($entry['week_start']) . ".\n\n"
            . "Open this link to read it, then approve it or send it back with a comment:\n"
            . ReviewLink::url($this->appUrl, $token) . "\n\n"
            . 'The link works once and expires on ' . self::dateText(gmdate('Y-m-d', $expires))
            . ". You do not need an account.\n\n"
            . "Quest International University\nCareer and Professional Development Centre\n";
    }

    /** @param array<string, mixed> $view */
    private function studentText(array $view): string
    {
        $what = $view['status'] === 'approved' ? 'approved' : 'returned';
        $comment = is_string($view['supervisor_comment']) ? "\n\nComment: " . $view['supervisor_comment'] : '';

        return 'Your supervisor ' . $view['supervisor_name'] . " $what your logbook for the week of "
            . self::dateText((string) $view['week_start']) . '.' . $comment . "\n\n"
            . 'Open your logbook: ' . rtrim($this->appUrl, '/') . "/logbook\n";
    }

    private function today(): string
    {
        return Weeks::today($this->now, $this->timezone);
    }

    private static function dbTime(int $unix): string
    {
        return gmdate('Y-m-d H:i:s', $unix);
    }

    private static function dateText(string $date): string
    {
        return gmdate('j M Y', (int) strtotime($date . ' UTC'));
    }
}

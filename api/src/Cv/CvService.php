<?php

declare(strict_types=1);

namespace Cpdc\Cv;

use Cpdc\Profile\ProfileService;
use PDO;

/**
 * The person's CV: the career profile (jobs, education, skills, read live) plus what the CV adds, kept in
 * cv_document. A person reads and writes only their own CV; there is no id in the API.
 * Slice B adds CGPA and intake, courses taken, awards (optionally from verified learning records), co-curricular
 * activities, up to two references, and the PDPA consent to share the CV with QIU partner employers (a timestamp in
 * its own column, kept from the first tick until the person unticks it).
 *
 * @phpstan-type Award array{kind: 'deans_list'|'award'|'certificate', title: string, issuer?: string,
 *     period?: string, learning_id?: int}
 * @phpstan-type Content array{contact: array{email: string, phone: string, location: string, website: string},
 *     headline: string, summary: string, hidden_experience: list<int>, hidden_education: list<int>,
 *     show_skills: bool, sections: list<array{title: string, items: list<array{heading: string, sub?: string,
 *     period?: string, detail?: string}>}>,
 *     academic?: array{show_cgpa: bool, cgpa: string, intake_period: string},
 *     courses_taken?: list<string>, awards?: list<Award>,
 *     cocurricular?: list<array{activity: string, role_and_details?: string, period?: string}>,
 *     references?: list<array{name: string, designation: string, company: string, phone: string, email: string}>,
 *     employer_sharing_consent?: bool}
 */
final class CvService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{id: int, organisation_id: int, ...} $user
     * @return array{profile: array<string, mixed>, cv: array<string, mixed>, saved_at: string|null,
     *     employer_sharing_consent_at: string|null, verified_learning: list<array<string, mixed>>}
     */
    public function get(array $user): array
    {
        $profile = (new ProfileService($this->pdo))->get($user['organisation_id'], $user['id']);
        $stmt = $this->pdo->prepare(
            'SELECT content_json, employer_sharing_consent_at, updated_at FROM cv_document'
            . ' WHERE user_id = ? AND organisation_id = ?'
        );
        $stmt->execute([$user['id'], $user['organisation_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        /** @var array<string, mixed>|null $saved */
        $saved = is_array($row) ? json_decode((string) $row['content_json'], true, 16, JSON_THROW_ON_ERROR) : null;
        $consent = is_array($row) ? $row['employer_sharing_consent_at'] : null;
        $cv = ($saved ?? []) + self::defaults($profile);
        $cv['employer_sharing_consent'] = $consent !== null;

        return [
            'profile' => self::profilePart($profile),
            'cv' => $cv,
            'saved_at' => is_array($row) ? self::iso((string) $row['updated_at']) : null,
            'employer_sharing_consent_at' => $consent === null ? null : self::iso((string) $consent),
            'verified_learning' => $this->verifiedLearning($user),
        ];
    }

    /**
     * Stores the CV (already schema-validated). Text is trimmed; hidden ids that are not the person's own
     * profile entries are dropped; empty sections and items are left out; an award may point only at the person's
     * own verified learning record.
     *
     * @param array{id: int, organisation_id: int, ...} $user
     * @param Content $content
     */
    public function save(array $user, array $content): void
    {
        $consent = $content['employer_sharing_consent'] ?? false;
        $this->pdo->prepare(
            'INSERT INTO cv_document (user_id, organisation_id, content_json, employer_sharing_consent_at, updated_at)'
            . ' VALUES (?, ?, ?, IF(?, UTC_TIMESTAMP(3), NULL), UTC_TIMESTAMP(3))'
            . ' ON DUPLICATE KEY UPDATE content_json = VALUES(content_json),'
            . ' employer_sharing_consent_at = IF(?, COALESCE(employer_sharing_consent_at, UTC_TIMESTAMP(3)), NULL),'
            . ' updated_at = UTC_TIMESTAMP(3)'
        )->execute([
            $user['id'],
            $user['organisation_id'],
            json_encode($this->clean($user, $content), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            (int) $consent,
            (int) $consent,
        ]);
    }

    /**
     * @param array{id: int, organisation_id: int, ...} $user
     * @param Content $content
     * @return array<string, mixed>
     */
    private function clean(array $user, array $content): array
    {
        $sections = [];
        foreach ($content['sections'] as $section) {
            $items = [];
            foreach ($section['items'] as $item) {
                $heading = trim($item['heading']);
                if ($heading !== '') {
                    $items[] = [
                        'heading' => $heading,
                        'sub' => trim($item['sub'] ?? ''),
                        'period' => trim($item['period'] ?? ''),
                        'detail' => trim($item['detail'] ?? ''),
                    ];
                }
            }
            $title = trim($section['title']);
            if ($title !== '') {
                $sections[] = ['title' => $title, 'items' => $items];
            }
        }
        $academic = $content['academic'] ?? ['show_cgpa' => false, 'cgpa' => '', 'intake_period' => ''];

        return [
            'contact' => array_map('trim', $content['contact']),
            'headline' => trim($content['headline']),
            'summary' => trim($content['summary']),
            'hidden_experience' => $this->own('career_experience', $user['id'], $content['hidden_experience']),
            'hidden_education' => $this->own('career_education', $user['id'], $content['hidden_education']),
            'show_skills' => $content['show_skills'],
            'sections' => $sections,
            'academic' => ['show_cgpa' => $academic['show_cgpa'] && $academic['cgpa'] !== '',
                'cgpa' => $academic['cgpa'], 'intake_period' => trim($academic['intake_period'])],
            'courses_taken' => array_values(array_filter(array_map('trim', $content['courses_taken'] ?? []))),
            'awards' => $this->awards($user, $content['awards'] ?? []),
            'cocurricular' => array_values(array_filter(array_map(static fn (array $c): array => [
                'activity' => trim($c['activity']),
                'role_and_details' => trim($c['role_and_details'] ?? ''),
                'period' => trim($c['period'] ?? ''),
            ], $content['cocurricular'] ?? []), static fn (array $c): bool => $c['activity'] !== '')),
            'references' => array_values(array_filter(
                array_map(static fn (array $r): array => array_map('trim', $r), $content['references'] ?? []),
                static fn (array $r): bool => $r['name'] !== ''
            )),
        ];
    }

    /**
     * @param array{id: int, organisation_id: int, ...} $user
     * @param list<Award> $awards
     * @return list<array<string, mixed>>
     */
    private function awards(array $user, array $awards): array
    {
        $verified = array_column($this->verifiedLearning($user), 'id');
        $out = [];
        foreach ($awards as $award) {
            $title = trim($award['title']);
            if ($title === '') {
                continue;
            }
            $clean = ['kind' => $award['kind'], 'title' => $title, 'issuer' => trim($award['issuer'] ?? ''),
                'period' => trim($award['period'] ?? '')];
            if (isset($award['learning_id']) && in_array($award['learning_id'], $verified, true)) {
                $clean['learning_id'] = $award['learning_id'];
            }
            $out[] = $clean;
        }

        return $out;
    }

    /**
     * The person's verified courses and certificates (learning records CPDC checked), newest first.
     *
     * @param array{id: int, organisation_id: int, ...} $user
     * @return list<array{id: int, kind: string, name: string, provider: string, completed_on: string}>
     */
    private function verifiedLearning(array $user): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, kind, name, provider, completed_on FROM learning_record'
            . " WHERE user_id = ? AND organisation_id = ? AND status = 'verified'"
            . ' ORDER BY completed_on DESC, id DESC LIMIT 50'
        );
        $stmt->execute([$user['id'], $user['organisation_id']]);

        return array_values(array_map(static fn (array $r): array => ['id' => (int) $r['id'],
            'kind' => (string) $r['kind'], 'name' => (string) $r['name'], 'provider' => (string) $r['provider'],
            'completed_on' => (string) $r['completed_on']], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * What a CV starts with before the first save: the profile's headline, about, location and email.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public static function defaults(array $profile): array
    {
        return [
            'contact' => [
                'email' => (string) ($profile['personal_email'] ?? $profile['email'] ?? ''),
                'phone' => '',
                'location' => (string) ($profile['location'] ?? ''),
                'website' => '',
            ],
            'headline' => (string) ($profile['headline'] ?? ''),
            'summary' => (string) ($profile['about'] ?? ''),
            'hidden_experience' => [],
            'hidden_education' => [],
            'show_skills' => true,
            'sections' => [],
            'academic' => ['show_cgpa' => false, 'cgpa' => '', 'intake_period' => ''],
            'courses_taken' => [],
            'awards' => [],
            'cocurricular' => [],
            'references' => [],
        ];
    }

    private static function iso(string $utc): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($utc . ' UTC'));
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private static function profilePart(array $profile): array
    {
        return array_intersect_key($profile, array_flip([
            'display_name', 'email', 'personal_email', 'headline', 'about', 'location', 'linkedin_url', 'github_url',
            'faculty', 'programme', 'graduation_year', 'experience', 'education', 'skills',
        ]));
    }

    /**
     * @param 'career_experience'|'career_education' $table
     * @param list<int> $ids
     * @return list<int>
     */
    private function own(string $table, int $userId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id FROM $table WHERE user_id = ? AND id IN ($marks) ORDER BY id");
        $stmt->execute([$userId, ...$ids]);

        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }
}

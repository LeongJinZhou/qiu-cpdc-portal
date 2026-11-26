<?php

declare(strict_types=1);

namespace Cpdc\Logbook;

/**
 * The secret in a supervisor's review link: 32 random bytes as base64url (43 characters, the Router's {token}).
 * Only its SHA-256 is stored, so a database leak does not leak working links.
 */
final class ReviewLink
{
    /** @return array{token: string, hash: string} */
    public static function create(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['token' => $token, 'hash' => self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function url(string $appUrl, string $token): string
    {
        return rtrim($appUrl, '/') . '/review/' . $token;
    }

    /** Voids every unused link of an internship's weeks: links go only to the supervisor on record. */
    public static function voidUnused(\PDO $pdo, int $internshipId): void
    {
        $pdo->prepare(
            'DELETE l FROM logbook_review_link l JOIN logbook_entry e ON e.id = l.entry_id'
            . ' WHERE e.internship_id = ? AND l.used_at IS NULL'
        )->execute([$internshipId]);
    }
}

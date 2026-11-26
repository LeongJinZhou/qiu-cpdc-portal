<?php

declare(strict_types=1);

namespace Cpdc\Http\Middleware;

use Cpdc\Http\ApiError;
use Cpdc\Http\Request;
use Cpdc\Settings\Settings;
use PDO;

/**
 * Native PHP sessions, stored in the database (DbSessionHandler), behind our own __Host- cookie. The user row is
 * re-read on every request
 * (by id AND organisation), so a role change or deactivation applies at once.
 *
 * A session remembers how it was opened (`provider`): a LinkedIn session is valid only while the user's role is
 * alumni (SPEC rule 8).
 *
 * @phpstan-type SessionUser array{id: int, organisation_id: int, email: string|null, display_name: string,
 *     role: string, active: int, onboarded_at: string|null, provider: string}
 */
final class Session
{
    public const COOKIE = '__Host-cpdc';

    /** Read-only requests rewrite the session at most this often to keep idle time fresh. */
    private const TOUCH_SECONDS = 60;

    /**
     * @param \Closure(int): Settings $settings settings of an organisation
     * @param \Closure(): int $clock unix time
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly \Closure $settings,
        private readonly \Closure $clock
    ) {
    }

    private static ?DbSessionHandler $store = null;

    /** Stored sessions must outlive the longest allowed session; real expiry is checked per request. */
    public static function gcSeconds(Settings $settings): int
    {
        return max($settings->int('session_absolute_hours') * 3600, $settings->int('session_idle_minutes') * 60);
    }

    /** Call once at startup, before any output: PHP refuses session ini changes afterwards. */
    public static function configure(PDO $pdo, int $gcSeconds): void
    {
        self::$store = new DbSessionHandler($pdo, static fn (): int => time());
        ini_set('session.use_cookies', '0'); // we send our own __Host- cookie
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cache_limiter', '');
        ini_set('session.gc_maxlifetime', (string) $gcSeconds);
        session_set_save_handler(self::$store, true);
    }

    public static function cookie(string $id): string
    {
        return self::COOKIE . '=' . $id . '; Path=/; Secure; HttpOnly; SameSite=Lax';
    }

    /** Set-Cookie value that removes the session cookie from the browser. */
    public static function expiredCookie(string $name = self::COOKIE): string
    {
        return $name . '=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax';
    }

    /** True when $id is a well-formed id of a stored session. Never stores anything. */
    public static function exists(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9,-]{22,128}$/D', $id) === 1 && self::$store !== null && self::$store->has($id);
    }

    /** Destroys the stored session $id if it exists (logout, login rotation). Unknown ids create nothing. */
    public static function forget(string $id): void
    {
        if (!self::exists($id)) {
            return;
        }
        self::close();
        session_id($id);
        session_start();
        session_destroy();
    }

    public static function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * Starts a brand-new session for a verified user.
     *
     * @param array{id: int, organisation_id: int, ...} $user
     * @param 'google'|'linkedin' $provider how the person proved who they are
     * @return string Set-Cookie header value
     */
    public function login(array $user, string $provider = 'google'): string
    {
        self::close();
        session_id((string) session_create_id());
        session_start();
        $now = ($this->clock)();
        $_SESSION = [
            'user_id' => $user['id'],
            'organisation_id' => $user['organisation_id'],
            'provider' => $provider,
            'csrf' => bin2hex(random_bytes(32)),
            'login_at' => $now,
            'seen_at' => $now,
        ];
        $id = (string) session_id();
        session_write_close();

        return self::cookie($id);
    }

    /**
     * GET and HEAD read the session with read_and_close so polling never holds the session lock.
     * Other methods leave it open for the handler; App closes it after the response.
     *
     * @return SessionUser
     */
    public function authenticate(Request $req): array
    {
        $id = $req->cookies[self::COOKIE] ?? '';
        // Unknown id (forged or expired): refuse before session_start, so nothing is ever stored for it.
        if (!self::exists($id)) {
            throw self::unauthenticated();
        }
        $readOnly = in_array($req->method, ['GET', 'HEAD'], true);
        self::close();
        session_id($id);
        session_start(['read_and_close' => $readOnly]);
        $data = $_SESSION;
        $complete = isset(
            $data['user_id'],
            $data['organisation_id'],
            $data['provider'],
            $data['csrf'],
            $data['login_at'],
            $data['seen_at']
        );
        if (!$complete || !in_array($data['provider'], ['google', 'linkedin'], true)) {
            $this->destroy($id); // empty or foreign data
            throw self::unauthenticated();
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, organisation_id, email, display_name, role, active, onboarded_at FROM `user`'
            . ' WHERE id = ? AND organisation_id = ?'
        );
        $stmt->execute([$data['user_id'], $data['organisation_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $linkedinStillAllowed = !is_array($user) || $data['provider'] !== 'linkedin' || $user['role'] === 'alumni';
        if (!is_array($user) || (int) $user['active'] !== 1 || !$linkedinStillAllowed) {
            $this->destroy($id);
            throw self::unauthenticated();
        }
        $superadminEmail = (string) getenv('SUPERADMIN_EMAIL');
        if ($user['role'] === 'superadmin' && strcasecmp((string) $user['email'], $superadminEmail) !== 0) {
            $this->destroy($id); // SUPERADMIN_EMAIL changed since sign-in: the next Google sign-in re-checks
            throw self::unauthenticated();
        }

        $settings = ($this->settings)((int) $user['organisation_id']);
        $now = ($this->clock)();
        $idle = $now - (int) $data['seen_at'] > $settings->int('session_idle_minutes') * 60;
        $absolute = $now - (int) $data['login_at'] > $settings->int('session_absolute_hours') * 3600;
        if ($idle || $absolute) {
            $this->destroy($id);
            throw self::unauthenticated();
        }

        if (!$readOnly) {
            $_SESSION['seen_at'] = $now;
        } elseif ($now - (int) $data['seen_at'] >= self::TOUCH_SECONDS) {
            session_id($id);
            session_start();
            $_SESSION['seen_at'] = $now;
            session_write_close();
        }
        $req->csrfToken = (string) $data['csrf'];

        return [
            'id' => (int) $user['id'],
            'organisation_id' => (int) $user['organisation_id'],
            'email' => $user['email'] === null ? null : (string) $user['email'],
            'display_name' => (string) $user['display_name'],
            'role' => (string) $user['role'],
            'active' => (int) $user['active'],
            'onboarded_at' => $user['onboarded_at'] === null ? null : (string) $user['onboarded_at'],
            'provider' => (string) $data['provider'],
        ];
    }

    private function destroy(string $id): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_id($id);
            session_start();
        }
        session_destroy(); // destroys the id session_start really used (strict mode may have replaced $id)
    }

    private static function unauthenticated(): ApiError
    {
        return new ApiError(401, 'UNAUTHENTICATED', 'Please sign in.');
    }
}

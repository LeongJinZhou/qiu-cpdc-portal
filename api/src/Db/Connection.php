<?php

declare(strict_types=1);

namespace Cpdc\Db;

use PDO;
use Cpdc\Env;

final class Connection
{
    /**
     * MySQL 8's default SQL mode without ONLY_FULL_GROUP_BY: MariaDB (Exabytes) has no such default, and its version
     * of that check refuses grouped queries MySQL accepts. Every part is known to MySQL 8 and MariaDB 10.11.
     */
    public const SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,'
        . 'NO_ENGINE_SUBSTITUTION';

    public static function fromEnv(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Env::get('DB_HOST'),
            Env::get('DB_PORT'),
            Env::get('DB_NAME')
        );
        $ca = getenv('DB_SSL_CA');
        $requireTls = self::requiresTls((string) getenv('APP_ENV'), Env::get('DB_HOST'));

        $options = self::options($ca === false ? null : $ca, $requireTls);

        return new PDO($dsn, Env::get('DB_USER'), Env::get('DB_PASSWORD'), $options);
    }

    /**
     * Production never reaches a database on another server without TLS. A database on the same server (Exabytes
     * cPanel, host "localhost") never leaves the machine, so it needs no certificate.
     */
    public static function requiresTls(string $appEnv, string $dbHost): bool
    {
        return $appEnv === 'production' && !self::isSameServer($dbHost);
    }

    public static function isSameServer(string $dbHost): bool
    {
        return in_array(strtolower(trim($dbHost)), ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * PDO options. With a CA file (DB_SSL_CA, a hosted database such as Aiven) the connection is TLS and the server
     * certificate is verified against that CA; without one (local MySQL) no TLS options are set. $requireTls refuses
     * to go on without a CA (production, database on another server).
     *
     * @return array<int, mixed>
     */
    public static function options(?string $sslCa, bool $requireTls = false): array
    {
        if ($requireTls && ($sslCa === null || trim($sslCa) === '')) {
            throw new \RuntimeException('DB_SSL_CA must be set in production');
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // All DATETIME values are UTC (ARCHITECTURE section 13). The SQL mode is MySQL 8's default, set on every
            // connection: hosts differ (Aiven defaults to ANSI, where "text" is a column name), the app must not.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode = '" . self::SQL_MODE . "'",
        ];
        if ($sslCa !== null && trim($sslCa) !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        return $options;
    }
}

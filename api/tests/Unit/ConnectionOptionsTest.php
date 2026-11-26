<?php

declare(strict_types=1);

namespace Cpdc\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Cpdc\Db\Connection;

final class ConnectionOptionsTest extends TestCase
{
    public function testWithoutCaThereAreNoTlsOptions(): void
    {
        foreach ([null, '', '   '] as $ca) {
            $options = Connection::options($ca);
            self::assertArrayNotHasKey(PDO::MYSQL_ATTR_SSL_CA, $options);
            self::assertArrayNotHasKey(PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT, $options);
        }
    }

    public function testWithCaTheServerCertificateIsVerified(): void
    {
        $options = Connection::options('/etc/secrets/db-ca.pem');

        self::assertSame('/etc/secrets/db-ca.pem', $options[PDO::MYSQL_ATTR_SSL_CA]);
        self::assertTrue($options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    /** @return array<string, array{?string}> */
    public static function missingCa(): array
    {
        return ['unset' => [null], 'empty' => [''], 'blank' => ['  ']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingCa')]
    public function testProductionRefusesToConnectWithoutCa(?string $ca): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB_SSL_CA');

        Connection::options($ca, true);
    }

    /** @return array<string, array{string, bool}> */
    public static function hosts(): array
    {
        return [
            'localhost' => ['localhost', true],
            'loopback' => ['127.0.0.1', true],
            'loopback v6' => ['::1', true],
            'upper case' => [' LocalHost ', true],
            'hosted' => ['mysql-1.aivencloud.com', false],
            'private ip' => ['10.0.0.5', false],
            'empty' => ['', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hosts')]
    public function testOnlyADatabaseOnTheSameServerCountsAsLocal(string $host, bool $local): void
    {
        self::assertSame($local, Connection::isSameServer($host));
    }

    public function testTlsIsRequiredInProductionOnlyForADatabaseOnAnotherServer(): void
    {
        self::assertTrue(Connection::requiresTls('production', 'mysql-1.aivencloud.com'));
        self::assertFalse(Connection::requiresTls('production', 'localhost')); // Exabytes: database on the same server
        self::assertFalse(Connection::requiresTls('local', 'mysql-1.aivencloud.com'));
    }

    public function testProductionWithCaVerifiesTheServer(): void
    {
        self::assertTrue(Connection::options('/ca.pem', true)[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    public function testBaseOptionsStayInBothModes(): void
    {
        foreach ([null, '/ca.pem'] as $ca) {
            $options = Connection::options($ca);
            self::assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
            self::assertFalse($options[PDO::ATTR_EMULATE_PREPARES]);
            self::assertSame(
                "SET time_zone = '+00:00', sql_mode = '" . Connection::SQL_MODE . "'",
                $options[PDO::MYSQL_ATTR_INIT_COMMAND]
            );
            self::assertStringNotContainsString('ANSI', Connection::SQL_MODE);
        }
    }
}

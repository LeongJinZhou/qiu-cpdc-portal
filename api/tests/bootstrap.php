<?php

declare(strict_types=1);

use Cpdc\Db\Connection;
use Cpdc\Env;
use Cpdc\Http\Middleware\Session;
use Cpdc\Settings\Settings;
use Cpdc\Tests\TestDb;

require __DIR__ . '/../vendor/autoload.php';

// Tests use their own database (DB_TEST_NAME, default <DB_NAME>_test), never the local dev one.
Env::load(dirname(__DIR__, 2) . '/.env');
$testDb = getenv('DB_TEST_NAME') ?: Env::get('DB_NAME') . '_test';
putenv('DB_NAME=' . $testDb);
putenv('APP_ENV=test');

$pdo = Connection::fromEnv();
TestDb::reset($pdo, Env::get('DB_NAME'));

// Before any output: PHP refuses session ini changes once output has started.
Session::configure($pdo, Session::gcSeconds(Settings::load($pdo, 1)));

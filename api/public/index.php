<?php

declare(strict_types=1);

use Cpdc\App;
use Cpdc\Db\Connection;
use Cpdc\Env;
use Cpdc\Http\ErrorHandler;
use Cpdc\Http\Middleware\Session;
use Cpdc\Http\Request;
use Cpdc\Http\Router;
use Cpdc\Http\SecurityHeaders;
use Cpdc\Settings\Settings;

// On shared hosting (Exabytes cPanel) the app folder sits above public_html; APP_ROOT points at it.
$root = getenv('APP_ROOT') ?: dirname(__DIR__, 2);
require $root . '/api/vendor/autoload.php';

ini_set('display_errors', '0');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false; // silenced with @ on purpose (best-effort cache and log writes)
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$storage = $root . '/storage'; // fallback log place if STORAGE_DIR itself is broken
try {
    Env::load($root . '/.env');
    $storage = Env::storageDir();
    Env::assertProdUrls();
    $pdo = Connection::fromEnv();
    $org = (int) Env::get('DEFAULT_ORGANISATION_ID');
    Session::configure($pdo, Session::gcSeconds(Settings::load($pdo, $org)));
    $app = new App(App::routes(new Router()), fn () => $pdo, Env::get('APP_URL'), $storage, $org);
} catch (\Throwable $e) {
    // Bootstrap failed (env, storage, database): generic 500 to the client, detail only in the log.
    SecurityHeaders::apply((new ErrorHandler($storage . '/logs'))->handle($e))->send();
    exit;
}
$app->handle(Request::fromGlobals())->send();

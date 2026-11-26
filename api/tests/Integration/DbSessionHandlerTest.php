<?php

declare(strict_types=1);

namespace Cpdc\Tests\Integration;

use Cpdc\Http\Middleware\DbSessionHandler;

final class DbSessionHandlerTest extends DbTestCase
{
    private int $now = 1_000_000;

    private function store(): DbSessionHandler
    {
        return new DbSessionHandler($this->pdo, fn (): int => $this->now);
    }

    public function testASessionIsStoredReadReplacedAndDestroyed(): void
    {
        $store = $this->store();
        $id = 'test-' . bin2hex(random_bytes(12));

        self::assertFalse($store->has($id));
        self::assertFalse($store->validateId($id));
        self::assertSame('', $store->read($id));

        $store->write($id, 'user_id|i:1;');
        self::assertTrue($store->has($id));
        self::assertTrue($store->validateId($id));
        self::assertSame('user_id|i:1;', $store->read($id));

        $store->write($id, 'user_id|i:2;');
        self::assertSame('user_id|i:2;', $store->read($id));

        $store->destroy($id);
        self::assertFalse($store->has($id));
    }

    public function testGcRemovesOnlySessionsOlderThanTheLifetime(): void
    {
        $store = $this->store();
        $old = 'old-' . bin2hex(random_bytes(12));
        $fresh = 'fresh-' . bin2hex(random_bytes(12));
        $store->write($old, 'a');
        $this->now += 3600;
        $store->write($fresh, 'b');

        self::assertGreaterThanOrEqual(1, $store->gc(1800));
        self::assertFalse($store->has($old));
        self::assertTrue($store->has($fresh));
    }

    public function testTouchingKeepsASessionFromGc(): void
    {
        $store = $this->store();
        $id = 'touch-' . bin2hex(random_bytes(12));
        $store->write($id, 'a');
        $this->now += 3600;
        $store->updateTimestamp($id, 'a');

        $store->gc(1800);
        self::assertTrue($store->has($id));
        self::assertSame('a', $store->read($id));
    }
}

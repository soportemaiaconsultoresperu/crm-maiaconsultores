<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integrations\Services\IdempotencyStore;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * IdempotencyStore — remember/has/forget/withLock contract.
 *
 * Uses the array cache driver (phpunit.xml forces CACHE_STORE=array) so
 * each test starts from a clean slate.
 */
class IdempotencyStoreTest extends TestCase
{
    private IdempotencyStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = app(IdempotencyStore::class);
        Cache::flush();
    }

    public function test_remember_returns_same_value_on_second_call_without_invoking_callback(): void
    {
        $key = 'idem-test-'.uniqid();

        $first = $this->store->remember($key, ['answer' => 42]);
        $this->assertSame(['answer' => 42], $first);

        $second = $this->store->remember($key, ['answer' => 99]); // ignored, already cached
        $this->assertSame(['answer' => 42], $second);
    }

    public function test_has_returns_true_after_remember_and_false_after_forget(): void
    {
        $key = 'idem-test-'.uniqid();
        $this->assertFalse($this->store->has($key));

        $this->store->remember($key, 'value');
        $this->assertTrue($this->store->has($key));

        $this->store->forget($key);
        $this->assertFalse($this->store->has($key));
    }

    public function test_with_lock_serialises_callback_invocation(): void
    {
        $key = 'idem-lock-test-'.uniqid();
        $counter = 0;

        $value = $this->store->withLock($key, function () use (&$counter): int {
            $counter++;

            return $counter;
        });

        $this->assertSame(1, $value);
        $this->assertSame(1, $counter);

        // A second withLock should re-enter the callback (lock released).
        $value2 = $this->store->withLock($key, function () use (&$counter): int {
            $counter++;

            return $counter;
        });

        $this->assertSame(2, $value2);
        $this->assertSame(2, $counter);
    }

    public function test_with_lock_releases_lock_even_when_callback_throws(): void
    {
        $key = 'idem-lock-throw-'.uniqid();

        $this->expectException(\RuntimeException::class);

        try {
            $this->store->withLock($key, function (): void {
                throw new \RuntimeException('boom');
            });
        } finally {
            // We should be able to immediately re-acquire the same lock.
            $value = $this->store->withLock($key, fn (): string => 'recovered');
            $this->assertSame('recovered', $value);
        }
    }
}
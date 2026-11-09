<?php

namespace App\Services;

use Closure;
use PHPUnit\Framework\Assert;

/**
 * Records instead of sending. Bound by Analytics::fake() in tests; records
 * whether or not a key is configured, so a test checks what would be sent.
 */
class AnalyticsFake extends Analytics
{
    /** @var list<array{type: string, distinct_id: string, event?: string, properties: array<string, mixed>}> */
    public array $sent = [];

    public function enabled(): bool
    {
        return true;
    }

    protected function dispatch(array $message): void
    {
        $this->sent[] = $message;
    }

    /**
     * @return list<array{distinct_id: string, event: string, properties: array<string, mixed>}>
     */
    public function captured(string $event, ?Closure $where = null): array
    {
        return array_values(array_filter(
            $this->sent,
            fn (array $m) => $m['type'] === 'capture' && $m['event'] === $event
                && ($where === null || $where($m['properties'], $m['distinct_id'])),
        ));
    }

    /**
     * @return list<array<string, mixed>> each call's person properties
     */
    public function setFor(string $userId): array
    {
        return array_values(array_map(
            fn (array $m) => $m['properties'],
            array_filter($this->sent, fn (array $m) => $m['type'] === 'set' && $m['distinct_id'] === $userId),
        ));
    }

    public function assertCapturedTimes(string $event, int $times, ?Closure $where = null): void
    {
        $count = count($this->captured($event, $where));
        Assert::assertSame($times, $count, "Expected [{$event}] captured {$times} time(s), got {$count}.");
    }

    public function assertCapturedOnce(string $event, ?Closure $where = null): void
    {
        $this->assertCapturedTimes($event, 1, $where);
    }

    public function assertNotCaptured(string $event): void
    {
        $this->assertCapturedTimes($event, 0);
    }

    /**
     * The one capture of $event, its property keys exactly $keys (any order).
     *
     * @param  list<string>  $keys
     * @return array{distinct_id: string, event: string, properties: array<string, mixed>}
     */
    public function assertCapturedWithKeys(string $event, array $keys): array
    {
        $this->assertCapturedOnce($event);
        $message = $this->captured($event)[0];
        $actual = array_keys($message['properties']);
        sort($actual);
        sort($keys);
        Assert::assertSame($keys, $actual, "Property keys of [{$event}] differ.");

        return $message;
    }
}

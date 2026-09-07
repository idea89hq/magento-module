<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model;

use Idea89\Assistant\Model\CheckoutRateLimit;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * CheckoutRateLimit had no dedicated coverage before the Task 3.3 review
 * round — it was exercised only indirectly, through mocks, in
 * Test/Unit/Controller/Checkout/PlaceTest.php. This file tests the class on
 * its own, focused on the two hardenings the review added: the
 * LockManagerInterface-guarded critical section (atomicity — a real
 * concurrent burst can't be simulated in a single-threaded PHPUnit process,
 * so this proves the atomic PRIMITIVE — the mutex — is actually wired
 * around the read-modify-write, and that a contested lock fails closed
 * rather than silently letting the request through), and the IP-keyed
 * second bucket.
 */
class CheckoutRateLimitTest extends TestCase
{
    /**
     * @param string|false $sessionLoad What CacheInterface::load() returns
     *        for the session-bucket key (false = cache miss, fresh bucket).
     * @param string|false $ipLoad Same, for the ip-bucket key.
     */
    private function build($sessionLoad = false, $ipLoad = false, bool $lockAcquires = true): array
    {
        $savedKeys = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            function (string $key) use ($sessionLoad, $ipLoad) {
                if (str_contains($key, ':session:')) {
                    return $sessionLoad;
                }
                if (str_contains($key, ':ip:')) {
                    return $ipLoad;
                }
                return false;
            }
        );
        $cache->method('save')->willReturnCallback(
            function (string $data, string $identifier) use (&$savedKeys) {
                $savedKeys[] = $identifier;
                return true;
            }
        );

        $lockedNames = [];
        $unlockedNames = [];
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturnCallback(
            function (string $name, int $timeout) use (&$lockedNames, $lockAcquires) {
                $lockedNames[] = $name;
                return $lockAcquires;
            }
        );
        $lockManager->method('unlock')->willReturnCallback(
            function (string $name) use (&$unlockedNames) {
                $unlockedNames[] = $name;
                return true;
            }
        );

        return [
            'rateLimit' => new CheckoutRateLimit($cache, $lockManager),
            'cache' => $cache,
            'lockManager' => $lockManager,
            'savedKeys' => &$savedKeys,
            'lockedNames' => &$lockedNames,
            'unlockedNames' => &$unlockedNames,
        ];
    }

    // --- Baseline sanity ---------------------------------------------

    public function testAllowsAFreshSessionAndIpUnderBothCeilings(): void
    {
        $b = $this->build(sessionLoad: false, ipLoad: false);
        $result = $b['rateLimit']->checkOther('session-1', '203.0.113.5');
        $this->assertTrue($result['allowed']);
    }

    public function testDeniesWhenTheSessionBucketIsAtItsOwnCeiling(): void
    {
        $atCeiling = (string) json_encode([
            'attempts' => CheckoutRateLimit::LIMIT_OTHER_PER_WINDOW,
            'window_start' => time(),
        ]);
        $b = $this->build(sessionLoad: $atCeiling, ipLoad: false);
        $result = $b['rateLimit']->checkOther('session-1', '203.0.113.5');
        $this->assertFalse($result['allowed']);
    }

    // --- The IP dimension the review asked for ------------------------

    /**
     * The point of the second bucket: a session that has never been seen
     * before (fresh, well under its own ceiling) is still denied if the IP
     * it's coming from is already at ITS ceiling — this is what actually
     * raises the bar against a client that rotates sessions to dodge the
     * per-session limit, since the IP doesn't move when the session does.
     */
    public function testDeniesWhenTheIpBucketIsAtItsCeilingEvenWithAFreshSession(): void
    {
        $ipAtCeiling = (string) json_encode([
            'attempts' => CheckoutRateLimit::LIMIT_OTHER_PER_IP_WINDOW,
            'window_start' => time(),
        ]);
        $b = $this->build(sessionLoad: false, ipLoad: $ipAtCeiling);
        $result = $b['rateLimit']->checkOther('brand-new-session', '203.0.113.5');
        $this->assertFalse($result['allowed']);
    }

    // --- Atomicity: the mutex is actually wired around the critical section ---

    /**
     * True concurrency can't be simulated in a single-threaded PHPUnit
     * process, so this tests the PRIMITIVE the fix relies on directly: when
     * the mutex can't be acquired (the exact situation a real concurrent
     * burst on the same bucket produces — the second request finds the
     * first one still holding the lock), the request must be denied, and
     * the read-modify-write critical section must never even run — proven
     * by asserting cache->save() is never called, not just by checking the
     * boolean result.
     */
    public function testFailingToAcquireTheLockDeniesAndNeverWritesTheCounter(): void
    {
        $b = $this->build(sessionLoad: false, ipLoad: false, lockAcquires: false);
        $b['cache']->expects($this->never())->method('save');

        $result = $b['rateLimit']->checkPlace('session-1', '203.0.113.5');

        $this->assertFalse($result['allowed']);
    }

    /**
     * checkOther()/checkPlace() each guard TWO independent buckets (session,
     * ip) — this asserts the mutex is actually taken around both, not just
     * one, and that every lock acquired is released (same name paired on
     * each side), which is what makes it safe to hold the lock across the
     * load()+save() pair without deadlocking a later request.
     */
    public function testLockIsAcquiredAndReleasedOnceForEachOfTheTwoBuckets(): void
    {
        $b = $this->build();
        $b['rateLimit']->checkOther('session-1', '203.0.113.5');

        $this->assertCount(2, $b['lockedNames']);
        $this->assertCount(2, $b['unlockedNames']);
        $this->assertSame($b['lockedNames'], $b['unlockedNames']);
        // One lock name mentions the session bucket, the other the ip bucket.
        $joined = implode('|', $b['lockedNames']);
        $this->assertStringContainsString(':session:', $joined);
        $this->assertStringContainsString(':ip:', $joined);
    }
}

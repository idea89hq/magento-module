<?php

declare(strict_types=1);

namespace Idea89\Assistant\Model;

use Magento\Framework\App\CacheInterface;

/**
 * IP-bucketed throttle for the guest order-lookup endpoint. Prevents
 * order-number enumeration by capping attempts per IP per hour. Storage
 * piggybacks on Magento's `default` cache (file or Redis, depending on
 * env) so we don't need a new schema or table.
 *
 * Window: 1 hour rolling, anchored to first attempt in the window.
 * Limit: 10 attempts/IP/hour — generous enough for legit shoppers who
 *        fat-finger their order number across two devices or family
 *        members sharing an IP, tight enough to make enumeration
 *        economically infeasible. With 8–10 digit order numbers the
 *        keyspace is ~10^8 → 240 guesses/day means ~414K days to
 *        exhaust per IP. Bumped from 4 → 10 on 2026-05-29 after a
 *        legit-user lockout during testing.
 *
 * On successful match, callers should invoke reset() to clear the
 * bucket — legit users finding their order shouldn't be penalised
 * for prior typos when they come back to check the next order.
 *
 * The bucket auto-expires via Magento's cache TTL once the window
 * passes; no explicit cleanup needed.
 */
class GuestLookupRateLimit
{
    public const LIMIT_PER_WINDOW = 10;
    public const WINDOW_SECONDS   = 3600;

    private const CACHE_KEY_PREFIX = 'idea89_guest_order_lookup:';
    /**
     * Cache tag used so a future "wipe rate limits" action could nuke
     * just our buckets without touching everything else in the cache.
     */
    private const CACHE_TAG = 'IDEA89_RATE_LIMIT';

    public function __construct(
        private readonly CacheInterface $cache
    ) {}

    /**
     * @return array{allowed: bool, attempts: int, retry_after: int}
     *         `retry_after` is seconds until the window resets (0 when allowed).
     */
    public function check(string $ip): array
    {
        $key = self::CACHE_KEY_PREFIX . $this->hashIp($ip);
        $raw = $this->cache->load($key);
        $now = time();

        $attempts = 0;
        $windowStart = $now;
        if (is_string($raw) && $raw !== '') {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)
                && isset($parsed['attempts'], $parsed['window_start'])
                && is_int($parsed['attempts'])
                && is_int($parsed['window_start'])
            ) {
                $attempts = $parsed['attempts'];
                $windowStart = $parsed['window_start'];
            }
        }

        // Window expired → reset.
        if ($now - $windowStart >= self::WINDOW_SECONDS) {
            $attempts = 0;
            $windowStart = $now;
        }

        $attempts++;
        $allowed = $attempts <= self::LIMIT_PER_WINDOW;
        $retryAfter = $allowed
            ? 0
            : max(0, ($windowStart + self::WINDOW_SECONDS) - $now);

        // Persist the new state. TTL = remaining window so the bucket
        // auto-clears at the boundary.
        $remaining = ($windowStart + self::WINDOW_SECONDS) - $now;
        $this->cache->save(
            (string) json_encode(['attempts' => $attempts, 'window_start' => $windowStart]),
            $key,
            [self::CACHE_TAG],
            max(1, $remaining)
        );

        return [
            'allowed' => $allowed,
            'attempts' => $attempts,
            'retry_after' => $retryAfter,
        ];
    }

    /**
     * Clear the bucket for an IP. Call this after a SUCCESSFUL match so
     * legitimate users who fat-fingered earlier attempts don't have
     * their next order-tracking session counting from a stale base.
     * Idempotent — safe to call when there's no bucket.
     */
    public function reset(string $ip): void
    {
        $this->cache->remove(self::CACHE_KEY_PREFIX . $this->hashIp($ip));
    }

    /**
     * Hash the IP so cache keys aren't browseable as raw IPs in any
     * cache backend's CLI. Uses sha256 truncated to 24 chars — entropy
     * is plenty against collisions in a per-IP bucket.
     */
    private function hashIp(string $ip): string
    {
        return substr(hash('sha256', $ip), 0, 24);
    }
}

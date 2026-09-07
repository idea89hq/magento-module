<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Session-bucketed throttle for the native checkout (Tier 3) façade routes.
 * Structure copied verbatim from GuestLookupRateLimit — same cache-backed
 * rolling window, same return shape — with two changes: the identifier is
 * the shopper's session id (not an IP; the session already IS the identity
 * for this feature, per Model/Checkout/Facade.php), and there are two
 * limits instead of one, because placing an order deserves a tighter ceiling
 * than looking at a quote or re-quoting shipping.
 *
 * Window: 10 minutes rolling, anchored to first attempt in the window.
 * Limits:
 *   - place():   8 attempts/session/10 min. This is the one that spends
 *     money and creates support tickets, so it gets the tight ceiling.
 *   - everything else (context/setAddress/setShippingMethod): 30/session/10
 *     min — generous enough for a shopper editing their address a few
 *     times or re-quoting shipping after a typo, tight enough to stop a
 *     scripted hammering of the quote endpoints.
 *
 * Storage piggybacks on Magento's `default` cache, same as
 * GuestLookupRateLimit — no new schema or table.
 *
 * Two hardenings, both of which are gaps a live store would hit:
 *
 *   1. ATOMICITY. Magento\Framework\App\CacheInterface exposes no atomic
 *      increment or compare-and-set — load() and save() are always two
 *      separate calls, on every backend (file, DB, memcached, Redis; the
 *      module can't assume which one a merchant runs), so N concurrent
 *      requests on the same bucket could all load the same pre-increment
 *      count and all pass. Guarded here with
 *      Magento\Framework\Lock\LockManagerInterface — a real mutex, bound in
 *      core's own app/etc/di.xml to Magento\Framework\Lock\Proxy and
 *      backend-selectable independently of the cache (defaults to a
 *      DB-based lock) — around the load-increment-save critical section.
 *      A failure to acquire the lock fails CLOSED (treated as over the
 *      limit): a request that can't even get the mutex within the timeout
 *      is itself the burst scenario this exists to catch, not a reason to
 *      skip the check.
 *
 *   2. A SECOND, IP-KEYED BUCKET alongside the session bucket, with a
 *      looser ceiling (3x). Session-keyed limiting alone is defeated by a
 *      client that simply discards its session cookie: Magento mints a
 *      fresh, valid session id — and a matching valid form key — on any
 *      ordinary storefront GET, so a script can rotate into a brand-new
 *      session bucket on every attempt with no XSS or cross-origin trick
 *      needed. The IP bucket doesn't move when the session does, so it's
 *      what actually raises the bar: an attacker then has to rotate source
 *      addresses too. Both buckets are checked (and incremented) on every
 *      call; a request is allowed only when both are within their own
 *      ceiling.
 *
 *      This is friction against a naive/scripted client and a guard against
 *      a concurrent burst, not a defence against a determined attacker with
 *      an IP pool who does return cookies selectively — that residual is
 *      accepted, not solved, and is no worse than the exposure of the
 *      merchant's own native /checkout with guest checkout enabled (script
 *      a session, place a cash-on-delivery order): see the task report.
 *
 *      THE $ip ARGUMENT'S TRUST BOUNDARY (added round 2, after review found
 *      the first version silently spoofable): the caller (see any
 *      Controller/Checkout/*.php's clientIp()) sources this from
 *      Magento\Framework\HTTP\PhpEnvironment\RemoteAddress::getRemoteAddress(),
 *      not the raw request — RemoteAddress validates the candidate as a
 *      real IP and, when the store has trusted proxies configured
 *      (its $trustedProxies constructor argument), filters out the proxy
 *      hops to find the actual client address. WITHOUT that configuration
 *      — Magento's shipped default, and the state of this store as
 *      deployed — X-Forwarded-For is still attacker-controlled: nothing
 *      strips it, so a caller can set a fresh one per request and still
 *      land in a fresh IP bucket every time, same as before. In that
 *      unconfigured state this bucket is friction, not a boundary — the
 *      SESSION bucket is the one doing real work. Configuring trusted
 *      proxies for the store's edge (this project runs behind Caddy in
 *      production) is what makes the IP bucket mean something. That is a
 *      deploy-time step, not something this class can do for you.
 */
class CheckoutRateLimit
{
    public const LIMIT_PLACE_PER_WINDOW = 8;
    public const LIMIT_PLACE_PER_IP_WINDOW = 24;
    public const LIMIT_OTHER_PER_WINDOW = 30;
    public const LIMIT_OTHER_PER_IP_WINDOW = 90;
    public const WINDOW_SECONDS = 600;

    private const CACHE_KEY_PREFIX = 'idea89_native_checkout:';
    /**
     * Cache tag used so a future "wipe rate limits" action could nuke
     * just our buckets without touching everything else in the cache.
     */
    private const CACHE_TAG = 'IDEA89_RATE_LIMIT';
    /** How long to wait for a bucket's mutex before failing closed. */
    private const LOCK_TIMEOUT_SECONDS = 3;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LockManagerInterface $lockManager
    ) {}

    /**
     * @return array{allowed: bool, attempts: int, retry_after: int}
     *         `retry_after` is seconds until the window resets (0 when allowed).
     */
    public function checkPlace(string $sessionId, string $ip): array
    {
        return $this->checkBoth(
            'place',
            $sessionId,
            $ip,
            self::LIMIT_PLACE_PER_WINDOW,
            self::LIMIT_PLACE_PER_IP_WINDOW
        );
    }

    /**
     * @return array{allowed: bool, attempts: int, retry_after: int}
     */
    public function checkOther(string $sessionId, string $ip): array
    {
        return $this->checkBoth(
            'other',
            $sessionId,
            $ip,
            self::LIMIT_OTHER_PER_WINDOW,
            self::LIMIT_OTHER_PER_IP_WINDOW
        );
    }

    /**
     * Both dimensions are checked — and incremented — on every call: each
     * is its own independent counter of real attempts regardless of the
     * other's outcome, rather than short-circuiting in a way an attacker
     * could use to avoid feeding one bucket. The request is allowed only if
     * BOTH are within their own ceiling; whichever denies first is the one
     * reported back, since its retry_after is the meaningful one.
     *
     * @return array{allowed: bool, attempts: int, retry_after: int}
     */
    private function checkBoth(
        string $bucket,
        string $sessionId,
        string $ip,
        int $sessionLimit,
        int $ipLimit
    ): array {
        $session = $this->check($bucket, 'session:' . $this->hashValue($sessionId), $sessionLimit);
        $ipResult = $this->check($bucket, 'ip:' . $this->hashValue($ip), $ipLimit);

        return $session['allowed'] ? $ipResult : $session;
    }

    /**
     * @return array{allowed: bool, attempts: int, retry_after: int}
     */
    private function check(string $bucket, string $keySuffix, int $limit): array
    {
        $key = self::CACHE_KEY_PREFIX . $bucket . ':' . $keySuffix;
        $lockName = $key . ':lock';

        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT_SECONDS)) {
            return ['allowed' => false, 'attempts' => $limit + 1, 'retry_after' => self::WINDOW_SECONDS];
        }

        try {
            return $this->checkLocked($key, $limit);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * The original load-increment-save body, unchanged except for being
     * moved under the mutex in check() above.
     *
     * @return array{allowed: bool, attempts: int, retry_after: int}
     */
    private function checkLocked(string $key, int $limit): array
    {
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
        $allowed = $attempts <= $limit;
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
     * Hash a session id or IP so cache keys aren't browseable as raw
     * identifiers in any cache backend's CLI. Uses sha256 truncated to 24
     * chars — entropy is plenty against collisions in a per-bucket key.
     */
    private function hashValue(string $value): string
    {
        return substr(hash('sha256', $value), 0, 24);
    }
}

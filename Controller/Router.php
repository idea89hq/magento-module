<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Controller;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RouterInterface;
use Idea89\Assistant\Model\LocatorConfig;

/**
 * Resolves URLs Magento's standard router cannot dispatch on its own.
 *
 * Two independent cases live here:
 *
 *   1. The merchant's custom URL slug for the store finder page. The
 *      default `/store-finder` is registered via `etc/frontend/routes.xml`
 *      (frontName = "store-finder") and works without any routing magic.
 *      When the merchant sets `idea89/locator/url_path` to something else
 *      — e.g. "showrooms" or "find-a-shop" — Magento's standard router
 *      won't know about it, so this router catches the request and
 *      forwards it to the locator action. Address bar stays on the custom
 *      slug because we use a `Forward` action, not a redirect. Default
 *      behaviour is preserved: empty config or value equal to
 *      "store-finder" makes this branch a no-op.
 *
 *   2. The ACP product feed's literal `.json` URL
 *      (`/idea89/acp/feed.json`, task-4.3-brief.md). Magento's standard
 *      router can never reach this on its own: action-name resolution
 *      (`Magento\Framework\App\Router\ActionList::get()`) builds the
 *      controller-class lookup key from the raw path segment after the
 *      controller folder — "feed.json" — but the key the framework
 *      actually indexes for `Controller/Acp/Feed.php` is "feed" (the
 *      filename, sans extension, is never itself split on the dot). No
 *      controller filename can satisfy that URL under standard routing;
 *      only a router that rewrites the path before the standard router
 *      sees it can. This branch does exactly that: strip the ".json"
 *      suffix and Forward, the same technique branch 1 already uses.
 *
 *   3. The five ACP checkout-session routes (`/idea89/acp/checkout_sessions`
 *      and its `/{id}`, `/{id}/complete`, `/{id}/cancel` variants,
 *      task-4.4-brief.md). Standard routing fails on even the bare route
 *      here, and for a DIFFERENT reason than branch 2's dot: action-name
 *      resolution lower-cases the entire module+controller+action string
 *      and THEN runs `str_replace('_', '\\', ...)` across all of it, so
 *      the underscore in "checkout_sessions" is read as a namespace
 *      separator — the lookup key becomes "...\acp\checkout\sessions",
 *      two segments, while the key
 *      `Magento\Framework\Module\Dir\Reader::getActionFiles()` has on file
 *      for `Controller/Acp/CheckoutSessions.php` is "...\acp\checkoutsessions",
 *      one (it takes that key straight from the filename and never
 *      re-splits it on underscore). Same fix shape as branch 2: rewrite
 *      the prefix to a segment standard routing CAN resolve
 *      ("checkoutsessions", no underscore) and Forward. Unlike branch 2,
 *      any `/{id}`, `/complete` or `/cancel` suffix is deliberately left
 *      unparsed here — Controller/Acp/CheckoutSessions.php's own job is
 *      "redirect or decline", not "which session", so it does not need
 *      the suffix split into params; it reads the untouched original path
 *      itself via `Http::getOriginalPathInfo()` when it needs to build a
 *      redirect target.
 *
 * Registered in `etc/frontend/di.xml` with sortOrder before the default
 * standard router (which would 404 all three of the above).
 */
class Router implements RouterInterface
{
    private const ACP_FEED_PATH = 'idea89/acp/feed.json';
    private const ACP_FEED_TARGET = 'idea89/acp/feed';

    private const ACP_CHECKOUT_SESSIONS_PATH = 'idea89/acp/checkout_sessions';
    private const ACP_CHECKOUT_SESSIONS_TARGET = 'idea89/acp/checkoutsessions';

    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly LocatorConfig $locatorConfig
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        // We can only meaningfully inspect HTTP requests — CLI / admin
        // routing flows pass other types here.
        if (!$request instanceof HttpRequest) {
            return null;
        }

        if (trim((string) $request->getPathInfo(), '/') === self::ACP_FEED_PATH) {
            // Claimed unconditionally, same philosophy as the locator slug
            // below: this router's only job is "can the standard router
            // reach the right controller class". Whether the feed is
            // actually enabled is Controller/Acp/Feed.php's own call (Auth
            // + CheckoutConfig::isAcpFeedEnabled()), not this router's.
            $request->setPathInfo('/' . self::ACP_FEED_TARGET);
            return $this->actionFactory->create(Forward::class);
        }

        $checkoutSessionsPath = trim((string) $request->getPathInfo(), '/');
        if ($checkoutSessionsPath === self::ACP_CHECKOUT_SESSIONS_PATH
            || str_starts_with($checkoutSessionsPath, self::ACP_CHECKOUT_SESSIONS_PATH . '/')
        ) {
            // Claimed unconditionally for the same reason as the feed
            // branch above: this router only gets the standard router to
            // the right controller class. Whether the request is
            // authorised, and whether a transactional module is installed,
            // are entirely Controller/Acp/CheckoutSessions.php's own call.
            // The `/{id}`, `/complete`, `/cancel` suffix is intentionally
            // NOT parsed or forwarded as a param here — see this class's
            // docblock, branch 3.
            $request->setPathInfo('/' . self::ACP_CHECKOUT_SESSIONS_TARGET);
            return $this->actionFactory->create(Forward::class);
        }

        $configured = $this->locatorConfig->getUrlPath();
        // Default slug is handled by the standard router; nothing to do.
        if ($configured === '' || $configured === LocatorConfig::DEFAULT_URL_PATH) {
            return null;
        }

        $path = trim((string) $request->getPathInfo(), '/');
        if ($path !== $configured) {
            return null;
        }

        // From here on we KNOW this URL is the merchant's locator slug.
        // We claim it unconditionally and let Controller/Index/Index decide
        // whether to render or 404 — its existing enable + api-key checks
        // are the single source of truth for "is the locator live". Falling
        // through here when enabled=0 would let url-rewrite serve a stale
        // CMS page at the same slug (e.g. an old /showrooms CMS page from
        // before the merchant set this field).

        // CRITICAL: rewrite pathInfo to the canonical frontName so the
        // next router-loop iteration matches `/store-finder` via the
        // standard router — NOT our custom slug again. Without this
        // rewrite, Forward re-enters routing with the same pathInfo
        // and we hit Magento's 100-iteration cap (Front controller
        // reached 100 router match iterations). Pattern lifted from
        // Magento\UrlRewrite\Controller\Router (which also rewrites
        // pathInfo before returning Forward).
        $request->setPathInfo('/' . LocatorConfig::DEFAULT_URL_PATH);

        return $this->actionFactory->create(Forward::class);
    }
}

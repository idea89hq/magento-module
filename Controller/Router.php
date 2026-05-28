<?php

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
 * Resolves the merchant's custom URL slug for the store finder page.
 *
 * The default `/store-finder` is registered via `etc/frontend/routes.xml`
 * (frontName = "store-finder") and works without any routing magic. When
 * the merchant sets `idea89/locator/url_path` to something else — e.g.
 * "showrooms" or "find-a-shop" — Magento's standard router won't know
 * about it, so this router catches the request and forwards it to the
 * locator action.
 *
 * Address bar stays on the custom slug because we use a `Forward` action,
 * not a redirect. Default behaviour is preserved: empty config or value
 * equal to "store-finder" makes this router a no-op.
 *
 * Registered in `etc/frontend/di.xml` with sortOrder before the default
 * standard router (which would 404 the custom slug).
 */
class Router implements RouterInterface
{
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

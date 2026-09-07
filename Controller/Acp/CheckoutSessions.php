<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Controller\Acp;

use Idea89\Assistant\Model\Acp\Auth;
use Idea89\Assistant\Model\Acp\Delegate;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Handles all five ACP checkout-session routes (task-4.4-brief.md):
 *
 *   POST   /idea89/acp/checkout_sessions
 *   POST   /idea89/acp/checkout_sessions/{id}
 *   GET    /idea89/acp/checkout_sessions/{id}
 *   POST   /idea89/acp/checkout_sessions/{id}/complete
 *   POST   /idea89/acp/checkout_sessions/{id}/cancel
 *
 * all funnelled here by Controller/Router.php's third branch, because none
 * of them can reach this class through standard routing — the underscore
 * in "checkout_sessions" gets read by
 * Magento\Framework\App\Router\ActionList::get() as a namespace separator
 * (it lower-cases the WHOLE module+controller+action string and then does
 * str_replace('_', '\\', ...) across all of it), so the action key it
 * builds is "...\acp\checkout\sessions" — two segments — while the key
 * ActionList's own cache has on file for this class is
 * "...\acp\checkoutsessions" — one, because Magento\Framework\Module\Dir\
 * Reader::getActionFiles() takes that key straight from the filename
 * (CheckoutSessions.php) and never re-splits it on underscore. Same reason
 * Task 4.3 needed a router branch for feed.json's literal dot; this is the
 * same problem, one layer earlier — the mismatch already exists on the
 * bare route, before an id or /complete /cancel suffix is even in play.
 *
 * This controller does NOT parse which of the five it is being asked for.
 * Every path here does exactly one of two things, and neither depends on
 * the session id or the sub-action:
 *
 *   - Magebit's module is installed → 307 to its equivalent route,
 *     preserving whatever path suffix (id, /complete, /cancel) the caller
 *     used. The 307 itself is what preserves the method and body — that is
 *     what a 307 (unlike a 302/303) is defined to do; nothing here reads
 *     the request body or resends anything. Deliberately NOT a proxy: see
 *     Model/Acp/Delegate.php's docblock for why reimplementing Magebit's
 *     checkout-session surface, or bridging into it, is out of scope.
 *   - Not installed → 501 with the fixed, documented decline body.
 *
 * Auth::check() runs FIRST in both branches, exactly as Feed.php does, and
 * for the same reason: an unauthorised caller must not be able to tell
 * which of the two states the store is in by watching for a 307 vs a 501
 * — that is a free reconnaissance signal. Delegate is never even consulted
 * unless Auth::check() has already returned null.
 */
class CheckoutSessions implements HttpGetActionInterface, HttpPostActionInterface
{
    /**
     * The path segment this controller is reached under, once
     * Controller/Router.php has rewritten it — see that file's docblock.
     * Stripped from Http::getOriginalPathInfo() (the UNREWRITTEN incoming
     * path) to recover whatever the caller appended after
     * "checkout_sessions" (an id, "/id/complete", "/id/cancel", or
     * nothing for the bare create route).
     */
    private const IDEA89_ROUTE_PREFIX = 'idea89/acp/checkout_sessions';

    /**
     * Magebit_AgenticCommerce's own route, confirmed against its public
     * source (github.com/magebitcom/magento2-agentic-commerce-module,
     * etc/frontend/routes.xml: frontName "agentic_commerce"; Model/
     * Config::getCheckoutRouterBasePath() default "checkout_sessions",
     * etc/config.xml's agentic_checkout/router_base_path). Both modules
     * use "checkout_sessions" as the base path segment because the ACP
     * spec mandates that literal name, not by coincidence between the two
     * implementations.
     *
     * This IS the one assumption in this file that cannot be verified on
     * this store — Magebit's module is not installed here, and installing
     * it to check is not this task's call to make (see the task brief).
     * If a merchant reconfigures Magebit's router_base_path away from its
     * shipped default, this redirect target goes stale; we have no way to
     * read that module's own config without building the bridge the brief
     * explicitly rules out. Flagged in the human-verification doc.
     */
    private const TRANSACTIONAL_FRONT_NAME = 'agentic_commerce';
    private const TRANSACTIONAL_BASE_PATH = 'checkout_sessions';

    /**
     * The exact body task-4.4-brief.md documents, byte-for-byte — a
     * merchant's guide links this "message" text, so it is not free to
     * reword.
     */
    private const DECLINE_BODY = [
        'type' => 'not_implemented',
        'code' => 'agentic_checkout_unavailable',
        'message' => 'This store publishes an ACP product feed but does not accept '
            . 'agent-initiated checkout. See https://idea89.com/docs/agentic-commerce',
    ];

    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly Http $request,
        private readonly Auth $auth,
        private readonly Delegate $delegate,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();
        // Never cacheable: a 307 must not be replayed to a caller once
        // Magebit's module is installed or removed, and a 501/error body
        // must not be replayed once ACP is reconfigured. Unlike Feed.php,
        // there is no successful "public, cacheable" state on this route
        // to carve an exception out for.
        $result->setHeader('Cache-Control', 'no-store', true);

        $authError = $this->auth->check($this->request);
        if ($authError !== null) {
            return $result->setHttpResponseCode($this->statusFor($authError))
                ->setData(['error' => $authError]);
        }

        if ($this->delegate->isTransactionalModuleInstalled()) {
            $location = $this->redirectLocation();
            return $result->setHttpResponseCode(307)
                ->setHeader('Location', $location, true)
                ->setData(['redirected_to' => $location]);
        }

        return $result->setHttpResponseCode(501)->setData(self::DECLINE_BODY);
    }

    /**
     * Same reasoning as Feed.php's statusFor(): Auth::check()'s error
     * codes are a documented string contract (task-4.2-brief.md), not
     * exported constants, and Auth.php is off-limits to modify for this
     * task — so this maps the three known values itself rather than
     * import Feed.php's private method (which is also off-limits).
     */
    private function statusFor(string $errorCode): int
    {
        return match ($errorCode) {
            'not_enabled' => 404,
            'unauthorized' => 401,
            'unsupported_api_version' => 400,
            default => 400,
        };
    }

    /**
     * Rebuilds the caller's full request path from
     * Http::getOriginalPathInfo() — the UNREWRITTEN path, unaffected by
     * Controller/Router.php's setPathInfo() call — strips this route's
     * own prefix off it, and re-mounts whatever is left (nothing, an id,
     * or "id/complete" / "id/cancel") under Magebit's route.
     */
    private function redirectLocation(): string
    {
        $originalPath = trim((string) $this->request->getOriginalPathInfo(), '/');
        $suffix = '';
        if (str_starts_with($originalPath, self::IDEA89_ROUTE_PREFIX)) {
            $suffix = substr($originalPath, strlen(self::IDEA89_ROUTE_PREFIX));
        }

        $targetPath = self::TRANSACTIONAL_FRONT_NAME . '/' . self::TRANSACTIONAL_BASE_PATH . $suffix;
        $baseUrl = rtrim((string) $this->storeManager->getStore()->getBaseUrl(), '/');

        return $baseUrl . '/' . $targetPath;
    }
}

<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Controller\Acp;

use Idea89\Assistant\Model\Acp\Auth;
use Idea89\Assistant\Model\Acp\FeedBuilder;
use Idea89\Assistant\Model\CheckoutConfig;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;

/**
 * GET /idea89/acp/feed.json?page=1
 *
 * Publishes this store's catalog in the Agentic Commerce Protocol product
 * feed shape so an external agent (ChatGPT and similar) can discover what
 * the store sells. Read-only, carries no payment/order surface — see
 * task-4.3-brief.md. Not same-origin gated: unlike Controller/Checkout/*,
 * this route is deliberately called by callers that are NOT the merchant's
 * own storefront, so Auth (bearer + API-Version) is the only gate, exactly
 * as Controller/Checkout/Context.php's docblock anticipates for the ACP
 * surface.
 *
 * Two independent config gates apply, checked in this order so an
 * unauthorised caller never learns which one is off:
 *   1. Auth::check() — not_enabled (acp_enabled=No) / unauthorized /
 *      unsupported_api_version.
 *   2. acp_feed_enabled — the feed's own sub-toggle (a store can run ACP
 *      for a future checkout-session surface without publishing the feed).
 *      Reported the same way as an unrecognised route: 404 not_available,
 *      not a distinct code — see Controller/Checkout/Context.php's "404,
 *      not 403" reasoning, which applies here for the same reason.
 */
class Feed implements HttpGetActionInterface
{
    private const DEFAULT_PAGE = 1;

    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly Http $request,
        private readonly Auth $auth,
        private readonly CheckoutConfig $checkoutConfig,
        private readonly FeedBuilder $feedBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        // Default every response to no-store. The public, max-age=900
        // header is applied ONLY on the successful 200 path below, once the
        // feed body it actually describes exists — never up front. Setting
        // it here unconditionally (as an earlier version of this method
        // did) would let a CDN/proxy cache a not_enabled/not_available 404
        // for 15 minutes on a public (no-token) store: a merchant who just
        // flipped the feed on would see it appear to stay off. A
        // bearer-gated store never reaches this concern the same way — an
        // unauthorised 401 there is correctly no-store already, and a
        // caller who does not yet have the token has nothing cacheable to
        // leak.
        $result->setHeader('Cache-Control', 'no-store', true);

        $authError = $this->auth->check($this->request);
        if ($authError !== null) {
            return $result->setHttpResponseCode($this->statusFor($authError))
                ->setData(['error' => $authError]);
        }

        if (!$this->checkoutConfig->isAcpFeedEnabled()) {
            return $result->setHttpResponseCode(404)->setData(['error' => 'not_available']);
        }

        $page = (int) $this->request->getParam('page', self::DEFAULT_PAGE);
        $pageSize = (int) $this->request->getParam('page_size', FeedBuilder::DEFAULT_PAGE_SIZE);

        try {
            $feed = $this->feedBuilder->build($page, $pageSize);
        } catch (\Throwable $e) {
            $this->logger->error('[idea89] ACP feed build failed', ['exception' => $e]);
            return $result->setHttpResponseCode(500)->setData(['error' => 'internal_error']);
        }

        // A public feed should be cacheable (it's the same document for
        // every caller); a bearer-gated one must not be — caching it would
        // mean the CDN/proxy serves an authorised response to the next,
        // unauthenticated, requester. Only reached once the feed has
        // actually been built successfully.
        if ($this->checkoutConfig->getAcpSecret() === '') {
            $result->setHeader('Cache-Control', 'public, max-age=900', true);
        }

        return $result->setData($feed);
    }

    /**
     * Auth::check()'s error codes are a documented string contract
     * (task-4.2-brief.md's "Produces" line), not exported constants — Auth.php
     * is explicitly off-limits to modify for this task, so this maps the
     * three known values and falls back to 400 for anything else rather
     * than risk crashing on a code this controller doesn't recognise yet.
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
}

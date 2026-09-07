<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Controller;

use Idea89\Assistant\Controller\Router;
use Idea89\Assistant\Model\LocatorConfig;
use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use PHPUnit\Framework\TestCase;

/**
 * No test file existed for Router.php before task-4.4-brief.md added a
 * third branch (ACP checkout sessions) alongside the two that were already
 * here (the ACP feed's literal ".json" and the locator custom slug). The
 * brief requires proving the new branch does not shadow either existing
 * one, so every test here checks both "does my own branch match what it
 * should" AND "do the other two branches still work once mine exists".
 */
class RouterTest extends TestCase
{
    private ActionFactory $actionFactory;
    private LocatorConfig $locatorConfig;
    private Router $router;
    /** @var Forward&\PHPUnit\Framework\MockObject\MockObject */
    private $forwardAction;

    protected function setUp(): void
    {
        $this->forwardAction = $this->createMock(Forward::class);

        $this->actionFactory = $this->createMock(ActionFactory::class);
        $this->actionFactory->method('create')->willReturnCallback(
            function (string $class) {
                $this->assertSame(Forward::class, $class);
                return $this->forwardAction;
            }
        );

        $this->locatorConfig = $this->createMock(LocatorConfig::class);
        $this->locatorConfig->method('getUrlPath')->willReturn(LocatorConfig::DEFAULT_URL_PATH);

        $this->router = new Router($this->actionFactory, $this->locatorConfig);
    }

    private function requestFor(string $pathInfo): HttpRequest
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getPathInfo')->willReturn($pathInfo);
        return $request;
    }

    // --- New branch: ACP checkout sessions ------------------------------

    public function testBareCheckoutSessionsRouteIsRewrittenAndForwarded(): void
    {
        $request = $this->requestFor('/idea89/acp/checkout_sessions');
        $request->expects($this->once())->method('setPathInfo')->with('/idea89/acp/checkoutsessions');

        $action = $this->router->match($request);

        $this->assertSame($this->forwardAction, $action);
    }

    public function testCheckoutSessionsWithIdIsRewrittenAndForwarded(): void
    {
        $request = $this->requestFor('/idea89/acp/checkout_sessions/cs_abc123');
        $request->expects($this->once())->method('setPathInfo')->with('/idea89/acp/checkoutsessions');

        $action = $this->router->match($request);

        $this->assertSame($this->forwardAction, $action);
    }

    public function testCheckoutSessionsCompleteIsRewrittenAndForwarded(): void
    {
        $request = $this->requestFor('/idea89/acp/checkout_sessions/cs_abc123/complete');
        $request->expects($this->once())->method('setPathInfo')->with('/idea89/acp/checkoutsessions');

        $action = $this->router->match($request);

        $this->assertSame($this->forwardAction, $action);
    }

    public function testCheckoutSessionsCancelIsRewrittenAndForwarded(): void
    {
        $request = $this->requestFor('/idea89/acp/checkout_sessions/cs_abc123/cancel');
        $request->expects($this->once())->method('setPathInfo')->with('/idea89/acp/checkoutsessions');

        $action = $this->router->match($request);

        $this->assertSame($this->forwardAction, $action);
    }

    /**
     * A path that merely CONTAINS "checkout_sessions" as a substring of a
     * different segment (not the literal prefix) must not match — proves
     * the branch does a real path-prefix check, not a loose substring one.
     */
    public function testPathThatOnlyContainsTheWordDoesNotMatch(): void
    {
        $request = $this->requestFor('/idea89/acp/checkout_sessions_report');
        $request->expects($this->never())->method('setPathInfo');

        $action = $this->router->match($request);

        $this->assertNull($action);
    }

    // --- Non-shadowing: the two pre-existing branches still work --------

    public function testFeedJsonRouteStillMatchesAfterAddingTheCheckoutSessionsBranch(): void
    {
        $request = $this->requestFor('/idea89/acp/feed.json');
        $request->expects($this->once())->method('setPathInfo')->with('/idea89/acp/feed');

        $action = $this->router->match($request);

        $this->assertSame($this->forwardAction, $action);
    }

    public function testLocatorCustomSlugStillMatchesAfterAddingTheCheckoutSessionsBranch(): void
    {
        $this->locatorConfig = $this->createMock(LocatorConfig::class);
        $this->locatorConfig->method('getUrlPath')->willReturn('showrooms');
        $this->router = new Router($this->actionFactory, $this->locatorConfig);

        $request = $this->requestFor('/showrooms');
        $request->expects($this->once())->method('setPathInfo')->with('/' . LocatorConfig::DEFAULT_URL_PATH);

        $action = $this->router->match($request);

        $this->assertSame($this->forwardAction, $action);
    }

    public function testUnrelatedPathMatchesNoBranch(): void
    {
        $request = $this->requestFor('/customer/account/login');
        $request->expects($this->never())->method('setPathInfo');

        $action = $this->router->match($request);

        $this->assertNull($action);
    }

    public function testNonHttpRequestIsIgnored(): void
    {
        $request = $this->createMock(\Magento\Framework\App\RequestInterface::class);

        $action = $this->router->match($request);

        $this->assertNull($action);
    }
}

<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Acp;

use Magento\Framework\Module\Manager;

/**
 * Detects whether a transactional Agentic Commerce module is installed
 * alongside IDEA89, so the settings status panel (Task 4.1) and, later, the
 * checkout-session router (Task 4.4) can hand off to it or decline
 * gracefully rather than reimplementing checkout sessions and delegated
 * payments ourselves.
 *
 * This class is a DETECTOR, not a bridge: it never calls into the other
 * module, never proxies a request to it, and holds no opinion about how it
 * works. Its job stops at "is it there, and what is it called".
 */
class Delegate
{
    /**
     * Magebit's free, MIT-licensed Agentic Commerce Protocol module —
     * see docs/agentic-commerce-guide.md for the install steps we point
     * merchants at once it lands (Task 4.4).
     */
    private const TRANSACTIONAL_MODULE = 'Magebit_AgenticCommerce';

    public function __construct(private readonly Manager $moduleManager)
    {
    }

    public function isTransactionalModuleInstalled(): bool
    {
        return $this->moduleManager->isEnabled(self::TRANSACTIONAL_MODULE);
    }

    public function getInstalledModuleName(): ?string
    {
        return $this->isTransactionalModuleInstalled() ? self::TRANSACTIONAL_MODULE : null;
    }
}

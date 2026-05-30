<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Idea89\Assistant\Model\Config;

class Widget extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function shouldRender(): bool
    {
        return $this->config->isEnabled() && $this->config->getApiKey() !== '';
    }

    public function getApiKey(): string
    {
        return $this->config->getApiKey();
    }

    public function getApiUrl(): string
    {
        return $this->config->getApiUrl();
    }

    public function getWidgetUrl(): string
    {
        return $this->config->getWidgetUrl();
    }

    public function getWidgetPosition(): string
    {
        return $this->config->getWidgetPosition();
    }

    public function getBrandColor(): string
    {
        return $this->config->getBrandColor();
    }
}

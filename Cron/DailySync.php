<?php

declare(strict_types=1);

namespace Idea89\Assistant\Cron;

use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Sync\CatalogSyncer;
use Idea89\Assistant\Model\Sync\ContentSyncer;

class DailySync
{
    public function __construct(
        private readonly Config $config,
        private readonly CatalogSyncer $catalogSyncer,
        private readonly ContentSyncer $contentSyncer
    ) {}

    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->config->getApiKey()) {
            return;
        }

        if ($this->config->isSyncProducts()) {
            $this->catalogSyncer->syncAll();
        }

        $this->contentSyncer->syncAll();
    }
}

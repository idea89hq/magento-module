<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Config\Backend;

use Idea89\Assistant\Model\Client\Idea89Client;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\RemoteCfg;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Makes Assistant Name mean what its own label says.
 *
 * It used to feed only the store-context record the module syncs, which the
 * chat prompt injects on every turn, while the name above the conversation
 * came from the merchant's IDEA89 account. So the field's note promised "Name
 * shown in the chat widget header" and the header showed something else, and
 * the assistant could introduce itself by a name the merchant had never seen.
 *
 * Now it is the same value the dashboard edits: read back through RemoteCfg,
 * written through the API, with the local row kept only as a render cache for
 * when the API is unreachable.
 *
 * Same rules as CheckoutUi: a failed push refuses the save, and an unchanged
 * value never calls out at all.
 */
class AssistantName extends Value
{
    /** Matches the dashboard's own bound, so neither side can store a name the other rejects. */
    private const MAX_LENGTH = 100;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly Idea89Client $client,
        private readonly Config $idea89Config,
        private readonly RemoteCfg $remoteCfg,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function afterLoad(): self
    {
        $remote = $this->remoteCfg->get();
        if (!empty($remote['assistantName'])) {
            $this->setValue($remote['assistantName']);
        }

        return parent::afterLoad();
    }

    /**
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        $value = trim((string) $this->getValue());

        if ($value === '') {
            throw new LocalizedException(
                __('Enter a name for the assistant. It is shown above the conversation.')
            );
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new LocalizedException(
                __('Keep the assistant name to %1 characters or fewer.', self::MAX_LENGTH)
            );
        }

        $this->setValue($value);

        if (!$this->isValueChanged()) {
            return $this;
        }

        $apiKey = $this->idea89Config->getApiKey();
        $apiUrl = $this->idea89Config->getApiUrl();

        if ($apiKey === '' || $apiUrl === '') {
            throw new LocalizedException(
                __('Add your IDEA89 API key and URL before changing this setting. It is stored in your IDEA89 account.')
            );
        }

        if (!$this->client->updateAssistantName($value, $apiKey, rtrim($apiUrl, '/'))) {
            throw new LocalizedException(
                __('Could not reach IDEA89 to save the assistant name, so nothing was changed. This setting is shared with your IDEA89 dashboard, and saving it here only in Magento would leave the two showing different names. Check your connection and try again.')
            );
        }

        return $this;
    }
}

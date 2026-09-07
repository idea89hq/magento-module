<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Config\Backend;

use Idea89\Assistant\Model\Client\Idea89Client;
use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\Config\Source\CheckoutUi as CheckoutUiSource;
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
 * Pushes the checkout-display setting to the merchant's IDEA89 account before
 * letting Magento store it.
 *
 * The value belongs to the IDEA89 account, not to Magento. The row written
 * here is a render cache so the admin form has something to show when the API
 * is unreachable; the widget never reads it.
 *
 * **A failed push refuses the save.** That is the whole point. Accepting the
 * value locally when IDEA89 never heard about it would leave the two screens
 * disagreeing, which is precisely the confusion this design exists to remove.
 * A merchant seeing an error and retrying is a far better outcome than one
 * who believes they changed a setting that did not change.
 */
class CheckoutUi extends Value
{
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

    /**
     * Render whatever IDEA89 actually holds, so a change made in the dashboard
     * shows up on this screen without the merchant doing anything.
     *
     * Backend models are instantiated for the admin config form, not for
     * frontend scopeConfig reads, so this costs a request only when a human is
     * looking at the settings page. RemoteCfg memoises per request and fails
     * closed to the locally cached value, which is exactly what should happen
     * when the API is down: show the last known value rather than an error.
     */
    public function afterLoad(): self
    {
        $remote = $this->remoteCfg->get();
        if (isset($remote['checkoutUi']) && $remote['checkoutUi'] !== '') {
            $this->setValue($remote['checkoutUi']);
        }

        return parent::afterLoad();
    }

    /**
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        $value = (string) $this->getValue();

        // Reject anything the source model does not offer before it reaches
        // the API, so a hand-edited form post cannot store a value the select
        // is then unable to render.
        if (!in_array($value, [CheckoutUiSource::MODE_FULL, CheckoutUiSource::MODE_INLINE], true)) {
            throw new LocalizedException(
                __('Choose either Full window or In the chat.')
            );
        }

        // Nothing to sync if the value has not actually changed. Magento calls
        // beforeSave() on every section save, not only when this field was
        // touched, so without this a merchant editing an unrelated field would
        // be blocked whenever the API happened to be slow.
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

        if (!$this->client->updateCheckoutUi($value, $apiKey, rtrim($apiUrl, '/'))) {
            throw new LocalizedException(
                __('Could not reach IDEA89 to save this setting, so nothing was changed. This setting is shared with your IDEA89 dashboard, and saving it here only in Magento would leave the two showing different values. Check your connection and try again.')
            );
        }

        return $this;
    }
}

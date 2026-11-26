<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model\Config\Backend;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Finds the API key a shared setting should be pushed with.
 *
 * On a new store the merchant pastes the key and sets the assistant name in
 * the same save. Magento runs every field's beforeSave() before the config
 * cache is rebuilt, so the stored config still has no key at that point and a
 * push that only read stored config refused the very first save with "Add your
 * IDEA89 API key". The key typed into this same form wins; the stored key is
 * the fallback.
 *
 * Used by backend models whose class extends Magento's config Value, so
 * getData(), getScope() and getScopeId() come from there.
 */
trait ResolvesApiKey
{
    private function resolveApiKey(): string
    {
        $field = $this->getData('groups')['general']['fields']['api_key'] ?? [];
        $submitted = trim((string) ($field['value'] ?? ''));

        // An obscure field posts its mask when left untouched, and an
        // inherited field posts nothing we should use.
        $isMask = $submitted !== '' && preg_match('/^\*+$/', $submitted) === 1;
        if ($submitted !== '' && !$isMask && empty($field['inherit'])) {
            return $submitted;
        }

        $scope = (string) ($this->getScope() ?: ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        if ($scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
            return $this->idea89Config->getApiKey($scope);
        }

        return $this->idea89Config->getApiKey($scope, (string) $this->getScopeId());
    }
}

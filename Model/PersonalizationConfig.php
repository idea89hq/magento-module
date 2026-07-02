<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Typed accessor for `idea89/personalization/*` config paths.
 *
 * Both secrets are stored as Magento encrypted values (Encrypted backend
 * model in system.xml). `getSigningSecret()` returns the HMAC signing
 * secret shared with the IDEA89 platform; `getApiKey()` decrypts the
 * same `idea89/general/api_key` that general config uses — it becomes the
 * `store_ref` field in the identity token so the backend can verify the
 * token belongs to this store without a separate lookup.
 */
class PersonalizationConfig
{
    public const XML_PATH_ENABLED        = 'idea89/personalization/enabled';
    public const XML_PATH_SIGNING_SECRET = 'idea89/personalization/signing_secret';
    public const XML_PATH_API_KEY        = 'idea89/general/api_key';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {}

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getSigningSecret(): string
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PATH_SIGNING_SECRET,
            ScopeInterface::SCOPE_STORE
        );
        return $raw === '' ? '' : (string) $this->encryptor->decrypt($raw);
    }

    /**
     * Decrypts `idea89/general/api_key` and returns it as the token's
     * `store_ref`. The backend verifies `store_ref === req.store.api_key`,
     * so the encrypted config path and the plaintext DB column are the
     * same credential.
     */
    public function getApiKey(): string
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PATH_API_KEY,
            ScopeInterface::SCOPE_STORE
        );
        return $raw === '' ? '' : (string) $this->encryptor->decrypt($raw);
    }
}

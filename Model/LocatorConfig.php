<?php

declare(strict_types=1);

namespace Idea89\Assistant\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed accessor for all `idea89/locator/*` config paths.
 *
 * Each getter returns the merchant-configured value, falling back to a
 * hardcoded default when the field is empty. The defaults here MUST
 * mirror what's set in etc/config.xml so a fresh install (before any
 * save) behaves the same as a save-with-default.
 *
 * Behaviour for the override rule (Magento overrides dashboard when
 * non-empty) is implemented in Block/Locator — this class just returns
 * the Magento value or empty string. The block layer decides whether
 * to fall back to the dashboard cfg JSON.
 */
class LocatorConfig
{
    public const XML_PATH_ENABLED          = 'idea89/locator/enabled';
    public const XML_PATH_URL_PATH         = 'idea89/locator/url_path';
    public const XML_PATH_LAYOUT           = 'idea89/locator/layout';
    public const XML_PATH_PAGE_TITLE       = 'idea89/locator/page_title';
    public const XML_PATH_META_DESCRIPTION = 'idea89/locator/meta_description';
    public const XML_PATH_HERO_EYEBROW     = 'idea89/locator/hero_eyebrow';
    public const XML_PATH_HERO_H1          = 'idea89/locator/hero_h1';
    public const XML_PATH_HERO_SUBHEAD     = 'idea89/locator/hero_subhead';
    public const XML_PATH_HELP_HEADING     = 'idea89/locator/help_heading';
    public const XML_PATH_HELP_BODY        = 'idea89/locator/help_body';
    public const XML_PATH_HELP_CTA_LABEL   = 'idea89/locator/help_cta_label';
    public const XML_PATH_HELP_CTA_URL     = 'idea89/locator/help_cta_url';

    /** Default URL slug — also the default frontName registered in routes.xml. */
    public const DEFAULT_URL_PATH = 'store-finder';

    /**
     * Slugs we refuse to claim because they collide with Magento core,
     * common store pages, or admin/API surfaces. Our custom router
     * runs before url-rewrite (sortOrder=15 vs 20), so without this
     * guard a merchant could accidentally steal /cart, /checkout etc.
     * Lowercase only — matches the same normalisation as getUrlPath().
     */
    private const RESERVED_SLUGS = [
        'admin', 'api', 'graphql', 'rest', 'soap',
        'cart', 'checkout', 'customer', 'account', 'sales',
        'catalog', 'catalogsearch', 'category', 'product',
        'cms', 'media', 'pub', 'static', 'errors',
        'newsletter', 'wishlist', 'review',
        'idea89',
    ];

    /**
     * Exposed for the save-time validator (Model/Config/Backend/LocatorUrlPath)
     * so the admin form and the runtime guard share one list. Const visibility
     * stays private; the static accessor is the single read path.
     *
     * @return string[]
     */
    public static function reservedSlugs(): array
    {
        return self::RESERVED_SLUGS;
    }

    /** Hardcoded copy fallbacks. Keep in sync with etc/config.xml. */
    private const DEFAULTS = [
        self::XML_PATH_PAGE_TITLE       => 'Find a store',
        self::XML_PATH_META_DESCRIPTION => 'Find your nearest store or showroom. Search by postcode or browse the map to plan your visit.',
        self::XML_PATH_HERO_EYEBROW     => 'Showrooms',
        self::XML_PATH_HERO_H1          => 'Find a store near you',
        self::XML_PATH_HERO_SUBHEAD     => 'Walk in, talk to a specialist, and try things before you buy. Use your postcode, share your location, or browse the map.',
        self::XML_PATH_HELP_HEADING     => "Can't find a store near you?",
        self::XML_PATH_HELP_BODY        => 'Our team can point you to the nearest stockist, arrange a click & collect, or guide you through ordering online.',
        self::XML_PATH_HELP_CTA_LABEL   => 'Get in touch',
        self::XML_PATH_HELP_CTA_URL     => '/contact',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {}

    public function isEnabled(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): bool
    {
        // Default Yes — un-configured fresh install gets the locator page live.
        $value = $this->scopeConfig->getValue(self::XML_PATH_ENABLED, $scopeType, $scopeCode);
        return $value === null
            ? true
            : $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, $scopeType, $scopeCode);
    }

    /**
     * The merchant's custom URL slug for the store finder page.
     * Returns the sanitised slug (no leading/trailing slash). Falls
     * back to the default 'store-finder' when empty.
     */
    public function getUrlPath(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_PATH_URL_PATH, $scopeType, $scopeCode);
        $slug = strtolower(trim($raw, "/ \t\n\r\0\x0B"));
        // Allow letters, digits, hyphens AND underscores — the same set the
        // admin's `validate-code` validator accepts. Earlier the regex was
        // `[^a-z0-9\-]` which silently stripped underscores at read time,
        // so a merchant who saved `store_locator` got a stored value of
        // `store_locator` but a runtime slug of `storelocator` — and a
        // request to /store_locator 404'd because the router compared
        // pathInfo against the wrong string.
        $slug = preg_replace('/[^a-z0-9_\-]/', '', $slug ?? '') ?? '';
        if ($slug === '') {
            return self::DEFAULT_URL_PATH;
        }
        // Reject collisions with Magento core surfaces (see RESERVED_SLUGS).
        // Falls back to the default so the locator stays reachable even
        // if the merchant typoes "cart" into the field.
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return self::DEFAULT_URL_PATH;
        }
        return $slug;
    }

    /**
     * 'fullwidth' | 'boxed' | '' (empty = defer to dashboard).
     * Empty string is the intentional "use dashboard setting" value;
     * callers (Block/Locator) decide the fallback.
     */
    public function getLayoutOverride(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_LAYOUT, $scopeType, $scopeCode);
        return in_array($value, ['fullwidth', 'boxed'], true) ? $value : '';
    }

    public function getPageTitle(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        return $this->getStringOrDefault(self::XML_PATH_PAGE_TITLE, $scopeType, $scopeCode);
    }

    public function getMetaDescription(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        return $this->getStringOrDefault(self::XML_PATH_META_DESCRIPTION, $scopeType, $scopeCode);
    }

    public function getHeroEyebrow(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        return $this->getStringOrDefault(self::XML_PATH_HERO_EYEBROW, $scopeType, $scopeCode);
    }

    public function getHeroH1(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        return $this->getStringOrDefault(self::XML_PATH_HERO_H1, $scopeType, $scopeCode);
    }

    public function getHeroSubhead(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        return $this->getStringOrDefault(self::XML_PATH_HERO_SUBHEAD, $scopeType, $scopeCode);
    }

    public function getHelpHeading(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        return $this->getStringOrDefault(self::XML_PATH_HELP_HEADING, $scopeType, $scopeCode);
    }

    public function getHelpBody(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        return $this->getStringOrDefault(self::XML_PATH_HELP_BODY, $scopeType, $scopeCode);
    }

    public function getHelpCtaLabel(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        return $this->getStringOrDefault(self::XML_PATH_HELP_CTA_LABEL, $scopeType, $scopeCode);
    }

    public function getHelpCtaUrl(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        return $this->getStringOrDefault(self::XML_PATH_HELP_CTA_URL, $scopeType, $scopeCode);
    }

    /**
     * Stable hash of all merchant-configurable content fields. Used by
     * Block/Locator::getCacheKeyInfo so FPC entries auto-invalidate
     * when any locator content changes — no observer needed.
     */
    public function getContentVersion(string $scopeType = ScopeInterface::SCOPE_STORE, ?string $scopeCode = null): string
    {
        // sha256, not md5, per the Magento coding standard (the standard
        // forbids md5 across the board even for non-security hashes like
        // this content-version key used purely to bust the FPC entry).
        return substr(hash('sha256', implode('|', [
            $this->getUrlPath($scopeType, $scopeCode),
            $this->getLayoutOverride($scopeType, $scopeCode),
            $this->getPageTitle($scopeType, $scopeCode),
            $this->getMetaDescription($scopeType, $scopeCode),
            $this->getHeroEyebrow($scopeType, $scopeCode),
            $this->getHeroH1($scopeType, $scopeCode),
            $this->getHeroSubhead($scopeType, $scopeCode),
            $this->getHelpHeading($scopeType, $scopeCode),
            $this->getHelpBody($scopeType, $scopeCode),
            $this->getHelpCtaLabel($scopeType, $scopeCode),
            $this->getHelpCtaUrl($scopeType, $scopeCode),
        ])), 0, 8);
    }

    private function getStringOrDefault(string $path, string $scopeType, ?string $scopeCode): string
    {
        $value = (string) $this->scopeConfig->getValue($path, $scopeType, $scopeCode);
        if ($value !== '') {
            return $value;
        }
        return self::DEFAULTS[$path] ?? '';
    }
}

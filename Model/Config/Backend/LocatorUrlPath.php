<?php

declare(strict_types=1);

namespace Idea89\Assistant\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\App\Route\ConfigInterface as RouteConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Idea89\Assistant\Model\LocatorConfig;

/**
 * Save-time validator for `idea89/locator/url_path`.
 *
 * Why this exists: the locator's custom Router (Controller/Router.php)
 * runs before the URL Rewrite router. If a merchant sets the slug to
 * "showrooms" while the storefront already has a CMS page, product,
 * category, or custom module living at /showrooms, the locator would
 * silently steal that URL. That's both confusing for the merchant and
 * destructive to their catalogue, so we intercept the save and refuse
 * conflicting slugs with a message that names the conflicting entity.
 *
 * Checks (in order):
 *   1. Reserved core slugs (cart, checkout, customer, admin, …).
 *      Mirrors the runtime guard in LocatorConfig::RESERVED_SLUGS so
 *      the merchant gets the rejection at SAVE time, not at request time.
 *   2. Routed frontNames — any Magento module whose `routes.xml` claims
 *      this frontName. Catches e.g. an existing WV_Showrooms install.
 *   3. URL rewrite table — covers CMS pages, products, categories, and
 *      custom rewrites all in one query. The `entity_type` column tells
 *      us what kind of thing is in the way, so the error message can
 *      say "CMS page" rather than the cryptic "url_rewrite row".
 *
 * Empty value and the default 'store-finder' bypass all checks — empty
 * resolves to the default at runtime, and 'store-finder' is owned by
 * this module's own routes.xml so we'd flag ourselves.
 */
class LocatorUrlPath extends Value
{
    /**
     * url_rewrite.entity_type → human label. Magento's built-in types
     * cover the common cases; anything else (custom rewrites) gets a
     * generic fallback so the merchant at least knows there's a row.
     */
    private const ENTITY_LABELS = [
        'cms-page' => 'CMS page',
        'product'  => 'product',
        'category' => 'category',
    ];

    private readonly RouteConfigInterface $routeConfig;
    private readonly ResourceConnection $appResource;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        RouteConfigInterface $routeConfig,
        ResourceConnection $appResource,
        // Parent Config\Value's $resource positional slot. KEEP THIS NAME — if
        // we rename to $resourceModel, the parent ctor sees something else at
        // position 5 and Magento's ObjectManager DI-injects Config\Data
        // into our $appResource slot by name match. The clash burned an
        // iteration; the workaround is to keep parent slot names verbatim.
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->routeConfig = $routeConfig;
        $this->appResource = $appResource;
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $resource,
            $resourceCollection,
            $data
        );
    }

    /**
     * @throws LocalizedException with a merchant-readable message
     *                            naming the conflicting entity.
     */
    public function beforeSave()
    {
        parent::beforeSave();

        $raw = (string) $this->getValue();
        $slug = $this->normalise($raw);

        // Empty / default → nothing to validate. Empty means "use default
        // store-finder"; the literal 'store-finder' is our own route.
        if ($slug === '' || $slug === LocatorConfig::DEFAULT_URL_PATH) {
            return $this;
        }

        // 1. Reserved core slugs.
        if (in_array($slug, LocatorConfig::reservedSlugs(), true)) {
            throw new LocalizedException(__(
                'The URL path "%1" is reserved by Magento (e.g. checkout, cart, customer). '
                . 'Please choose another path like "find-a-shop" or "branches".',
                $slug
            ));
        }

        // 2. Module frontName collision.
        $modules = $this->routeConfig->getModulesByFrontName($slug);
        if (!empty($modules)) {
            $other = array_diff($modules, ['Idea89_Assistant']);
            if (!empty($other)) {
                throw new LocalizedException(__(
                    'The URL path "%1" is already used by the module "%2" (registered route). '
                    . 'Pick a different path or disable/remove that module first.',
                    $slug,
                    implode(', ', $other)
                ));
            }
        }

        // 3. url_rewrite table.
        $conflict = $this->findUrlRewrite($slug);
        if ($conflict !== null) {
            $label = self::ENTITY_LABELS[$conflict['entity_type']] ?? $conflict['entity_type'];
            $target = $conflict['target_path'] !== ''
                ? sprintf(' (currently points to %s)', $conflict['target_path'])
                : '';
            throw new LocalizedException(__(
                'The URL path "%1" is already in use by a %2%3. '
                . 'Choose a different path, or remove the existing rewrite under '
                . 'Marketing → SEO &amp; Search → URL Rewrites.',
                $slug,
                $label,
                $target
            ));
        }

        return $this;
    }

    private function normalise(string $raw): string
    {
        // Same set as LocatorConfig::getUrlPath() — letters, digits,
        // hyphens, underscores. Must stay in sync or the save-time
        // validation will accept slugs the runtime then mangles.
        $slug = strtolower(trim($raw, "/ \t\n\r\0\x0B"));
        $slug = preg_replace('/[^a-z0-9_\-]/', '', $slug ?? '') ?? '';
        return $slug;
    }

    /**
     * @return array{entity_type:string,target_path:string}|null
     */
    private function findUrlRewrite(string $slug): ?array
    {
        $connection = $this->appResource->getConnection();
        $table = $this->appResource->getTableName('url_rewrite');
        // Match both the bare slug and the .html-suffixed variant
        // (Magento appends .html for products + categories by default).
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['entity_type', 'target_path'])
                ->where('request_path IN (?)', [$slug, $slug . '.html'])
                ->limit(1)
        );
        if (!isset($rows[0])) {
            return null;
        }
        return [
            'entity_type' => (string) ($rows[0]['entity_type'] ?? 'custom'),
            'target_path' => (string) ($rows[0]['target_path'] ?? ''),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Idea89\Assistant\Model;

/**
 * Derives a public tracking URL from a carrier code + tracking number
 * so the chat widget's "Track parcel" button can deep-link straight to
 * the carrier's own tracking page.
 *
 * Magento's shipment record only sometimes carries an explicit URL; for
 * everything else we synthesise one from this matrix. Carriers covered
 * are the ones IDEA89 has seen in real merchant integrations to date —
 * extend as new merchants come on.
 *
 * Returns null when the carrier code doesn't match any known pattern;
 * the caller (OrderSanitizer) falls back to the shipment-record URL
 * field, and the widget hides the tracking button if both are null.
 */
class TrackingUrlResolver
{
    /**
     * Maps a normalised carrier key → URL template with `{n}` for the
     * tracking number. Keep keys lowercase, hyphenated where the carrier
     * uses a multi-word name. Magento carrier codes are usually short
     * (`ups`, `fedex`, `dhl`) but custom modules sometimes use longer
     * codes — normalise() handles the common prefixes.
     */
    private const PATTERNS = [
        'ups'        => 'https://www.ups.com/track?tracknum={n}',
        'fedex'      => 'https://www.fedex.com/fedextrack/?tracknumbers={n}',
        'dhl'        => 'https://www.dhl.com/global-en/home/tracking.html?tracking-id={n}',
        'royal-mail' => 'https://www.royalmail.com/track-your-item#/tracking-results/{n}',
        'dpd'        => 'https://www.dpd.co.uk/apps/tracking/?reference={n}',
        'usps'       => 'https://tools.usps.com/go/TrackConfirmAction?qtc_tLabels1={n}',
        'evri'       => 'https://www.evri.com/track/parcel/{n}',
        'hermes'     => 'https://www.evri.com/track/parcel/{n}',
        'tnt'        => 'https://www.tnt.com/express/en_gb/site/shipping-tools/tracking.html?searchType=con&cons={n}',
        'parcelforce' => 'https://www.parcelforce.com/portal/pw/track?trackNumber={n}',
        'yodel'      => 'https://www.yodel.co.uk/tracking/{n}',
    ];

    public function resolve(string $carrierCode, string $trackingNumber): ?string
    {
        $key = $this->normalise($carrierCode);
        if ($key === '' || $trackingNumber === '') {
            return null;
        }
        $pattern = self::PATTERNS[$key] ?? null;
        if ($pattern === null) {
            return null;
        }
        return str_replace('{n}', rawurlencode($trackingNumber), $pattern);
    }

    /**
     * Magento carrier codes vary in their casing and sometimes carry
     * a vendor prefix (e.g. `mt_dhl`, `magento_ups`, `ups_freight`).
     * Strip common prefixes, lowercase, and map well-known synonyms.
     */
    private function normalise(string $carrierCode): string
    {
        $code = strtolower(trim($carrierCode));
        // Common prefixes to strip
        foreach (['mt_', 'magento_', 'custom_', 'ext_'] as $prefix) {
            if (str_starts_with($code, $prefix)) {
                $code = substr($code, strlen($prefix));
            }
        }
        // Suffix variants (e.g. ups_freight, fedex_express) collapse to base
        $base = explode('_', $code, 2)[0];
        // Known aliases (single-word ones already match; multi-word need a map)
        return match ($base) {
            'royalmail' => 'royal-mail',
            'rm'        => 'royal-mail',
            default     => $base,
        };
    }
}

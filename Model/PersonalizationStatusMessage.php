<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model;

/**
 * What the personalization panel says about the two halves.
 *
 * Kept out of the Block on purpose. Magento\Backend\Block\Template reaches for
 * the ObjectManager in its constructor, so logic living in a block cannot be
 * unit tested without booting the framework, and the only thing that matters
 * about this panel is that it is ACCURATE about both halves.
 */
class PersonalizationStatusMessage
{
    /**
     * @param bool      $magentoEnabled Whether this store signs and sends the token.
     * @param bool|null $accountEnabled The IDEA89 account's half, or null when it could not be asked.
     */
    public function build(bool $magentoEnabled, ?bool $accountEnabled): string
    {
        $intro = 'Personalization needs two switches on, one here and one in your IDEA89 dashboard. '
            . 'This page controls the Magento half: whether your storefront signs and sends the '
            . 'shopper identity token.';

        if ($accountEnabled === null) {
            return $intro . '<br/><br/>IDEA89 could not be reached, so the state of the other half '
                . 'is unknown. Check it in your dashboard under Personalization.';
        }

        if ($magentoEnabled && $accountEnabled) {
            return $intro . '<br/><br/><strong>Both halves are on</strong>, so personalization is live.';
        }

        if (!$magentoEnabled && !$accountEnabled) {
            return $intro . '<br/><br/><strong>Both halves are off.</strong> Turning this one on is '
                . 'not enough on its own; switch it on in your dashboard too.';
        }

        return $intro . '<br/><br/><strong>Only one half is on</strong>, so personalization is not '
            . 'running. Magento is <strong>' . ($magentoEnabled ? 'on' : 'off') . '</strong> and your '
            . 'IDEA89 account is <strong>' . ($accountEnabled ? 'on' : 'off') . '</strong>. '
            . 'Both need to be on.';
    }
}

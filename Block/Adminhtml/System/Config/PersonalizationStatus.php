<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Block\Adminhtml\System\Config;

use Idea89\Assistant\Model\PersonalizationConfig;
use Idea89\Assistant\Model\PersonalizationStatusMessage;
use Idea89\Assistant\Model\RemoteCfg;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Read-only status panel above the Enable Personalization toggle.
 *
 * Personalization needs BOTH halves on: Magento signs and emits the identity
 * token, and the IDEA89 account accepts it. The switch below controls only the
 * Magento half, and the dashboard has a switch of its own labelled the same
 * way. A merchant looking at either screen alone sees one word, "on" or "off",
 * and reasonably concludes that is the state of the feature.
 *
 * It is not a divergence in the sense the shared settings were, since the two
 * flags genuinely control different things. It is worse in one respect: they
 * share a name, so nothing on either screen hints that the other exists. This
 * panel says what both halves are actually doing right now.
 *
 * Same wiring idiom as AcpStatus: a <frontend_model> on a <field>, so the
 * label column stays and only the value column's markup is replaced.
 */
class PersonalizationStatus extends Field
{
    public function __construct(
        Context $context,
        private readonly PersonalizationConfig $personalizationConfig,
        private readonly RemoteCfg $remoteCfg,
        private readonly PersonalizationStatusMessage $message,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return '<div class="idea89-personalization-status" style="'
            . 'padding:10px 14px;background:#f4f6f5;border:1px solid #dbe3e0;'
            . 'border-radius:4px;font-size:13px;line-height:1.6;color:#333;max-width:640px;">'
            . $this->message->build(
                $this->personalizationConfig->isEnabled(),
                // Absent means the API could not be asked. Passing null keeps
                // "unknown" distinct from "off" on the one screen a merchant
                // consults to find out.
                array_key_exists('personalizationEnabled', $remote = $this->remoteCfg->get())
                    ? (bool) $remote['personalizationEnabled']
                    : null
            )
            . '</div>';
    }

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }
}

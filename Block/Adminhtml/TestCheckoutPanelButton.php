<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Block\Adminhtml;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;

class TestCheckoutPanelButton extends Field
{
    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $url = $this->getUrl('idea89/ajax/testcheckoutpanel');

        return <<<HTML
<button type="button" id="idea89-test-checkout-panel" class="action-default" onclick="idea89TestCheckoutPanel('{$url}')">
    Test Checkout Panel
</button>
<div id="idea89-test-checkout-panel-result" style="margin-top:8px;"></div>
<script>
function idea89TestCheckoutPanel(url) {
    var btn = document.getElementById('idea89-test-checkout-panel');
    var result = document.getElementById('idea89-test-checkout-panel-result');
    btn.disabled = true;
    result.innerHTML = 'Testing…';
    var body = new FormData();
    body.append('form_key', window.FORM_KEY || '');
    fetch(url, {method:'POST', credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}, body: body})
        .then(function(r){return r.json();})
        .then(function(d){
            if (!d || !d.messages) {
                result.innerHTML = '<span style="color:#c00">&#10007; Request failed</span>';
                return;
            }
            var colors = {ok:'#2c7a2c', warn:'#b45309', error:'#c00'};
            var icons = {ok:'&#10003;', warn:'&#9888;', error:'&#10007;'};
            var lines = d.messages.map(function (m) {
                var color = colors[m.level] || '#333';
                var icon = icons[m.level] || '';
                return '<div style="color:' + color + ';margin:2px 0;">' + icon + ' ' + m.text + '</div>';
            });
            result.innerHTML = lines.join('');
        })
        .catch(function(e){result.innerHTML='<span style="color:#c00">Request failed: ' + e + '</span>';})
        .finally(function(){btn.disabled=false;});
}
</script>
HTML;
    }

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }
}

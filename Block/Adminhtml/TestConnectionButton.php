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

class TestConnectionButton extends Field
{
    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $url = $this->getUrl('idea89/ajax/testconnection');

        return <<<HTML
<button type="button" id="idea89-test-connection" class="action-default" onclick="idea89TestConnection('{$url}')">
    Test Connection
</button>
<span id="idea89-test-result" style="margin-left:10px;"></span>
<script>
function idea89TestConnection(url) {
    var btn = document.getElementById('idea89-test-connection');
    var result = document.getElementById('idea89-test-result');
    btn.disabled = true;
    result.innerHTML = 'Testing…';
    var body = new FormData();
    body.append('form_key', window.FORM_KEY || '');
    fetch(url, {method:'POST', credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}, body: body})
        .then(function(r){return r.json();})
        .then(function(d){
            result.innerHTML = d.ok
                ? '<span style="color:#2c7a2c">&#10003; Connected</span>'
                : '<span style="color:#c00">&#10007; ' + (d.error || 'Failed') + '</span>';
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

<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Block\Adminhtml\System\Config;

use Idea89\Assistant\Model\PersonalizationStatusMessage;
use Idea89\Assistant\Model\PersonalizationConfig;
use Idea89\Assistant\Model\RemoteCfg;
use Magento\Backend\Block\Template\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Personalization needs both halves on: Magento signs and sends the shopper
 * identity token, the IDEA89 account accepts it. The dashboard has a switch
 * with the same name, so a merchant reading either screen alone sees one word
 * and concludes that is the state of the feature. demo89 was in exactly that
 * state: Magento off, account on, and nothing on either screen saying so.
 *
 * The panel's only job is to be accurate about both halves, so these check
 * each combination says the right thing rather than that it renders at all.
 */
class PersonalizationStatusTest extends TestCase
{
    /** @var PersonalizationConfig&MockObject */
    private $personalization;

    /** @var RemoteCfg&MockObject */
    private $remoteCfg;

    protected function setUp(): void
    {
        $this->personalization = $this->createMock(PersonalizationConfig::class);
        $this->remoteCfg       = $this->createMock(RemoteCfg::class);
    }

    private function message(bool $magentoOn, array $remote): string
    {
        $account = array_key_exists('personalizationEnabled', $remote)
            ? (bool) $remote['personalizationEnabled']
            : null;

        return strip_tags((new PersonalizationStatusMessage())->build($magentoOn, $account));
    }

    public function testBothOnSaysItIsLive(): void
    {
        $out = $this->message(true, ['personalizationEnabled' => true]);

        $this->assertStringContainsString('Both halves are on', $out);
    }

    public function testBothOffSaysOneSwitchIsNotEnough(): void
    {
        // The trap: a merchant flips this one, sees nothing happen, and has
        // no way to learn the dashboard has its own.
        $out = $this->message(false, ['personalizationEnabled' => false]);

        $this->assertStringContainsString('Both halves are off', $out);
        $this->assertStringContainsString('not enough on its own', $out);
    }

    public function testOnlyMagentoOnNamesWhichHalfIsWhich(): void
    {
        $out = $this->message(true, ['personalizationEnabled' => false]);

        $this->assertStringContainsString('Only one half is on', $out);
        $this->assertStringContainsString('Magento is on', $out);
        $this->assertStringContainsString('account is off', $out);
    }

    public function testOnlyTheAccountOnIsTheStateDemo89WasIn(): void
    {
        $out = $this->message(false, ['personalizationEnabled' => true]);

        $this->assertStringContainsString('Only one half is on', $out);
        $this->assertStringContainsString('Magento is off', $out);
        $this->assertStringContainsString('account is on', $out);
    }

    public function testAnUnreachableApiSaysUnknownRatherThanGuessing(): void
    {
        // RemoteCfg omits the key when it could not ask. Reporting "off"
        // there would be a confident statement about something unknown, on
        // the one screen a merchant consults to find out.
        $out = $this->message(true, []);

        $this->assertStringContainsString('could not be reached', $out);
        $this->assertStringNotContainsString('Both halves are on', $out);
    }

    public function testEveryStateExplainsThatThereAreTwoSwitches(): void
    {
        foreach ([[true, true], [true, false], [false, true], [false, false]] as [$here, $there]) {
            $this->setUp();
            $out = $this->message($here, ['personalizationEnabled' => $there]);
            $this->assertStringContainsString('two switches', $out);
        }
    }
}

<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * No em-dashes in the copy a merchant reads.
 *
 * This exists because grepping for the literal character was not enough. Seven
 * em-dashes sat in the Checkout Experience copy as the HTML entity `&mdash;`
 * and passed every check run against this file, because those checks looked
 * for "—" and the entity is seven ASCII characters. They were only spotted by
 * rendering the field and reading it, where the browser decodes the entity.
 *
 * So this checks BOTH spellings, and only inside <comment> and <label>, since
 * XML comments are notes to ourselves rather than copy anyone reads.
 */
class AdminCopyTest extends TestCase
{
    private const DASH_SPELLINGS = ['—', '&mdash;', '&#8212;', '&#x2014;'];

    private function merchantFacingText(): array
    {
        $xml = file_get_contents(__DIR__ . '/../../etc/adminhtml/system.xml');
        preg_match_all('/<(comment|label)>(.*?)<\/\1>/s', $xml, $matches, PREG_SET_ORDER);

        $out = [];
        foreach ($matches as $m) {
            $out[] = $m[2];
        }

        return $out;
    }

    public function testNoEmDashInAnySpellingReachesTheMerchant(): void
    {
        $offenders = [];
        foreach ($this->merchantFacingText() as $text) {
            foreach (self::DASH_SPELLINGS as $dash) {
                $at = strpos($text, $dash);
                if ($at !== false) {
                    $offenders[] = trim(preg_replace('/\s+/', ' ', substr($text, max(0, $at - 50), 110)));
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Em-dashes in merchant-facing admin copy. Use a colon, comma or full stop:\n  "
                . implode("\n  ", $offenders)
        );
    }

    /**
     * Guards the scan. If the regex stops matching, the test above passes
     * vacuously and protects nothing, which is how a source scan usually dies.
     */
    public function testTheScanActuallyReadsTheCopy(): void
    {
        $text = $this->merchantFacingText();

        $this->assertGreaterThan(30, count($text), 'The copy scan found almost nothing.');
        $joined = implode(' ', $text);
        $this->assertStringContainsString('Checkout Display', $joined);
        $this->assertStringContainsString('Shared with your IDEA89 dashboard', $joined);
    }
}

<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model\Sync;

use Idea89\Assistant\Model\Sync\ProductSerializer;
use PHPUnit\Framework\TestCase;

/** Product HTML to text keeps one line per block (found on a local store, 1.4.0). */
class ProductSerializerTextTest extends TestCase
{
    public function testBlocksBecomeLines(): void
    {
        $s = (new \ReflectionClass(ProductSerializer::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod($s, 'htmlToText');
        $m->setAccessible(true);
        $html = '<p>Soft linen.</p><ul><li>100% linen, 250&nbsp;gsm</li><li>50cm &times; 50cm</li></ul>Wash cold<br>Line dry';
        $this->assertSame("Soft linen.\n100% linen, 250 gsm\n50cm × 50cm\nWash cold\nLine dry", $m->invoke($s, $html));
    }

    public function testEscapedMarkupFromAnHtmlCodeElementIsConvertedToo(): void
    {
        $s = (new \ReflectionClass(ProductSerializer::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod($s, 'htmlToText');
        $m->setAccessible(true);
        $html = '<div data-content-type="html" data-appearance="default">&lt;P&gt;Folds flat.&lt;/P&gt;&lt;UL&gt;&lt;LI&gt;Max load 120kg&lt;/LI&gt;&lt;/UL&gt;</div>';
        $this->assertSame("Folds flat.\nMax load 120kg", $m->invoke($s, $html));
        // A plain "<" in text is not a tag.
        $this->assertSame('Weighs <5kg & folds', $m->invoke($s, '<p>Weighs &lt;5kg &amp; folds</p>'));
    }
}

<?php

namespace Tests\Unit;

use App\Domain\StatusPage\AccentColor;
use PHPUnit\Framework\TestCase;

class AccentColorTest extends TestCase
{
    public function test_validates_and_normalizes_hex_colours(): void
    {
        $this->assertSame('#1a2b3c', AccentColor::normalize('#1A2B3C'));
        $this->assertNull(AccentColor::normalize('1a2b3c'));
        $this->assertNull(AccentColor::normalize('#fff'));
        $this->assertNull(AccentColor::normalize('red'));
        $this->assertNull(AccentColor::normalize(null));
    }

    public function test_picks_the_readable_text_colour(): void
    {
        $this->assertSame(AccentColor::LIGHT_TEXT, AccentColor::textColorFor('#0b3d91'));
        $this->assertSame(AccentColor::LIGHT_TEXT, AccentColor::textColorFor('#111827'));
        $this->assertSame(AccentColor::DARK_TEXT, AccentColor::textColorFor('#fde047'));
        $this->assertSame(AccentColor::DARK_TEXT, AccentColor::textColorFor('#ffffff'));

        foreach (['#0b3d91', '#16a34a', '#fde047', '#ff6600'] as $accent) {
            $this->assertGreaterThanOrEqual(4.5, AccentColor::contrastRatio($accent, AccentColor::textColorFor($accent)), $accent);
        }
    }
}

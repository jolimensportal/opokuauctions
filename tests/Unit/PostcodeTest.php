<?php

namespace Tests\Unit;

use App\Services\Postcode;
use PHPUnit\Framework\TestCase;

/**
 * Postcodes arrive in whatever form a customer types them, and the two
 * countries format differently. Getting this wrong is not a cosmetic bug: it
 * routes a sender to the wrong rate band, and therefore to the wrong price.
 */
class PostcodeTest extends TestCase
{
    public function test_a_plain_us_zip_is_unchanged(): void
    {
        $this->assertSame('07030', Postcode::normalise('07030', 'us'));
    }

    public function test_a_us_zip_plus_four_is_truncated_to_the_zip(): void
    {
        $this->assertSame('07030', Postcode::normalise('07030-1234', 'us'));
    }

    public function test_a_short_us_zip_is_refused_rather_than_padded(): void
    {
        // A US ZIP whose leading zeros were dropped is ambiguous. "703" could
        // be 00703 or a truncated 07030, and padding it invents a digit the
        // sender never typed — which routes the parcel to a rate band chosen
        // by us rather than by them.
        //
        // The plan originally asserted that "703" padded to "00703" while
        // asserting "0703" was invalid. Both are four-or-fewer digit strings,
        // so padding one and refusing the other was a rule nobody could
        // explain. Refusing both is the answer that matches the principle the
        // whole normaliser is built on.
        $this->assertNull(Postcode::normalise('703', 'us'));
        $this->assertNull(Postcode::normalise(' 703 ', 'us'));
        $this->assertNull(Postcode::normalise('0703', 'us'));
    }

    public function test_an_invalid_us_postcode_is_not_guessed_at(): void
    {
        $this->assertNull(Postcode::normalise('ABCDE', 'us'));
    }

    public function test_a_canadian_postcode_is_uppercased_and_stripped(): void
    {
        $this->assertSame('M5V2T6', Postcode::normalise('m5v 2t6', 'ca'));
        $this->assertSame('M5V2T6', Postcode::normalise('M5V-2T6', 'ca'));
    }

    public function test_an_invalid_canadian_postcode_is_not_guessed_at(): void
    {
        $this->assertNull(Postcode::normalise('M5V2T', 'ca'));
        $this->assertNull(Postcode::normalise('12345', 'ca'));
    }

    public function test_a_canadian_postcode_is_never_read_as_a_number(): void
    {
        // The whole reason Canada is handled separately: M5V is not 500.
        $this->assertSame('M5V2T6', Postcode::normalise('M5V 2T6', 'ca'));
    }

    public function test_an_unknown_country_is_refused_rather_than_assumed(): void
    {
        $this->assertNull(Postcode::normalise('07030', 'xx'));
    }

    public function test_an_empty_postcode_is_refused(): void
    {
        $this->assertNull(Postcode::normalise('', 'us'));
        $this->assertNull(Postcode::normalise('   ', 'ca'));
    }
}
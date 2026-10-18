<?php

namespace Tests\Feature;

use App\Enums\IntakeMethod;
use App\Enums\ShipmentUnit;
use App\Models\OriginZone;
use App\Models\PostcodeBand;
use App\Services\Postcode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Postcodes map to internal zones; rate rows price zones.
 *
 * The two live in separate tables on purpose. Adding a band for a new town is a
 * data edit, not a reprice, and the two change for different reasons on
 * different timescales.
 */
class PostcodeBandTest extends TestCase
{
    use RefreshDatabase;

    private function zone(string $code, string $country = 'us'): OriginZone
    {
        return OriginZone::create([
            'id' => 'zone_'.strtolower($code),
            'country' => $country,
            'code' => $code,
            'name' => $code,
            'active' => true,
        ]);
    }

    private function band(string $prefix, OriginZone $zone, string $country = 'us'): PostcodeBand
    {
        return PostcodeBand::create([
            'id' => 'band_'.$prefix,
            'country' => $country,
            'prefix' => $prefix,
            'zone_id' => $zone->id,
            'active' => true,
        ]);
    }

    public function test_a_matching_prefix_resolves_to_its_zone(): void
    {
        $zone = $this->zone('US-NJ-NE');
        $this->band('070', $zone);

        $this->assertSame($zone->id, PostcodeBand::resolve('07030', 'us')?->zone_id);
    }

    public function test_a_non_matching_prefix_resolves_to_nothing(): void
    {
        $this->band('070', $this->zone('US-NJ-NE'));

        $this->assertNull(PostcodeBand::resolve('60601', 'us'));
    }

    public function test_the_longest_matching_prefix_wins(): void
    {
        // "07" and "070" both match 07030. Without a defined order the result
        // depends on row order, which is not a rule.
        $this->band('07', $this->zone('US-NJ-WIDE'));
        $this->band('070', $this->zone('US-NJ-NARROW'));

        $this->assertSame('zone_us-nj-narrow', PostcodeBand::resolve('07030', 'us')?->zone_id);
    }

    public function test_a_band_never_matches_across_countries(): void
    {
        $zone = $this->zone('CA-ON', 'ca');
        $this->band('M5', $zone, 'ca');

        // The same letters typed as a US postcode must not find a Canadian band.
        $this->assertNull(PostcodeBand::resolve('M5V2T6', 'us'));
    }

    public function test_an_inactive_band_does_not_resolve(): void
    {
        $zone = $this->zone('US-NJ-NE');
        $band = $this->band('070', $zone);

        $band->update(['active' => false]);

        $this->assertNull(PostcodeBand::resolve('07030', 'us'));
    }

    public function test_an_unmapped_postcode_returns_null_rather_than_a_fallback(): void
    {
        $this->band('070', $this->zone('US-NJ-NE'));

        // No national fallback. Quoting a rate we have not priced is the one
        // thing this whole resolution step exists to prevent.
        $this->assertNull(PostcodeBand::resolve('60601', 'us'));
    }

    public function test_bands_resolve_through_the_normalised_form(): void
    {
        $zone = $this->zone('CA-ON-TORONTO', 'ca');
        $this->band('M5V', $zone, 'ca');

        $normalised = Postcode::normalise('m5v-2t6', 'ca');

        $this->assertSame($zone->id, PostcodeBand::resolve($normalised, 'ca')?->zone_id);
    }

    public function test_an_empty_postcode_resolves_to_nothing(): void
    {
        $this->band('070', $this->zone('US-NJ-NE'));

        // An empty string would otherwise match every prefix under a
        // starts_with check.
        $this->assertNull(PostcodeBand::resolve('', 'us'));
        $this->assertNull(PostcodeBand::resolve(null, 'us'));
    }

    public function test_the_enum_covers_the_three_intake_methods(): void
    {
        $this->assertSame(
            ['dropoff', 'partner_pickup', 'self_pickup'],
            array_column(IntakeMethod::cases(), 'value'),
        );
    }

    public function test_the_enum_covers_both_units(): void
    {
        $this->assertSame(['parcel', 'pallet'], array_column(ShipmentUnit::cases(), 'value'));
    }

    public function test_every_unavailable_reason_has_a_message(): void
    {
        foreach (\App\Enums\QuoteUnavailableReason::cases() as $reason) {
            $message = $reason->message(['postcode' => '60601']);

            $this->assertNotSame('', $message, "{$reason->value} must explain itself.");
        }
    }
}
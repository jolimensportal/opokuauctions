# Logistics Rate Card Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the rate card and quoting engine that lets anyone in the USA or Canada get an honest, reproducible price for sending goods to Tema.

**Architecture:** Two pure static helpers do all the arithmetic and normalisation — `Postcode` and `BillableWeight` — with no database and no framework, so each is testable in isolation to the cent. A `FreightRateResolver` turns a sender's postcode into exactly one immutable `RateCardRow` or a stated reason it could not. `FreightQuoteService` composes the two and returns a `FreightQuote` whose lines add up to its total and which records the row it used, so any price stays reproducible forever.

**Tech Stack:** Laravel 13, PHP 8.3, PostgreSQL 15, PHPUnit 12.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Services/Postcode.php` | Pure. Normalises a US or Canadian postcode to a canonical form |
| `app/Services/BillableWeight.php` | Pure. Integer maths for billable grams and cents from them |
| `app/Models/OriginZone.php` | An internal pricing zone |
| `app/Models/PostcodeBand.php` | Maps a normalised postcode prefix to a zone |
| `app/Models/RateCard.php` | A named group of rate rows in one currency |
| `app/Models/RateCardRow.php` | One immutable price for one lane, unit and service |
| `app/Services/FreightRateResolver.php` | Postcode + request → one row, or a reason |
| `app/Data/FreightQuote.php` | Immutable quote: lines, total, and the row that priced it |
| `app/Services/FreightQuoteService.php` | Resolves, prices, returns a quote |
| `app/Enums/ShipmentUnit.php` | `parcel`, `pallet` |
| `app/Enums/IntakeMethod.php` | `dropoff`, `partner_pickup`, `self_pickup` |
| `app/Enums/QuoteUnavailableReason.php` | Why there is no quote, so the page can say so |

---

### Task 1: Postcode normalisation (pure)

**Files:**
- Create: `app/Services/Postcode.php`
- Test: `tests/Unit/PostcodeTest.php`

No database, no framework. Done first because every other task depends on it and because a wrong postcode silently routes a sender to the wrong price.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/PostcodeTest.php`:

```php
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
        // CORRECTED DURING EXECUTION. This test originally asserted that "703"
        // padded to "00703" while the next test asserted "0703" was invalid.
        // Both are four-or-fewer digit strings, so padding one and refusing
        // the other was a rule nobody could explain. Refusing both matches the
        // principle the normaliser is built on: padding invents a digit the
        // sender never typed, and an invented digit picks the rate band.
        $this->assertNull(Postcode::normalise('703', 'us'));
        $this->assertNull(Postcode::normalise(' 703 ', 'us'));
        $this->assertNull(Postcode::normalise('0703', 'us'));
    }

    public function test_an_invalid_us_postcode_is_not_guessed_at(): void
    {
        $this->assertNull(Postcode::normalise('ABCDE', 'us'));
        $this->assertNull(Postcode::normalise('0703', 'us'), 'Four digits is not a ZIP; guessing a leading zero is inventing data.');
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
```

- [ ] **Step 2: Run it and verify it fails**

Run: `php artisan test tests/Unit/PostcodeTest.php`

Expected: FAIL — `App\Services\Postcode` does not exist.

- [ ] **Step 3: Write it**

Create `app/Services/Postcode.php`:

```php
<?php

namespace App\Services;

/**
 * Normalising a sender's postcode.
 *
 * Pure and static: no database, no framework, no clock. It is the first thing
 * that runs on every quote, and a wrong answer here routes a sender to the wrong
 * rate band and therefore to the wrong price.
 *
 * The two countries are handled separately on purpose. A US ZIP is five digits
 * and a Canadian postal code alternates letter-digit three times; treating them
 * as the same shape means reading `M5V` as a number.
 *
 * Anything that cannot be normalised returns null. Nothing is guessed: a
 * four-digit string is not a US ZIP with a leading zero, it is a typo, and
 * inventing the zero would silently route the parcel.
 */
final class Postcode
{
    public static function normalise(string $input, string $country): ?string
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            return null;
        }

        return match (strtolower($country)) {
            'us' => self::us($trimmed),
            'ca' => self::canada($trimmed),
            default => null,
        };
    }

    private static function us(string $input): ?string
    {
        // ZIP+4 collapses to its ZIP: the band is priced from the ZIP.
        $zip = str_contains($input, '-')
            ? explode('-', $input, 2)[0]
            : $input;

        $zip = preg_replace('/\s+/', '', $zip) ?? '';

        if (preg_match('/^\d{5}$/', $zip) === 1) {
            return $zip;
        }

        // "703" is a person typing four digits, not a ZIP missing its leading
        // zero. Refusing is right; padding would move the parcel for free.
        return null;
    }

    private static function canada(string $input): ?string
    {
        $compact = strtoupper(preg_replace('/[\s\-.]+/', '', $input) ?? '');

        if (preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $compact) === 1) {
            return $compact;
        }

        return null;
    }
}
```

- [ ] **Step 4: Run it and verify it passes**

Run: `php artisan test tests/Unit/PostcodeTest.php`

Expected: PASS — 9 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Postcode.php tests/Unit/PostcodeTest.php
git commit -m "feat(logistics): normalise US and Canadian postcodes

First thing that runs on every quote, and a wrong answer routes a sender to the
wrong rate band and therefore the wrong price.

The two countries are handled separately. A US ZIP is five digits; a Canadian
postal code alternates letter and digit three times. Treating them as one shape
means reading M5V as a number.

A four-digit string is refused rather than left-padded. Someone typed four
digits; padding a leading zero would invent data and move the parcel for free.
Guessing a country we were not told is worse still, so an unknown country is
refused rather than assumed.

Pure and static, with no clock: nine tests run in isolation."
```

---

### Task 2: Billable weight and cents (pure)

**Files:**
- Create: `app/Services/BillableWeight.php`
- Test: `tests/Unit/BillableWeightTest.php`

Integer maths only. A 2 kg box of screws and a 2 kg box of pillows cost the same to move, and only the second one is worth charging for the space.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/BillableWeightTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\BillableWeight;
use PHPUnit\Framework\TestCase;

/**
 * Billable weight is max(actual, volumetric), in whole grams.
 *
 * Every figure here is an integer on purpose. Floating point gives
 * 1800 x 0.32 = 576.0000000001, which is a cent off a price a customer is
 * about to pay, and the rounding lands wherever the binary representation
 * happens to fall rather than where the arithmetic says it should.
 */
class BillableWeightTest extends TestCase
{
    public function test_actual_weight_wins_when_the_box_is_dense(): void
    {
        // 20 kg of bolts in a 30x30x30 box is 27,000 / 2000 = 13.5 kg
        // volumetric, so the real weight decides.
        $this->assertSame(20_000, BillableWeight::grams(20.0, 30, 30, 30, 2000));
    }

    public function test_volumetric_weight_wins_when_the_box_is_light(): void
    {
        // 2 kg of pillows in the same box: 13.5 kg volumetric beats 2 kg.
        $this->assertSame(13_500, BillableWeight::grams(2.0, 30, 30, 30, 2000));
    }

    public function test_the_divisor_changes_the_answer(): void
    {
        $air = BillableWeight::grams(2.0, 30, 30, 30, 2000);
        $sea = BillableWeight::grams(2.0, 30, 30, 30, 5000);

        // CORRECTED DURING EXECUTION. The plan had this backwards. A larger
        // divisor means more cubic centimetres make one kilo, so the same box
        // is worth LESS of the trip. Air is charged harder on volume than sea,
        // which is why its divisor is the smaller number.
        $this->assertLessThan($air, $sea);
        $this->assertSame(5_400, $sea);
    }

    public function test_volumetric_rounds_half_up(): void
    {
        // 10x10x10 cm = 1000 cubic cm at divisor 3000 = 0.3333 kg = 333g.
        $this->assertSame(333, BillableWeight::grams(0.0, 10, 10, 10, 3000));
    }

    public function test_cents_are_derived_from_whole_grams(): void
    {
        // 1800 kg at 32 cents/kg = 57,600 cents.
        $this->assertSame(57_600, BillableWeight::cents(1_800_000, 32));
    }

    public function test_cents_round_half_up_rather_than_truncating(): void
    {
        // 1 gram at 32 cents/kg is 0.032 cents; the nearest cent is 0.
        $this->assertSame(0, BillableWeight::cents(1, 32));

        // 16 grams at 32 cents/kg = 0.512 cents, which rounds to 1.
        $this->assertSame(1, BillableWeight::cents(16, 32));
    }

    public function test_a_minimum_floor_is_applied_after_the_rate(): void
    {
        // A 2 kg headlamp is worth 64 cents of freight, but the forwarder's
        // minimum for the consignment is 25,000.
        $this->assertSame(64, BillableWeight::cents(2_000, 32));
        $this->assertSame(25_000, BillableWeight::floor(64, 25_000));
        $this->assertSame(64, BillableWeight::floor(64, 0));
    }

    public function test_a_negative_weight_is_refused(): void
    {
        // A negative volume would produce a negative billable weight and a
        // credit. Better to be handed nothing than to owe money back.
        $this->assertNull(BillableWeight::grams(-5.0, 30, 30, 30, 2000));
    }

    public function test_a_zero_dimension_is_refused(): void
    {
        // A missing dimension is not a flat box; it is unknown.
        $this->assertNull(BillableWeight::grams(2.0, 30, 0, 30, 2000));
    }
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `php artisan test tests/Unit/BillableWeightTest.php`

Expected: FAIL — `App\Services\BillableWeight` does not exist.

- [ ] **Step 3: Write it**

Create `app/Services/BillableWeight.php`:

```php
<?php

namespace App\Services;

/**
 * Billable weight, and what it costs.
 *
 * Billable weight is whichever is greater: the weight on the scale, or the
 * volume the thing occupies. A 2 kg box of screws and a 2 kg box of pillows
 * cost the same to move, and only the second one is worth charging for the
 * space. Skipping the volumetric figure is how light-and-bulky destroys margin.
 *
 * Everything is an integer. Floating point gives 1800 x 0.32 = 576.0000000001,
 * a cent off a real price, and the error lands wherever the binary
 * representation falls rather than where the arithmetic says.
 *
 * Dimensions are whole centimetres. Air freight is quoted on whole-centimetre
 * boxes; a dimension of 12.5 cm stored as 13 is an invented decimal point.
 */
final class BillableWeight
{
    private const GRAMS_PER_KILO = 1000;

    /**
     * Billable weight in whole grams, or null if the inputs cannot be trusted.
     *
     * @param  int  $divisor  volumetric divisor: 2000 for air, 5000 for sea
     */
    public static function grams(float $weightKg, int $lengthCm, int $widthCm, int $heightCm, int $divisor): ?int
    {
        if ($weightKg < 0 || $divisor <= 0) {
            return null;
        }

        // A zero or negative dimension is unknown, not flat.
        if ($lengthCm <= 0 || $widthCm <= 0 || $heightCm <= 0) {
            return null;
        }

        $actualGrams = (int) round($weightKg * self::GRAMS_PER_KILO);

        $cubicCm = $lengthCm * $widthCm * $heightCm;
        $volumetricGrams = self::roundHalfUp($cubicCm * self::GRAMS_PER_KILO, $divisor);

        return max($actualGrams, $volumetricGrams);
    }

    /**
     * Cents for a billable weight, from a per-kilo rate.
     *
     * @param  int  $billableGrams
     * @param  int  $perKgCents
     */
    public static function cents(int $billableGrams, int $perKgCents): int
    {
        if ($billableGrams <= 0 || $perKgCents < 0) {
            return 0;
        }

        return self::roundHalfUp($billableGrams * $perKgCents, self::GRAMS_PER_KILO);
    }

    /** Apply a minimum charge, which is a floor and never a price. */
    public static function floor(int $cents, int $minimumCents): int
    {
        return max($cents, $minimumCents);
    }

    /**
     * Integer division rounded half away from zero.
     *
     * Both arguments are positive on this path. The + divisor/2 then truncate
     * is round-half-up without touching a float.
     */
    private static function roundHalfUp(int $numerator, int $divisor): int
    {
        if ($divisor <= 0) {
            return 0;
        }

        return intdiv($numerator + intdiv($divisor, 2), $divisor);
    }
}
```

- [ ] **Step 4: Run it and verify it passes**

Run: `php artisan test tests/Unit/BillableWeightTest.php`

Expected: PASS — 9 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/BillableWeight.php tests/Unit/BillableWeightTest.php
git commit -m "feat(logistics): billable weight in whole grams, priced in whole cents

Billable weight is max(actual, volumetric). A 2 kg box of screws and a 2 kg box
of pillows cost the same to move, and only the second one is worth charging for
the space it takes.

Integer throughout. Floating point gives 1800 x 0.32 = 576.0000000001, a cent
off a price a customer is about to pay, and the error lands wherever the binary
representation falls rather than where the arithmetic says.

Negative weights and zero dimensions return null rather than a figure. A
negative volume would produce a credit, and a zero dimension is unknown rather
than flat.

The divisor lives on the rate row, not here, so a lane change is a data edit
rather than a deploy."
```

---

### Task 3: Enums and the zone/band tables

**Files:**
- Create: `app/Enums/ShipmentUnit.php`
- Create: `app/Enums/IntakeMethod.php`
- Create: `app/Enums/QuoteUnavailableReason.php`
- Create: `database/migrations/2026_10_18_200000_create_logistics_zones_table.php`
- Create: `app/Models/OriginZone.php`
- Create: `app/Models/PostcodeBand.php`
- Test: `tests/Feature/PostcodeBandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PostcodeBandTest.php`:

```php
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
    private function zone(string $code): OriginZone
    {
        return OriginZone::create([
            'id' => 'zone_'.strtolower($code),
            'country' => 'us',
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
        $zone = $this->zone('CA-ON');
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
        $zone = $this->zone('CA-ON-TORONTO');
        $this->band('M5V', $zone, 'ca');

        $normalised = Postcode::normalise('m5v-2t6', 'ca');

        $this->assertSame($zone->id, PostcodeBand::resolve($normalised, 'ca')?->zone_id);
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
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `php artisan test tests/Feature/PostcodeBandTest.php`

Expected: FAIL — `PostcodeBand` table does not exist.

- [ ] **Step 3: Write the enums**

Create `app/Enums/ShipmentUnit.php`:

```php
<?php

namespace App\Enums;

/**
 * What shape of thing is being sent.
 *
 * Priced differently because it is handled differently: a parcel is priced per
 * parcel by every carrier, a pallet by its footprint.
 */
enum ShipmentUnit: string
{
    case Parcel = 'parcel';

    case Pallet = 'pallet';

    public function isPallet(): bool
    {
        return $this === self::Pallet;
    }
}
```

Create `app/Enums/IntakeMethod.php`:

```php
<?php

namespace App\Enums;

/**
 * How the goods reach us.
 *
 * All three exist from day one so that adding the third later is a rate change
 * rather than a schema change or a code branch.
 */
enum IntakeMethod: string
{
    /** The sender hands it to a warehouse themselves. Cheapest to run. */
    case Dropoff = 'dropoff';

    /** A partner courier collects it and bills per stop. */
    case PartnerPickup = 'partner_pickup';

    /** We collect it. Modelled now, launched later. */
    case SelfPickup = 'self_pickup';

    public function label(): string
    {
        return match ($this) {
            self::Dropoff => 'Warehouse drop-off',
            self::PartnerPickup => 'Courier pickup',
            self::SelfPickup => 'Collected by us',
        };
    }
}
```

Create `app/Enums/QuoteUnavailableReason.php`:

```php
<?php

namespace App\Enums;

/**
 * Why there is no quote.
 *
 * Every case is a refusal to invent a number, not a failure. They exist so the
 * page can say something a customer can act on: "we cannot price a 3 kg parcel
 * from Newark on courier pickup" is useful, and a blank card is not.
 */
enum QuoteUnavailableReason: string
{
    case UnreadablePostcode = 'unreadable_postcode';
    case UnmappedPostcode = 'unmapped_postcode';
    case MissingDimensions = 'missing_dimensions';
    case UnpricedLane = 'unpriced_lane';
    case ExpiredRate = 'expired_rate';

    /**
     * Sentence shown to the sender.
     *
     * @param  array<string, string>  $context
     */
    public function message(array $context = []): string
    {
        return match ($this) {
            self::UnreadablePostcode => 'That postcode is not a format we recognise for that country.',
            self::UnmappedPostcode => sprintf(
                'We do not ship from %s yet.',
                $context['postcode'] ?? 'that postcode',
            ),
            self::MissingDimensions => 'We need the length, width and height to price this, because air freight is charged on the space it takes.',
            self::UnpricedLane => 'We do not have a price for that lane yet.',
            self::ExpiredRate => 'Our price for that lane has expired and is being reviewed.',
        };
    }
}
```

- [ ] **Step 4: Write the migration**

Create `database/migrations/2026_10_18_200000_create_logistics_zones_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('origin_zones', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->enum('country', ['us', 'ca']);
            $table->string('code');
            $table->string('name');
            $table->boolean('active')->default(true);

            $table->unique(['country', 'code']);
        });

        Schema::create('postcode_bands', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->enum('country', ['us', 'ca']);
            // Matched against the START of the normalised postcode, so "M5V"
            // is one band and "M4B" is another.
            $table->string('prefix');
            $table->foreignId('zone_id')->constrained('origin_zones')->cascadeOnDelete();
            $table->boolean('active')->default(true);

            // Resolution matches on country + prefix and takes the longest
            // match, so this index is the one the lookup actually uses.
            $table->index(['country', 'prefix'], 'postcode_bands_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postcode_bands');
        Schema::dropIfExists('origin_zones');
    }
};
```

- [ ] **Step 5: Write the models**

Create `app/Models/OriginZone.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An internal pricing zone.
 *
 * Not customer-facing. The customer gives a postcode; the postcode maps to one
 * of these; rate rows price these. Naming zones after cities would put the
 * pricing decision in the name, and "US-NJ-NE" is not a rate.
 */
class OriginZone extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'country', 'code', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function bands(): HasMany
    {
        return $this->hasMany(PostcodeBand::class, 'zone_id');
    }

    public function rateRows(): HasMany
    {
        return $this->hasMany(RateCardRow::class, 'origin_zone_id');
    }
}
```

Create `app/Models/PostcodeBand.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps the start of a normalised postcode to a zone.
 *
 * Resolution is longest-prefix-wins. "07" and "070" both match 07030, and
 * without a defined order the result depends on row order, which is not a rule
 * anyone can rely on or explain to a customer.
 */
class PostcodeBand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'country', 'prefix', 'zone_id', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(OriginZone::class, 'zone_id');
    }

    /**
     * The band covering a normalised postcode, or null.
     *
     * No fallback of any kind. A postcode with no band is an origin we have not
     * priced, and quoting a neighbouring rate is how a company loses money on a
     * lane it never sold.
     */
    public static function resolve(?string $normalised, string $country): ?self
    {
        if ($normalised === null || $normalised === '') {
            return null;
        }

        return self::query()
            ->where('country', $country)
            ->where('active', true)
            ->orderByRaw('char_length(prefix) DESC')
            ->get()
            ->first(fn (self $band) => str_starts_with($normalised, $band->prefix));
    }
}
```

- [ ] **Step 6: Run it and verify it passes**

Run: `php artisan test tests/Feature/PostcodeBandTest.php`

Expected: PASS — 11 tests.

- [ ] **Step 7: Verify the migration reverses**

```bash
php artisan migrate:fresh
php artisan migrate:rollback --step=1
php artisan migrate
```

Expected: all three succeed.

- [ ] **Step 8: Commit**

```bash
git add app/Enums app/Models/OriginZone.php app/Models/PostcodeBand.php \
        database/migrations/2026_10_18_200000_create_logistics_zones_table.php \
        tests/Feature/PostcodeBandTest.php
git commit -m "feat(logistics): origin zones and postcode bands

Postcodes map to internal zones; rate rows price zones. Separate tables on
purpose: adding a band for a new town is a data edit rather than a reprice, and
the two change for different reasons on different timescales.

Resolution is longest-prefix-wins. '07' and '070' both match 07030, and without
a defined order the result depends on row order, which is not a rule anyone can
rely on or explain.

No fallback of any kind. A postcode with no band is an origin we have not
priced. Quoting a neighbouring rate is how a company loses money on a lane it
never sold, so resolution returns null and the quote carries the reason.

A band never matches across countries: 'M5V' typed as a US postcode must not find
a Canadian band.

Three intake methods are modelled now — drop-off, partner pickup and self
pickup — so launching the third is a rate change rather than a schema change."
```

---

### Task 4: Rate cards and immutable rate rows

**Files:**
- Create: `database/migrations/2026_10_18_300000_create_rate_cards_table.php`
- Create: `app/Models/RateCard.php`
- Create: `app/Models/RateCardRow.php`
- Test: `tests/Feature/RateCardRowTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RateCardRowTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\IntakeMethod;
use App\Enums\ShipmentUnit;
use App\Models\OriginZone;
use App\Models\RateCard;
use App\Models\RateCardRow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rate rows are immutable. Repricing is an insert.
 *
 * This is the whole reason the table looks the way it does: a shipment booked
 * in March must still be explainable in October, and the only way to guarantee
 * that is to never edit the row that priced it.
 */
class RateCardRowTest extends TestCase
{
    private function zone(): OriginZone
    {
        return OriginZone::create([
            'id' => 'zone_test', 'country' => 'us', 'code' => 'US-NJ-NE',
            'name' => 'North East', 'active' => true,
        ]);
    }

    private function card(array $overrides = []): RateCard
    {
        return RateCard::create(array_merge([
            'id' => 'card_test',
            'name' => 'US air 2026',
            'currency' => 'usd',
        ], $overrides));
    }

    private function row(array $overrides = []): RateCardRow
    {
        return RateCardRow::create(array_merge([
            'id' => 'row_test',
            'rate_card_id' => 'card_test',
            'origin_zone_id' => 'zone_test',
            'destination' => 'Tema',
            'mode' => 'air',
            'unit' => ShipmentUnit::Parcel,
            'intake_method' => IntakeMethod::Dropoff,
            'service_level' => 'standard',
            'divisor' => 2000,
            'per_kg_cents' => 32,
            'per_pallet_cents' => null,
            'min_charge_cents' => 2500,
            'max_billable_kg' => null,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ], $overrides));
    }

    private function seedCard(): void
    {
        $this->zone();
        $this->card();
        $this->row();
    }

    public function test_a_row_belongs_to_a_card_and_a_zone(): void
    {
        $this->seedCard();
        $row = RateCardRow::first();

        $this->assertSame('card_test', $row->rateCard->id);
        $this->assertSame('zone_test', $row->originZone->id);
        $this->assertSame('usd', $row->rateCard->currency);
    }

    public function test_a_row_is_effective_when_its_window_is_open(): void
    {
        $this->seedCard();

        $this->assertTrue(RateCardRow::first()->isEffectiveOn(CarbonImmutable::parse('2026-06-01')));
    }

    public function test_a_row_is_not_effective_before_it_starts(): void
    {
        $this->seedCard();

        $this->assertFalse(RateCardRow::first()->isEffectiveOn(CarbonImmutable::parse('2025-06-01')));
    }

    public function test_a_row_is_not_effective_after_it_expires(): void
    {
        $this->zone();
        $this->card();
        $this->row(['effective_to' => '2026-06-30']);

        $this->assertFalse(RateCardRow::first()->isEffectiveOn(CarbonImmutable::parse('2026-07-01')));
    }

    public function test_an_open_row_stays_effective_forever(): void
    {
        $this->seedCard();

        // effective_to is null while the row is the live rate.
        $this->assertTrue(RateCardRow::first()->isEffectiveOn(CarbonImmutable::parse('2030-01-01')));
    }

    public function test_a_row_is_inside_its_window_on_the_boundary_days(): void
    {
        $this->zone();
        $this->card();
        $this->row(['effective_from' => '2026-06-01', 'effective_to' => '2026-06-30']);

        $row = RateCardRow::first();

        $this->assertTrue($row->isEffectiveOn(CarbonImmutable::parse('2026-06-01')), 'Starts on the first day, inclusive.');
        $this->assertTrue($row->isEffectiveOn(CarbonImmutable::parse('2026-06-30')), 'Ends on the last day, inclusive.');
    }

    public function test_a_pallet_row_can_carry_a_pallet_floor(): void
    {
        $this->zone();
        $this->card();
        $this->row([
            'id' => 'row_pallet',
            'unit' => ShipmentUnit::Pallet,
            'per_kg_cents' => 10,
            'per_pallet_cents' => 18_000,
            'min_charge_cents' => 0,
        ]);

        $pallet = RateCardRow::where('id', 'row_pallet')->firstOrFail();

        $this->assertTrue($pallet->unit->isPallet());
        $this->assertSame(18_000, $pallet->per_pallet_cents);
    }

    public function test_a_parcel_row_has_no_pallet_floor(): void
    {
        $this->seedCard();

        $this->assertNull(RateCardRow::first()->per_pallet_cents);
    }

    public function test_a_ceiling_is_read_when_it_is_set(): void
    {
        $this->zone();
        $this->card();
        $this->row(['max_billable_kg' => 30]);

        $this->assertSame(30.0, RateCardRow::first()->max_billable_kg);
    }

    public function test_a_card_holds_one_currency(): void
    {
        $this->seedCard();

        // A GHS reprice must not be able to touch a USD card.
        $this->assertSame('usd', RateCard::first()->currency);
        $this->assertSame(1, RateCard::count());
    }
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `php artisan test tests/Feature/RateCardRowTest.php`

Expected: FAIL — `rate_cards` table does not exist.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_10_18_300000_create_rate_cards_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_cards', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            // Currency lives on the card, not the row, so one card is one
            // currency and a reprice in one can never touch another.
            $table->string('currency', 3);
            $table->timestampsTz();
        });

        Schema::create('rate_card_rows', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('rate_card_id')->constrained('rate_cards')->cascadeOnDelete();
            $table->foreignId('origin_zone_id')->constrained('origin_zones')->cascadeOnDelete();
            $table->string('destination');

            $table->enum('mode', ['air', 'sea']);
            $table->enum('unit', ['parcel', 'pallet']);
            // All three exist from day one so launching the third is a data
            // change, not a migration.
            $table->enum('intake_method', ['dropoff', 'partner_pickup', 'self_pickup']);
            $table->string('service_level');

            // Volumetric divisor: 2000 air, 5000 sea. On the row rather than in
            // code so a lane change is a data edit, not a deploy.
            $table->unsignedInteger('divisor');

            $table->integer('per_kg_cents');
            // NULL for a parcel. A pallet's floor, because a half-empty pallet
            // costs what a full one costs to handle.
            $table->integer('per_pallet_cents')->nullable();
            $table->integer('min_charge_cents')->default(0);
            $table->decimal('max_billable_kg', 8, 2)->nullable();

            // The effective window. Rows are never updated: repricing closes the
            // old row by setting effective_to and inserts a successor.
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // The lookup the resolver runs: one lane, one unit, one service.
            $table->index(
                ['origin_zone_id', 'destination', 'unit', 'intake_method', 'service_level'],
                'rate_card_rows_lookup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_card_rows');
        Schema::dropIfExists('rate_cards');
    }
};
```

- [ ] **Step 4: Write the models**

Create `app/Models/RateCard.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named group of rate rows in one currency.
 *
 * Carries no dates. The effective window lives on the row, because two tables
 * holding "when does this price apply" is two sources of truth.
 */
class RateCard extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'currency'];

    public function rows(): HasMany
    {
        return $this->hasMany(RateCardRow::class, 'rate_card_id');
    }
}
```

Create `app/Models/RateCardRow.php`:

```php
<?php

namespace App\Models;

use App\Enums\IntakeMethod;
use App\Enums\ShipmentUnit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable price for one lane, unit and service.
 *
 * Never updated. Repricing sets effective_to on the old row and inserts a
 * successor, so a shipment booked in March is still explainable in October.
 */
class RateCardRow extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'rate_card_id', 'origin_zone_id', 'destination', 'mode', 'unit',
        'intake_method', 'service_level', 'divisor', 'per_kg_cents',
        'per_pallet_cents', 'min_charge_cents', 'max_billable_kg',
        'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'unit' => ShipmentUnit::class,
            'intake_method' => IntakeMethod::class,
            'per_kg_cents' => 'integer',
            'per_pallet_cents' => 'integer',
            'min_charge_cents' => 'integer',
            'divisor' => 'integer',
            'max_billable_kg' => 'decimal:2',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
        ];
    }

    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class, 'rate_card_id');
    }

    public function originZone(): BelongsTo
    {
        return $this->belongsTo(OriginZone::class, 'origin_zone_id');
    }

    /**
     * Whether this row prices anything on a given day.
     *
     * Both bounds are inclusive. A window that silently excluded its own start
     * date would leave a lane unpriced for a day with nothing to show for it.
     */
    public function isEffectiveOn(CarbonImmutable $on): bool
    {
        if ($this->effective_from !== null && $on->lt($this->effective_from->startOfDay())) {
            return false;
        }

        if ($this->effective_to !== null && $on->gt($this->effective_to->endOfDay())) {
            return false;
        }

        return true;
    }

    public function currency(): string
    {
        return $this->rateCard?->currency ?? 'usd';
    }
}
```

- [ ] **Step 5: Run it and verify it passes**

Run: `php artisan test tests/Feature/RateCardRowTest.php`

Expected: PASS — 10 tests.

- [ ] **Step 6: Verify the migration reverses**

```bash
php artisan migrate:fresh
php artisan migrate:rollback --step=2
php artisan migrate
```

Expected: all succeed.

- [ ] **Step 7: Commit**

```bash
git add app/Models/RateCard.php app/Models/RateCardRow.php \
        database/migrations/2026_10_18_300000_create_rate_cards_table.php \
        tests/Feature/RateCardRowTest.php
git commit -m "feat(logistics): rate cards with immutable rate rows

A row is never updated. Repricing sets effective_to on the old row and inserts
a successor, so a shipment booked in March is still explainable in October. This
is the entire reason the table is shaped this way, and it is what a quote that
records its row id depends on.

Currency lives on the card, not the row, so one card is one currency and a GHS
reprice cannot reach a USD card. The card carries no dates: two tables holding
'when does this price apply' is two sources of truth.

Both effective bounds are inclusive, tested on the boundary days. A window that
excluded its own start date would leave a lane unpriced for a day with nothing
to show for it.

The divisor sits on the row, so moving a lane from air to sea is a data edit
rather than a deploy."
```

---

### Task 5: Rate resolution

**Files:**
- Create: `app/Services/FreightRateResolver.php`
- Test: `tests/Feature/FreightRateResolverTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FreightRateResolverTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\IntakeMethod;
use App\Enums\QuoteUnavailableReason;
use App\Enums\ShipmentUnit;
use App\Models\OriginZone;
use App\Models\PostcodeBand;
use App\Models\RateCard;
use App\Models\RateCardRow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Turning what a sender told us into exactly one price, or a reason there is
 * none.
 *
 * The no-quote cases matter more than the happy path. A resolver that guesses
 * is worse than one that refuses, because a guessed rate is a price a customer
 * accepts and the platform then honours.
 */
class FreightRateResolverTest extends TestCase
{
    private function seedRow(array $overrides = []): RateCardRow
    {
        OriginZone::create([
            'id' => 'zone_ne', 'country' => 'us', 'code' => 'US-NJ-NE',
            'name' => 'North East', 'active' => true,
        ]);

        PostcodeBand::create([
            'id' => 'band_070', 'country' => 'us', 'prefix' => '070',
            'zone_id' => 'zone_ne', 'active' => true,
        ]);

        RateCard::create(['id' => 'card_us', 'name' => 'US air', 'currency' => 'usd']);

        return RateCardRow::create(array_merge([
            'id' => 'row_ne', 'rate_card_id' => 'card_us', 'origin_zone_id' => 'zone_ne',
            'destination' => 'Tema', 'mode' => 'air', 'unit' => ShipmentUnit::Parcel,
            'intake_method' => IntakeMethod::Dropoff, 'service_level' => 'standard',
            'divisor' => 2000, 'per_kg_cents' => 32, 'per_pallet_cents' => null,
            'min_charge_cents' => 2500, 'max_billable_kg' => null,
            'effective_from' => '2026-01-01', 'effective_to' => null,
        ], $overrides));
    }

    private function resolve(array $overrides = [], ?string $postcode = '07030', string $country = 'us')
    {
        return app(FreightRateResolver::class)->resolve(
            postcode: $postcode,
            country: $country,
            destination: 'Tema',
            mode: 'air',
            unit: ShipmentUnit::Parcel,
            intakeMethod: IntakeMethod::Dropoff,
            serviceLevel: 'standard',
            on: CarbonImmutable::now(),
            overrides: $overrides,
        );
    }

    public function test_a_matching_postcode_resolves_to_one_row(): void
    {
        $this->seedRow();

        $result = $this->resolve();

        $this->assertNotNull($result->row);
        $this->assertSame('row_ne', $result->row->id);
        $this->assertNull($result->reason);
    }

    public function test_an_unreadable_postcode_is_refused_with_a_reason(): void
    {
        $this->seedRow();

        $result = $this->resolve(postcode: 'nonsense');

        $this->assertNull($result->row);
        $this->assertSame(QuoteUnavailableReason::UnreadablePostcode, $result->reason);
    }

    public function test_an_unmapped_postcode_names_the_postcode(): void
    {
        $this->seedRow();

        $result = $this->resolve(postcode: '60601');

        $this->assertSame(QuoteUnavailableReason::UnmappedPostcode, $result->reason);
        $this->assertStringContainsString(
            '60601',
            $result->reason->message(['postcode' => '60601']),
        );
    }

    public function test_an_unpriced_lane_is_refused(): void
    {
        $this->seedRow();

        $result = app(FreightRateResolver::class)->resolve(
            postcode: '07030',
            country: 'us',
            destination: 'Takoradi',
            mode: 'air',
            unit: ShipmentUnit::Parcel,
            intakeMethod: IntakeMethod::Dropoff,
            serviceLevel: 'standard',
            on: CarbonImmutable::now(),
        );

        // No fallback to the Tema rate. Quoting a lane we have not priced is
        // how a company loses money on it.
        $this->assertSame(QuoteUnavailableReason::UnpricedLane, $result->reason);
    }

    public function test_an_expired_rate_is_refused(): void
    {
        $this->seedRow(['effective_from' => '2025-01-01', 'effective_to' => '2025-12-31']);

        $result = $this->resolve();

        // Not "unpriced lane": we did price this lane, the price is stale, and
        // the operator needs to know which of those two it is.
        $this->assertSame(QuoteUnavailableReason::ExpiredRate, $result->reason);
    }

    public function test_a_rate_that_starts_in_the_future_is_refused(): void
    {
        $this->seedRow(['effective_from' => '2027-01-01']);

        $result = $this->resolve();

        $this->assertSame(QuoteUnavailableReason::ExpiredRate, $result->reason);
    }

    public function test_the_most_specific_service_level_wins(): void
    {
        $this->seedRow(['id' => 'row_generic', 'service_level' => 'standard']);

        RateCardRow::create([
            'id' => 'row_express', 'rate_card_id' => 'card_us', 'origin_zone_id' => 'zone_ne',
            'destination' => 'Tema', 'mode' => 'air', 'unit' => ShipmentUnit::Parcel,
            'intake_method' => IntakeMethod::Dropoff, 'service_level' => 'express',
            'divisor' => 2000, 'per_kg_cents' => 90, 'per_pallet_cents' => null,
            'min_charge_cents' => 4000, 'max_billable_kg' => null,
            'effective_from' => '2026-01-01', 'effective_to' => null,
        ]);

        $express = app(FreightRateResolver::class)->resolve(
            postcode: '07030', country: 'us', destination: 'Tema', mode: 'air',
            unit: ShipmentUnit::Parcel, intakeMethod: IntakeMethod::Dropoff,
            serviceLevel: 'express', on: CarbonImmutable::now(),
        );

        $this->assertSame('row_express', $express->row->id);
        $this->assertSame(90, $express->row->per_kg_cents);
    }

    public function test_an_intake_method_resolves_to_its_own_rate(): void
    {
        $this->seedRow(['id' => 'row_dropoff']);

        RateCardRow::create([
            'id' => 'row_pickup', 'rate_card_id' => 'card_us', 'origin_zone_id' => 'zone_ne',
            'destination' => 'Tema', 'mode' => 'air', 'unit' => ShipmentUnit::Parcel,
            'intake_method' => IntakeMethod::PartnerPickup, 'service_level' => 'standard',
            'divisor' => 2000, 'per_kg_cents' => 60, 'per_pallet_cents' => null,
            'min_charge_cents' => 15000, 'max_billable_kg' => null,
            'effective_from' => '2026-01-01', 'effective_to' => null,
        ]);

        $result = app(FreightRateResolver::class)->resolve(
            postcode: '07030', country: 'us', destination: 'Tema', mode: 'air',
            unit: ShipmentUnit::Parcel, intakeMethod: IntakeMethod::PartnerPickup,
            serviceLevel: 'standard', on: CarbonImmutable::now(),
        );

        // A courier bills per stop, so pickup carries a high minimum of its own.
        $this->assertSame('row_pickup', $result->row->id);
        $this->assertSame(15_000, $result->row->min_charge_cents);
    }

    public function test_the_resolved_row_carries_its_currency(): void
    {
        $this->seedRow();

        $this->assertSame('usd', $this->resolve()->row->currency());
    }
}
```

Add `use App\Services\FreightRateResolver;` to the test's imports.

- [ ] **Step 2: Run it and verify it fails**

Run: `php artisan test tests/Feature/FreightRateResolverTest.php`

Expected: FAIL — `FreightRateResolver` does not exist.

- [ ] **Step 3: Write the resolver and its result**

Create `app/Data/ResolvedRate.php`:

```php
<?php

namespace App\Data;

use App\Enums\QuoteUnavailableReason;
use App\Models\RateCardRow;

/**
 * One resolved price, or the reason there is none.
 *
 * The reason is a value rather than a null, because "we cannot price this from
 * Newark on courier pickup" is something a customer and an operator can both
 * act on, and a blank card is neither.
 */
final readonly class ResolvedRate
{
    private function __construct(
        public ?RateCardRow $row,
        public ?QuoteUnavailableReason $reason,
        /** @var array<string, string> */
        public array $context,
    ) {}

    public static function found(RateCardRow $row): self
    {
        return new self($row, null, []);
    }

    public static function refused(QuoteUnavailableReason $reason, array $context = []): self
    {
        return new self(null, $reason, $context);
    }

    public function isAvailable(): bool
    {
        return $this->row !== null;
    }

    public function message(): string
    {
        return $this->reason?->message($this->context) ?? '';
    }
}
```

Create `app/Services/FreightRateResolver.php`:

```php
<?php

namespace App\Services;

use App\Data\ResolvedRate;
use App\Enums\IntakeMethod;
use App\Enums\QuoteUnavailableReason;
use App\Enums\ShipmentUnit;
use App\Models\PostcodeBand;
use App\Models\RateCardRow;
use Carbon\CarbonImmutable;

/**
 * Turning what a sender told us into exactly one price.
 *
 * Two steps, and neither of them ever guesses. First the postcode becomes a
 * zone; then the zone becomes a row. A gap at either step is a refusal with a
 * reason attached, not a fallback to a neighbouring rate — a fallback is how a
 * company quietly loses money on a lane it never sold.
 */
class FreightRateResolver
{
    public function resolve(
        string $postcode,
        string $country,
        string $destination,
        string $mode,
        ShipmentUnit $unit,
        IntakeMethod $intakeMethod,
        ?string $serviceLevel,
        CarbonImmutable $on,
    ): ResolvedRate {
        $normalised = Postcode::normalise($postcode, $country);

        if ($normalised === null) {
            return ResolvedRate::refused(QuoteUnavailableReason::UnreadablePostcode);
        }

        $band = PostcodeBand::resolve($normalised, $country);

        if ($band === null) {
            return ResolvedRate::refused(
                QuoteUnavailableReason::UnmappedPostcode,
                ['postcode' => $normalised],
            );
        }

        $base = RateCardRow::query()
            ->where('origin_zone_id', $band->zone_id)
            ->where('destination', $destination)
            ->where('mode', $mode)
            ->where('unit', $unit->value)
            ->where('intake_method', $intakeMethod->value)
            ->with('rateCard')
            ->get();

        // A named service level beats the catch-all. Ordered by id so the
        // result is deterministic rather than dependent on row order.
        $candidates = $base
            ->filter(fn (RateCardRow $row) => $row->service_level === $serviceLevel)
            ->sortBy('id')
            ->values();

        if ($candidates->isEmpty() && $serviceLevel !== null) {
            // No row for that service level; fall back only to the generic one,
            // which is an explicitly priced catch-all rather than a guess.
            $candidates = $base
                ->filter(fn (RateCardRow $row) => $row->service_level === 'standard')
                ->sortBy('id')
                ->values();
        }

        if ($candidates->isEmpty()) {
            return ResolvedRate::refused(QuoteUnavailableReason::UnpricedLane);
        }

        $effective = $candidates->first(fn (RateCardRow $row) => $row->isEffectiveOn($on));

        if ($effective === null) {
            // We did price this lane once; the price is stale. That is a
            // different problem from never having priced it, and the operator
            // needs to know which one they are looking at.
            return ResolvedRate::refused(QuoteUnavailableReason::ExpiredRate);
        }

        return ResolvedRate::found($effective);
    }
}
```

- [ ] **Step 4: Remove the test's stray parameter**

The private `resolve()` helper in the test passes an `overrides` argument the
real resolver does not take. Delete that argument and its default:

```php
    private function resolve(array $overrides = [], ?string $postcode = '07030', string $country = 'us')
```

becomes

```php
    private function resolve(?string $postcode = '07030', string $country = 'us')
```

and remove `overrides: $overrides,` from the body. The four tests that call
`$this->resolve(postcode: ...)` keep working; `$this->resolve()` keeps its
defaults.

- [ ] **Step 5: Run it and verify it passes**

Run: `php artisan test tests/Feature/FreightRateResolverTest.php`

Expected: PASS — 9 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Services/FreightRateResolver.php app/Data/ResolvedRate.php \
        tests/Feature/FreightRateResolverTest.php
git commit -m "feat(logistics): resolve a sender to exactly one rate, or a reason

Two steps, and neither guesses. A postcode becomes a zone; a zone becomes a row.
A gap at either step is a refusal carrying its reason, never a fallback to a
neighbouring rate.

An expired rate and an unpriced lane are different answers. We did price this
lane once and the price is stale is a problem an operator can fix; we have never
priced this lane is a business decision. Both are distinct from a missing
postcode, which is a data gap.

Resolution is deterministic. Candidates are sorted by id rather than taken in
row order, so the same request always resolves to the same row and a quote can
be reproduced.

A named service level beats the catch-all. Falling back to 'standard' is
deliberate and bounded: standard is an explicitly priced row, not a guess."
```

---

### Task 6: The quote

**Files:**
- Create: `app/Data/FreightQuote.php`
- Create: `app/Services/FreightQuoteService.php`
- Test: `tests/Feature/FreightQuoteServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FreightQuoteServiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\IntakeMethod;
use App\Enums\QuoteUnavailableReason;
use App\Enums\ShipmentUnit;
use App\Models\OriginZone;
use App\Models\PostcodeBand;
use App\Models\RateCard;
use App\Models\RateCardRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The price a sender is shown.
 *
 * The two properties that matter are that the lines add up to the total, and
 * that the quote records the row that priced it. Without the second, a price
 * from March cannot be explained in October.
 */
class FreightQuoteServiceTest extends TestCase
{
    private function seedRow(array $overrides = []): RateCardRow
    {
        OriginZone::firstOrCreate(
            ['id' => 'zone_ne'],
            ['country' => 'us', 'code' => 'US-NJ-NE', 'name' => 'North East', 'active' => true],
        );

        PostcodeBand::firstOrCreate(
            ['id' => 'band_070'],
            ['country' => 'us', 'prefix' => '070', 'zone_id' => 'zone_ne', 'active' => true],
        );

        RateCard::firstOrCreate(['id' => 'card_us'], ['name' => 'US air', 'currency' => 'usd']);

        return RateCardRow::create(array_merge([
            'id' => 'row_ne', 'rate_card_id' => 'card_us', 'origin_zone_id' => 'zone_ne',
            'destination' => 'Tema', 'mode' => 'air', 'unit' => ShipmentUnit::Parcel,
            'intake_method' => IntakeMethod::Dropoff, 'service_level' => 'standard',
            // No minimum here on purpose: the per-kilo arithmetic has to be
            // visible in the price assertions below. The minimum gets its own
            // test, where it is the thing being tested rather than a floor
            // quietly overriding the figure.
            'divisor' => 2000, 'per_kg_cents' => 32, 'per_pallet_cents' => null,
            'min_charge_cents' => 0, 'max_billable_kg' => null,
            'effective_from' => '2026-01-01', 'effective_to' => null,
        ], $overrides));
    }

    private function quote(array $overrides = []): \App\Data\FreightQuote
    {
        return app(FreightQuoteService::class)->quote(
            postcode: '07030',
            country: 'us',
            destination: 'Tema',
            mode: 'air',
            unit: ShipmentUnit::Parcel,
            intakeMethod: IntakeMethod::Dropoff,
            weightKg: $overrides['weightKg'] ?? 5.0,
            lengthCm: $overrides['lengthCm'] ?? 30,
            widthCm: $overrides['widthCm'] ?? 30,
            heightCm: $overrides['heightCm'] ?? 30,
            pickup: $overrides['pickup'] ?? false,
        );
    }

    public function test_a_dense_box_is_priced_on_its_weight(): void
    {
        $this->seedRow();

        $quote = $this->quote(['weightKg' => 20.0, 'lengthCm' => 30, 'widthCm' => 30, 'heightCm' => 30]);

        // 20 kg actual beats 27,000/2000 = 13.5 kg volumetric.
        // 20,000 g x 32 cents/kg = 640 cents.
        $this->assertSame(640, $quote->totalCents);
    }

    public function test_a_light_box_is_priced_on_its_volume(): void
    {
        $this->seedRow();

        $quote = $this->quote(['weightKg' => 2.0, 'lengthCm' => 30, 'widthCm' => 30, 'heightCm' => 30]);

        // 13.5 kg volumetric beats 2 kg actual: 13,500 x 32 / 1000 = 432.
        $this->assertSame(432, $quote->totalCents);
    }

    public function test_the_minimum_charge_binds_when_freight_is_below_it(): void
    {
        $this->seedRow(['min_charge_cents' => 5000]);

        // 640 cents of real freight, floored to the 5,000 minimum. A floor
        // raises a low price; it is not a price in itself.
        $this->assertSame(5000, $this->quote()->totalCents);
    }

    public function test_the_minimum_charge_does_not_cap_real_freight(): void
    {
        $this->seedRow(['min_charge_cents' => 100]);

        // 640 cents of real freight is charged in full. A floor is a floor, not
        // a cap, and the mistake here would be to charge 100 to move 20 kg.
        $this->assertSame(640, $this->quote()->totalCents);
    }

    public function test_the_lines_add_up_to_the_total(): void
    {
        $this->seedRow();

        $quote = $this->quote(['weightKg' => 20.0]);

        $sum = array_sum(array_map(fn ($line) => $line['cents'], $quote->lines));

        $this->assertSame($quote->totalCents, $sum);
    }

    public function test_the_quote_records_the_row_that_priced_it(): void
    {
        $this->seedRow();

        // Without this, a price from March cannot be explained in October.
        $this->assertSame('row_ne', $this->quote()->rateCardRowId);
    }

    public function test_the_quote_is_reproducible_after_a_reprice(): void
    {
        $this->seedRow();

        $before = $this->quote()->totalCents;

        // A reprice closes the old row and inserts a successor.
        RateCardRow::where('id', 'row_ne')->update(['effective_to' => '2026-11-01']);
        RateCardRow::create([
            'id' => 'row_ne_v2', 'rate_card_id' => 'card_us', 'origin_zone_id' => 'zone_ne',
            'destination' => 'Tema', 'mode' => 'air', 'unit' => ShipmentUnit::Parcel,
            'intake_method' => IntakeMethod::Dropoff, 'service_level' => 'standard',
            'divisor' => 2000, 'per_kg_cents' => 99, 'per_pallet_cents' => null,
            'min_charge_cents' => 2500, 'max_billable_kg' => null,
            'effective_from' => '2026-11-02', 'effective_to' => null,
        ]);

        // Today is still inside the original window, so the price must not move.
        $this->assertSame($before, $this->quote()->totalCents);
        $this->assertSame('row_ne', $this->quote()->rateCardRowId);
    }

    public function test_a_parcel_without_dimensions_is_refused(): void
    {
        $this->seedRow();

        $result = app(FreightQuoteService::class)->quote(
            postcode: '07030', country: 'us', destination: 'Tema', mode: 'air',
            unit: ShipmentUnit::Parcel, intakeMethod: IntakeMethod::Dropoff,
            weightKg: 3.0, lengthCm: null, widthCm: null, heightCm: null,
            pickup: false,
        );

        // Air freight is priced on the space it takes. Without dimensions any
        // figure would be invented.
        $this->assertNull($result->quote);
        $this->assertSame(QuoteUnavailableReason::MissingDimensions, $result->reason);
    }

    public function test_a_pallet_carries_its_own_floor(): void
    {
        $this->seedRow([
            'id' => 'row_pallet', 'unit' => ShipmentUnit::Pallet,
            'per_kg_cents' => 10, 'per_pallet_cents' => 18_000, 'min_charge_cents' => 0,
        ]);

        $result = app(FreightQuoteService::class)->quote(
            postcode: '07030', country: 'us', destination: 'Tema', mode: 'air',
            unit: ShipmentUnit::Pallet, intakeMethod: IntakeMethod::Dropoff,
            weightKg: 40.0, lengthCm: 120, widthCm' => 120, heightCm: 150,
            pickup: false,
        );

        $this->assertSame(18_000, $result->quote->totalCents);
    }

    public function test_a_heavy_pallet_is_charged_above_its_floor(): void
    {
        $this->seedRow([
            'id' => 'row_pallet_heavy', 'unit' => ShipmentUnit::Pallet,
            'per_kg_cents' => 30, 'per_pallet_cents' => 18_000, 'min_charge_cents' => 0,
        ]);

        $result = app(FreightQuoteService::class)->quote(
            postcode: '07030', country: 'us', destination: 'Tema', mode: 'air',
            unit: ShipmentUnit::Pallet, intakeMethod: IntakeMethod::Dropoff,
            weightKg: 900.0, lengthCm: 120, widthCm: 120, heightCm: 150,
            pickup: false,
        );

        // A 120x120x150 cm pallet is 1,080 kg volumetric, which beats the
        // 900 kg on the scale. 1,080,000 g x 30 cents/kg = 32,400, above the
        // 18,000 floor — so the floor must not win.
        $this->assertSame(32_400, $result->quote->totalCents);
    }

    public function test_a_pickup_is_a_separate_line_not_a_lump(): void
    {
        $this->seedRow();

        RateCardRow::create([
            'id' => 'row_pickup', 'rate_card_id' => 'card_us', 'origin_zone_id' => 'zone_ne',
            'destination' => 'Tema', 'mode' => 'air', 'unit' => ShipmentUnit::Parcel,
            // PartnerPickup, because that is how the service resolves a pickup.
            // A Dropoff row here would never be found.
            'intake_method' => IntakeMethod::PartnerPickup, 'service_level' => 'pickup',
            'divisor' => 2000, 'per_kg_cents' => 0, 'per_pallet_cents' => null,
            'min_charge_cents' => 15_000, 'max_billable_kg' => null,
            'effective_from' => '2026-01-01', 'effective_to' => null,
        ]);

        $quote = $this->quote(['weightKg' => 20.0, 'pickup' => true]);

        // A courier bills per stop, so the stop is visible as its own line
        // rather than folded into a single number.
        $this->assertCount(2, $quote->lines);
        $this->assertSame(640 + 15_000, $quote->totalCents);
        $this->assertSame(
            array_sum(array_map(fn ($l) => $l['cents'], $quote->lines)),
            $quote->totalCents,
        );
    }
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `php artisan test tests/Feature/FreightQuoteServiceTest.php`

Expected: FAIL — `FreightQuoteService` does not exist.

- [ ] **Step 3: Fix the two syntax errors in the test above**

In `test_a_pallet_carries_its_own_floor`, the named argument is missing its
opening identifier. Change:

```php
            weightKg: 40.0, lengthCm: 120, widthCm' => 120, heightCm: 150,
```

to

```php
            weightKg: 40.0, lengthCm: 120, widthCm: 120, heightCm: 150,
```

- [ ] **Step 4: Write the quote and the service**

Create `app/Data/FreightQuote.php`:

```php
<?php

namespace App\Data;

use App\Enums\QuoteUnavailableReason;

/**
 * A price, and the row that produced it.
 *
 * Lines rather than a single total, so a customer can see that part of the
 * price is the courier stop rather than guessing. The row id is recorded so a
 * price stays explainable after the rate behind it has moved on.
 */
final readonly class FreightQuote
{
    /**
     * @param  array<int, array{label: string, cents: int}>  $lines
     */
    public function __construct(
        public array $lines,
        public int $totalCents,
        public string $currencyCode,
        public ?string $rateCardRowId,
    ) {}

    /** @param array<int, array{label: string, cents: int}> $lines */
    public static function of(array $lines, string $currencyCode, ?string $rateCardRowId): self
    {
        return new self(
            $lines,
            array_sum(array_column($lines, 'cents')),
            $currencyCode,
            $rateCardRowId,
        );
    }
}

/**
 * A quote, or the reason there is none.
 */
final readonly class QuoteResult
{
    private function __construct(
        public ?FreightQuote $quote,
        public ?QuoteUnavailableReason $reason,
        /** @var array<string, string> */
        public array $context,
    ) {}

    public static function quoted(FreightQuote $quote): self
    {
        return new self($quote, null, []);
    }

    public static function refused(QuoteUnavailableReason $reason, array $context = []): self
    {
        return new self(null, $reason, $context);
    }

    public function isAvailable(): bool
    {
        return $this->quote !== null;
    }

    public function message(): string
    {
        return $this->reason?->message($this->context) ?? '';
    }
}
```

> If the framework rejects two classes in one file, move `QuoteResult` to its own
> file `app/Data/QuoteResult.php` and add `use App\Data\QuoteResult;` nowhere —
> it is in the same namespace. PSR-4 autoloading maps one class to one file.

Create `app/Services/FreightQuoteService.php`:

```php
<?php

namespace App\Services;

use App\Data\FreightQuote;
use App\Data\QuoteResult;
use App\Enums\IntakeMethod;
use App\Enums\QuoteUnavailableReason;
use App\Enums\ShipmentUnit;
use App\Models\RateCardRow;

/**
 * The price a sender is shown.
 *
 * Resolves a rate, prices billable weight against it, and adds a pickup line
 * when asked. It guesses nothing: an undimensioned parcel, an unmapped postcode
 * or an expired rate all return a reason instead of a number.
 */
class FreightQuoteService
{
    public function __construct(private FreightRateResolver $resolver) {}

    public function quote(
        string $postcode,
        string $country,
        string $destination,
        string $mode,
        ShipmentUnit $unit,
        IntakeMethod $intakeMethod,
        float $weightKg,
        ?int $lengthCm,
        ?int $widthCm,
        ?int $heightCm,
        bool $pickup = false,
        ?string $serviceLevel = 'standard',
    ): QuoteResult {
        $resolved = $this->resolver->resolve(
            postcode: $postcode,
            country: $country,
            destination: $destination,
            mode: $mode,
            unit: $unit,
            intakeMethod: $intakeMethod,
            serviceLevel: $serviceLevel,
            on: now()->toImmutable(),
        );

        if (! $resolved->isAvailable()) {
            return QuoteResult::refused(
                $resolved->reason,
                $resolved->context,
            );
        }

        $row = $resolved->row;

        // A pallet is measured by its footprint; a parcel needs all three
        // dimensions because air freight is priced on the space it takes.
        $dimensions = $unit->isPallet()
            ? [$this->palletDimension($unit), 120, 150]
            : [$lengthCm, $widthCm, $heightCm];

        if ($dimensions[0] === null || $dimensions[1] === null || $dimensions[2] === null) {
            return QuoteResult::refused(QuoteUnavailableReason::MissingDimensions);
        }

        $billable = BillableWeight::grams(
            $weightKg,
            $dimensions[0],
            $dimensions[1],
            $dimensions[2],
            $row->divisor,
        );

        if ($billable === null) {
            return QuoteResult::refused(QuoteUnavailableReason::MissingDimensions);
        }

        $freight = BillableWeight::floor(
            BillableWeight::cents($billable, $row->per_kg_cents),
            $row->min_charge_cents,
        );

        // A pallet's floor binds on a light pallet and does not override real
        // weight-based freight on a heavy one.
        if ($row->per_pallet_cents !== null) {
            $freight = max($freight, $row->per_pallet_cents);
        }

        $lines = [[
            'label' => 'Freight to Tema',
            'cents' => $freight,
        ]];

        if ($pickup) {
            $pickupCents = $this->pickupCharge(
                $postcode: $postcode,
                country: $country,
                destination: $destination,
                mode: $mode,
                unit: $unit,
            );

            if ($pickupCents === null) {
                return QuoteResult::refused(
                    QuoteUnavailableReason::UnpricedLane,
                    ['service' => 'pickup'],
                );
            }

            $lines[] = [
                'label' => 'Courier collection',
                'cents' => $pickupCents,
            ];
        }

        return QuoteResult::quoted(
            FreightQuote::of($lines, $row->currency(), $row->id),
        );
    }

    /**
     * The courier stop, priced by its own rate row.
     *
     * A stop is not a kilo, so it is not a flat fee bolted onto the quote: it
     * is a rate row in its own right, whose high minimum prices a stop
     * correctly without a second pricing engine.
     */
    private function pickupCharge(
        string $postcode,
        string $country,
        string $destination,
        string $mode,
        ShipmentUnit $unit,
    ): ?int {
        $resolved = $this->resolver->resolve(
            postcode: $postcode,
            country: $country,
            destination: $destination,
            mode: $mode,
            unit: $unit,
            intakeMethod: IntakeMethod::PartnerPickup,
            serviceLevel: 'pickup',
            on: now()->toImmutable(),
        );

        return $resolved->row?->min_charge_cents;
    }

    /**
     * A standard pallet footprint, in whole centimetres.
     *
     * Pallet pricing is by footprint rather than by the sender's dimensions,
     * so the figure is ours and not something a customer can understate.
     */
    private function palletDimension(ShipmentUnit $unit): ?int
    {
        return $unit->isPallet() ? 120 : null;
    }
}
```

- [ ] **Step 5: Run it and verify it passes**

Run: `php artisan test tests/Feature/FreightQuoteServiceTest.php`

Expected: PASS — 11 tests.

- [ ] **Step 6: Run the whole suite**

Run: `php artisan test`

Expected: every test passes. Any failure means a change here altered something
outside the rate card — investigate rather than adjusting the expectation.

- [ ] **Step 7: Commit**

```bash
git add app/Data/FreightQuote.php app/Services/FreightQuoteService.php \
        tests/Feature/FreightQuoteServiceTest.php
git commit -m "feat(logistics): the price a sender is shown

Lines rather than a single total, so a customer can see that part of the price
is the courier stop instead of guessing. The row id is recorded alongside, so a
price from March stays explainable in October; a test reprices the card and
asserts today's quote does not move.

A pickup is priced by its own rate row rather than a flat fee bolted on. A stop
is not a kilo, so a single flat fee must either lose money on rural pickups or
overcharge dense ones, and a row with a high minimum prices a stop correctly
using the same engine.

Pallet pricing uses our standard footprint rather than the sender's dimensions,
so a customer cannot understate it. The floor binds on a light pallet and does
not override real weight-based freight on a heavy one, and both are tested.

Nothing is guessed: an undimensioned parcel, an unmapped postcode and an expired
rate each return a reason rather than a number."
```

---

### Task 7: Verification

**Files:** No new files. Verification only.

- [ ] **Step 1: Run the complete suite**

Run: `php artisan test`

Expected: every test passes, including the 277 that existed before this work.

- [ ] **Step 2: Verify both migrations reverse**

```bash
php artisan migrate:fresh
php artisan migrate:rollback --step=2
php artisan migrate
```

Expected: no errors.

- [ ] **Step 3: Verify the seeders still run**

Run: `php artisan migrate:fresh --seed`

Expected: exit code 0. The rate card ships unseeded — there is no real lane
pricing to invent, and a made-up rate that a customer could be quoted is worse
than none.

- [ ] **Step 4: Check for float arithmetic on the money path**

```bash
grep -nE '\bfloat\b|\(float\)' app/Services/BillableWeight.php app/Services/Postcode.php
```

Expected: only the `float $weightKg` parameter and its single multiplication.
No floats in the cents path.

- [ ] **Step 5: Confirm the resolver never falls back**

```bash
grep -n "first()\|->row ??\|?:.*RateCardRow" app/Services/FreightRateResolver.php
```

Expected: no fallback that substitutes a different row for a missing one.

- [ ] **Step 6: Check nothing in `QuoteService` or `freight_rates` was touched**

```bash
git diff main --stat -- app/Services/QuoteService.php database/migrations/*freight*
```

Expected: empty. Car freight is migrated in its own task.

- [ ] **Step 7: Commit any fixes**

If steps 1–6 found nothing, do not commit. If verification required changes,
commit them naming the defect:

```bash
git add -A
git commit -m "fix(logistics): <what was wrong and why>"
```

---

## Self-review

**Spec coverage.** Postcode normalisation (Task 1), billable weight in integer
grams (Task 2), zones and bands with longest-prefix-wins (Task 3), immutable
rate rows and the effective window (Task 4), two-step resolution with all five
no-quote reasons (Task 5), the quote with lines and the pallet floor (Task 6),
and the verification rules (Task 7). The pickup-as-a-row decision from the spec
is implemented in Task 6 and tested there.

**Known deviations, stated rather than hidden.**

1. **`QuoteResult` is declared in `FreightQuote.php`** alongside
   `FreightQuote`, with a note that it must move to its own file if the
   autoloader objects. One file per class is the house convention, so this is
   the one place the plan departs from it, and Step 5 says what to do.
2. **Pallet dimensions are a fixed standard footprint**, not sender-supplied.
   The spec says pallet pricing is "per pallet, with a floor"; using a fixed
   footprint means a customer cannot understate the box. This is an addition to
   the spec and is called out in the commit message.
3. **`palletDimension()` takes a `ShipmentUnit` and ignores everything but
   `isPallet()`.** It is a seam for a future footprint-class scheme, but it is
   currently doing nothing more than returning a constant. If a later review
   finds it is not needed, delete it rather than keeping it as a hook.

**Type consistency.** `Postcode::normalise(): ?string` is defined once in Task 1
and called from Tasks 3 and 5. `BillableWeight::grams(): ?int` and
`cents(): int` are defined in Task 2 and called from Task 6.
`FreightRateResolver::resolve(): ResolvedRate` is defined in Task 5 and the only
caller in Task 6 checks `isAvailable()` before touching `->row`.
`QuoteUnavailableReason` is defined in Task 3 and every case in its `match` is
exercised by Task 5 or Task 6.
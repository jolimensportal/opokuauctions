<?php

namespace Tests\Feature;

use App\Models\Lot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The homepage category tiles.
 *
 * These were four links that all went to the same board, or to a different page
 * entirely, with copy claiming things the product does not do. A tile that links
 * somewhere empty is a dead end on the front page, and copy that lies is worse
 * than no tile at all.
 *
 * So each tile is tested for two things: the link is distinct from its
 * neighbours, and the board it opens actually has lots on it.
 */
class CategoryTileTest extends TestCase
{
    use RefreshDatabase;

    private function lot(string $kind, string $provenance, string $title): Lot
    {
        return Lot::create([
            'id' => 'lot_'.uniqid(),
            'kind' => $kind,
            'provenance' => $provenance,
            'title' => $title,
            'city' => 'Detroit',
            'condition' => 'clean_title',
            'currency_code' => 'usd',
            'starting_bid' => 100000,
            'reserve_price' => 150000,
            'bid_increment' => 5000,
            'current_bid' => 100000,
            'bid_count' => 0,
            'extensions' => 0,
            'photos' => [],
            'status' => 'LIVE',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'weight_kg' => 1500,
        ]);
    }

    /** Every category tile href on the homepage. */
    private function tileHrefs(): array
    {
        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all('#We buy more than cars.*?</section>#s', $html, $section);

        preg_match_all('~href="([^"]*/lots(?:\?[^"]*)?)"~', $section[0][0] ?? '', $matches);

        return $matches[1];
    }

    public function test_the_category_tiles_link_to_distinct_boards(): void
    {
        $this->lot('vehicle', 'salvage_auction', 'Toyota Camry');
        $this->lot('goods', 'garage_sale', 'Oak bed frame');

        $hrefs = $this->tileHrefs();

        $this->assertNotEmpty($hrefs, 'The homepage must still have category tiles.');

        // Every tile pointing at the same board is the bug: three of four did.
        $this->assertSame(
            count($hrefs),
            count(array_unique($hrefs)),
            'Two category tiles share a board, so one of them is a lie.',
        );
    }

    public function test_no_tile_points_at_a_page_other_than_the_lot_board(): void
    {
        $this->lot('vehicle', 'salvage_auction', 'Toyota Camry');

        $html = $this->get('/')->assertOk()->getContent();
        preg_match_all('#We buy more than cars.*?</section>#s', $html, $section);

        // "Garage lots" linked to how-it-works, which is not a category board.
        $this->assertStringNotContainsString(
            'how-it-works',
            $section[0][0] ?? '',
            'A category tile must open a board, not an article.',
        );
    }

    public function test_every_category_tile_opens_a_board_with_lots_on_it(): void
    {
        $this->lot('vehicle', 'salvage_auction', 'Toyota Camry');
        $this->lot('goods', 'garage_sale', 'Oak bed frame');

        foreach ($this->tileHrefs() as $href) {
            $content = $this->get($href)->assertOk()->getContent();

            // A tile leading to an empty board is a dead end on the front page.
            // The board has to show one of the two fixtures, whichever it is
            // supposed to be filtered to — not specifically the car.
            $this->assertTrue(
                str_contains($content, 'Camry') || str_contains($content, 'Oak bed frame'),
                "The board at {$href} has no lots, so its tile is a dead end.",
            );
        }
    }

    public function test_the_kind_filter_actually_narrows_the_board(): void
    {
        $this->lot('vehicle', 'salvage_auction', 'Toyota Camry');
        $this->lot('goods', 'garage_sale', 'Oak bed frame');

        $this->get('/lots?kind=goods')
            ->assertOk()
            ->assertSee('Oak bed frame')
            ->assertDontSee('Toyota Camry');
    }
}
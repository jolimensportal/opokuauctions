<?php

namespace Tests\Unit;

use App\Data\AttentionItem;
use App\Enums\AttentionKind;
use App\Enums\Severity;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The item the queue renders. It is immutable because every consumer — page,
 * badge, digest — must see exactly the same facts for the same exception, and
 * a mutable item lets one consumer's edit silently change another's view.
 */
class AttentionItemTest extends TestCase
{
    private function item(array $overrides = []): AttentionItem
    {
        return new AttentionItem(
            kind: $overrides['kind'] ?? AttentionKind::FailedMessage,
            severity: $overrides['severity'] ?? Severity::Medium,
            ref: $overrides['ref'] ?? 'MSG-0007',
            label: $overrides['label'] ?? 'A message failed to send',
            raisedAt: $overrides['raisedAt'] ?? CarbonImmutable::parse('2026-10-18T09:00:00Z'),
            ownerId: $overrides['ownerId'] ?? null,
            actionUrl: $overrides['actionUrl'] ?? '/admin/messages/7',
        );
    }

    public function test_it_exposes_its_facts(): void
    {
        $item = $this->item();

        $this->assertSame(AttentionKind::FailedMessage, $item->kind);
        $this->assertSame(Severity::Medium, $item->severity);
        $this->assertSame('MSG-0007', $item->ref);
        $this->assertNull($item->ownerId);
    }

    public function test_it_is_immutable(): void
    {
        $this->expectException(\Error::class);

        $item = $this->item();
        // @phpstan-ignore-next-line — deliberately illegal, proving readonly.
        $item->ref = 'MSG-9999';
    }

    public function test_an_unassigned_item_says_so(): void
    {
        // No staff available is a real state, not an error. The queue must be
        // able to show it rather than inventing a recipient.
        $this->assertTrue($this->item()->isUnassigned());
    }

    public function test_an_assigned_item_is_not_unassigned(): void
    {
        $this->assertFalse($this->item(['ownerId' => 'usr_kofi'])->isUnassigned());
    }

    public function test_age_is_measured_from_when_it_was_raised(): void
    {
        $item = $this->item([
            'raisedAt' => CarbonImmutable::parse('2026-10-11T09:00:00Z'),
        ]);

        // Seven days exactly, not "about a week" — the queue sorts on this.
        $this->assertSame(7, $item->ageInDays(CarbonImmutable::parse('2026-10-18T09:00:00Z')));
    }

    public function test_an_item_raised_today_is_zero_days_old(): void
    {
        $now = CarbonImmutable::parse('2026-10-18T23:00:00Z');

        $this->assertSame(0, $this->item([
            'raisedAt' => CarbonImmutable::parse('2026-10-18T01:00:00Z'),
        ])->ageInDays($now));
    }

    public function test_sorting_puts_critical_before_low(): void
    {
        $items = [
            $this->item(['severity' => Severity::Low, 'ref' => 'A']),
            $this->item(['severity' => Severity::Critical, 'ref' => 'B']),
            $this->item(['severity' => Severity::High, 'ref' => 'C']),
        ];

        usort($items, AttentionItem::compareForQueue(...));

        $this->assertSame(['B', 'C', 'A'], array_map(fn ($i) => $i->ref, $items));
    }

    public function test_within_one_severity_the_oldest_sorts_first(): void
    {
        // An unevidenced duty that has been open nine days matters more than one
        // opened this morning, even though both are Critical.
        $items = [
            $this->item(['severity' => Severity::High, 'ref' => 'NEW', 'raisedAt' => CarbonImmutable::parse('2026-10-18T08:00:00Z')]),
            $this->item(['severity' => Severity::High, 'ref' => 'OLD', 'raisedAt' => CarbonImmutable::parse('2026-10-10T08:00:00Z')]),
        ];

        usort($items, AttentionItem::compareForQueue(...));

        $this->assertSame(['OLD', 'NEW'], array_map(fn ($i) => $i->ref, $items));
    }
}
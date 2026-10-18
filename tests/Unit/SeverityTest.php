<?php

namespace Tests\Unit;

use App\Enums\AttentionKind;
use App\Enums\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Severity is derived from row facts, never typed by a human. These tests pin
 * the ordering the queue sorts on, because a queue sorted by the wrong order
 * shows a failed message above an unpaid duty and nobody reads past row two.
 */
class SeverityTest extends TestCase
{
    public function test_severity_sorts_most_urgent_first(): void
    {
        // Listed most urgent first, which is the order the queue sorts on.
        $ordered = [
            Severity::Critical,
            Severity::High,
            Severity::Medium,
            Severity::Low,
        ];

        for ($i = 0; $i < count($ordered) - 1; $i++) {
            $this->assertGreaterThan(
                $ordered[$i + 1]->weight(),
                $ordered[$i]->weight(),
                'Severity order is what the queue sorts on.',
            );
        }
    }

    public function test_critical_is_the_most_urgent(): void
    {
        $this->assertSame(4, Severity::Critical->weight());
        $this->assertSame(1, Severity::Low->weight());
    }

    public function test_every_attention_kind_declares_a_severity(): void
    {
        foreach (AttentionKind::cases() as $kind) {
            $this->assertInstanceOf(
                Severity::class,
                $kind->defaultSeverity(),
                "{$kind->value} must carry a severity.",
            );
        }
    }

    public function test_the_seven_specified_kinds_exist(): void
    {
        $values = array_column(AttentionKind::cases(), 'value');

        $this->assertSame([
            'unevidenced_money',
            'shipment_uncleared',
            'shipment_unallocated',
            'open_lot_exception',
            'failed_message',
            'lot_unweighed',
            'wallet_held',
        ], $values);
    }

    public function test_money_left_unaccounted_for_is_critical(): void
    {
        // The one kind where real money is unaccounted for is the only Critical.
        // Nothing else may claim that level, or the queue loses its meaning.
        $critical = array_filter(
            AttentionKind::cases(),
            fn (AttentionKind $k) => $k->defaultSeverity() === Severity::Critical,
        );

        $this->assertCount(1, $critical);
        $this->assertSame(AttentionKind::UnevidencedMoney, reset($critical));
    }

    public function test_a_kind_can_carry_a_contextual_severity(): void
    {
        // An unweighed lot is only Medium on its own, but Critical once it is
        // allocated to a sailing container that cannot be weighed without it.
        $this->assertSame(
            Severity::Medium,
            AttentionKind::LotUnweighed->defaultSeverity(),
        );
        $this->assertSame(
            Severity::Critical,
            AttentionKind::LotUnweighed->severityFor(['on_sailing_container' => true]),
        );
    }
}
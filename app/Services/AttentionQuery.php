<?php

namespace App\Services;

use App\Data\AttentionItem;
use App\Enums\AttentionKind;
use App\Models\ClearanceTask;
use App\Models\Lot;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use App\Models\ShipmentLotStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every open exception in the platform, derived on read.
 *
 * Read-only by design: nothing here writes a queue record, and no exception is
 * stored. An item exists while its condition holds and stops existing the moment
 * that condition is repaired, which is why the console has no dismiss button —
 * there is no row to mark done.
 *
 * The detectors deliberately mirror ClearanceService::unevidencedMoneyTasks(),
 * which already found unevidenced spends and had nowhere to surface them.
 */
class AttentionQuery
{
    public function __construct(private AttentionAssigner $assigner) {}

    /**
     * Every open exception, ordered for the queue.
     *
     * @return Collection<int, AttentionItem>
     */
    public function all(): Collection
    {
        return $this->allWithHealth()['items'];
    }

    /**
     * The items plus a report of any detector that failed.
     *
     * A detector that throws yields its items as absent AND its name in
     * `failed`. A queue that quietly loses its most expensive items is the worst
     * failure this system can have, so a short queue must always be explainable.
     *
     * @return array{items: Collection<int, AttentionItem>, failed: array<string, string>}
     */
    public function allWithHealth(): array
    {
        $detectors = [
            AttentionKind::UnevidencedMoney->value => fn () => $this->unevidencedMoneyTasks(),
            AttentionKind::ShipmentUncleared->value => fn () => $this->unclearedShipments(),
            AttentionKind::ShipmentUnallocated->value => fn () => $this->unallocatedShipments(),
            AttentionKind::OpenLotException->value => fn () => $this->openLotExceptions(),
            AttentionKind::FailedMessage->value => fn () => $this->failedMessages(),
            AttentionKind::LotUnweighed->value => fn () => $this->unweighedLots(),
            AttentionKind::WalletHeld->value => fn () => $this->heldWallets(),
        ];

        $items = collect();
        $failed = [];

        foreach ($detectors as $name => $detector) {
            try {
                $items = $items->concat($detector());
            } catch (Throwable $e) {
                // Report, never swallow. The console shows a banner instead of
                // pretending the queue is complete.
                report($e);

                $failed[$name] = $e->getMessage();
            }
        }

        $sorted = $items->sort(AttentionItem::compareForQueue(...))->values();

        return ['items' => $sorted, 'failed' => $failed];
    }

    /**
     * How many exceptions are open. Always equals count(all()).
     */
    public function count(): int
    {
        return $this->all()->count();
    }

    /**
     * Open counts grouped by severity, for the stat tiles.
     *
     * @return array<string, int>
     */
    public function countBySeverity(): array
    {
        $counts = [];

        foreach ($this->all() as $item) {
            $label = $item->severity->label();
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        return $counts;
    }

    // ------------------------------------------------------- detectors ----

    /**
     * A clearance task marked complete that costs money but carries no receipt.
     *
     * This is the sharpest exception in the set: the platform spent money and
     * cannot prove it. It mirrors ClearanceService::unevidencedMoneyTasks()
     * across every shipment rather than one.
     *
     * @return Collection<int, AttentionItem>
     */
    private function unevidencedMoneyTasks(): Collection
    {
        return ClearanceTask::query()
            ->whereIn('type', ClearanceService::moneyTypes())
            ->where('status', 'complete')
            ->where(fn ($query) => $query->whereNull('receipt_path')->orWhere('receipt_path', ''))
            ->get()
            ->map(fn (ClearanceTask $task) => $this->item(
                $kind = AttentionKind::UnevidencedMoney,
                $task->id,
                sprintf(
                    '%s was marked complete but has no receipt attached',
                    ClearanceService::labelFor($task->type),
                ),
                $this->asImmutable($task->completed_at ?? $task->created_at),
                // The ClearanceTasks resource exposes only a list page, so there
                // is no per-task edit route to send an operator to.
                route('filament.admin.resources.clearance-tasks.index'),
            ));
    }

    /**
     * A shipment with clearance tasks still outstanding.
     *
     * @return Collection<int, AttentionItem>
     */
    private function unclearedShipments(): Collection
    {
        return Shipment::query()
            ->whereIn('id', ClearanceTask::query()
                ->where('status', '!=', 'complete')
                ->distinct()
                ->pluck('shipment_id'))
            ->get()
            ->map(fn (Shipment $shipment) => $this->item(
                AttentionKind::ShipmentUncleared,
                $shipment->id,
                sprintf('%s still has clearance tasks outstanding', $this->shipmentLabel($shipment)),
                $this->asImmutable($shipment->created_at),
                // No Shipments resource exists in the panel yet, so the
                // clearance checklist is where an operator clears a container.
                route('filament.admin.resources.clearance-tasks.index'),
            ));
    }

    /**
     * A shipment carrying nothing, so freight cannot be divided.
     *
     * @return Collection<int, AttentionItem>
     */
    private function unallocatedShipments(): Collection
    {
        return Shipment::query()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('shipment_lots')
                ->whereColumn('shipment_lots.shipment_id', 'shipments.id'))
            ->get()
            ->map(fn (Shipment $shipment) => $this->item(
                AttentionKind::ShipmentUnallocated,
                $shipment->id,
                sprintf(
                    '%s has no lots allocated, so freight cannot be divided',
                    $this->shipmentLabel($shipment),
                ),
                $this->asImmutable($shipment->created_at),
                route('filament.admin.resources.clearance-tasks.index'),
            ));
    }

    /**
     * A damage, loss or delay claim that has not been resolved.
     *
     * @return Collection<int, AttentionItem>
     */
    private function openLotExceptions(): Collection
    {
        return ShipmentLotStatus::query()
            ->whereNull('resolved_at')
            ->with('shipmentLot.lot')
            ->get()
            ->filter(fn (ShipmentLotStatus $status) => $status->shipmentLot?->lot !== null)
            ->map(fn (ShipmentLotStatus $status) => $this->item(
                AttentionKind::OpenLotException,
                (string) $status->shipment_lot_id,
                sprintf(
                    '%s has an unresolved exception against it',
                    $status->shipmentLot->lot->title,
                ),
                $this->asImmutable($status->created_at),
                route('filament.admin.resources.lots.edit', ['record' => $status->shipmentLot->lot_id]),
            ));
    }

    /**
     * An outbound message that failed to send.
     *
     * @return Collection<int, AttentionItem>
     */
    private function failedMessages(): Collection
    {
        return DB::table('outbound_messages')
            ->where('status', 'failed')
            ->orderBy('created_at')
            ->get()
            ->map(fn (object $message) => $this->item(
                AttentionKind::FailedMessage,
                (string) $message->id,
                sprintf(
                    'A message about %s failed to send%s',
                    $message->about_type ?: 'something',
                    $message->last_error ? ': '.$message->last_error : '',
                ),
                $this->asImmutable($message->created_at),
                url('/admin/messages'),
            ));
    }

    /**
     * A lot nobody has weighed.
     *
     * Only UPCOMING and LIVE lots: a closed lot will never be freighted again,
     * so its weight blocks nothing. Reporting those would grow the queue
     * without bound as old inventory accumulated.
     *
     * @return Collection<int, AttentionItem>
     */
    private function unweighedLots(): Collection
    {
        // Which lots are aboard a container that has already sailed. Fetched
        // once as a set rather than per lot: the obvious version of this loops
        // over every unweighed lot issuing its own query, which is one query
        // per lot on a platform with hundreds of them.
        $sailingLotIds = ShipmentLot::query()
            ->whereIn('shipment_id', Shipment::query()->where('status', 'SAILING')->select('id'))
            ->pluck('lot_id')
            ->flip();

        return Lot::query()
            ->whereNull('weight_kg')
            ->whereIn('status', ['UPCOMING', 'LIVE'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (Lot $lot) => $this->item(
                AttentionKind::LotUnweighed,
                $lot->id,
                sprintf('%s has no recorded weight, so it cannot be freighted', $lot->title),
                $this->asImmutable($lot->created_at),
                route('filament.admin.resources.lots.edit', ['record' => $lot->id]),
                ['on_sailing_container' => $sailingLotIds->has($lot->id)],
            ));
    }

    /**
     * Funds sitting in hold rather than available.
     *
     * @return Collection<int, AttentionItem>
     */
    private function heldWallets(): Collection
    {
        // The holder's name is fetched in one query for all wallets rather than
        // per wallet, for the same reason as the sailing lot set.
        //
        // Cast to text on both sides: wallet.user_id is character varying
        // while users.id is bigint, and PostgreSQL refuses to compare the two
        // directly. Casting inside the query also avoids casting every value in
        // PHP.
        $names = User::query()
            ->whereIn(DB::raw('id::text'), DB::table('wallet')->select('user_id'))
            ->pluck('name', 'id');

        return DB::table('wallet')
            ->where('balance_held', '>', 0)
            ->orderByDesc('balance_held')
            ->get()
            ->map(fn (object $wallet) => $this->item(
                AttentionKind::WalletHeld,
                (string) $wallet->id,
                sprintf(
                    '%s has %s held rather than available',
                    $names[$wallet->user_id] ?? 'A customer',
                    $this->money((int) $wallet->balance_held),
                ),
                $this->asImmutable($wallet->created_at),
                url('/admin/wallets'),
            ));
    }

    // -------------------------------------------------------- helpers ----

    /**
     * Build one item, assigning an owner on first sight.
     *
     * @param  array<string, mixed>  $context
     */
    private function item(
        AttentionKind $kind,
        string $ref,
        string $label,
        CarbonImmutable $raisedAt,
        string $actionUrl,
        array $context = [],
    ): AttentionItem {
        return new AttentionItem(
            kind: $kind,
            severity: $kind->severityFor($context),
            ref: $ref,
            label: $label,
            raisedAt: $raisedAt,
            ownerId: $this->assigner->assign($kind, $ref),
            actionUrl: $actionUrl,
        );
    }

    private function shipmentLabel(Shipment $shipment): string
    {
        return $shipment->container_no ?: $shipment->id;
    }

    /**
     * Timestamps arrive as strings from raw queries and as Carbon from models.
     * Everything the queue renders is immutable, so normalise here once rather
     * than casting at each call site.
     */
    private function asImmutable(mixed $value): CarbonImmutable
    {
        return $value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value ?? 'now');
    }

    private function money(int $cents): string
    {
        return 'USD '.number_format($cents / 100, 2);
    }
}
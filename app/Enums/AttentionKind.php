<?php

namespace App\Enums;

/**
 * The kinds of exception the operations queue can surface.
 *
 * Every one of these is a query, not a record. Nothing here is stored: an
 * exception exists while its condition holds and stops existing the moment the
 * condition is repaired. That is why the queue has no dismiss button — there is
 * no row to mark as done, and adding one would teach operators to dismiss
 * rather than fix.
 */
enum AttentionKind: string
{
    /** A clearance task marked complete that costs money but has no receipt. */
    case UnevidencedMoney = 'unevidenced_money';

    /** A shipment with clearance tasks still outstanding. */
    case ShipmentUncleared = 'shipment_uncleared';

    /** A shipment with no lots allocated, so freight cannot be divided. */
    case ShipmentUnallocated = 'shipment_unallocated';

    /** An unresolved damage, loss or delay claim on a specific lot. */
    case OpenLotException = 'open_lot_exception';

    /** An outbound message that failed to send. */
    case FailedMessage = 'failed_message';

    /** A lot nobody has weighed, so it cannot be freighted. */
    case LotUnweighed = 'lot_unweighed';

    /** Funds sitting in hold rather than available. */
    case WalletHeld = 'wallet_held';

    /**
     * The severity this kind carries on its own.
     *
     * Only unevidenced money is Critical by default: it is the single condition
     * where real money is unaccounted for.
     */
    public function defaultSeverity(): Severity
    {
        return match ($this) {
            self::UnevidencedMoney => Severity::Critical,
            self::ShipmentUncleared,
            self::ShipmentUnallocated,
            self::OpenLotException => Severity::High,
            self::FailedMessage,
            self::LotUnweighed => Severity::Medium,
            self::WalletHeld => Severity::Low,
        };
    }

    /**
     * Severity given the facts of one specific instance.
     *
     * Some kinds are worse in context than they are in general. An unweighed
     * lot on the shelf is a Medium nobody is blocked by; the same lot aboard a
     * container that is sailing without it is Critical.
     *
     * @param  array<string, mixed>  $context
     */
    public function severityFor(array $context): Severity
    {
        if ($this === self::LotUnweighed && ($context['on_sailing_container'] ?? false) === true) {
            return Severity::Critical;
        }

        return $this->defaultSeverity();
    }

    public function label(): string
    {
        return match ($this) {
            self::UnevidencedMoney => 'Unevidenced money',
            self::ShipmentUncleared => 'Shipment not cleared',
            self::ShipmentUnallocated => 'Shipment unallocated',
            self::OpenLotException => 'Open lot exception',
            self::FailedMessage => 'Failed message',
            self::LotUnweighed => 'Lot not weighed',
            self::WalletHeld => 'Funds held',
        };
    }

    /**
     * Sentence shown in the queue row.
     *
     * @param  array<string, mixed>  $context
     */
    public function describe(array $context = []): string
    {
        $subject = $context['subject'] ?? $this->label();

        return match ($this) {
            self::UnevidencedMoney => sprintf(
                '%s was marked complete but has no receipt attached',
                $subject,
            ),
            self::ShipmentUncleared => sprintf(
                '%s still has clearance tasks outstanding',
                $subject,
            ),
            self::ShipmentUnallocated => sprintf(
                '%s has no lots allocated, so freight cannot be divided',
                $subject,
            ),
            self::OpenLotException => sprintf(
                '%s has an unresolved exception against it',
                $subject,
            ),
            self::FailedMessage => sprintf(
                'A message about %s failed to send',
                $subject,
            ),
            self::LotUnweighed => sprintf(
                '%s has no recorded weight, so it cannot be freighted',
                $subject,
            ),
            self::WalletHeld => sprintf(
                '%s has funds held rather than available',
                $subject,
            ),
        };
    }
}
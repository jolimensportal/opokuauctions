<?php

namespace App\Notifications;

use App\Jobs\SendWhatsAppMessage;
use App\Models\OutboundMessage;
use App\Models\Shipment;
use App\Models\ShipmentMilestone;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Turns shipment events into WhatsApp messages.
 *
 * The corridor already runs on WhatsApp; email is how you get ignored. So this
 * is the channel buyers actually read, and getting it wrong is a support
 * incident rather than a cosmetic bug.
 *
 * Every message is written to `outbound_messages` BEFORE it is queued. That is
 * what makes the send path auditable, retryable, and provably non-blocking.
 */
class CorridorNotifier
{
    /**
     * Milestones worth interrupting a buyer for.
     *
     * Not every milestone earns a message. A buyer does not need to hear that
     * a container was discharged internally; they need to know when it is
     * theirs.
     */
    public const NOTIFY_ON = [
        'BOOKED' => 'shipment_booked',
        'SAILED' => 'shipment_sailed',
        'CLEARED' => 'shipment_cleared',
        'DELIVERED' => 'shipment_delivered',
    ];

    public function __construct(private readonly bool $dispatch = true)
    {
    }

    /**
     * Notify every buyer with a lot aboard that the milestone happened.
     *
     * @return int number of messages queued
     */
    public function notifyShipmentMilestone(ShipmentMilestone $milestone): int
    {
        $template = self::NOTIFY_ON[$milestone->type] ?? null;

        if ($template === null) {
            return 0;
        }

        $shipment = Shipment::with('lots.lot')->find($milestone->shipment_id);
        if (! $shipment) {
            return 0;
        }

        $queued = 0;

        foreach ($shipment->lots as $shipmentLot) {
            $buyerId = $shipmentLot->lot->highest_bidder_id;

            if (! $buyerId) {
                continue;
            }

            $buyer = $this->findBuyer($buyerId);

            // A buyer without a phone number cannot be reached. That is a data
            // problem to fix at signup, not a reason to block a shipment.
            if (! $buyer || ! $buyer->phone) {
                continue;
            }

            if ($this->alreadyQueuedFor($shipment->id, $milestone->type, $buyer->id)) {
                continue;
            }

            $this->queue(
                $buyer,
                $this->body($shipment, $milestone->type),
                $template,
                'shipment',
                $shipment->id,
            );

            $queued++;
        }

        return $queued;
    }

    /**
     * Tell a buyer their money is being held pending a dispute decision.
     * Only an Owner may later release it.
     */
    public function notifyDispute(Shipment $shipment, User $buyer, string $reason): void
    {
        if (! $buyer->phone) {
            return;
        }

        $body = 'Opoku Auctions: a dispute has been opened on your shipment '
            .'('.$shipment->container_no.'). Your funds are held, not lost. '
            .'We will contact you about it. Reason: '.$reason;

        $this->queue(
            $buyer,
            $body,
            'shipment_disputed',
            'shipment',
            $shipment->id,
        );
    }

    /**
     * Persist a message, then queue it.
     *
     * The row is written first so it exists for a worker to pick up, so a
     * failure is visible to an operator, and so the buyer can always be shown
     * exactly what they were told and when.
     */
    private function queue(
        User $buyer,
        string $body,
        string $template,
        string $aboutType,
        string $aboutId
    ): OutboundMessage {
        $message = OutboundMessage::create([
            'id' => 'msg_'.Str::random(20),
            'user_id' => $buyer->id,
            'phone' => $buyer->phone,
            'channel' => 'whatsapp',
            'template' => $template,
            // Every message here carries a live amount or date, so it can only
            // go out inside the 24h service window as a session message.
            // Initiation and re-engagement need pre-approved templates.
            'message_kind' => 'session',
            'body' => $body,
            'about_type' => $aboutType,
            'about_id' => $aboutId,
            'status' => 'queued',
        ]);

        if ($this->dispatch) {
            // afterCommit: the row must exist before a worker can pick it up,
            // and the milestone's transaction must not be held open by a queue
            // dispatch.
            SendWhatsAppMessage::dispatch($message->id)->afterCommit();
        }

        return $message;
    }

    /**
     * Look up a buyer, or return null.
     *
     * The guard is deliberately BEFORE the query, not a try/catch around it.
     * `users.id` is a bigint, so `User::find('buyer-1')` hands a non-numeric
     * id straight to Postgres and fails with "invalid input syntax". Catching
     * that exception is NOT a recovery: the surrounding transaction is already
     * aborted and every later query in it fails with SQLSTATE 25P02. So an
     * invalid reference is rejected without ever issuing a query.
     */
    private function findBuyer(string|int|null $buyerId): ?User
    {
        if ($buyerId === null || $buyerId === '' || ! is_numeric($buyerId)) {
            return null;
        }

        return User::find((int) $buyerId);
    }

    /** Idempotency: never send the same buyer the same milestone twice. */
    private function alreadyQueuedFor(string $shipmentId, string $type, string $userId): bool
    {
        $templates = array_values(self::NOTIFY_ON);

        return OutboundMessage::where('about_type', 'shipment')
            ->where('about_id', $shipmentId)
            ->where('user_id', $userId)
            ->whereIn('template', $templates)
            ->where('body', 'like', '%'.$type.'%')
            ->exists();
    }

    private function body(Shipment $shipment, string $type): string
    {
        $reference = $shipment->container_no ?? $shipment->id;
        $eta = $shipment->eta ? $shipment->eta->format('j M Y') : 'to be confirmed';

        switch ($type) {
            case 'BOOKED':
                return 'Opoku Auctions: your vehicle is booked on container '.$reference.'. '
                    .'Expected arrival at Tema: '.$eta.'. You will hear again when it sails.';

            case 'SAILED':
                return 'Opoku Auctions: container '.$reference.' has sailed. '
                    .'Expected arrival at Tema: '.$eta.'.';

            case 'CLEARED':
                return 'Opoku Auctions: your vehicle on container '.$reference
                    .' has CLEARED customs at Tema. It is ready for collection. '
                    .'We will contact you about gate-out.';

            case 'DELIVERED':
                return 'Opoku Auctions: container '.$reference.' has been delivered. '
                    .'Thank you for buying with us.';

            default:
                return 'Opoku Auctions: an update on your container '.$reference.'.';
        }
    }
}
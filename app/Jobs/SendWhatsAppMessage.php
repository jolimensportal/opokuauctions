<?php

namespace App\Jobs;

use App\Models\OutboundMessage;
use App\Notifications\WhatsApp\WhatsAppGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Send one queued WhatsApp message.
 *
 * Runs on the queue, never inline: recording a milestone must not wait on a
 * third-party network call, and a provider outage must never roll back a
 * shipment that has actually moved.
 *
 * Every failure is caught and recorded on the row. The only case that rethrows
 * is an exhausted retry budget, and even then the message row survives so an
 * operator can see exactly what failed and why.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Bounded so a permanently failing message cannot retry forever. */
    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly string $messageId)
    {
    }

    public function handle(WhatsAppGateway $gateway): void
    {
        $message = OutboundMessage::find($this->messageId);

        if (! $message || $message->isTerminal()) {
            return;
        }

        $message->update([
            'status' => 'sending',
            'attempts' => $message->attempts + 1,
        ]);

        try {
            $providerId = $gateway->send(
                $message->phone,
                $message->body,
                $message->message_kind,
                $message->template,
            );

            $message->update([
                'status' => 'sent',
                'provider_message_id' => $providerId,
                'sent_at' => now(),
                'last_error' => null,
            ]);
        } catch (\Throwable $e) {
            $message->update([
                'status' => 'failed',
                'failed_at' => now(),
                'last_error' => $e->getMessage(),
            ]);

            Log::warning('WhatsApp send failed', [
                'message_id' => $message->id,
                'attempt' => $message->attempts,
                'error' => $e->getMessage(),
            ]);

            // Swallowed on purpose. The shipment moved regardless, and failing
            // the job would only make the queue retry a message whose failure
            // is already recorded and visible to an operator.
        }
    }

    /**
     * Called by the queue when retries are exhausted.
     */
    public function failed(\Throwable $exception): void
    {
        OutboundMessage::where('id', $this->messageId)->update([
            'status' => 'failed',
            'failed_at' => now(),
            'last_error' => $exception->getMessage(),
        ]);
    }
}
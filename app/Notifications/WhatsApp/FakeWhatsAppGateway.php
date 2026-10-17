<?php

namespace App\Notifications\WhatsApp;

use Illuminate\Support\Facades\Log;

/**
 * Records instead of sending. Used in tests and in local development, so the
 * send path is exercised without a provider account.
 */
class FakeWhatsAppGateway implements WhatsAppGateway
{
    /** @var array<int, array{to: string, body: string, kind: string, template: ?string}> */
    public array $sent = [];

    public bool $shouldFail = false;

    public function send(string $to, string $body, string $kind, ?string $template): string
    {
        if ($this->shouldFail) {
            throw new WhatsAppException('Simulated provider failure');
        }

        $this->sent[] = compact('to', 'body', 'kind', 'template');

        return 'SM'.fake()->uuid();
    }
}

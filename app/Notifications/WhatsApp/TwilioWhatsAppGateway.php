<?php

namespace App\Notifications\WhatsApp;

use Twilio\Rest\Api\V2010\Account\MessageInstance;

/**
 * Twilio WhatsApp transport.
 *
 * Chosen over Meta's Cloud API because Cloud requires a verified business and
 * a pre-approved template per message type — a multi-day process that should
 * not block a launch. Twilio also serves Ghanaian numbers.
 */
class TwilioWhatsAppGateway implements WhatsAppGateway
{
    public function __construct(
        private readonly string $sid,
        private readonly string $token,
        private readonly string $from,
    ) {
    }

    public function send(string $to, string $body, string $kind, ?string $template): string
    {
        if ($this->sid === '' || $this->token === '' || $this->from === '') {
            throw new WhatsAppException('Twilio credentials are not configured');
        }

        $client = new \Twilio\Rest\Client($this->sid, $this->token);

        $params = [
            'from' => $this->from,
            'to' => $this->toWhatsAppNumber($to),
            'body' => $body,
        ];

        // Outside the 24h service window WhatsApp requires an approved
        // template. Message bodies carry live amounts and dates, so they can
        // only go out as session messages; initiation and re-engagement need
        // templates approved in advance.
        if ($kind === 'template') {
            if (! $template) {
                throw new WhatsAppException('A template name is required for a template message');
            }
            $params['contentSid'] = $template;
            unset($params['body']);
        }

        try {
            /** @var MessageInstance $message */
            $message = $client->messages->create($params);

            return $message->sid;
        } catch (\Throwable $e) {
            throw new WhatsAppException($e->getMessage(), 0, $e);
        }
    }

    private function toWhatsAppNumber(string $number): string
    {
        return str_starts_with($number, 'whatsapp:') ? $number : 'whatsapp:'.$number;
    }
}

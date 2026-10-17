<?php

namespace App\Notifications\WhatsApp;

/**
 * The WhatsApp transport.
 *
 * An interface rather than a direct Twilio call so the send path can be tested
 * without a network or an account, and so a provider swap later does not touch
 * any calling code.
 *
 * Deliberately narrow: send one message, return the provider id or throw.
 */
interface WhatsAppGateway
{
    /**
     * @throws \App\Notifications\WhatsApp\WhatsAppException on provider failure
     */
    public function send(string $to, string $body, string $kind, ?string $template): string;
}
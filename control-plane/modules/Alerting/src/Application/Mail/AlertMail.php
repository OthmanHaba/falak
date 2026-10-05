<?php

namespace Falak\Alerting\Application\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Falak\Alerting\Application\AlertMessage;

final class AlertMail extends Mailable
{
    public function __construct(public readonly AlertMessage $alert) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->alert->headline());
    }

    public function content(): Content
    {
        return new Content(markdown: 'alerting::mail.alert', with: ['alert' => $this->alert]);
    }
}

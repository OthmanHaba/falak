<?php

namespace Falak\Apm\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Falak\Apm\Recorder;
use Falak\Apm\Span;
use Symfony\Component\Mime\Email;
use Throwable;

final class MailWatcher
{
    /** @var array<int, int> message object id => start ns */
    private array $starts = [];

    public function __construct(private Recorder $recorder)
    {
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(MessageSending::class, function (MessageSending $event) {
            if ($this->recorder->recording('mail') && count($this->starts) < 100) {
                $this->starts[spl_object_id($event->message)] = $this->recorder->now();
            }
        });

        $events->listen(MessageSent::class, function (MessageSent $event) {
            if (! $this->recorder->recording('mail')) {
                return;
            }

            try {
                $message = $event->sent->getOriginalMessage();
            } catch (Throwable) {
                $message = null;
            }

            $end = $this->recorder->now();
            $id = $message !== null ? spl_object_id($message) : 0;
            $start = $this->starts[$id] ?? $end;
            unset($this->starts[$id]);

            $recipients = $message instanceof Email
                ? count($message->getTo()) + count($message->getCc()) + count($message->getBcc())
                : 0;

            $class = $event->data['__laravel_mailable'] ?? $event->data['__laravel_notification'] ?? 'mail';

            $this->recorder->record('mail', $class, Span::KIND_INTERNAL, $start, $end, [
                'falak.mail.class' => $class,
                'falak.mail.recipients_count' => $recipients,
                'falak.mail.mailer' => (string) ($event->data['mailer'] ?? ''),
            ]);
        });
    }
}

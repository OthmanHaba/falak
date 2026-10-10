<?php

namespace Falak\Alerting\Application;

use DateTimeImmutable;
use Falak\Alerting\Contracts\Severity;
use Falak\Alerting\Domain\Models\Alert;

/**
 * What a channel sender renders: an alert (or a test message).
 */
final readonly class AlertMessage
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $type,
        public Severity $severity,
        public string $title,
        public string $body,
        public ?string $url,
        public array $context,
        public bool $resolved,
        public DateTimeImmutable $createdAt,
        public bool $test = false,
        // Label of the suggested fix at $url ("Grow volume"), shown instead of the generic "Open in Falak".
        public ?string $action = null,
    ) {}

    public static function fromAlert(Alert $alert): self
    {
        return new self(
            $alert->id,
            $alert->organization_id,
            $alert->type,
            $alert->severity,
            $alert->title,
            (string) $alert->body,
            $alert->url,
            $alert->context ?? [],
            $alert->recovery,
            $alert->created_at->toDateTimeImmutable(),
            action: $alert->recovery ? null : $alert->action,
        );
    }

    public static function test(string $organizationId, string $channelName): self
    {
        return new self(
            'test',
            $organizationId,
            'alerting.test',
            Severity::Info,
            'Falak test alert',
            "This is a test message for the \"{$channelName}\" channel. If you can read it, the channel works.",
            url('/settings/alert-channels'),
            [],
            false,
            new DateTimeImmutable,
            test: true,
        );
    }

    /** Title prefixed for chat channels, e.g. "[CRITICAL] Agent offline" / "[RESOLVED] …". */
    public function headline(): string
    {
        $tag = $this->resolved ? 'RESOLVED' : strtoupper($this->severity->value);

        return "[{$tag}] {$this->title}";
    }

    /** What the link to $url says: the suggested fix, else "Open in Falak". */
    public function linkLabel(): string
    {
        return $this->action ?? 'Open in Falak';
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'action' => $this->action,
            'organization_id' => $this->organizationId,
            'context' => (object) $this->context,
            'resolved' => $this->resolved,
            'test' => $this->test,
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}

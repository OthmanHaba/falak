<?php

namespace Falak\Edge\Contracts\Data;

use DateTimeImmutable;
use Falak\Edge\Application\DnsInstructions;
use Falak\Edge\Contracts\DnsStatus;

final readonly class DnsCheckResult
{
    /**
     * @param  list<string>  $addresses  A and AAAA answers
     * @param  list<string>  $cnames  CNAME chain, in order
     * @param  list<DnsTarget>  $targets  where it should point
     * @param  list<DnsTarget>  $matched  targets it points at
     * @param  array<string, mixed>  $instructions  {@see DnsInstructions}
     * @param  ?array{status: 'issued'|'pending', message: string, issuer: ?string, expires_at: ?string}  $certificate
     */
    public function __construct(
        public string $name,
        public DnsStatus $status,
        public string $message,
        public array $addresses,
        public array $cnames,
        public array $targets,
        public array $matched,
        public array $instructions,
        public ?array $certificate,
        public DateTimeImmutable $checkedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status->value,
            'message' => $this->message,
            'addresses' => $this->addresses,
            'cnames' => $this->cnames,
            'targets' => array_map(fn (DnsTarget $target) => $target->toArray(), $this->targets),
            'matched' => array_map(fn (DnsTarget $target) => $target->toArray(), $this->matched),
            'instructions' => $this->instructions,
            'certificate' => $this->certificate,
            'checked_at' => $this->checkedAt->format(DATE_ATOM),
        ];
    }
}

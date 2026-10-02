<?php

namespace Kiln\Servers\Domain\MachineCheck;

/**
 * The decision for one component of the machine, with what was found and why.
 */
final readonly class ComponentDecision
{
    /**
     * @param  list<array{name: string, version: ?string, source: ?string}>  $found  what the machine has
     * @param  list<string>  $install  apt packages provisioning installs for it (complete: only the missing ones)
     * @param  list<string>  $keep  installed packages it is made of (adopt: verified, never installed)
     * @param  list<Note>  $notes  blocks first; a block note's hint is the fix
     */
    public function __construct(
        public string $component,
        public string $label,
        public Decision $decision,
        public string $reason,
        public array $found = [],
        public array $install = [],
        public array $keep = [],
        public ?string $service = null,
        public array $notes = [],
    ) {}

    public function severity(): Severity
    {
        if ($this->decision === Decision::Block) {
            return Severity::Block;
        }

        return Severity::max(...array_map(fn (Note $note) => $note->severity, $this->notes));
    }

    /**
     * The fix for the first block (or warning) note.
     */
    public function hint(): ?string
    {
        foreach ([Severity::Block, Severity::Warning] as $severity) {
            foreach ($this->notes as $note) {
                if ($note->severity === $severity && $note->hint !== null) {
                    return $note->hint;
                }
            }
        }

        return null;
    }

    /**
     * @return list<Note>
     */
    public function blocks(): array
    {
        return array_values(array_filter($this->notes, fn (Note $note) => $note->severity === Severity::Block));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'label' => $this->label,
            'decision' => $this->decision->value,
            'decision_label' => $this->decision->label(),
            'severity' => $this->severity()->value,
            'reason' => $this->reason,
            'hint' => $this->hint(),
            'found' => $this->found,
            'install' => $this->install,
            'keep' => $this->keep,
            'service' => $this->service,
            'notes' => array_map(fn (Note $note) => $note->toArray(), $this->notes),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            component: (string) ($data['component'] ?? ''),
            label: (string) ($data['label'] ?? ''),
            decision: Decision::tryFrom((string) ($data['decision'] ?? '')) ?? Decision::Install,
            reason: (string) ($data['reason'] ?? ''),
            found: array_values((array) ($data['found'] ?? [])),
            install: array_values(array_map('strval', (array) ($data['install'] ?? []))),
            keep: array_values(array_map('strval', (array) ($data['keep'] ?? []))),
            service: isset($data['service']) ? (string) $data['service'] : null,
            notes: array_map(fn ($note) => Note::fromArray((array) $note), array_values((array) ($data['notes'] ?? []))),
        );
    }
}

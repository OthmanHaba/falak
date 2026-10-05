<?php

namespace Falak\Servers\Domain\MachineCheck;

/**
 * All component decisions of one machine check, blocks first.
 */
final readonly class MachineCheck
{
    /** @var list<ComponentDecision> */
    public array $components;

    /**
     * @param  list<ComponentDecision>  $components
     */
    public function __construct(array $components)
    {
        $order = array_flip(DecisionEngine::COMPONENTS);
        usort($components, fn (ComponentDecision $a, ComponentDecision $b) => [$b->severity()->rank(), $order[$a->component] ?? 99]
            <=> [$a->severity()->rank(), $order[$b->component] ?? 99]);

        $this->components = $components;
    }

    public function for(string $component): ?ComponentDecision
    {
        foreach ($this->components as $decision) {
            if ($decision->component === $component) {
                return $decision;
            }
        }

        return null;
    }

    public function blocking(): bool
    {
        return $this->blocked() !== [];
    }

    /**
     * @return list<ComponentDecision>
     */
    public function blocked(): array
    {
        return array_values(array_filter($this->components, fn (ComponentDecision $d) => $d->decision === Decision::Block));
    }

    /**
     * One line per block for the server's status message.
     */
    public function summary(): string
    {
        $messages = [];

        foreach ($this->blocked() as $decision) {
            foreach ($decision->blocks() as $note) {
                $messages[] = rtrim($note->message, '.').'.';
            }
        }

        if ($messages === []) {
            return 'The machine check found nothing that blocks provisioning.';
        }

        $count = count($messages);

        return "Machine check: {$count} ".($count === 1 ? 'conflict' : 'conflicts').' to fix before provisioning. '.implode(' ', $messages);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (ComponentDecision $d) => $d->toArray(), $this->components);
    }

    /**
     * @param  list<array<string, mixed>>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(fn ($d) => ComponentDecision::fromArray((array) $d), array_values($data)));
    }
}

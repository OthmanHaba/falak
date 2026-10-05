<?php

namespace Falak\Functions\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Falak\Functions\Application\Code;
use LogicException;

/**
 * One deployed state of a function's code. Never changes once written.
 *
 * @property string $id
 * @property string $function_id
 * @property int $number
 * @property array<string, string> $files path => content
 * @property string $entrypoint
 * @property string $hash
 * @property int $size
 * @property ?string $message
 * @property ?string $author_id
 * @property ?string $author_name
 * @property ?string $base_version_id
 * @property Carbon $created_at
 */
class FunctionVersion extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'functions_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['files' => 'array', 'number' => 'integer', 'size' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Function versions are immutable.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'hash' => $this->hash,
            'short_hash' => substr($this->hash, 0, 7),
            'message' => $this->message,
            'author' => $this->author_name,
            'size' => $this->size,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * The summary with the files, and what changed per file since the previous version (all "added" for v1).
     *
     * @return array<string, mixed>
     */
    public function detail(): array
    {
        $previous = self::query()->where('function_id', $this->function_id)->where('number', '<', $this->number)->orderByDesc('number')->value('files');

        return [
            ...$this->summary(),
            'entrypoint' => $this->entrypoint,
            'files' => $this->files,
            'changes' => Code::changes(is_string($previous) ? (array) json_decode($previous, true) : (array) $previous, $this->files),
        ];
    }
}

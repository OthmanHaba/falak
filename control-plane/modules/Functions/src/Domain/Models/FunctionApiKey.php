<?php

namespace Falak\Functions\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An API key of a function: only its sha256 is stored; callers send it as `Authorization: Bearer <key>` or
 * `X-Falak-Key: <key>`.
 *
 * @property string $id
 * @property string $function_id
 * @property string $name
 * @property string $hash
 * @property string $prefix
 * @property ?string $created_by
 * @property Carbon $created_at
 */
class FunctionApiKey extends Model
{
    use HasUlids;

    protected $table = 'functions_api_keys';

    protected $guarded = [];

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'prefix' => $this->prefix, 'created_at' => $this->created_at->toIso8601String()];
    }
}

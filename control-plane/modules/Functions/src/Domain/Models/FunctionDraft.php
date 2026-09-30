<?php

namespace Kiln\Functions\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A user's unsaved editor work on a function (autosaved; removed when they deploy).
 *
 * @property string $id
 * @property string $function_id
 * @property string $user_id
 * @property array<string, string> $files
 * @property ?string $base_version_id
 * @property Carbon $updated_at
 */
class FunctionDraft extends Model
{
    use HasUlids;

    protected $table = 'functions_drafts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['files' => 'array'];
    }
}

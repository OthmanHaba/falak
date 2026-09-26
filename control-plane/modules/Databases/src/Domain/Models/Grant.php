<?php

namespace Kiln\Databases\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $user_id
 * @property string $database_id
 * @property list<string> $privileges
 * @property-read Database $database
 * @property-read DatabaseUser $user
 */
class Grant extends Model
{
    use HasUlids;

    protected $table = 'databases_grants';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['privileges' => 'array'];
    }

    /**
     * @return BelongsTo<Database, $this>
     */
    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    /**
     * @return BelongsTo<DatabaseUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(DatabaseUser::class, 'user_id');
    }
}

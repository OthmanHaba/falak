<?php

namespace Kiln\Builds\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $build_id
 * @property int $seq
 * @property string $stream
 * @property string $data
 * @property Carbon $at
 */
class BuildLog extends Model
{
    public $timestamps = false;

    protected $table = 'builds_logs';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['seq' => 'integer', 'at' => 'datetime'];
    }

    /**
     * @return array{seq: int, stream: string, data: string, at: string}
     */
    public function toLine(): array
    {
        return ['seq' => $this->id, 'stream' => $this->stream, 'data' => $this->data, 'at' => $this->at->toIso8601ZuluString('millisecond')];
    }
}

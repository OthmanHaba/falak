<?php

namespace Kiln\Deployments\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id the output cursor (`seq`)
 * @property string $deployment_id
 * @property ?string $step_id
 * @property ?string $server_id
 * @property ?string $server_name
 * @property ?string $phase
 * @property string $stream
 * @property string $data
 * @property ?int $source_seq
 * @property Carbon $at
 */
class OutputLine extends Model
{
    public $timestamps = false;

    protected $table = 'deployments_output';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['at' => 'datetime', 'source_seq' => 'integer'];
    }

    /**
     * Shape of `api.OutputLine` in the Go CLI.
     *
     * @return array{seq: int, at: string, server: ?string, server_id: ?string, step_id: ?string, phase: ?string, stream: string, data: string}
     */
    public function toLine(): array
    {
        return [
            'seq' => $this->id,
            'at' => $this->at->toIso8601ZuluString('millisecond'),
            'server' => $this->server_name,
            'server_id' => $this->server_id,
            'step_id' => $this->step_id,
            'phase' => $this->phase,
            'stream' => $this->stream,
            'data' => $this->data,
        ];
    }
}

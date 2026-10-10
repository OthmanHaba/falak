<?php

namespace Falak\Security\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One check of an audit (reported by the agent, or computed here: backups).
 *
 * @property string $id
 * @property string $audit_id
 * @property string $organization_id
 * @property string $server_id
 * @property string $check_id
 * @property string $title
 * @property string $area
 * @property string $status pass|warn|fail|info
 * @property string $severity critical|high|medium|low|info
 * @property string $evidence
 * @property ?string $fix_id
 * @property bool $disruptive
 */
class Finding extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'security_findings';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['disruptive' => 'boolean'];
    }

    public function failing(): bool
    {
        return in_array($this->status, ['fail', 'warn'], true);
    }
}

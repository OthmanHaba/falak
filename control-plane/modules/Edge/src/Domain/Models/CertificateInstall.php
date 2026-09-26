<?php

namespace Kiln\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kiln\Edge\Domain\Enums\InstallStatus;

/**
 * @property string $id
 * @property string $certificate_id
 * @property string $server_id
 * @property ?string $command_id
 * @property InstallStatus $status
 * @property ?string $error
 * @property ?Carbon $installed_at
 * @property Certificate $certificate
 */
class CertificateInstall extends Model
{
    use HasUlids;

    protected $table = 'edge_certificate_installs';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => InstallStatus::class, 'installed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Certificate, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }
}

<?php

namespace Falak\Servers\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Falak\Servers\Contracts\Data\PhpSettings;
use Falak\Servers\Domain\Enums\PhpVersionStatus;

/**
 * @property string $id
 * @property string $server_id
 * @property string $version
 * @property PhpVersionStatus $status
 * @property bool $is_default
 * @property array<string, string|int|bool> $ini
 * @property array{pm: string, max_children: int, start_servers: int, min_spare_servers: int, max_spare_servers: int, max_requests: int} $fpm
 * @property ?string $command_id
 * @property ?string $status_message
 */
class PhpVersion extends Model
{
    use HasUlids;

    public const DEFAULT_INI = [
        'memory_limit' => '512M',
        'upload_max_filesize' => '100M',
        'post_max_size' => '100M',
        'max_execution_time' => 60,
        'max_input_vars' => 5000,
        'date.timezone' => 'UTC',
        'opcache.enable' => true,
        'opcache.memory_consumption' => 256,
        'opcache.validate_timestamps' => false,
    ];

    protected $table = 'servers_php_versions';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PhpVersionStatus::class,
            'is_default' => 'boolean',
            'ini' => 'array',
            'fpm' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * FPM pool defaults sized to the machine (≈64 MB per worker, 60% of RAM).
     *
     * @return array{pm: string, max_children: int, start_servers: int, min_spare_servers: int, max_spare_servers: int, max_requests: int}
     */
    public static function defaultFpm(?int $memoryBytes): array
    {
        $children = $memoryBytes ? max(5, (int) floor($memoryBytes * 0.6 / (64 * 1024 ** 2))) : 5;

        return [
            'pm' => 'dynamic',
            'max_children' => $children,
            'start_servers' => max(2, intdiv($children, 4)),
            'min_spare_servers' => max(1, intdiv($children, 8)),
            'max_spare_servers' => max(3, intdiv($children, 3)),
            'max_requests' => 500,
        ];
    }

    public function toSettings(): PhpSettings
    {
        return new PhpSettings($this->version, $this->ini, $this->fpm, $this->is_default);
    }
}

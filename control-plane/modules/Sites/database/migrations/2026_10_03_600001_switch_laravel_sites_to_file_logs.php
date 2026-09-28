<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Kiln\Sites\Application\Actions\SaveEnvironment;
use Kiln\Sites\Domain\Models\EnvironmentVersion;
use Kiln\Sites\Domain\Models\Site;

/**
 * Laravel sites were created with LOG_CHANNEL=stderr. Under FrankenPHP / PHP-FPM the web requests' stderr goes to
 * the edge's (or FPM master's) journal shared by every site, so their logs never reached Loki. Sites still on that
 * value get LOG_CHANNEL=daily (storage/logs, tailed per site by the agent) as a new environment version; it takes
 * effect with the next deploy. Other values are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $save = app(SaveEnvironment::class);

        Site::query()->orderBy('created_at')->orderBy('id')->each(function (Site $site) use ($save) {
            if (! $site->framework->isLaravel() || ! $site->runtime->isPhp()) {
                return;
            }

            try {
                /** @var ?EnvironmentVersion $current */
                $current = EnvironmentVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first();
                $variables = $current?->variables ?? [];
            } catch (DecryptException) {
                // Encrypted with another APP_KEY (a restored or copied database): leave it to its owner.
                Log::warning('sites: could not read the environment to switch LOG_CHANNEL', ['site_id' => $site->id]);

                return;
            }

            if (($variables['LOG_CHANNEL'] ?? null) !== 'stderr') {
                return;
            }

            $variables['LOG_CHANNEL'] = 'daily';
            $save($site, $variables, $current->exposed ?? [], null, 'site.environment_log_channel_migrated');
        });
    }

    public function down(): void {}
};

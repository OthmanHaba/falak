<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Kiln\Alerting\Domain\Enums\ChannelType;
use Kiln\Alerting\Domain\Models\Channel;
use Kiln\Alerting\Domain\Models\Rule;
use Kiln\Builds\Application\Actions\CreateExternalBuilder;
use Kiln\Builds\Domain\Models\Builder;
use Kiln\Databases\Domain\Enums\StorageDriver;
use Kiln\Databases\Domain\Models\StorageProvider;
use Kiln\Providers\Contracts\ProviderType;
use Kiln\Providers\Domain\CredentialStatus;
use Kiln\Providers\Domain\Models\ProviderCredential;
use Kiln\Recipes\Application\Actions\SaveRecipe;
use Kiln\SourceControl\Contracts\ProviderType as GitProvider;
use Kiln\SourceControl\Domain\Models\Connection;
use Kiln\SourceControl\Domain\Models\Push;

/**
 * Organization settings demo data (called by UiDemoSeeder): git connections and pushes, cloud credentials, backup
 * buckets, builders, alert channels + rules and recipes, so every /settings section renders with content.
 * Credentials are fake; nothing here talks to a real provider. Never run in production.
 */
class SettingsDemoSeeder extends Seeder
{
    public function run(string $organizationId, string $userId): void
    {
        $github = $this->connection($organizationId, GitProvider::GitHub, 'Acme on GitHub', 'oauth', 'acme');
        $this->connection($organizationId, GitProvider::GitLab, 'GitLab (self-managed)', 'token', 'acme-platform', 'https://gitlab.acme.test');
        $this->connection($organizationId, GitProvider::Custom, 'Internal git', 'none', null, 'ssh://git@git.acme.test');

        foreach ([
            ['acme/storefront', 'main', 'Speed up checkout totals', 'Grace Hopper', 3],
            ['acme/storefront', 'feature/coupons', "Coupons: stackable discounts\n\nAlso fixes rounding", 'Alan Turing', 40],
            ['acme/marketing-site', 'main', 'New pricing page copy', 'Ada Lovelace', 180],
            ['acme/docs', 'main', 'Document the deploy hooks', 'Linus Torvalds', 60 * 26],
        ] as [$repository, $branch, $message, $author, $minutesAgo]) {
            Push::query()->create([
                'organization_id' => $organizationId,
                'connection_id' => $github->id,
                'repository' => $repository,
                'branch' => $branch,
                'sha' => bin2hex(random_bytes(20)),
                'message' => $message,
                'author_name' => $author,
                'pusher' => Str::slug($author),
                'url' => "https://github.com/{$repository}/commit/".bin2hex(random_bytes(20)),
                'received_at' => now()->subMinutes($minutesAgo),
            ]);
        }

        ProviderCredential::factory()->forOrganization($organizationId)->create(['name' => 'Hetzner production', 'provider' => ProviderType::Hetzner]);
        ProviderCredential::factory()->forOrganization($organizationId)->create([
            'name' => 'DigitalOcean staging',
            'provider' => ProviderType::DigitalOcean,
            'credentials' => ['token' => 'dop_v1_'.Str::random(40)],
            'last_verified_at' => now()->subDays(3),
        ]);
        ProviderCredential::factory()->forOrganization($organizationId)->create([
            'name' => 'Old Vultr account',
            'provider' => ProviderType::Vultr,
            'credentials' => ['api_key' => Str::random(36)],
            'status' => CredentialStatus::Invalid,
            'last_verified_at' => now()->subDays(40),
            'last_error' => 'Vultr: unable to authenticate (401 Unauthorized)',
        ]);

        foreach ([
            ['Offsite backups (R2)', StorageDriver::R2, 'https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com', 'auto', 'acme-db-backups', true, now()->subHours(5)],
            ['Archive (B2)', StorageDriver::B2, 'https://s3.eu-central-003.backblazeb2.com', 'eu-central-003', 'acme-archive', false, null],
        ] as [$name, $driver, $endpoint, $region, $bucket, $pathStyle, $verifiedAt]) {
            StorageProvider::query()->create([
                'organization_id' => $organizationId,
                'name' => $name,
                'driver' => $driver,
                'endpoint' => $endpoint,
                'region' => $region,
                'bucket' => $bucket,
                'prefix' => 'kiln',
                'path_style' => $pathStyle,
                'access_key_id' => 'AKIA'.Str::upper(Str::random(16)),
                'secret_access_key' => Str::random(40),
                'verified_at' => $verifiedAt,
            ]);
        }

        [$ci] = app(CreateExternalBuilder::class)($organizationId, 'ci-runner-1', ['native', 'docker'], $userId);
        Builder::query()->whereKey($ci->id)->update(['last_seen_at' => now()->subSeconds(20), 'reported_name' => 'gh-runner-7', 'last_ip' => '203.0.113.24']);
        app(CreateExternalBuilder::class)($organizationId, 'spare-mac-mini', ['native'], $userId);

        $slack = Channel::query()->create([
            'organization_id' => $organizationId,
            'type' => ChannelType::Slack,
            'name' => '#ops-alerts',
            'config' => ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/'.Str::random(24)],
            'enabled' => true,
            'last_sent_at' => now()->subMinutes(12),
        ]);
        $email = Channel::query()->create([
            'organization_id' => $organizationId,
            'type' => ChannelType::Email,
            'name' => 'On-call email',
            'config' => ['recipients' => ['oncall@acme.test', 'ops@acme.test']],
            'enabled' => true,
        ]);
        $webhook = Channel::query()->create([
            'organization_id' => $organizationId,
            'type' => ChannelType::Webhook,
            'name' => 'PagerDuty bridge',
            'config' => ['url' => 'https://events.acme.test/kiln', 'secret' => Str::random(32)],
            'enabled' => false,
            'last_error' => 'HTTP 502: bad gateway',
        ]);

        $everything = Rule::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Everything ≥ warning',
            'event_types' => ['*'],
            'min_severity' => 'warning',
            'enabled' => true,
            'rate_limit_per_hour' => 30,
        ]);
        $everything->channels()->sync([$slack->id]);

        $critical = Rule::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Critical: wake someone up',
            'event_types' => ['servers.*', 'deployments.failed'],
            'min_severity' => 'critical',
            'enabled' => true,
            'quiet_hours' => ['start' => '22:00', 'end' => '07:00', 'timezone' => 'Europe/Berlin', 'days' => [], 'allow_critical' => true],
        ]);
        $critical->channels()->sync([$email->id, $webhook->id]);

        $save = app(SaveRecipe::class);
        $save($organizationId, [
            'name' => 'Clear Laravel caches',
            'description' => 'optimize:clear on every site checkout',
            'script' => "#!/usr/bin/env bash\nset -euo pipefail\n\nfor site in /home/*/current; do\n    php \"\$site/artisan\" optimize:clear\ndone\n",
            'user' => 'root',
        ], null, $userId);
        $save($organizationId, [
            'name' => 'Disk usage report',
            'description' => null,
            'script' => "#!/usr/bin/env bash\ndf -h /\ndu -sh /var/log /home/* 2>/dev/null | sort -h | tail -n 10\n",
            'user' => 'root',
        ], null, $userId);
    }

    private function connection(string $organizationId, GitProvider $provider, string $name, string $authType, ?string $account, ?string $baseUrl = null): Connection
    {
        $connection = new Connection([
            'organization_id' => $organizationId,
            'provider' => $provider,
            'name' => $name,
            'auth_type' => $authType,
            'account' => $account,
            'base_url' => $baseUrl,
        ]);
        $connection->credentials = $authType === 'none' ? [] : ['token' => Str::random(40)];
        $connection->save();

        return $connection;
    }
}

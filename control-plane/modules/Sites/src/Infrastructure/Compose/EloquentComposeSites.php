<?php

namespace Kiln\Sites\Infrastructure\Compose;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Kiln\Sites\Application\Compose\ComposeNetworks;
use Kiln\Sites\Application\Compose\KilnAdjustments;
use Kiln\Sites\Contracts\ComposeServiceExtraction;
use Kiln\Sites\Contracts\ComposeSites;
use Kiln\Sites\Contracts\ComposeSource;
use Kiln\Sites\Contracts\Data\ComposeServiceState;
use Kiln\Sites\Contracts\Data\ComposeVersionData;
use Kiln\Sites\Contracts\Data\RenderedCompose;
use Kiln\Sites\Contracts\Exceptions\ComposeRenderException;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Domain\Models\ComposeState;
use Kiln\Sites\Domain\Models\ComposeVersion;
use Kiln\Sites\Domain\Models\OrganizationSettings;
use Kiln\Sites\Domain\Models\Site;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class EloquentComposeSites implements ComposeSites
{
    public function __construct(private readonly YamlComposeInspector $inspector) {}

    public function content(string $siteId, ?int $version = null): ?ComposeVersionData
    {
        $query = ComposeVersion::query()->where('site_id', strtolower($siteId));

        $row = $version !== null ? $query->where('version', $version)->first() : $query->orderByDesc('version')->first();

        return $row?->toData();
    }

    public function project(string $siteId): ?string
    {
        $site = Site::query()->find(strtolower($siteId), ['id', 'compose_source', 'compose_snapshot']);

        if ($site === null) {
            return null;
        }

        return $site->compose_source === ComposeSource::Inline ? $this->content($site->id)?->content : $site->compose_snapshot;
    }

    public function stacksUsing(string $siteId): array
    {
        $site = Site::query()->find(strtolower($siteId), ['id', 'organization_id']);

        if ($site === null) {
            return [];
        }

        $stacks = [];

        foreach (Site::query()->where('organization_id', $site->organization_id)->where('runtime', SiteRuntime::Compose->value)->whereNotNull('compose_services')->get() as $stack) {
            foreach ((array) $stack->compose_services as $service => $decision) {
                if (is_array($decision) && ($decision['mode'] ?? null) === 'site' && strtolower((string) ($decision['site_id'] ?? '')) === $site->id) {
                    $stacks[$stack->id] = (string) $service;
                }
            }
        }

        return $stacks;
    }

    public function stackNetworks(string $siteId, string $serverId): array
    {
        $site = Site::query()->find(strtolower($siteId), ['id', 'organization_id']);

        if ($site === null) {
            return [];
        }

        $stacks = Site::query()->where('organization_id', $site->organization_id)->where('runtime', SiteRuntime::Compose->value)
            ->whereNotNull('compose_services')->get();

        foreach ($stacks as $stack) {
            foreach ((array) $stack->compose_services as $service => $decision) {
                if (! is_array($decision) || ($decision['mode'] ?? null) !== 'site' || strtolower((string) ($decision['site_id'] ?? '')) !== $site->id) {
                    continue;
                }

                if (! in_array(strtolower($serverId), $stack->serverIds(), true)) {
                    return [];
                }

                // Decisions recorded before the networks were: the stack's default network. An empty list is a service
                // on no stack network (network_mode): it joins none.
                $names = array_key_exists('networks', $decision)
                    ? array_values(array_filter(array_map('strval', (array) $decision['networks'])))
                    : ["{$stack->slug}_default"];
                // Decisions recorded before extraction checked them: only what the agent accepts, never a failed deploy.
                $names = ComposeNetworks::check($names)['networks'];
                $declared = (array) ($decision['network_aliases'] ?? []);
                // Networks the stack's project owns (key per real name); older decisions: its default network.
                $owned = array_key_exists('compose_networks', $decision)
                    ? array_filter((array) $decision['compose_networks'], 'is_string')
                    : ["{$stack->slug}_default" => 'default'];

                return array_map(function (string $name) use ($service, $declared, $owned, $stack) {
                    // The service's name, then the aliases it declared on that network (what the agent accepts).
                    $aliases = array_values(array_unique(array_filter(
                        array_map('strval', [(string) $service, ...array_filter((array) ($declared[$name] ?? []), 'is_scalar')]),
                        fn (string $alias) => ComposeNetworks::validAlias($alias),
                    )));
                    $key = ComposeNetworks::reserved($name) ? null : ($owned[$name] ?? null);

                    return array_filter([
                        'name' => $name,
                        'aliases' => array_slice($aliases, 0, ComposeNetworks::MAX_ALIASES),
                        // The agent creates it the way Compose would when the stack hasn't run yet (docker.networks.create).
                        'compose' => $key !== null && ComposeNetworks::validAlias($key) ? ['project' => $stack->slug, 'network' => $key] : null,
                    ]);
                }, $names);
            }
        }

        return [];
    }

    public function setPublicDomains(string $siteId, array $domains): void
    {
        // Read-modify-write of public_services under a row lock: Settings → Compose and the extraction write it too.
        DB::transaction(function () use ($siteId, $domains) {
            $site = Site::query()->whereKey(strtolower($siteId))->lockForUpdate()->first();

            if ($site === null || $site->runtime !== SiteRuntime::Compose) {
                return;
            }

            $public = array_values(array_filter((array) $site->public_services, 'is_array'));
            $changed = false;

            foreach ($public as $i => $service) {
                $name = (string) ($service['service'] ?? '');

                if (! array_key_exists($name, $domains) || ($service['domain'] ?? null) === $domains[$name]) {
                    continue;
                }

                $public[$i]['domain'] = $domains[$name];
                $changed = true;
            }

            if ($changed) {
                Site::withoutEvents(fn () => $site->forceFill(['public_services' => $public])->save());
            }
        });
    }

    public function allowsPrivileged(string $organizationId): bool
    {
        return OrganizationSettings::for($organizationId)->allow_privileged_compose;
    }

    public function render(string $siteId, string $yaml, array $images, string $releaseId, ?array $repoFiles = null): RenderedCompose
    {
        $site = Site::query()->find(strtolower($siteId)) ?? throw new ComposeRenderException('The site no longer exists.');

        if ($site->runtime !== SiteRuntime::Compose) {
            throw new ComposeRenderException('The site is not a Docker Compose site.');
        }

        // What this release deploys is what the canvas shows for a repository stack (no SiteUpdated: nothing to apply).
        if ($site->compose_source !== ComposeSource::Inline && $site->compose_snapshot !== $yaml) {
            Site::withoutEvents(fn () => $site->forceFill(['compose_snapshot' => $yaml])->save());
        }

        // Kiln's adjustments (docs/plans/COMPOSE_APPS.md): extracted services out, their variables rewritten and,
        // for repository projects, mounted repository files pointed at <release>/repo/.
        $config = $site->composeConfig();
        $adjustedWarnings = [];

        if ($config !== null && ($repoFiles !== null || $config->extracted() !== [])) {
            try {
                $loaded = YamlComposeInspector::load($yaml);
            } catch (ParseException $e) {
                throw new ComposeRenderException('Invalid compose file: '.$e->getMessage());
            }

            if (is_array($loaded)) {
                $adjusted = KilnAdjustments::apply($loaded, $config, $repoFiles, $this->extraction()->rewrites($site->id), array_map(fn ($p) => $p->service, $site->publicServices()));

                if ($adjusted['errors'] !== []) {
                    throw new ComposeRenderException(implode(' ', $adjusted['errors']));
                }

                $yaml = self::dump($adjusted['doc']);
                $adjustedWarnings = $adjusted['warnings'];
            }
        }

        $summary = $this->inspector->parse($yaml);

        if (! $summary->valid()) {
            throw new ComposeRenderException('Invalid compose file: '.implode(' ', $summary->errors));
        }

        if ($summary->violations !== [] && ! $this->allowsPrivileged($site->organization_id)) {
            throw new ComposeRenderException('The compose file violates the compose policy ('.implode(' ', $summary->violations).') Enable “Allow privileged compose” in the organization settings to deploy it.');
        }

        try {
            /** @var array<string, mixed> $doc */
            $doc = YamlComposeInspector::load($yaml);
        } catch (ParseException $e) {
            throw new ComposeRenderException('Invalid compose file: '.$e->getMessage());
        }

        $publicServices = $site->publicServices();
        $hostPorts = [];
        $warnings = [...$summary->warnings, ...$adjustedWarnings];

        foreach ($publicServices as $public) {
            if ($summary->service($public->service) === null) {
                throw new ComposeRenderException("The public service {$public->service} is not in the compose file.");
            }

            if ($public->hostPort === null) {
                throw new ComposeRenderException("The public service {$public->service} has no host port; save the compose settings again.");
            }

            $hostPorts[$public->service] = $public->hostPort;
        }

        $leader = [];
        $release = strtoupper($releaseId);

        foreach ($doc['services'] as $name => $service) {
            $name = (string) $name;
            $service = is_array($service) ? $service : [];

            if (array_key_exists('build', $service)) {
                $image = $images[$name] ?? null;

                if ($image === null || $image === '') {
                    throw new ComposeRenderException("Service {$name} has a `build:` section but no image was built for it (inline compose files cannot build; push the image to a registry and use `image:`).");
                }

                unset($service['build']);
                $service['image'] = $image;
            } elseif (isset($images[$name])) {
                $service['image'] = $images[$name];
            }

            unset($service['ports']);

            if (isset($hostPorts[$name])) {
                $public = $site->publicService($name);
                $service['ports'] = ["127.0.0.1:{$hostPorts[$name]}:{$public?->port}"];
            }

            $labels = YamlComposeInspector::labels($service['labels'] ?? []);

            if (isset($labels[YamlComposeInspector::LEADER_COMMAND_LABEL]) && trim($labels[YamlComposeInspector::LEADER_COMMAND_LABEL]) !== '') {
                $argv = self::argv($labels[YamlComposeInspector::LEADER_COMMAND_LABEL]);

                if ($argv === null) {
                    throw new ComposeRenderException("Service {$name}: kiln.deploy.leader_command has unbalanced quotes.");
                }

                $leader[$name] = $argv;
            }

            $service['labels'] = [...$labels, 'kiln.site' => $site->slug, 'kiln.release' => $release, 'kiln.service' => $name];
            $doc['services'][$name] = $service;
        }

        return new RenderedCompose(self::dump($doc), array_map('strval', array_keys($doc['services'])), $leader, $hostPorts, $warnings);
    }

    /** Resolved per call: the extraction implementation may itself use compose sites. */
    private function extraction(): ComposeServiceExtraction
    {
        return app(ComposeServiceExtraction::class);
    }

    public function pinDigests(string $yaml, array $digests): string
    {
        if ($digests === []) {
            return $yaml;
        }

        try {
            $doc = YamlComposeInspector::load($yaml);
        } catch (ParseException) {
            return $yaml;
        }

        if (! is_array($doc) || ! is_array($doc['services'] ?? null)) {
            return $yaml;
        }

        foreach ($digests as $service => $digest) {
            $image = $doc['services'][$service]['image'] ?? null;

            if (! is_string($image) || preg_match('/^sha256:[a-f0-9]{64}$/', $digest) !== 1 || str_contains($image, '$')) {
                continue;
            }

            $doc['services'][$service]['image'] = preg_replace('/@sha256:[a-f0-9]{64}$/', '', $image).'@'.$digest;
        }

        return self::dump($doc);
    }

    public function recordStatus(string $siteId, string $serverId, array $services): void
    {
        $state = ComposeState::query()->firstOrNew(['site_id' => strtolower($siteId), 'server_id' => strtolower($serverId)]);

        if (! Site::query()->whereKey($state->site_id)->exists()) {
            return;
        }

        $state->forceFill(['services' => array_values(array_filter($services, 'is_array')), 'reported_at' => now()])->save();
    }

    public function status(string $siteId): array
    {
        $out = [];

        foreach (ComposeState::query()->where('site_id', strtolower($siteId))->orderBy('server_id')->get() as $state) {
            foreach ($state->services as $service) {
                $out[] = self::state($state, $service);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $service
     */
    public static function state(ComposeState $state, array $service): ComposeServiceState
    {
        return new ComposeServiceState(
            serverId: $state->server_id,
            service: (string) ($service['service'] ?? ''),
            state: (string) ($service['state'] ?? 'unknown'),
            health: isset($service['health']) && $service['health'] !== '' ? (string) $service['health'] : null,
            image: (string) ($service['image'] ?? ''),
            imageDigest: isset($service['image_digest']) ? (string) $service['image_digest'] : null,
            ports: array_values(array_filter((array) ($service['ports'] ?? []), 'is_array')),
            restarts: (int) ($service['restarts'] ?? 0),
            cpuPercent: is_numeric($service['cpu_percent'] ?? null) ? (float) $service['cpu_percent'] : null,
            memoryBytes: is_numeric($service['memory_bytes'] ?? null) ? (int) $service['memory_bytes'] : null,
            containerName: isset($service['container_name']) ? (string) $service['container_name'] : null,
            reportedAt: $state->reported_at ? DateTimeImmutable::createFromInterface($state->reported_at) : null,
        );
    }

    /**
     * Shell-style word splitting (quotes and backslashes); null on unbalanced quotes. The result is passed as
     * separate arguments — never through a shell on the host.
     *
     * @return ?list<string>
     */
    public static function argv(string $command): ?array
    {
        $args = [];
        $current = '';
        $inWord = false;
        $quote = null;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                } elseif ($char === '\\' && $quote === '"' && $i + 1 < $length) {
                    $current .= $command[++$i];
                } else {
                    $current .= $char;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $inWord = true;
            } elseif ($char === '\\' && $i + 1 < $length) {
                $current .= $command[++$i];
                $inWord = true;
            } elseif (ctype_space($char)) {
                if ($inWord) {
                    $args[] = $current;
                    $current = '';
                    $inWord = false;
                }
            } else {
                $current .= $char;
                $inWord = true;
            }
        }

        if ($quote !== null) {
            return null;
        }

        if ($inWord) {
            $args[] = $current;
        }

        return $args;
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    public static function dump(array $doc): string
    {
        // Symfony parses `{}` into []; mappings Compose requires must stay mappings.
        foreach (['volumes', 'networks', 'configs', 'secrets'] as $key) {
            if (($doc[$key] ?? null) === []) {
                $doc[$key] = new stdClass;
            } elseif (is_array($doc[$key] ?? null)) {
                foreach ($doc[$key] as $name => $definition) {
                    if ($definition === []) {
                        $doc[$key][$name] = null; // `name: {}` ≡ `name:` (default definition)
                    }
                }
            }
        }

        foreach ($doc['services'] ?? [] as $name => $service) {
            foreach (['environment', 'labels', 'deploy', 'healthcheck', 'logging'] as $key) {
                if (is_array($service) && ($service[$key] ?? null) === []) {
                    $doc['services'][$name][$key] = new stdClass;
                }
            }
        }

        return Yaml::dump($doc, 12, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }
}

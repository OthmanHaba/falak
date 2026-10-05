<?php

namespace Falak\Sites\Application;

use Illuminate\Validation\ValidationException;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\OctaneServer;
use Falak\Sites\Domain\Models\Site;

/**
 * Octane server + port of a site. The port is stable (persisted in the site's Laravel settings) and unique
 * on every server the site targets: it never equals another site's app port, compose host port, Octane
 * port or Octane admin/RPC port (port + {@see LaravelSettings::OCTANE_AUX_PORT_OFFSET}), and neither does
 * its own admin/RPC port. The first candidate is base + crc32(site id) % span (spreads sites out), then the
 * next free port in the range (wrapping) — collisions are resolved, never silently shared.
 */
final class OctanePorts
{
    public function __construct(private readonly SiteRules $rules) {}

    /**
     * Settings with a valid Octane server and a free port for the site's current servers (unchanged when
     * Octane is off or everything is already valid).
     *
     * @throws ValidationException
     */
    public function resolve(Site $site, LaravelSettings $settings): LaravelSettings
    {
        if (! $settings->octane) {
            return $settings;
        }

        $server = $settings->octaneServer ?? OctaneServer::defaultFor($site->runtime);

        if (! $server->supports($site->runtime)) {
            throw ValidationException::withMessages(['octane_server' => "{$server->label()} is not available for {$site->runtime->label()} sites; pick ".implode(' or ', array_map(fn (OctaneServer $s) => $s->label(), OctaneServer::for($site->runtime))).'.']);
        }

        $port = $this->port($site->id, $site->serverIds(), $settings->octanePort);

        return $settings->with(octaneServer: $server, octanePort: $port);
    }

    /**
     * Re-check the persisted port after the site's servers changed; saves and returns true when it moved.
     */
    public function reassign(Site $site): bool
    {
        if (! $site->laravel->octane) {
            return false;
        }

        $settings = $this->resolve($site, $site->laravel);

        if ($settings == $site->laravel) {
            return false;
        }

        $site->forceFill(['laravel' => $settings])->save();

        return true;
    }

    /**
     * @param  list<string>  $serverIds
     *
     * @throws ValidationException
     */
    public function port(string $siteId, array $serverIds, ?int $preferred = null): int
    {
        $used = array_flip($serverIds === [] ? [] : $this->rules->portsInUse($serverIds, $siteId));
        $base = (int) config('sites.octane_port_base', 8000);
        $span = max(1, (int) config('sites.octane_port_span', 1000));
        $free = fn (int $port) => ! isset($used[$port]) && ! isset($used[$port + LaravelSettings::OCTANE_AUX_PORT_OFFSET]);

        if ($preferred !== null && $preferred > 0 && $preferred + LaravelSettings::OCTANE_AUX_PORT_OFFSET <= 65535 && $free($preferred)) {
            return $preferred;
        }

        $start = crc32(strtolower($siteId)) % $span;

        for ($i = 0; $i < $span; $i++) {
            $port = $base + (($start + $i) % $span);

            if ($free($port)) {
                return $port;
            }
        }

        throw ValidationException::withMessages(['octane' => 'No free Octane port left on the site\'s servers.']);
    }
}

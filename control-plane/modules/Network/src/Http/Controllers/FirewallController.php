<?php

namespace Kiln\Network\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Network\Application\Actions\DeleteFirewallRule;
use Kiln\Network\Application\Actions\EnsureDefaultFirewallRules;
use Kiln\Network\Application\Actions\SaveFirewallRule;
use Kiln\Network\Application\ApplyFirewall;
use Kiln\Network\Domain\Enums\RuleAction;
use Kiln\Network\Domain\Models\FirewallRule;
use Kiln\Network\Domain\Models\FirewallState;
use Kiln\Network\Http\Requests\FirewallRuleRequest;
use Kiln\Network\Infrastructure\FirewallCompiler;
use Kiln\Servers\Contracts\ServerHeaders;

final class FirewallController extends Controller
{
    use ResolvesServers;

    public function __construct(private readonly OrganizationAccess $access) {}

    public function show(Request $request, string $server, EnsureDefaultFirewallRules $defaults, FirewallCompiler $compiler): Response
    {
        $data = $this->server($request->user(), $server);

        if ($defaults($data) && $data->isActive() && $this->access->can($request->user(), $data->organizationId, 'network.manage')) {
            // First visit of a server provisioned before Network existed: converge it.
            app(ApplyFirewall::class)($data->id);
        }

        $rules = FirewallRule::query()
            ->where('server_id', $data->id)
            ->orderByRaw('CASE WHEN action = ? THEN 0 ELSE 1 END', [RuleAction::Deny->value])
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();

        $state = FirewallState::query()->find($data->id);

        return Inertia::render('Network/Firewall', [
            'server' => app(ServerHeaders::class)->for($data->id),
            'rules' => $rules->map(fn (FirewallRule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'action' => $rule->action->value,
                'protocol' => $rule->protocol->value,
                'port' => $rule->port,
                'source' => $rule->source,
                'is_default' => $rule->is_default,
                'created_at' => $rule->created_at->toIso8601String(),
            ])->values(),
            'networkRules' => collect($compiler->compile($data->id)['rules'])->filter(fn (array $rule) => str_starts_with((string) $rule['id'], 'wg-'))->values(),
            'state' => $state ? self::presentState($state) : null,
            'sshPort' => (int) config('network.ssh_port', 22),
            'can' => ['manage' => $this->access->can($request->user(), $data->organizationId, 'network.manage')],
        ]);
    }

    public function store(FirewallRuleRequest $request, string $server, SaveFirewallRule $save): RedirectResponse
    {
        $data = $this->server($request->user(), $server, 'network.manage');

        /** @var array{name: string, action: string, protocol: string, port?: ?string, source?: ?string} $validated */
        $validated = $request->validated();
        $save($data, $validated);

        return back();
    }

    public function update(FirewallRuleRequest $request, string $server, FirewallRule $rule, SaveFirewallRule $save): RedirectResponse
    {
        $data = $this->server($request->user(), $server, 'network.manage');
        abort_unless($rule->server_id === $data->id, 404);
        $this->authorize('manage', $rule);

        /** @var array{name: string, action: string, protocol: string, port?: ?string, source?: ?string} $validated */
        $validated = $request->validated();
        $save($data, $validated, $rule);

        return back();
    }

    public function destroy(Request $request, string $server, FirewallRule $rule, DeleteFirewallRule $delete): RedirectResponse
    {
        $data = $this->server($request->user(), $server, 'network.manage');
        abort_unless($rule->server_id === $data->id, 404);
        $this->authorize('manage', $rule);

        $delete($rule);

        return back();
    }

    public function apply(Request $request, string $server, EnsureDefaultFirewallRules $defaults, ApplyFirewall $apply, AuditLog $audit): RedirectResponse
    {
        $data = $this->server($request->user(), $server, 'network.manage');
        abort_unless($data->isActive(), 422, 'The firewall is applied once the server is active.');

        $defaults($data);
        $apply($data->id, force: true);
        $audit->record('network.firewall_reapplied', 'server', $data->id, [], $data->organizationId);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentState(FirewallState $state): array
    {
        return [
            'status' => $state->status->value,
            'in_sync' => $state->desired_hash !== null && $state->desired_hash === $state->applied_hash,
            'revision' => $state->revision,
            'command_id' => $state->command_id,
            'ruleset_sha256' => $state->ruleset_sha256,
            'error' => $state->error,
            'applied_at' => $state->applied_at?->toIso8601String(),
        ];
    }
}

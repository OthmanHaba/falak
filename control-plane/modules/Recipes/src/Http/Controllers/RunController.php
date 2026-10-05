<?php

namespace Falak\Recipes\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Recipes\Application\Actions\RunRecipe;
use Falak\Recipes\Domain\BuiltinRecipe;
use Falak\Recipes\Domain\Enums\RunStatus;
use Falak\Recipes\Domain\Models\Recipe;
use Falak\Recipes\Domain\Models\Run;
use Falak\Recipes\Domain\Models\RunTarget;
use Falak\Recipes\Infrastructure\BuiltinRecipes;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerHeaders;
use Falak\Servers\Contracts\ServerStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class RunController extends Controller
{
    use PresentsRecipes;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly BuiltinRecipes $builtins,
        private readonly ServerDirectory $servers,
    ) {}

    public function create(Request $request, Recipe $recipe): Response
    {
        $this->authorize('run', $recipe);

        return $this->runPage($request, $recipe->organization_id, [
            ...$this->recipe($recipe),
            'builtin' => false,
            'variables' => (object) [],
            'run_url' => route('recipes.runs.store', $recipe),
        ]);
    }

    public function createBuiltin(Request $request, string $key): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'recipes.run');
        $builtin = $this->builtins->find($key) ?? abort(404);

        return $this->runPage($request, $organizationId, [
            ...$builtin->toArray(),
            'id' => null,
            'builtin' => true,
            'run_url' => route('recipes.builtin.runs.store', $key),
        ]);
    }

    public function store(Request $request, Recipe $recipe, RunRecipe $run): RedirectResponse
    {
        $this->authorize('run', $recipe);

        return $this->start($request, $recipe->organization_id, $recipe, $run);
    }

    public function storeBuiltin(Request $request, string $key, RunRecipe $run): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'recipes.run');
        $builtin = $this->builtins->find($key) ?? abort(404);

        return $this->start($request, $organizationId, $builtin, $run);
    }

    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'recipes.view');

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(RunStatus::class)],
            'recipe' => ['nullable', 'string', 'max:100'],
            'server' => ['nullable', 'string', 'max:26'],
        ]);

        $runs = Run::query()
            ->with('targets')
            ->where('organization_id', $organizationId)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['recipe'] ?? null, fn ($q, $recipe) => $q->where(fn ($q) => $q->where('recipe_id', $recipe)->orWhere('builtin', $recipe)))
            ->when($filters['server'] ?? null, fn ($q, $server) => $q->whereHas('targets', fn ($q) => $q->where('server_id', $server)))
            ->latest()
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Recipes/History', [
            'runs' => [
                'data' => collect($runs->items())->map(fn (Run $run) => $this->runSummary($run))->values(),
                'current_page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'total' => $runs->total(),
            ],
            'filters' => array_filter($filters),
            'recipes' => [
                ...Recipe::query()->where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name'])->map(fn (Recipe $r) => ['value' => $r->id, 'label' => $r->name])->all(),
                ...array_values(array_map(fn (BuiltinRecipe $r) => ['value' => $r->key, 'label' => "{$r->name} (built-in)"], $this->builtins->all())),
            ],
            'servers' => array_map(fn (ServerData $s) => ['value' => $s->id, 'label' => $s->name], $this->servers->forOrganization($organizationId)),
        ]);
    }

    /**
     * The server page's "Recipes" tab: run a recipe on this server and its run history here.
     */
    public function server(Request $request, string $server, ServerHeaders $headers): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'recipes.view');
        $data = $this->servers->find($server);
        abort_if($data === null || $data->organizationId !== $organizationId, 404);

        $runs = Run::query()
            ->with('targets')
            ->where('organization_id', $organizationId)
            ->whereHas('targets', fn ($q) => $q->where('server_id', $data->id))
            ->latest()
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        return Inertia::render('Recipes/Server', [
            'server' => $headers->for($data->id),
            'recipes' => Recipe::query()->where('organization_id', $organizationId)->orderBy('name')->get()->map(fn (Recipe $r) => [
                ...$this->recipe($r),
                'run_url' => route('recipes.run', $r).'?server='.$data->id,
            ])->values(),
            'builtins' => array_values(array_map(fn (BuiltinRecipe $r) => [
                ...$r->toArray(),
                'run_url' => route('recipes.builtin.run', $r->key).'?server='.$data->id,
            ], $this->builtins->all())),
            'runs' => $runs->map(function (Run $run) use ($data) {
                $target = $run->targets->firstWhere('server_id', $data->id);

                return [
                    ...$this->runSummary($run),
                    'target' => $target ? $this->runTarget($target) : null,
                ];
            })->values(),
            'can' => [
                'run' => $this->access->can($request->user(), $organizationId, 'recipes.run') && $data->status !== ServerStatus::Deleting,
                'manage' => $this->access->can($request->user(), $organizationId, 'recipes.manage'),
            ],
        ]);
    }

    public function show(Request $request, Run $run): Response
    {
        $this->authorize('view', $run);
        $run->load('targets');

        return Inertia::render('Recipes/RunShow', [
            'run' => [
                ...$this->runSummary($run),
                'script' => $run->script,
                'timeout_s' => $run->timeout_s,
                // Values may be secrets; only names are shown.
                'env_keys' => array_keys($run->env ?? []),
            ],
            'targets' => $run->targets->map(fn (RunTarget $t) => $this->runTarget($t))->values(),
            'can' => [
                'run' => $this->access->can($request->user(), $run->organization_id, 'recipes.run'),
                'viewOutput' => $this->access->can($request->user(), $run->organization_id, 'fleet.commands.view'),
            ],
        ]);
    }

    /**
     * Polling fallback for the live run page.
     */
    public function status(Run $run): JsonResponse
    {
        $this->authorize('view', $run);
        $run->load('targets');

        return response()->json(['data' => [
            ...$this->runSummary($run),
            'targets' => $run->targets->map(fn (RunTarget $t) => $this->runTarget($t))->values(),
        ]]);
    }

    /**
     * @param  array<string, mixed>  $recipe
     */
    private function runPage(Request $request, string $organizationId, array $recipe): Response
    {
        $servers = array_values(array_filter(
            $this->servers->forOrganization($organizationId),
            fn (ServerData $server) => $server->status !== ServerStatus::Deleting,
        ));
        $preselected = array_values(array_intersect(
            array_filter(explode(',', (string) $request->query('server', ''))),
            array_map(fn (ServerData $s) => $s->id, $servers),
        ));

        return Inertia::render('Recipes/Run', [
            'recipe' => $recipe,
            'servers' => array_map(fn (ServerData $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'type' => $s->type->value,
                'status' => $s->status->value,
                'ipv4' => $s->ipv4,
            ], $servers),
            // ?server=<id>[,<id>] (from a server's Recipes tab) preselects servers.
            'preselected' => $preselected,
            'defaultTimeout' => (int) config('recipes.timeout', 900),
            'maxTimeout' => (int) config('recipes.max_timeout', 3600),
        ]);
    }

    private function start(Request $request, string $organizationId, Recipe|BuiltinRecipe $recipe, RunRecipe $run): RedirectResponse
    {
        $data = $request->validate([
            'server_ids' => ['required', 'array', 'min:1', 'max:'.(int) config('recipes.max_servers', 100)],
            'server_ids.*' => ['required', 'string', 'distinct'],
            'env' => ['nullable', 'array', 'max:50'],
            'env.*.name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/', 'distinct'],
            'env.*.value' => ['nullable', 'string', 'max:4096'],
            'timeout' => ['nullable', 'integer', 'min:10', 'max:'.(int) config('recipes.max_timeout', 3600)],
        ], ['env.*.name.regex' => 'Variable names may contain letters, digits and underscores and cannot start with a digit.']);

        $env = [];

        foreach ($data['env'] ?? [] as $pair) {
            $env[(string) $pair['name']] = (string) ($pair['value'] ?? '');
        }

        $result = $run(
            $organizationId,
            $recipe,
            array_values(array_map('strval', $data['server_ids'])),
            $env,
            isset($data['timeout']) ? (int) $data['timeout'] : null,
            $request->user()?->getAuthIdentifier(),
        );

        return to_route('recipes.runs.show', $result);
    }
}

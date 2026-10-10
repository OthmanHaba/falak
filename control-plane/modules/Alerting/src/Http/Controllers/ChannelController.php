<?php

namespace Falak\Alerting\Http\Controllers;

use Falak\Alerting\Application\Actions\DeleteChannel;
use Falak\Alerting\Application\Actions\MakeDefaultChannel;
use Falak\Alerting\Application\Actions\SaveChannel;
use Falak\Alerting\Application\Actions\SendTestMessage;
use Falak\Alerting\Domain\Enums\ChannelType;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Alerting\Http\Requests\ChannelRequest;
use Falak\Alerting\Infrastructure\Senders\SenderRegistry;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ChannelController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly SenderRegistry $senders,
    ) {}

    public function index(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'alerting.view');

        $channels = Channel::query()->withCount('rules')->where('organization_id', $organizationId)->orderByDesc('is_default')->orderBy('name')->get();

        return Inertia::render('Alerting/Channels', [
            'channels' => $channels->map(fn (Channel $channel) => $this->present($channel))->values(),
            'types' => collect(ChannelType::cases())->map(fn (ChannelType $type) => ['value' => $type->value, 'label' => $type->label()])->values(),
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, 'alerting.manage')],
        ]);
    }

    public function store(ChannelRequest $request, SaveChannel $save): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'alerting.manage');

        $save($organizationId, $request->validated());

        return to_route('alerting.channels.index');
    }

    public function update(ChannelRequest $request, Channel $channel, SaveChannel $save): RedirectResponse
    {
        $this->authorize('update', $channel);

        $save($channel->organization_id, $request->validated(), $channel);

        return to_route('alerting.channels.index');
    }

    public function destroy(Channel $channel, DeleteChannel $delete): RedirectResponse
    {
        $this->authorize('delete', $channel);

        $delete($channel);

        return to_route('alerting.channels.index');
    }

    public function makeDefault(Channel $channel, MakeDefaultChannel $make): RedirectResponse
    {
        $this->authorize('update', $channel);

        $make($channel);

        return to_route('alerting.channels.index');
    }

    public function test(Channel $channel, SendTestMessage $send): JsonResponse
    {
        $this->authorize('update', $channel);

        $error = $send($channel);

        return response()->json(['ok' => $error === null, 'error' => $error], $error === null ? 200 : 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Channel $channel): array
    {
        $sender = $this->senders->for($channel->type);

        return [
            'id' => $channel->id,
            'name' => $channel->name,
            'type' => $channel->type->value,
            'enabled' => $channel->enabled,
            'is_default' => $channel->is_default,
            'config' => (object) $sender->mask($channel->config),
            'secret_keys' => $sender->secretKeys(),
            'rules_count' => (int) $channel->getAttribute('rules_count'),
            'last_sent_at' => $channel->last_sent_at?->toIso8601String(),
            'last_error' => $channel->last_error,
        ];
    }
}

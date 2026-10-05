<?php

namespace Falak\Alerting\Http\Controllers;

use Falak\Alerting\Application\Actions\MarkNotificationsRead;
use Falak\Alerting\Domain\Models\Notification;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Kernel\Http\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notification center: the current user's notifications in the current organization.
 */
final class NotificationController extends Controller
{
    public function __construct(private readonly CurrentOrganization $organization) {}

    public function index(Request $request): Response
    {
        $unreadOnly = $request->boolean('unread');

        $notifications = $this->query($request)
            ->when($unreadOnly, fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Notification $notification) => $notification->toPayload());

        return Inertia::render('Alerting/Notifications', [
            'notifications' => $notifications,
            'filters' => ['unread' => $unreadOnly],
            'unreadCount' => $this->query($request)->whereNull('read_at')->count(),
        ]);
    }

    public function unread(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $this->query($request)->whereNull('read_at')->count(),
            'latest' => $this->query($request)->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get()
                ->map(fn (Notification $notification) => $notification->toPayload())->values(),
        ]);
    }

    public function read(Request $request, string $notification, MarkNotificationsRead $mark): RedirectResponse|JsonResponse
    {
        abort_unless($this->query($request)->whereKey($notification)->exists(), 404);

        $mark((string) $request->user()?->getAuthIdentifier(), $this->organization->requireId(), $notification);

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json(['ok' => true]) : back();
    }

    public function readAll(Request $request, MarkNotificationsRead $mark): RedirectResponse|JsonResponse
    {
        $updated = $mark((string) $request->user()?->getAuthIdentifier(), $this->organization->requireId());

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json(['ok' => true, 'updated' => $updated]) : back();
    }

    /**
     * @return Builder<Notification>
     */
    private function query(Request $request)
    {
        return Notification::query()
            ->where('user_id', (string) $request->user()?->getAuthIdentifier())
            ->where('organization_id', $this->organization->requireId());
    }
}

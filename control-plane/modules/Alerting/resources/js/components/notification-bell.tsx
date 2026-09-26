import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { Bell } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { type AppNotification } from '../types';
import { jsonRequest, SeverityDot } from './alerting-ui';

interface UnreadResponse {
    count: number;
    latest: AppNotification[];
}

const POLL_MS = 60_000;

export function NotificationBell() {
    const { props } = usePage<SharedData>();
    const userId = props.auth.user?.id;
    const organizationId = props.organization?.current?.id;
    const [count, setCount] = useState(0);
    const [latest, setLatest] = useState<AppNotification[]>([]);

    const refresh = useCallback(async () => {
        const response = await jsonRequest<UnreadResponse>('GET', '/notifications/unread');

        if (response.ok && response.body) {
            setCount(response.body.count);
            setLatest(response.body.latest);
        }
    }, []);

    const realtime = useEchoChannel<{ organization_id: string; notification: AppNotification }>(
        userId ? `alerting.users.${userId}` : null,
        ['notification.created'],
        (_event, payload) => {
            if (payload.organization_id !== organizationId) return;
            setCount((value) => value + 1);
            setLatest((items) => [payload.notification, ...items.filter((item) => item.id !== payload.notification.id)].slice(0, 5));
        },
    );

    useEffect(() => {
        void refresh();

        if (realtime) return;

        const timer = window.setInterval(() => void refresh(), POLL_MS);

        return () => window.clearInterval(timer);
    }, [refresh, realtime, organizationId]);

    const open = async (notification: AppNotification) => {
        if (!notification.read_at) {
            await jsonRequest('POST', `/notifications/${notification.id}/read`);
            void refresh();
        }

        if (notification.url) {
            const url = new URL(notification.url, window.location.origin);
            if (url.origin === window.location.origin) {
                router.visit(url.pathname + url.search);
            } else {
                window.location.href = url.toString();
            }
        }
    };

    const markAll = async () => {
        await jsonRequest('POST', '/notifications/read-all');
        void refresh();
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative h-9 w-9"
                    aria-label={count > 0 ? `Notifications (${count} unread)` : 'Notifications'}
                >
                    <Bell className="size-5 opacity-80" />
                    {count > 0 && (
                        <span className="bg-destructive absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] leading-none font-semibold text-white">
                            {count > 99 ? '99+' : count}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80">
                <DropdownMenuLabel className="flex items-center justify-between">
                    <span>Notifications</span>
                    {count > 0 && (
                        <button
                            type="button"
                            className="text-muted-foreground hover:text-foreground text-xs font-normal"
                            onClick={() => void markAll()}
                        >
                            Mark all read
                        </button>
                    )}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                {latest.length === 0 ? (
                    <p className="text-muted-foreground px-2 py-6 text-center text-sm">You're all caught up.</p>
                ) : (
                    <ul className="max-h-96 overflow-y-auto">
                        {latest.map((notification) => (
                            <li key={notification.id}>
                                <button
                                    type="button"
                                    onClick={() => void open(notification)}
                                    className="hover:bg-accent flex w-full gap-2 rounded-sm px-2 py-2 text-left text-sm"
                                >
                                    <SeverityDot severity={notification.severity} />
                                    <span className="min-w-0 flex-1">
                                        <span
                                            className={notification.read_at ? 'text-muted-foreground block truncate' : 'block truncate font-medium'}
                                        >
                                            {notification.title}
                                        </span>
                                        <span className="text-muted-foreground block text-xs">
                                            {formatDistanceToNow(new Date(notification.created_at), { addSuffix: true })}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                <DropdownMenuSeparator />
                <Link href="/notifications" className="hover:bg-accent block rounded-sm px-2 py-1.5 text-center text-sm">
                    View all
                </Link>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

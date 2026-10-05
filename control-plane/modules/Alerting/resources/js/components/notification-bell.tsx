import { Button } from '@/components/falak/button';
import { MenuContent, MenuRoot, MenuTrigger } from '@/components/falak/menu';
import { RelativeTime } from '@/components/falak/relative-time';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { Bell, BellOff, Check, CheckCheck } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { type AppNotification } from '../types';
import { jsonRequest } from './alerting-ui';
import { openUrl, SeverityIndicator } from './severity';

interface UnreadResponse {
    count: number;
    latest: AppNotification[];
}

const POLL_MS = 60_000;

/** 🔔 in the top bar (header-items slot): unread badge, latest notifications, mark read / all read; live via Reverb. */
export function NotificationBell() {
    const { props } = usePage<SharedData>();
    const userId = props.auth.user?.id;
    const organizationId = props.organization?.current?.id;
    const [count, setCount] = useState(0);
    const [latest, setLatest] = useState<AppNotification[]>([]);
    const [open, setOpen] = useState(false);

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

    // Opening the popover re-syncs (cheap) so read state from other tabs is current.
    useEffect(() => {
        if (open) void refresh();
    }, [open, refresh]);

    const markRead = async (notification: AppNotification) => {
        if (notification.read_at) return;
        setLatest((items) => items.map((item) => (item.id === notification.id ? { ...item, read_at: new Date().toISOString() } : item)));
        setCount((value) => Math.max(0, value - 1));
        await jsonRequest('POST', `/notifications/${notification.id}/read`);
    };

    const select = async (notification: AppNotification) => {
        await markRead(notification);
        if (notification.url) openUrl(notification.url, (path) => router.visit(path));
    };

    const markAll = async () => {
        setCount(0);
        setLatest((items) => items.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() })));
        await jsonRequest('POST', '/notifications/read-all');
        void refresh();
    };

    return (
        <MenuRoot open={open} onOpenChange={setOpen}>
            <MenuTrigger asChild>
                <Button
                    variant="ghost"
                    className="relative w-8 px-0"
                    icon={<Bell />}
                    aria-label={count > 0 ? `Notifications (${count} unread)` : 'Notifications'}
                >
                    {count > 0 && (
                        <span
                            aria-hidden
                            className="bg-danger text-on-accent ring-bg tabular pointer-events-none absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] leading-none font-semibold ring-2"
                        >
                            {count > 99 ? '99+' : count}
                        </span>
                    )}
                </Button>
            </MenuTrigger>
            <MenuContent className="w-[min(22rem,calc(100vw-1.5rem))] p-0">
                <div className="border-border flex items-center justify-between gap-2 border-b px-3 py-2">
                    <p className="text-fg text-sm font-medium">
                        Notifications
                        {count > 0 && <span className="text-fg-faint tabular ml-1.5 text-xs font-normal">{count} unread</span>}
                    </p>
                    {count > 0 && (
                        <Button size="sm" variant="ghost" icon={<CheckCheck />} onClick={() => void markAll()}>
                            Mark all read
                        </Button>
                    )}
                </div>
                {latest.length === 0 ? (
                    <div className="grid justify-items-center gap-2 px-4 py-8 text-center">
                        <BellOff className="text-fg-faint size-5" aria-hidden />
                        <p className="text-fg text-sm">You're all caught up</p>
                        <p className="text-fg-faint text-xs">Deploys, failures and issues you're involved in show up here.</p>
                    </div>
                ) : (
                    <ul className="max-h-96 overflow-y-auto p-1">
                        {latest.map((notification) => (
                            <li key={notification.id} className="group relative">
                                <DropdownMenu.Item
                                    onSelect={() => void select(notification)}
                                    className="data-[highlighted]:bg-surface-2 flex w-full cursor-default items-start gap-2.5 rounded-md py-2 pr-9 pl-2 text-left outline-none"
                                >
                                    <SeverityIndicator severity={notification.severity} className="mt-1.5" />
                                    <span className="grid min-w-0 flex-1 gap-0.5">
                                        <span className={cn('truncate text-sm', notification.read_at ? 'text-fg-muted' : 'text-fg font-medium')}>
                                            {notification.title}
                                        </span>
                                        {notification.body && <span className="text-fg-faint line-clamp-1 text-xs">{notification.body}</span>}
                                        <RelativeTime value={notification.created_at} className="text-fg-faint text-2xs" />
                                    </span>
                                    {!notification.read_at && (
                                        <span
                                            className="bg-primary absolute top-3.5 right-3 size-1.5 rounded-full group-hover:hidden"
                                            aria-label="Unread"
                                        />
                                    )}
                                </DropdownMenu.Item>
                                {!notification.read_at && (
                                    <button
                                        type="button"
                                        onClick={() => void markRead(notification)}
                                        aria-label={`Mark "${notification.title}" as read`}
                                        className="text-fg-faint hover:bg-surface-3 hover:text-fg absolute top-2 right-1.5 hidden size-6 items-center justify-center rounded-sm group-hover:flex focus-visible:flex"
                                    >
                                        <Check className="size-3.5" />
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                <div className="border-border border-t p-1">
                    <DropdownMenu.Item
                        asChild
                        className="text-fg-muted data-[highlighted]:bg-surface-2 data-[highlighted]:text-fg block rounded-md px-2 py-1.5 text-center text-sm outline-none"
                    >
                        <Link href="/notifications">View all notifications</Link>
                    </DropdownMenu.Item>
                </div>
            </MenuContent>
        </MenuRoot>
    );
}

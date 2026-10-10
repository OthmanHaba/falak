import { AppShell } from '@/components/falak/app-shell';
import { Button } from '@/components/falak/button';
import { EmptyState } from '@/components/falak/empty-state';
import { Pagination } from '@/components/falak/pagination';
import { RelativeTime } from '@/components/falak/relative-time';
import { PageHeader } from '@/components/falak/section';
import { Segmented } from '@/components/falak/segmented';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import { cn } from '@/lib/utils';
import { type Paginated, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { format, isToday, isYesterday } from 'date-fns';
import { BellOff, Check, CheckCheck } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { openUrl, SeverityIndicator } from '../components/severity';
import { type AppNotification } from '../types';

interface Props {
    notifications: Paginated<AppNotification>;
    filters: { unread: boolean };
    unreadCount: number;
}

function dayLabel(iso: string): string {
    const date = new Date(iso);
    if (isToday(date)) return 'Today';
    if (isYesterday(date)) return 'Yesterday';

    return format(date, 'EEEE, MMM d');
}

/** Notification center (docs/UI_DESIGN.md §3): the current user's notifications in this organization, live. */
export default function Notifications({ notifications, filters, unreadCount }: Props) {
    const { props } = usePage<SharedData>();
    const userId = props.auth.user?.id;
    const [marking, setMarking] = useState(false);

    // New notifications refresh the list in place (polling when Reverb isn't available).
    const realtime = useEchoChannel(userId ? `alerting.users.${userId}` : null, ['notification.created'], () => {
        router.reload({ only: ['notifications', 'unreadCount'] });
    });

    useEffect(() => {
        if (realtime) return;
        const timer = window.setInterval(() => router.reload({ only: ['notifications', 'unreadCount'] }), 60_000);

        return () => window.clearInterval(timer);
    }, [realtime]);

    const groups = useMemo(() => {
        const out: { label: string; items: AppNotification[] }[] = [];
        notifications.data.forEach((notification) => {
            const label = dayLabel(notification.created_at);
            const last = out[out.length - 1];
            if (last?.label === label) last.items.push(notification);
            else out.push({ label, items: [notification] });
        });

        return out;
    }, [notifications.data]);

    const markRead = (notification: AppNotification, then?: () => void) => {
        if (notification.read_at) {
            then?.();

            return;
        }
        router.post(route('notifications.read', notification.id), {}, { preserveScroll: true, preserveState: true, onSuccess: () => then?.() });
    };

    const open = (notification: AppNotification) =>
        markRead(notification, () => notification.url && openUrl(notification.url, (path) => router.visit(path)));

    const markAll = () => {
        setMarking(true);
        router.post(route('notifications.read-all'), {}, { preserveScroll: true, onFinish: () => setMarking(false) });
    };

    return (
        <AppShell breadcrumbs={[{ title: 'Notifications', href: '/notifications' }]}>
            <Head title="Notifications" />
            <div className="mx-auto grid w-full max-w-3xl gap-6">
                <PageHeader
                    title="Notifications"
                    description={unreadCount > 0 ? `${unreadCount} unread` : "You're all caught up"}
                    actions={
                        <>
                            <Segmented
                                label="Show"
                                value={filters.unread ? 'unread' : 'all'}
                                onValueChange={(value) =>
                                    router.get(route('notifications.index'), value === 'unread' ? { unread: 1 } : {}, {
                                        preserveState: true,
                                        replace: true,
                                    })
                                }
                                options={[
                                    { value: 'all', label: 'All' },
                                    { value: 'unread', label: `Unread${unreadCount > 0 ? ` · ${unreadCount}` : ''}` },
                                ]}
                            />
                            {unreadCount > 0 && (
                                <Button size="sm" icon={<CheckCheck />} loading={marking} onClick={markAll}>
                                    Mark all read
                                </Button>
                            )}
                        </>
                    }
                />

                {notifications.data.length === 0 ? (
                    <EmptyState
                        icon={<BellOff />}
                        title={filters.unread ? 'No unread notifications' : 'No notifications yet'}
                        description="Failed deploys, new and regressed issues, missed scheduled tasks and server alerts routed to you in-app appear here."
                        action={
                            filters.unread ? (
                                <Button size="sm" onClick={() => router.get(route('notifications.index'))}>
                                    Show all
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="grid gap-5">
                        {groups.map((group) => (
                            <section key={group.label} aria-label={group.label} className="grid gap-2">
                                <h2 className="text-fg-faint text-xs font-medium">{group.label}</h2>
                                <ul className="border-border bg-surface-1 divide-border divide-y overflow-hidden rounded-lg border">
                                    {group.items.map((notification) => (
                                        <li key={notification.id} className="group relative">
                                            <button
                                                type="button"
                                                onClick={() => open(notification)}
                                                className="hover:bg-surface-2 focus-visible:outline-primary flex w-full items-start gap-3 px-4 py-3 pr-12 text-left transition-colors duration-150 focus-visible:outline-2 focus-visible:-outline-offset-2"
                                            >
                                                <SeverityIndicator severity={notification.severity} className="mt-1.5" />
                                                <span className="grid min-w-0 flex-1 gap-0.5">
                                                    <span className={cn('text-sm', notification.read_at ? 'text-fg-muted' : 'text-fg font-medium')}>
                                                        {notification.title}
                                                    </span>
                                                    {notification.body && (
                                                        <span className="text-fg-muted line-clamp-2 text-sm">{notification.body}</span>
                                                    )}
                                                    <span className="text-fg-faint flex flex-wrap items-center gap-2 text-xs">
                                                        <RelativeTime value={notification.created_at} />
                                                        <span className="font-mono">{notification.type}</span>
                                                        {notification.action && notification.url && (
                                                            <span className="text-primary font-medium">Suggested fix: {notification.action} →</span>
                                                        )}
                                                    </span>
                                                </span>
                                            </button>
                                            {!notification.read_at && (
                                                <>
                                                    <span
                                                        className="bg-primary pointer-events-none absolute top-4.5 right-5 size-2 rounded-full group-hover:hidden"
                                                        aria-label="Unread"
                                                    />
                                                    <button
                                                        type="button"
                                                        onClick={() => markRead(notification)}
                                                        aria-label={`Mark "${notification.title}" as read`}
                                                        className="text-fg-faint hover:bg-surface-3 hover:text-fg absolute top-3 right-3 hidden size-7 items-center justify-center rounded-md group-hover:flex focus-visible:flex"
                                                    >
                                                        <Check className="size-4" />
                                                    </button>
                                                </>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </div>
                )}
                <Pagination page={notifications} noun="notifications" />
            </div>
        </AppShell>
    );
}

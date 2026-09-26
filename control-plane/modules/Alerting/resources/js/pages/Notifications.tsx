import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { CheckCheck } from 'lucide-react';
import { SeverityBadge } from '../components/alerting-ui';
import { type AppNotification } from '../types';

interface Props {
    notifications: Paginated<AppNotification>;
    filters: { unread: boolean };
    unreadCount: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Notifications', href: '/notifications' }];

export default function Notifications({ notifications, filters, unreadCount }: Props) {
    const { props } = usePage<SharedData>();
    const userId = props.auth.user?.id;

    // New notifications refresh the list in place.
    useEchoChannel(userId ? `alerting.users.${userId}` : null, ['notification.created'], () => {
        router.reload({ only: ['notifications', 'unreadCount'] });
    });

    const markRead = (notification: AppNotification) => {
        if (!notification.read_at) {
            router.post(route('notifications.read', notification.id), {}, { preserveScroll: true, preserveState: true });
        }
    };

    const markAll = () => router.post(route('notifications.read-all'), {}, { preserveScroll: true });

    const setFilter = (unread: boolean) =>
        router.get(route('notifications.index'), unread ? { unread: 1 } : {}, { preserveState: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notifications" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Notifications" description={unreadCount > 0 ? `${unreadCount} unread` : 'You are all caught up'} />
                    <div className="flex gap-2">
                        <div className="flex gap-1" role="group" aria-label="Filter">
                            <Button size="sm" variant={filters.unread ? 'ghost' : 'secondary'} onClick={() => setFilter(false)}>
                                All
                            </Button>
                            <Button size="sm" variant={filters.unread ? 'secondary' : 'ghost'} onClick={() => setFilter(true)}>
                                Unread
                            </Button>
                        </div>
                        {unreadCount > 0 && (
                            <Button size="sm" variant="outline" onClick={markAll}>
                                <CheckCheck /> Mark all read
                            </Button>
                        )}
                    </div>
                </div>

                <Card>
                    <CardContent className="divide-y p-0">
                        {notifications.data.length === 0 ? (
                            <p className="text-muted-foreground p-10 text-center text-sm">
                                {filters.unread ? 'No unread notifications.' : 'No notifications yet.'}
                            </p>
                        ) : (
                            notifications.data.map((notification) => (
                                <div
                                    key={notification.id}
                                    className={cn('flex items-start gap-3 px-4 py-3', !notification.read_at && 'bg-accent/40')}
                                >
                                    <SeverityBadge severity={notification.severity} className="mt-0.5" />
                                    <div className="min-w-0 flex-1">
                                        {notification.url ? (
                                            <a
                                                href={notification.url}
                                                onClick={() => markRead(notification)}
                                                className={cn('hover:underline', notification.read_at ? 'text-muted-foreground' : 'font-medium')}
                                            >
                                                {notification.title}
                                            </a>
                                        ) : (
                                            <span className={notification.read_at ? 'text-muted-foreground' : 'font-medium'}>
                                                {notification.title}
                                            </span>
                                        )}
                                        {notification.body && <p className="text-muted-foreground mt-0.5 text-sm">{notification.body}</p>}
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            {formatDistanceToNow(new Date(notification.created_at), { addSuffix: true })} · {notification.type}
                                        </p>
                                    </div>
                                    {!notification.read_at && (
                                        <Button size="sm" variant="ghost" onClick={() => markRead(notification)}>
                                            Mark read
                                        </Button>
                                    )}
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>

                {notifications.last_page > 1 && (
                    <div className="flex justify-end gap-1">
                        {notifications.links.map((link, index) => (
                            <Button
                                key={index}
                                asChild={link.url !== null}
                                size="sm"
                                variant={link.active ? 'secondary' : 'ghost'}
                                disabled={link.url === null}
                            >
                                {link.url ? (
                                    <Link href={link.url} preserveScroll dangerouslySetInnerHTML={{ __html: link.label }} />
                                ) : (
                                    <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                )}
                            </Button>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

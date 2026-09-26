import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Moon, Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';
import { AlertingTabs, SeverityBadge } from '../components/alerting-ui';
import { type AlertTypeOption, type ChannelType, type RuleRow, type Severity } from '../types';

interface Props {
    rules: RuleRow[];
    channels: { id: string; name: string; type: ChannelType; enabled: boolean }[];
    alertTypes: AlertTypeOption[];
    severities: { value: Severity; label: string }[];
    timezones: string[];
    can: { manage: boolean };
}

interface RuleForm {
    name: string;
    enabled: boolean;
    event_types: string[];
    min_severity: Severity;
    channel_ids: string[];
    rate_limit_per_hour: string;
    quiet_hours: { enabled: boolean; start: string; end: string; timezone: string; days: number[]; allow_critical: boolean };
}

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Alerts', href: '/alerting/rules' },
    { title: 'Rules', href: '/alerting/rules' },
];

function browserTimezone(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
}

function emptyForm(): RuleForm {
    return {
        name: '',
        enabled: true,
        event_types: [],
        min_severity: 'warning',
        channel_ids: [],
        rate_limit_per_hour: '',
        quiet_hours: { enabled: false, start: '22:00', end: '07:00', timezone: browserTimezone(), days: [], allow_critical: true },
    };
}

function toggle<T>(list: T[], value: T): T[] {
    return list.includes(value) ? list.filter((item) => item !== value) : [...list, value];
}

export default function Rules({ rules, channels, alertTypes, severities, timezones, can }: Props) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<RuleRow | null>(null);
    const [deleting, setDeleting] = useState<RuleRow | null>(null);
    const form = useForm<RuleForm>(emptyForm());

    const groups = useMemo(() => {
        const map = new Map<string, AlertTypeOption[]>();
        alertTypes.forEach((type) => map.set(type.group, [...(map.get(type.group) ?? []), type]));

        return [...map.entries()];
    }, [alertTypes]);

    const labelFor = (pattern: string) => {
        if (pattern === '*') return 'Everything';
        if (pattern.endsWith('.*')) return `All ${pattern.slice(0, -2)}`;

        return alertTypes.find((type) => type.type === pattern)?.label ?? pattern;
    };

    const startCreate = () => {
        setEditing(null);
        form.clearErrors();
        form.setData(emptyForm());
        setOpen(true);
    };

    const startEdit = (rule: RuleRow) => {
        setEditing(rule);
        form.clearErrors();
        form.setData({
            name: rule.name,
            enabled: rule.enabled,
            event_types: rule.event_types,
            min_severity: rule.min_severity,
            channel_ids: rule.channels.map((channel) => channel.id),
            rate_limit_per_hour: rule.rate_limit_per_hour ? String(rule.rate_limit_per_hour) : '',
            quiet_hours: rule.quiet_hours
                ? {
                      enabled: true,
                      start: rule.quiet_hours.start,
                      end: rule.quiet_hours.end,
                      timezone: rule.quiet_hours.timezone,
                      days: rule.quiet_hours.days ?? [],
                      allow_critical: rule.quiet_hours.allow_critical ?? true,
                  }
                : emptyForm().quiet_hours,
        });
        setOpen(true);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, rate_limit_per_hour: data.rate_limit_per_hour === '' ? null : Number(data.rate_limit_per_hour) }));
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };

        if (editing) {
            form.put(route('alerting.rules.update', editing.id), options);
        } else {
            form.post(route('alerting.rules.store'), options);
        }
    };

    const destroy = () => {
        if (!deleting) return;
        router.delete(route('alerting.rules.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) });
    };

    const errors = form.errors as Record<string, string | undefined>;
    const everything = form.data.event_types.includes('*');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Alert rules" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Alerts"
                        description="Rules decide which events notify which channels. Every issue alerts once until it is resolved."
                    />
                    {can.manage && (
                        <Button onClick={startCreate}>
                            <Plus /> Add rule
                        </Button>
                    )}
                </div>

                <AlertingTabs active="/alerting/rules" />

                <Card>
                    <CardContent className="p-0">
                        {rules.length === 0 ? (
                            <p className="text-muted-foreground p-10 text-center text-sm">
                                No rules yet. Without rules, alerts are only recorded in the history.{' '}
                                {channels.length === 0 && (
                                    <Link href="/alerting/channels" className="underline">
                                        Add a channel first.
                                    </Link>
                                )}
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Events</TableHead>
                                        <TableHead>Min. severity</TableHead>
                                        <TableHead>Channels</TableHead>
                                        <TableHead>Limits</TableHead>
                                        <TableHead className="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rules.map((rule) => (
                                        <TableRow key={rule.id}>
                                            <TableCell className="font-medium">
                                                {rule.name}
                                                {!rule.enabled && (
                                                    <Badge variant="secondary" className="ml-2">
                                                        Disabled
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex max-w-sm flex-wrap gap-1">
                                                    {rule.event_types.map((pattern) => (
                                                        <Badge key={pattern} variant="outline">
                                                            {labelFor(pattern)}
                                                        </Badge>
                                                    ))}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <SeverityBadge severity={rule.min_severity} />
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {rule.channels.length === 0 ? (
                                                    <span className="text-muted-foreground">In-app only</span>
                                                ) : (
                                                    rule.channels.map((channel) => channel.name).join(', ')
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-xs">
                                                {rule.quiet_hours && (
                                                    <span className="flex items-center gap-1">
                                                        <Moon className="size-3" /> {rule.quiet_hours.start}–{rule.quiet_hours.end} (
                                                        {rule.quiet_hours.timezone})
                                                    </span>
                                                )}
                                                {rule.rate_limit_per_hour && <span>≤ {rule.rate_limit_per_hour}/hour</span>}
                                            </TableCell>
                                            <TableCell className="text-right whitespace-nowrap">
                                                {can.manage && (
                                                    <>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() => startEdit(rule)}
                                                            aria-label={`Edit ${rule.name}`}
                                                        >
                                                            <Pencil />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() => setDeleting(rule)}
                                                            aria-label={`Delete ${rule.name}`}
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    </>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <form onSubmit={submit} className="space-y-5">
                        <DialogHeader>
                            <DialogTitle>{editing ? `Edit ${editing.name}` : 'Add rule'}</DialogTitle>
                            <DialogDescription>
                                Matching alerts are sent to the selected channels and to members' notification centers.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="rule-name">Name</Label>
                                <Input id="rule-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                                <InputError message={form.errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label>Minimum severity</Label>
                                <Select value={form.data.min_severity} onValueChange={(value) => form.setData('min_severity', value as Severity)}>
                                    <SelectTrigger aria-label="Minimum severity">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {severities.map((severity) => (
                                            <SelectItem key={severity.value} value={severity.value}>
                                                {severity.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.min_severity} />
                            </div>
                        </div>

                        <fieldset className="space-y-3">
                            <legend className="text-sm font-medium">Events</legend>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={everything} onCheckedChange={() => form.setData('event_types', everything ? [] : ['*'])} />
                                Everything (including future event types)
                            </label>
                            {!everything &&
                                groups.map(([group, types]) => {
                                    const wildcard = `${types[0].type.split('.')[0]}.*`;
                                    const all = form.data.event_types.includes(wildcard);

                                    return (
                                        <div key={group} className="rounded-md border p-3">
                                            <label className="flex items-center gap-2 text-sm font-medium">
                                                <Checkbox
                                                    checked={all}
                                                    onCheckedChange={() =>
                                                        form.setData(
                                                            'event_types',
                                                            all
                                                                ? form.data.event_types.filter((item) => item !== wildcard)
                                                                : [
                                                                      ...form.data.event_types.filter(
                                                                          (item) => !types.some((type) => type.type === item),
                                                                      ),
                                                                      wildcard,
                                                                  ],
                                                        )
                                                    }
                                                />
                                                All {group}
                                            </label>
                                            {!all && (
                                                <div className="mt-2 grid gap-1 pl-6 sm:grid-cols-2">
                                                    {types.map((type) => (
                                                        <label key={type.type} className="flex items-center gap-2 text-sm">
                                                            <Checkbox
                                                                checked={form.data.event_types.includes(type.type)}
                                                                onCheckedChange={() =>
                                                                    form.setData('event_types', toggle(form.data.event_types, type.type))
                                                                }
                                                            />
                                                            {type.label}
                                                        </label>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            <InputError message={errors.event_types ?? errors['event_types.0']} />
                        </fieldset>

                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium">Channels</legend>
                            {channels.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No channels yet: alerts will only appear in the notification center.</p>
                            ) : (
                                <div className="grid gap-1 sm:grid-cols-2">
                                    {channels.map((channel) => (
                                        <label key={channel.id} className="flex items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={form.data.channel_ids.includes(channel.id)}
                                                onCheckedChange={() => form.setData('channel_ids', toggle(form.data.channel_ids, channel.id))}
                                            />
                                            {channel.name}
                                            <span className="text-muted-foreground text-xs">
                                                {channel.type}
                                                {!channel.enabled && ' · disabled'}
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            )}
                            <InputError message={errors.channel_ids ?? Object.entries(errors).find(([key]) => key.startsWith('channel_ids.'))?.[1]} />
                        </fieldset>

                        <fieldset className="space-y-3">
                            <legend className="text-sm font-medium">Quiet hours</legend>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={form.data.quiet_hours.enabled}
                                    onCheckedChange={(checked) =>
                                        form.setData('quiet_hours', { ...form.data.quiet_hours, enabled: checked === true })
                                    }
                                />
                                Hold channel notifications during quiet hours (in-app notifications still arrive)
                            </label>
                            {form.data.quiet_hours.enabled && (
                                <div className="space-y-3 pl-6">
                                    <div className="flex flex-wrap items-end gap-3">
                                        <div className="grid gap-1">
                                            <Label htmlFor="quiet-start">From</Label>
                                            <Input
                                                id="quiet-start"
                                                type="time"
                                                value={form.data.quiet_hours.start}
                                                onChange={(e) => form.setData('quiet_hours', { ...form.data.quiet_hours, start: e.target.value })}
                                                className="w-32"
                                            />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="quiet-end">Until</Label>
                                            <Input
                                                id="quiet-end"
                                                type="time"
                                                value={form.data.quiet_hours.end}
                                                onChange={(e) => form.setData('quiet_hours', { ...form.data.quiet_hours, end: e.target.value })}
                                                className="w-32"
                                            />
                                        </div>
                                        <div className="grid min-w-56 flex-1 gap-1">
                                            <Label htmlFor="quiet-timezone">Timezone</Label>
                                            <Input
                                                id="quiet-timezone"
                                                list="alerting-timezones"
                                                value={form.data.quiet_hours.timezone}
                                                onChange={(e) => form.setData('quiet_hours', { ...form.data.quiet_hours, timezone: e.target.value })}
                                            />
                                            <datalist id="alerting-timezones">
                                                {timezones.map((timezone) => (
                                                    <option key={timezone} value={timezone} />
                                                ))}
                                            </datalist>
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap gap-3" role="group" aria-label="Days">
                                        {DAYS.map((day, index) => (
                                            <label key={day} className="flex items-center gap-1 text-sm">
                                                <Checkbox
                                                    checked={form.data.quiet_hours.days.includes(index + 1)}
                                                    onCheckedChange={() =>
                                                        form.setData('quiet_hours', {
                                                            ...form.data.quiet_hours,
                                                            days: toggle(form.data.quiet_hours.days, index + 1),
                                                        })
                                                    }
                                                />
                                                {day}
                                            </label>
                                        ))}
                                        <span className="text-muted-foreground text-xs">(none selected = every day)</span>
                                    </div>
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={form.data.quiet_hours.allow_critical}
                                            onCheckedChange={(checked) =>
                                                form.setData('quiet_hours', { ...form.data.quiet_hours, allow_critical: checked === true })
                                            }
                                        />
                                        Critical alerts still go through
                                    </label>
                                    <InputError
                                        message={
                                            errors['quiet_hours.start'] ??
                                            errors['quiet_hours.end'] ??
                                            errors['quiet_hours.timezone'] ??
                                            errors['quiet_hours.days.0']
                                        }
                                    />
                                </div>
                            )}
                        </fieldset>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="rule-rate">Max. alerts per hour</Label>
                                <Input
                                    id="rule-rate"
                                    type="number"
                                    min={1}
                                    max={1000}
                                    placeholder="unlimited"
                                    value={form.data.rate_limit_per_hour}
                                    onChange={(e) => form.setData('rate_limit_per_hour', e.target.value)}
                                />
                                <InputError message={form.errors.rate_limit_per_hour} />
                            </div>
                            <label className="flex items-center gap-2 self-end pb-2 text-sm">
                                <Checkbox checked={form.data.enabled} onCheckedChange={(checked) => form.setData('enabled', checked === true)} />
                                Enabled
                            </label>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {editing ? 'Save' : 'Add rule'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={deleting !== null} onOpenChange={(value) => !value && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete {deleting?.name}?</DialogTitle>
                        <DialogDescription>Alerts matched only by this rule will no longer be delivered.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="secondary" onClick={() => setDeleting(null)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={destroy}>
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

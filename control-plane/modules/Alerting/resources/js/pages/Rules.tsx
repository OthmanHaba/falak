import { Button } from '@/components/kiln/button';
import { Checkbox } from '@/components/kiln/checkbox';
import { ConfirmDestructive } from '@/components/kiln/confirm-destructive';
import { DataTable } from '@/components/kiln/data-table';
import { Dialog } from '@/components/kiln/dialog';
import { EmptyState } from '@/components/kiln/empty-state';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import { IntegrationIcon } from '@/components/kiln/integration-icon';
import { Section } from '@/components/kiln/section';
import { Select } from '@/components/kiln/select';
import { Switch } from '@/components/kiln/switch';
import { Tag, type TagProps } from '@/components/kiln/tag';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { Link, router, useForm } from '@inertiajs/react';
import { BellRing, Gauge, Moon, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState, type FormEventHandler } from 'react';
import { type AlertTypeOption, type ChannelType, type QuietHours, type RuleRow, type Severity } from '../types';

interface ChannelOption {
    id: string;
    name: string;
    type: ChannelType;
    enabled: boolean;
}

interface Props {
    rules: RuleRow[];
    channels: ChannelOption[];
    alertTypes: AlertTypeOption[];
    severities: { value: Severity; label: string }[];
    timezones: string[];
    can: { manage: boolean };
}

interface QuietForm extends QuietHours {
    enabled: boolean;
    days: number[];
    allow_critical: boolean;
}

interface RuleForm {
    name: string;
    enabled: boolean;
    event_types: string[];
    min_severity: Severity;
    channel_ids: string[];
    rate_limit_per_hour: string;
    quiet_hours: QuietForm;
}

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const SEVERITY_TONE: Record<Severity, TagProps['tone']> = { info: 'info', warning: 'warning', critical: 'danger' };

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

/** The full PUT payload for a rule with some fields changed (matrix / inline toggles). */
function payload(rule: RuleRow, changes: Partial<{ channel_ids: string[]; enabled: boolean }>) {
    return {
        name: rule.name,
        enabled: rule.enabled,
        event_types: rule.event_types,
        min_severity: rule.min_severity,
        channel_ids: rule.channels.map((channel) => channel.id),
        rate_limit_per_hour: rule.rate_limit_per_hour,
        quiet_hours: rule.quiet_hours ? { ...rule.quiet_hours, enabled: true } : null,
        ...changes,
    };
}

function SeverityTag({ severity }: { severity: Severity }) {
    return (
        <Tag tone={SEVERITY_TONE[severity]} className="capitalize">
            ≥ {severity}
        </Tag>
    );
}

export default function Rules({ rules, channels, alertTypes, severities, timezones, can }: Props) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<RuleRow | null>(null);
    const [deleting, setDeleting] = useState<RuleRow | null>(null);
    const [pending, setPending] = useState<string | null>(null);
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

    const update = (rule: RuleRow, key: string, changes: Partial<{ channel_ids: string[]; enabled: boolean }>) => {
        setPending(key);
        router.put(route('alerting.rules.update', rule.id), payload(rule, changes), { preserveScroll: true, onFinish: () => setPending(null) });
    };

    const destroy = () =>
        new Promise<void>((resolve) => {
            if (!deleting) return resolve();
            router.delete(route('alerting.rules.destroy', deleting.id), {
                preserveScroll: true,
                onSuccess: () => setDeleting(null),
                onFinish: () => resolve(),
            });
        });

    const errors = form.errors as Record<string, string | undefined>;
    const everything = form.data.event_types.includes('*');
    const quiet = form.data.quiet_hours;
    const setQuiet = (changes: Partial<QuietForm>) => form.setData('quiet_hours', { ...quiet, ...changes });
    const rate = form.data.rate_limit_per_hour;
    const rateError = errors.rate_limit_per_hour ?? (rate !== '' && (Number(rate) < 1 || Number(rate) > 1000) ? 'Between 1 and 1000.' : undefined);

    return (
        <SettingsLayout
            title="Alert rules"
            description="Rules decide which events notify which channels. Every issue alerts once until it resolves; members always see alerts in their notification center."
            actions={
                can.manage &&
                rules.length > 0 && (
                    <Button variant="primary" icon={<Plus />} onClick={startCreate}>
                        Add rule
                    </Button>
                )
            }
            wide
        >
            {rules.length === 0 ? (
                <EmptyState
                    icon={<BellRing />}
                    title="No alert rules yet"
                    description={
                        channels.length === 0
                            ? 'Alerts are recorded in the history but go nowhere. Add a channel (Slack, email, …) first, then a rule that routes to it.'
                            : 'Alerts are recorded in the history but reach no channel. Add a rule, e.g. “Everything ≥ warning → Slack”.'
                    }
                    action={
                        can.manage &&
                        (channels.length === 0 ? (
                            <Button asChild variant="primary">
                                <Link href={route('alerting.channels.index')}>
                                    <Plus /> Add a channel
                                </Link>
                            </Button>
                        ) : (
                            <Button variant="primary" icon={<Plus />} onClick={startCreate}>
                                Add a rule
                            </Button>
                        ))
                    }
                    secondary={
                        can.manage &&
                        channels.length === 0 && (
                            <Button variant="ghost" onClick={startCreate}>
                                Create an in-app rule
                            </Button>
                        )
                    }
                />
            ) : (
                <DataTable
                    label="Alert rules"
                    rows={rules}
                    rowKey={(rule) => rule.id}
                    columns={[
                        {
                            id: 'enabled',
                            header: <span className="sr-only">Enabled</span>,
                            width: '48px',
                            cell: (rule) => (
                                <Switch
                                    checked={rule.enabled}
                                    disabled={!can.manage || pending === `enabled-${rule.id}`}
                                    onCheckedChange={(enabled) => update(rule, `enabled-${rule.id}`, { enabled })}
                                    aria-label={`${rule.enabled ? 'Disable' : 'Enable'} ${rule.name}`}
                                />
                            ),
                        },
                        {
                            id: 'name',
                            header: 'Rule',
                            sortValue: (rule) => rule.name,
                            cell: (rule) => (
                                <span className={cn('grid gap-1 py-1.5', !rule.enabled && 'opacity-60')}>
                                    <span className="font-medium">{rule.name}</span>
                                    <span className="flex max-w-md flex-wrap gap-1">
                                        <SeverityTag severity={rule.min_severity} />
                                        {rule.event_types.map((pattern) => (
                                            <Tag key={pattern}>{labelFor(pattern)}</Tag>
                                        ))}
                                    </span>
                                </span>
                            ),
                        },
                        {
                            id: 'channels',
                            header: 'Delivers to',
                            hideOnMobile: true,
                            cell: (rule) =>
                                rule.channels.length === 0 ? (
                                    <span className="text-fg-faint">In-app only</span>
                                ) : (
                                    <span className="flex flex-wrap gap-1">
                                        {rule.channels.map((channel) => (
                                            <Tag key={channel.id} icon={<IntegrationIcon name={channel.type} size={12} />}>
                                                {channel.name}
                                            </Tag>
                                        ))}
                                    </span>
                                ),
                        },
                        {
                            id: 'limits',
                            header: 'Limits',
                            hideOnMobile: true,
                            cell: (rule) => (
                                <span className="text-fg-muted grid gap-0.5 text-xs">
                                    {rule.quiet_hours && (
                                        <span className="flex items-center gap-1">
                                            <Moon className="size-3" aria-hidden /> {rule.quiet_hours.start}–{rule.quiet_hours.end}
                                        </span>
                                    )}
                                    {rule.rate_limit_per_hour && (
                                        <span className="flex items-center gap-1">
                                            <Gauge className="size-3" aria-hidden /> ≤ {rule.rate_limit_per_hour}/h
                                        </span>
                                    )}
                                    {!rule.quiet_hours && !rule.rate_limit_per_hour && <span className="text-fg-faint">—</span>}
                                </span>
                            ),
                        },
                    ]}
                    rowActions={
                        can.manage
                            ? (rule) => [
                                  { label: 'Edit', icon: <Pencil />, onSelect: () => startEdit(rule) },
                                  { type: 'separator' },
                                  { label: 'Delete', icon: <Trash2 />, danger: true, onSelect: () => setDeleting(rule) },
                              ]
                            : undefined
                    }
                />
            )}

            {rules.length > 0 && channels.length > 0 && (
                <Section title="Routing" description="Which rule delivers to which channel. Click a cell to route or unroute; changes apply immediately." bare>
                    <div className="border-border bg-surface-1 overflow-x-auto rounded-lg border">
                        <table className="w-full border-collapse text-xs">
                            <caption className="sr-only">Rule to channel routing</caption>
                            <thead>
                                <tr className="border-border border-b">
                                    <th scope="col" className="text-fg-faint h-9 px-3 text-left font-medium">
                                        Rule
                                    </th>
                                    {channels.map((channel) => (
                                        <th key={channel.id} scope="col" className="text-fg-muted h-9 px-2 font-medium whitespace-nowrap">
                                            <span className="inline-flex items-center gap-1.5">
                                                <IntegrationIcon name={channel.type} size={12} />
                                                <span className={cn(!channel.enabled && 'line-through opacity-60')}>{channel.name}</span>
                                            </span>
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {rules.map((rule) => {
                                    const routed = rule.channels.map((channel) => channel.id);

                                    return (
                                        <tr key={rule.id} className="border-border border-b last:border-0">
                                            <th scope="row" className={cn('text-fg h-10 px-3 text-left text-sm font-medium', !rule.enabled && 'opacity-60')}>
                                                {rule.name}
                                            </th>
                                            {channels.map((channel) => {
                                                const key = `${rule.id}:${channel.id}`;
                                                const on = routed.includes(channel.id);

                                                return (
                                                    <td key={channel.id} className="px-2 text-center">
                                                        <Checkbox
                                                            checked={on}
                                                            disabled={!can.manage || pending === key}
                                                            onCheckedChange={() => update(rule, key, { channel_ids: toggle(routed, channel.id) })}
                                                            aria-label={`${on ? 'Stop routing' : 'Route'} ${rule.name} to ${channel.name}`}
                                                            className="mx-auto"
                                                        />
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </Section>
            )}

            <Dialog
                open={open}
                onOpenChange={setOpen}
                size="lg"
                title={editing ? `Edit ${editing.name}` : 'Add alert rule'}
                description="Matching alerts go to the selected channels and to members' notification centers."
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" form="rule-form" loading={form.processing}>
                            {editing ? 'Save changes' : 'Add rule'}
                        </Button>
                    </>
                }
            >
                <form id="rule-form" onSubmit={submit} className="grid gap-5">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Name" error={errors.name} required>
                            <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="Production incidents" />
                        </Field>
                        <Field label="Minimum severity" error={errors.min_severity}>
                            <Select
                                value={form.data.min_severity}
                                onValueChange={(value) => form.setData('min_severity', value)}
                                options={severities.map((severity) => ({ value: severity.value, label: severity.label }))}
                            />
                        </Field>
                    </div>

                    <fieldset className="grid gap-2">
                        <legend className="text-fg mb-1 text-xs font-medium">Events</legend>
                        <Field inline label="Everything, including future event types">
                            <Checkbox checked={everything} onCheckedChange={() => form.setData('event_types', everything ? [] : ['*'])} />
                        </Field>
                        {!everything && (
                            <div className="grid gap-2 sm:grid-cols-2">
                                {groups.map(([group, types]) => {
                                    const wildcard = `${types[0].type.split('.')[0]}.*`;
                                    const all = form.data.event_types.includes(wildcard);

                                    return (
                                        <div key={group} className="border-border grid content-start gap-2 rounded-md border p-3">
                                            <Field inline label={<span className="capitalize">All {group}</span>}>
                                                <Checkbox
                                                    checked={all}
                                                    onCheckedChange={() =>
                                                        form.setData(
                                                            'event_types',
                                                            all
                                                                ? form.data.event_types.filter((item) => item !== wildcard)
                                                                : [...form.data.event_types.filter((item) => !types.some((type) => type.type === item)), wildcard],
                                                        )
                                                    }
                                                />
                                            </Field>
                                            {!all && (
                                                <div className="grid gap-1.5 pl-6">
                                                    {types.map((type) => (
                                                        <Field key={type.type} inline label={<span className="font-normal">{type.label}</span>}>
                                                            <Checkbox
                                                                checked={form.data.event_types.includes(type.type)}
                                                                onCheckedChange={() => form.setData('event_types', toggle(form.data.event_types, type.type))}
                                                            />
                                                        </Field>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                        {(errors.event_types ?? errors['event_types.0']) && (
                            <p className="text-danger text-xs" role="alert">
                                {errors.event_types ?? errors['event_types.0']}
                            </p>
                        )}
                    </fieldset>

                    <fieldset className="grid gap-2">
                        <legend className="text-fg mb-1 text-xs font-medium">Channels</legend>
                        {channels.length === 0 ? (
                            <p className="text-fg-muted text-sm">
                                No channels yet — this rule will only notify in-app.{' '}
                                <Link href={route('alerting.channels.index')} className="text-primary hover:underline">
                                    Add a channel
                                </Link>
                            </p>
                        ) : (
                            <div className="grid gap-1.5 sm:grid-cols-2">
                                {channels.map((channel) => (
                                    <Field
                                        key={channel.id}
                                        inline
                                        label={
                                            <span className="inline-flex items-center gap-1.5 font-normal">
                                                <IntegrationIcon name={channel.type} size={12} />
                                                {channel.name}
                                                {!channel.enabled && <span className="text-fg-faint">(disabled)</span>}
                                            </span>
                                        }
                                    >
                                        <Checkbox
                                            checked={form.data.channel_ids.includes(channel.id)}
                                            onCheckedChange={() => form.setData('channel_ids', toggle(form.data.channel_ids, channel.id))}
                                        />
                                    </Field>
                                ))}
                            </div>
                        )}
                        {(errors.channel_ids ?? Object.entries(errors).find(([key]) => key.startsWith('channel_ids.'))?.[1]) && (
                            <p className="text-danger text-xs" role="alert">
                                {errors.channel_ids ?? Object.entries(errors).find(([key]) => key.startsWith('channel_ids.'))?.[1]}
                            </p>
                        )}
                    </fieldset>

                    <fieldset className="border-border grid gap-3 rounded-md border p-3">
                        <legend className="sr-only">Quiet hours</legend>
                        <Field inline label="Quiet hours" hint="Hold channel notifications during these hours (in-app notifications still arrive).">
                            <Switch checked={quiet.enabled} onCheckedChange={(enabled) => setQuiet({ enabled })} />
                        </Field>
                        {quiet.enabled && (
                            <>
                                <div className="grid gap-3 sm:grid-cols-[8rem_8rem_minmax(0,1fr)]">
                                    <Field label="From" error={errors['quiet_hours.start']}>
                                        <Input type="time" value={quiet.start} onChange={(event) => setQuiet({ start: event.target.value })} />
                                    </Field>
                                    <Field label="Until" error={errors['quiet_hours.end']}>
                                        <Input type="time" value={quiet.end} onChange={(event) => setQuiet({ end: event.target.value })} />
                                    </Field>
                                    <Field
                                        label="Timezone"
                                        error={errors['quiet_hours.timezone'] ?? (quiet.timezone && !timezones.includes(quiet.timezone) ? 'Unknown timezone.' : undefined)}
                                    >
                                        <Input list="alerting-timezones" value={quiet.timezone} onChange={(event) => setQuiet({ timezone: event.target.value })} />
                                    </Field>
                                    <datalist id="alerting-timezones">
                                        {timezones.map((timezone) => (
                                            <option key={timezone} value={timezone} />
                                        ))}
                                    </datalist>
                                </div>
                                <div className="grid gap-1.5">
                                    <span className="text-fg text-xs font-medium">Days</span>
                                    <div className="flex flex-wrap gap-1" role="group" aria-label="Days">
                                        {DAYS.map((day, index) => {
                                            const on = quiet.days.includes(index + 1);

                                            return (
                                                <button
                                                    key={day}
                                                    type="button"
                                                    aria-pressed={on}
                                                    onClick={() => setQuiet({ days: toggle(quiet.days, index + 1) })}
                                                    className={cn(
                                                        'h-7 w-11 rounded-md border text-xs font-medium transition-colors duration-150',
                                                        on ? 'border-primary bg-primary-soft text-fg' : 'border-border bg-surface-2 text-fg-muted hover:text-fg',
                                                    )}
                                                >
                                                    {day}
                                                </button>
                                            );
                                        })}
                                    </div>
                                    <p className="text-fg-faint text-xs">{quiet.days.length === 0 ? 'No days selected: every day.' : ''}</p>
                                </div>
                                <Field inline label="Critical alerts still go through">
                                    <Checkbox checked={quiet.allow_critical} onCheckedChange={(checked) => setQuiet({ allow_critical: checked === true })} />
                                </Field>
                            </>
                        )}
                    </fieldset>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Rate limit" hint="Max. channel notifications per hour; empty = unlimited." error={rateError}>
                            <Input
                                type="number"
                                min={1}
                                max={1000}
                                placeholder="Unlimited"
                                value={rate}
                                onChange={(event) => form.setData('rate_limit_per_hour', event.target.value)}
                                suffix={<span className="text-xs">/ hour</span>}
                            />
                        </Field>
                        <Field inline label="Enabled" className="self-center">
                            <Switch checked={form.data.enabled} onCheckedChange={(enabled) => form.setData('enabled', enabled)} />
                        </Field>
                    </div>
                </form>
            </Dialog>

            <ConfirmDestructive
                open={deleting !== null}
                onOpenChange={(value) => !value && setDeleting(null)}
                title={`Delete ${deleting?.name ?? ''}`}
                description="Alerts matched only by this rule will no longer be delivered to channels."
                confirmText={deleting?.name ?? ''}
                confirmLabel="Delete rule"
                onConfirm={destroy}
            />
        </SettingsLayout>
    );
}

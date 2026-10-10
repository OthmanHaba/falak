import { Button } from '@/components/falak/button';
import { ConfirmDestructive } from '@/components/falak/confirm-destructive';
import { DataTable } from '@/components/falak/data-table';
import { Dialog } from '@/components/falak/dialog';
import { EmptyState } from '@/components/falak/empty-state';
import { Field } from '@/components/falak/field';
import { Input, Textarea } from '@/components/falak/input';
import { IntegrationTile } from '@/components/falak/integration-icon';
import { RelativeTime } from '@/components/falak/relative-time';
import { SecretInput } from '@/components/falak/secret-input';
import { StatusBadge } from '@/components/falak/status';
import { Switch } from '@/components/falak/switch';
import { Tag } from '@/components/falak/tag';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { Link, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Send, Star, Trash2 } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';
import { jsonRequest } from '../components/alerting-ui';
import { type ChannelRow, type ChannelType } from '../types';

interface Props {
    channels: ChannelRow[];
    types: { value: ChannelType; label: string }[];
    can: { manage: boolean };
}

interface FieldSpec {
    key: string;
    label: string;
    placeholder?: string;
    help?: string;
    multiline?: boolean;
    secret?: boolean;
    /** Inline format check while typing (the server re-validates). */
    check?: (value: string) => string | undefined;
}

const url = (prefix: string) => (value: string) => (value && !value.startsWith(prefix) ? `Should start with ${prefix}` : undefined);

const FIELDS: Record<ChannelType, FieldSpec[]> = {
    email: [
        {
            key: 'recipients',
            label: 'Recipients',
            placeholder: 'ops@example.com, oncall@example.com',
            help: 'Comma or newline separated.',
            multiline: true,
            check: (value) => {
                const bad = value
                    .split(/[\s,;]+/)
                    .filter(Boolean)
                    .find((item) => !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(item));

                return bad ? `“${bad}” is not an email address.` : undefined;
            },
        },
    ],
    slack: [
        {
            key: 'webhook_url',
            label: 'Incoming webhook URL',
            placeholder: 'https://hooks.slack.com/services/…',
            help: 'Slack → Apps → Incoming Webhooks → Add to channel.',
            secret: true,
            check: url('https://hooks.slack.com/'),
        },
    ],
    discord: [
        {
            key: 'webhook_url',
            label: 'Webhook URL',
            placeholder: 'https://discord.com/api/webhooks/…',
            help: 'Channel settings → Integrations → Webhooks.',
            secret: true,
            check: url('https://'),
        },
    ],
    telegram: [
        { key: 'bot_token', label: 'Bot token', placeholder: '123456:ABC…', help: 'From @BotFather.', secret: true },
        { key: 'chat_id', label: 'Chat ID', placeholder: '-1001234567890 or @channel' },
    ],
    webhook: [
        { key: 'url', label: 'URL', placeholder: 'https://example.com/falak-alerts', secret: true, check: url('https://') },
        {
            key: 'secret',
            label: 'Signing secret',
            placeholder: 'at least 16 characters',
            help: 'Requests carry X-Falak-Signature: sha256=HMAC(secret, "<X-Falak-Timestamp>.<body>").',
            secret: true,
            check: (value) => (value && value.length < 16 ? 'Use at least 16 characters.' : undefined),
        },
    ],
};

const TYPE_HINTS: Record<ChannelType, string> = {
    email: 'Mail to people',
    slack: 'Post to a channel',
    discord: 'Post to a channel',
    telegram: 'Message a chat',
    webhook: 'Signed JSON POST',
};

interface ChannelForm {
    name: string;
    type: ChannelType;
    enabled: boolean;
    config: Record<string, string>;
}

type TestResult = { ok: boolean; message: string; pending?: boolean };

function describeTarget(channel: ChannelRow): string {
    const config = channel.config;

    switch (channel.type) {
        case 'email':
            return Array.isArray(config.recipients) ? config.recipients.join(', ') : '';
        case 'telegram':
            return `chat ${String(config.chat_id ?? '')}`;
        case 'webhook':
            return String(config.url ?? '');
        default:
            return String(config.webhook_url ?? '');
    }
}

export default function Channels({ channels, types, can }: Props) {
    const [editing, setEditing] = useState<ChannelRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<ChannelRow | null>(null);
    const [tests, setTests] = useState<Record<string, TestResult>>({});
    const form = useForm<ChannelForm>({ name: '', type: 'slack', enabled: true, config: {} });

    const startCreate = (type: ChannelType = 'slack') => {
        setEditing(null);
        form.clearErrors();
        form.setData({ name: '', type, enabled: true, config: {} });
        setOpen(true);
    };

    const startEdit = (channel: ChannelRow) => {
        setEditing(channel);
        form.clearErrors();
        const config: Record<string, string> = {};
        FIELDS[channel.type].forEach((field) => {
            const value = channel.config[field.key];
            // Secrets are write-only: blank keeps the stored value.
            config[field.key] = field.secret ? '' : Array.isArray(value) ? value.join(', ') : String(value ?? '');
        });
        form.setData({ name: channel.name, type: channel.type, enabled: channel.enabled, config });
        setOpen(true);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            config: Object.fromEntries(
                Object.entries(data.config).map(([key, value]) => [
                    key,
                    key === 'recipients'
                        ? value
                              .split(/[\s,;]+/)
                              .map((item) => item.trim())
                              .filter(Boolean)
                        : value,
                ]),
            ),
        }));
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };

        if (editing) {
            form.put(route('alerting.channels.update', editing.id), options);
        } else {
            form.post(route('alerting.channels.store'), options);
        }
    };

    const sendTest = async (channel: ChannelRow) => {
        setTests((current) => ({ ...current, [channel.id]: { ok: true, message: 'Sending…', pending: true } }));
        const response = await jsonRequest<{ ok: boolean; error: string | null; message?: string }>(
            'POST',
            route('alerting.channels.test', channel.id),
        );
        const message = response.ok ? 'Test message delivered' : (response.body?.error ?? response.body?.message ?? `HTTP ${response.status}`);
        setTests((current) => ({ ...current, [channel.id]: { ok: response.ok, message } }));
    };

    const destroy = () =>
        new Promise<void>((resolve) => {
            if (!deleting) return resolve();
            router.delete(route('alerting.channels.destroy', deleting.id), {
                preserveScroll: true,
                onSuccess: () => setDeleting(null),
                onFinish: () => resolve(),
            });
        });

    const fieldError = (spec: FieldSpec): string | undefined => {
        const errors = form.errors as Record<string, string | undefined>;

        return (
            errors[`config.${spec.key}`] ??
            Object.entries(errors).find(([name]) => name.startsWith(`config.${spec.key}.`))?.[1] ??
            spec.check?.(form.data.config[spec.key] ?? '')
        );
    };

    const typeLabel = (type: ChannelType) => types.find((item) => item.value === type)?.label ?? type;

    return (
        <SettingsLayout
            title="Alert channels"
            description={
                <>
                    Where alerts are delivered. Secrets are stored encrypted and never shown again.{' '}
                    <Link href={route('alerting.rules.index')} className="text-primary hover:underline">
                        Rules
                    </Link>{' '}
                    decide which alerts reach which channel; the default rules route to the default channel.
                </>
            }
            actions={
                can.manage &&
                channels.length > 0 && (
                    <Button variant="primary" icon={<Plus />} onClick={() => startCreate()}>
                        Add channel
                    </Button>
                )
            }
            wide
        >
            {channels.length === 0 ? (
                <EmptyState
                    icon={<Send />}
                    title="No alert channels yet"
                    description="Without channels, alerts only reach members' notification centers. Add Slack, Discord, Telegram, email or a signed webhook — then send a test message."
                    action={
                        can.manage && (
                            <div className="flex flex-wrap justify-center gap-2">
                                {types.map((type) => (
                                    <Button key={type.value} size="sm" onClick={() => startCreate(type.value)}>
                                        <IntegrationTile name={type.value} size="sm" className="-ml-1 size-5 border-0 bg-transparent" />
                                        {type.label}
                                    </Button>
                                ))}
                            </div>
                        )
                    }
                />
            ) : (
                <DataTable
                    label="Alert channels"
                    rows={channels}
                    rowKey={(channel) => channel.id}
                    columns={[
                        {
                            id: 'name',
                            header: 'Channel',
                            sortValue: (channel) => channel.name,
                            cell: (channel) => (
                                <span className="flex min-w-0 items-center gap-2.5 py-1.5">
                                    <IntegrationTile name={channel.type} size="sm" />
                                    <span className="grid min-w-0">
                                        <span className="flex items-center gap-2">
                                            <span className="truncate font-medium">{channel.name}</span>
                                            {channel.is_default && <Tag tone="info">Default</Tag>}
                                            {!channel.enabled && <Tag tone="faint">Disabled</Tag>}
                                        </span>
                                        <span className="text-fg-faint max-w-[14rem] truncate font-mono text-xs sm:max-w-xs">
                                            {describeTarget(channel)}
                                        </span>
                                    </span>
                                </span>
                            ),
                        },
                        {
                            id: 'rules',
                            header: 'Rules',
                            align: 'right',
                            hideOnMobile: true,
                            sortValue: (channel) => channel.rules_count,
                            cell: (channel) => <span className="text-fg-muted">{channel.rules_count}</span>,
                        },
                        {
                            id: 'delivery',
                            header: 'Last delivery',
                            cell: (channel) => {
                                const test = tests[channel.id];

                                return (
                                    <span className="grid justify-items-start gap-0.5 py-1.5" aria-live="polite">
                                        {test ? (
                                            <>
                                                <StatusBadge
                                                    status={test.pending ? 'running' : test.ok ? 'success' : 'failed'}
                                                    label={test.pending ? 'Sending test' : test.ok ? 'Test delivered' : 'Test failed'}
                                                />
                                                {!test.ok && !test.pending && (
                                                    <span className="text-danger line-clamp-2 max-w-xs text-xs" title={test.message}>
                                                        {test.message}
                                                    </span>
                                                )}
                                            </>
                                        ) : channel.last_error ? (
                                            <>
                                                <StatusBadge status="failed" label="Failing" />
                                                <span className="text-danger line-clamp-2 max-w-xs text-xs" title={channel.last_error}>
                                                    {channel.last_error}
                                                </span>
                                            </>
                                        ) : channel.last_sent_at ? (
                                            <span className="text-fg-muted flex items-center gap-1.5 text-sm">
                                                <StatusBadge status="success" label="OK" />
                                                <RelativeTime value={channel.last_sent_at} className="text-xs" />
                                            </span>
                                        ) : (
                                            <span className="text-fg-faint text-sm">Never</span>
                                        )}
                                    </span>
                                );
                            },
                        },
                        ...(can.manage
                            ? [
                                  {
                                      id: 'test',
                                      hideOnMobile: true,
                                      header: <span className="sr-only">Test</span>,
                                      align: 'right' as const,
                                      cell: (channel: ChannelRow) => (
                                          <Button
                                              size="sm"
                                              variant="ghost"
                                              icon={<Send />}
                                              loading={tests[channel.id]?.pending}
                                              onClick={() => void sendTest(channel)}
                                              aria-label={`Send a test message to ${channel.name}`}
                                          >
                                              <span className="hidden sm:inline">Test</span>
                                          </Button>
                                      ),
                                  },
                              ]
                            : []),
                    ]}
                    rowActions={
                        can.manage
                            ? (channel) => [
                                  { label: 'Send test message', icon: <Send />, onSelect: () => void sendTest(channel) },
                                  { label: 'Edit', icon: <Pencil />, onSelect: () => startEdit(channel) },
                                  ...(channel.is_default
                                      ? []
                                      : [
                                            {
                                                label: 'Make default',
                                                icon: <Star />,
                                                onSelect: () =>
                                                    router.post(route('alerting.channels.default', channel.id), {}, { preserveScroll: true }),
                                            },
                                        ]),
                                  { type: 'separator' as const },
                                  { label: 'Delete', icon: <Trash2 />, danger: true, onSelect: () => setDeleting(channel) },
                              ]
                            : undefined
                    }
                />
            )}

            <Dialog
                open={open}
                onOpenChange={setOpen}
                title={editing ? `Edit ${editing.name}` : 'Add alert channel'}
                description={editing ? 'Secrets are write-only: use Replace to change one.' : 'Save it, then use Test to check delivery.'}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" type="submit" form="channel-form" loading={form.processing}>
                            {editing ? 'Save changes' : 'Add channel'}
                        </Button>
                    </>
                }
            >
                <form id="channel-form" onSubmit={submit} className="grid gap-4">
                    {!editing && (
                        <fieldset className="grid gap-1.5">
                            <legend className="text-fg mb-1.5 text-xs font-medium">Type</legend>
                            <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-3" role="radiogroup" aria-label="Channel type">
                                {types.map((type) => (
                                    <button
                                        key={type.value}
                                        type="button"
                                        role="radio"
                                        aria-checked={form.data.type === type.value}
                                        onClick={() => form.setData({ ...form.data, type: type.value, config: {} })}
                                        className={cn(
                                            'flex items-center gap-2 rounded-md border px-2 py-1.5 text-left transition-colors duration-150',
                                            form.data.type === type.value
                                                ? 'border-primary bg-primary-soft text-fg'
                                                : 'border-border bg-surface-2 text-fg-muted hover:border-border-strong hover:text-fg',
                                        )}
                                    >
                                        <IntegrationTile name={type.value} size="sm" className="bg-surface-1" />
                                        <span className="grid min-w-0">
                                            <span className="truncate text-sm">{type.label}</span>
                                            <span className="text-fg-faint truncate text-xs">{TYPE_HINTS[type.value]}</span>
                                        </span>
                                    </button>
                                ))}
                            </div>
                            {form.errors.type && <p className="text-danger text-xs">{form.errors.type}</p>}
                        </fieldset>
                    )}

                    <Field label="Name" error={form.errors.name} required>
                        <Input
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            placeholder={`#ops ${typeLabel(form.data.type)}`}
                        />
                    </Field>

                    {FIELDS[form.data.type].map((spec) => {
                        const value = form.data.config[spec.key] ?? '';
                        const onChange = (next: string) => form.setData('config', { ...form.data.config, [spec.key]: next });
                        const masked = editing?.config[spec.key];

                        return (
                            <Field key={spec.key} label={spec.label} hint={spec.help} error={fieldError(spec)} required={!editing || !spec.secret}>
                                {spec.secret ? (
                                    <SecretInput
                                        stored={Boolean(editing)}
                                        storedHint={typeof masked === 'string' && masked ? masked : undefined}
                                        value={value}
                                        onChange={onChange}
                                        placeholder={spec.placeholder}
                                    />
                                ) : spec.multiline ? (
                                    <Textarea
                                        value={value}
                                        placeholder={spec.placeholder}
                                        rows={3}
                                        onChange={(event) => onChange(event.target.value)}
                                    />
                                ) : (
                                    <Input mono value={value} placeholder={spec.placeholder} onChange={(event) => onChange(event.target.value)} />
                                )}
                            </Field>
                        );
                    })}

                    <Field inline label="Enabled" hint="Disabled channels keep their rules but receive nothing.">
                        <Switch checked={form.data.enabled} onCheckedChange={(checked) => form.setData('enabled', checked)} />
                    </Field>
                </form>
            </Dialog>

            <ConfirmDestructive
                open={deleting !== null}
                onOpenChange={(value) => !value && setDeleting(null)}
                title={`Delete ${deleting?.name ?? ''}`}
                description={`${deleting?.rules_count ?? 0} rule(s) deliver to this channel; they stop delivering to it.`}
                confirmText={deleting?.name ?? ''}
                confirmLabel="Delete channel"
                onConfirm={destroy}
            />
        </SettingsLayout>
    );
}

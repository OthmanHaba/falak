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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { Pencil, Plus, Send, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { AlertingTabs, jsonRequest } from '../components/alerting-ui';
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
}

const FIELDS: Record<ChannelType, FieldSpec[]> = {
    email: [
        {
            key: 'recipients',
            label: 'Recipients',
            placeholder: 'ops@example.com, oncall@example.com',
            help: 'Comma or newline separated.',
            multiline: true,
        },
    ],
    slack: [{ key: 'webhook_url', label: 'Incoming webhook URL', placeholder: 'https://hooks.slack.com/services/…', secret: true }],
    discord: [{ key: 'webhook_url', label: 'Webhook URL', placeholder: 'https://discord.com/api/webhooks/…', secret: true }],
    telegram: [
        { key: 'bot_token', label: 'Bot token', placeholder: '123456:ABC…', secret: true },
        { key: 'chat_id', label: 'Chat ID', placeholder: '-1001234567890 or @channel' },
    ],
    webhook: [
        { key: 'url', label: 'URL', placeholder: 'https://example.com/kiln-alerts', secret: true },
        {
            key: 'secret',
            label: 'Signing secret',
            placeholder: 'at least 16 characters',
            help: 'Requests carry X-Kiln-Signature: sha256=HMAC(secret, "<X-Kiln-Timestamp>.<body>").',
            secret: true,
        },
    ],
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Alerts', href: '/alerting/rules' },
    { title: 'Channels', href: '/alerting/channels' },
];

interface ChannelForm {
    name: string;
    type: ChannelType;
    enabled: boolean;
    config: Record<string, string>;
}

function emptyForm(type: ChannelType = 'slack'): ChannelForm {
    return { name: '', type, enabled: true, config: {} };
}

export default function Channels({ channels, types, can }: Props) {
    const [editing, setEditing] = useState<ChannelRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<ChannelRow | null>(null);
    const [testResults, setTestResults] = useState<Record<string, { ok: boolean; message: string; pending?: boolean }>>({});
    const form = useForm<ChannelForm>(emptyForm());

    const startCreate = () => {
        setEditing(null);
        form.clearErrors();
        form.setData(emptyForm());
        setOpen(true);
    };

    const startEdit = (channel: ChannelRow) => {
        setEditing(channel);
        form.clearErrors();
        const config: Record<string, string> = {};
        FIELDS[channel.type].forEach((field) => {
            const value = channel.config[field.key];
            // Secrets are masked by the server: leave blank to keep the stored value.
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
        setTestResults((results) => ({ ...results, [channel.id]: { ok: true, message: 'Sending…', pending: true } }));
        const response = await jsonRequest<{ ok: boolean; error: string | null; message?: string }>(
            'POST',
            route('alerting.channels.test', channel.id),
        );
        const message = response.ok ? 'Test message sent.' : (response.body?.error ?? response.body?.message ?? `HTTP ${response.status}`);
        setTestResults((results) => ({ ...results, [channel.id]: { ok: response.ok, message } }));
    };

    const destroy = () => {
        if (!deleting) return;
        router.delete(route('alerting.channels.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) });
    };

    const fieldError = (key: string): string | undefined => {
        const errors = form.errors as Record<string, string | undefined>;

        return errors[`config.${key}`] ?? Object.entries(errors).find(([name]) => name.startsWith(`config.${key}.`))?.[1];
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Alert channels" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Alerts" description="Where alerts are delivered: email, chat and webhooks" />
                    {can.manage && (
                        <Button onClick={startCreate}>
                            <Plus /> Add channel
                        </Button>
                    )}
                </div>

                <AlertingTabs active="/alerting/channels" />

                <Card>
                    <CardContent className="p-0">
                        {channels.length === 0 ? (
                            <p className="text-muted-foreground p-10 text-center text-sm">
                                No channels yet. Add one, then route alerts to it with a rule.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Target</TableHead>
                                        <TableHead>Rules</TableHead>
                                        <TableHead>Last delivery</TableHead>
                                        <TableHead className="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {channels.map((channel) => (
                                        <TableRow key={channel.id}>
                                            <TableCell className="font-medium">
                                                {channel.name}
                                                {!channel.enabled && (
                                                    <Badge variant="secondary" className="ml-2">
                                                        Disabled
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell>{types.find((type) => type.value === channel.type)?.label ?? channel.type}</TableCell>
                                            <TableCell className="text-muted-foreground max-w-xs truncate font-mono text-xs">
                                                {describeTarget(channel)}
                                            </TableCell>
                                            <TableCell>{channel.rules_count}</TableCell>
                                            <TableCell className="text-xs">
                                                {channel.last_error ? (
                                                    <span className="text-destructive" title={channel.last_error}>
                                                        Failed: {channel.last_error}
                                                    </span>
                                                ) : channel.last_sent_at ? (
                                                    <span className="text-muted-foreground">
                                                        {formatDistanceToNow(new Date(channel.last_sent_at), { addSuffix: true })}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">Never</span>
                                                )}
                                                {testResults[channel.id] && (
                                                    <p
                                                        className={testResults[channel.id].ok ? 'mt-1 text-emerald-600' : 'text-destructive mt-1'}
                                                        role="status"
                                                    >
                                                        {testResults[channel.id].message}
                                                    </p>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right whitespace-nowrap">
                                                {can.manage && (
                                                    <>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => void sendTest(channel)}
                                                            disabled={testResults[channel.id]?.pending}
                                                        >
                                                            <Send /> Test
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() => startEdit(channel)}
                                                            aria-label={`Edit ${channel.name}`}
                                                        >
                                                            <Pencil />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() => setDeleting(channel)}
                                                            aria-label={`Delete ${channel.name}`}
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
                <DialogContent>
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>{editing ? `Edit ${editing.name}` : 'Add channel'}</DialogTitle>
                            <DialogDescription>
                                {editing
                                    ? 'Leave secret fields blank to keep the stored values.'
                                    : 'Secrets are stored encrypted and never shown again.'}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-2">
                            <Label htmlFor="channel-name">Name</Label>
                            <Input id="channel-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                            <InputError message={form.errors.name} />
                        </div>

                        {!editing && (
                            <div className="grid gap-2">
                                <Label>Type</Label>
                                <Select
                                    value={form.data.type}
                                    onValueChange={(value) => form.setData({ ...form.data, type: value as ChannelType, config: {} })}
                                >
                                    <SelectTrigger aria-label="Channel type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {types.map((type) => (
                                            <SelectItem key={type.value} value={type.value}>
                                                {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.type} />
                            </div>
                        )}

                        {FIELDS[form.data.type].map((field) => {
                            const id = `channel-${field.key}`;
                            const value = form.data.config[field.key] ?? '';
                            const onChange = (next: string) => form.setData('config', { ...form.data.config, [field.key]: next });
                            const placeholder = editing && field.secret ? 'unchanged' : field.placeholder;

                            return (
                                <div key={field.key} className="grid gap-2">
                                    <Label htmlFor={id}>{field.label}</Label>
                                    {field.multiline ? (
                                        <Textarea
                                            id={id}
                                            value={value}
                                            placeholder={placeholder}
                                            onChange={(e) => onChange(e.target.value)}
                                            rows={3}
                                        />
                                    ) : (
                                        <Input
                                            id={id}
                                            value={value}
                                            placeholder={placeholder}
                                            type={field.secret ? 'password' : 'text'}
                                            autoComplete="off"
                                            onChange={(e) => onChange(e.target.value)}
                                        />
                                    )}
                                    {field.help && <p className="text-muted-foreground text-xs">{field.help}</p>}
                                    <InputError message={fieldError(field.key)} />
                                </div>
                            );
                        })}

                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="channel-enabled"
                                checked={form.data.enabled}
                                onCheckedChange={(checked) => form.setData('enabled', checked === true)}
                            />
                            <Label htmlFor="channel-enabled">Enabled</Label>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {editing ? 'Save' : 'Add channel'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={deleting !== null} onOpenChange={(value) => !value && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete {deleting?.name}?</DialogTitle>
                        <DialogDescription>Rules using this channel will stop delivering to it.</DialogDescription>
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

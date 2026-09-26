import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import SiteLayout from '@/layouts/site-layout';
import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';
import { type RoutingPageProps } from '../types';

const STATUSES = ['301', '302', '307', '308'];
const MB = 1024 * 1024;

function DeleteButton({ label, href }: { label: string; href: string }) {
    return (
        <Button
            variant="ghost"
            size="icon"
            aria-label={label}
            onClick={() => {
                if (window.confirm(`${label}?`)) {
                    router.delete(href, { preserveScroll: true });
                }
            }}
        >
            <Trash2 />
        </Button>
    );
}

function Redirects({ site, redirects, can }: RoutingPageProps) {
    const form = useForm({ from: '', to: '', status: '301' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, status: Number(data.status) }));
        form.post(route('edge.redirects.store', site.id), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Redirects</CardTitle>
                <CardDescription>
                    Evaluated in order before the application. Paths may use wildcards, e.g. <code className="font-mono">/blog/*</code>.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {redirects.length > 0 && (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>From</TableHead>
                                <TableHead>To</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-12" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {redirects.map((redirect) => (
                                <TableRow key={redirect.id}>
                                    <TableCell className="font-mono text-xs">{redirect.from}</TableCell>
                                    <TableCell className="font-mono text-xs break-all">{redirect.to}</TableCell>
                                    <TableCell>{redirect.status}</TableCell>
                                    <TableCell>
                                        {can.manage && (
                                            <DeleteButton
                                                label={`Delete redirect ${redirect.from}`}
                                                href={route('edge.redirects.destroy', [site.id, redirect.id])}
                                            />
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
                {redirects.length === 0 && <p className="text-muted-foreground text-sm">No redirects.</p>}
                {can.manage && (
                    <form onSubmit={submit} className="grid gap-2 sm:grid-cols-[1fr_1fr_7rem_auto] sm:items-start">
                        <div className="grid gap-1">
                            <Input
                                aria-label="From path"
                                placeholder="/old-path"
                                value={form.data.from}
                                onChange={(e) => form.setData('from', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.from} />
                        </div>
                        <div className="grid gap-1">
                            <Input
                                aria-label="Target"
                                placeholder="/new-path or https://…"
                                value={form.data.to}
                                onChange={(e) => form.setData('to', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.to} />
                        </div>
                        <Select value={form.data.status} onValueChange={(value) => form.setData('status', value)}>
                            <SelectTrigger aria-label="Status code">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {STATUSES.map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {status}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Button type="submit" disabled={form.processing}>
                            Add
                        </Button>
                    </form>
                )}
            </CardContent>
        </Card>
    );
}

function SecurityRules({ site, rules, can }: RoutingPageProps) {
    const form = useForm({ path: '', username: '', password: '', name: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('edge.security-rules.store', site.id), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Basic authentication</CardTitle>
                <CardDescription>
                    Protect the whole site (leave the path empty) or specific paths such as /admin/*. Passwords are stored as bcrypt hashes.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {rules.length === 0 && <p className="text-muted-foreground text-sm">No protected paths.</p>}
                {rules.map((rule) => (
                    <div key={rule.id} className="flex items-center gap-3 rounded-md border p-2">
                        <code className="font-mono text-xs">{rule.path ?? '/* (whole site)'}</code>
                        <span className="flex-1 text-sm">
                            {rule.username}
                            {rule.name && <span className="text-muted-foreground"> · {rule.name}</span>}
                        </span>
                        {can.manage && (
                            <DeleteButton
                                label={`Delete ${rule.username} on ${rule.path ?? '/*'}`}
                                href={route('edge.security-rules.destroy', [site.id, rule.id])}
                            />
                        )}
                    </div>
                ))}
                {can.manage && (
                    <form onSubmit={submit} className="grid gap-2 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-start">
                        <div className="grid gap-1">
                            <Input
                                aria-label="Path"
                                placeholder="/admin/* (empty = whole site)"
                                value={form.data.path}
                                onChange={(e) => form.setData('path', e.target.value)}
                            />
                            <InputError message={form.errors.path} />
                        </div>
                        <div className="grid gap-1">
                            <Input
                                aria-label="Username"
                                placeholder="Username"
                                value={form.data.username}
                                onChange={(e) => form.setData('username', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.username} />
                        </div>
                        <div className="grid gap-1">
                            <Input
                                aria-label="Password"
                                type="password"
                                autoComplete="new-password"
                                placeholder="Password"
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.password} />
                        </div>
                        <Button type="submit" disabled={form.processing}>
                            Protect
                        </Button>
                    </form>
                )}
            </CardContent>
        </Card>
    );
}

function Headers({ site, headers, can }: RoutingPageProps) {
    const form = useForm({ name: '', value: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('edge.headers.store', site.id), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Response headers</CardTitle>
                <CardDescription>
                    Set on every response (e.g. Strict-Transport-Security, Content-Security-Policy). Saving an existing name replaces it.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {headers.length === 0 && <p className="text-muted-foreground text-sm">No custom headers.</p>}
                {headers.map((header) => (
                    <div key={header.id} className="flex items-center gap-3 rounded-md border p-2">
                        <code className="font-mono text-xs font-semibold">{header.name}</code>
                        <code className="text-muted-foreground min-w-0 flex-1 truncate font-mono text-xs">{header.value}</code>
                        {can.manage && (
                            <DeleteButton label={`Delete header ${header.name}`} href={route('edge.headers.destroy', [site.id, header.id])} />
                        )}
                    </div>
                ))}
                {can.manage && (
                    <form onSubmit={submit} className="grid gap-2 sm:grid-cols-[1fr_2fr_auto] sm:items-start">
                        <div className="grid gap-1">
                            <Input
                                aria-label="Header name"
                                placeholder="X-Frame-Options"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid gap-1">
                            <Input
                                aria-label="Header value"
                                placeholder="DENY"
                                value={form.data.value}
                                onChange={(e) => form.setData('value', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.value} />
                        </div>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </form>
                )}
            </CardContent>
        </Card>
    );
}

const lines = (value: string) =>
    value
        .split(/[\n,]/)
        .map((line) => line.trim())
        .filter(Boolean);

function Settings({ site, settings, behindLoadBalancer, can }: RoutingPageProps) {
    const form = useForm({
        allow_ips: settings.allow_ips.join('\n'),
        deny_ips: settings.deny_ips.join('\n'),
        max_body_mb: settings.max_body_bytes ? String(Math.round((settings.max_body_bytes / MB) * 100) / 100) : '',
        encode: settings.encode,
    });

    const errors = form.errors as Record<string, string | undefined>;
    const firstError = (prefix: string) => Object.entries(errors).find(([key]) => key.startsWith(prefix))?.[1];

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            allow_ips: lines(data.allow_ips),
            deny_ips: lines(data.deny_ips),
            max_body_bytes: data.max_body_mb === '' ? null : Math.round(Number(data.max_body_mb) * MB),
            encode: data.encode,
        }));
        form.put(route('edge.settings.update', site.id), { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Access &amp; limits</CardTitle>
                <CardDescription>
                    IP rules accept addresses or CIDR ranges, one per line. With an allow list, every other client gets 403.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {behindLoadBalancer && (
                    <Alert className="mb-4">
                        <AlertDescription>
                            This site is behind a load balancer: redirects, authentication and IP rules are enforced there.
                        </AlertDescription>
                    </Alert>
                )}
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="allow-ips">Allow only</Label>
                            <Textarea
                                id="allow-ips"
                                rows={4}
                                className="font-mono text-xs"
                                placeholder="203.0.113.0/24"
                                value={form.data.allow_ips}
                                onChange={(e) => form.setData('allow_ips', e.target.value)}
                                disabled={!can.manage}
                            />
                            <InputError message={firstError('allow_ips')} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="deny-ips">Deny</Label>
                            <Textarea
                                id="deny-ips"
                                rows={4}
                                className="font-mono text-xs"
                                placeholder="198.51.100.7"
                                value={form.data.deny_ips}
                                onChange={(e) => form.setData('deny_ips', e.target.value)}
                                disabled={!can.manage}
                            />
                            <InputError message={firstError('deny_ips')} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="max-body">Max request body (MB)</Label>
                            <Input
                                id="max-body"
                                type="number"
                                min={0}
                                step="0.1"
                                placeholder="Unlimited"
                                value={form.data.max_body_mb}
                                onChange={(e) => form.setData('max_body_mb', e.target.value)}
                                disabled={!can.manage}
                            />
                            <InputError message={errors.max_body_bytes} />
                        </div>
                        <div className="flex items-center gap-2 pt-6">
                            <Checkbox
                                id="encode"
                                checked={form.data.encode}
                                onCheckedChange={(checked) => form.setData('encode', checked === true)}
                                disabled={!can.manage}
                            />
                            <Label htmlFor="encode">Compress responses (zstd, gzip)</Label>
                        </div>
                    </div>
                    {can.manage && (
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    )}
                </form>
            </CardContent>
        </Card>
    );
}

export default function Routing(props: RoutingPageProps) {
    return (
        <SiteLayout site={props.site} title="Routing">
            <Redirects {...props} />
            <SecurityRules {...props} />
            <Headers {...props} />
            <Settings {...props} />
        </SiteLayout>
    );
}

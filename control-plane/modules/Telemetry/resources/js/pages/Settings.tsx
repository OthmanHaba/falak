import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { ExternalLink, RefreshCw } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface SettingsValues {
    otlp_endpoint: string | null;
    otlp_token_set: boolean;
    environment: string | null;
    traces_ratio: number | null;
    metrics_interval_s: number | null;
}

interface Props {
    settings: SettingsValues;
    defaults: { otlp_endpoint: string; otlp_token_set: boolean; environment: string; traces_ratio: number; metrics_interval_s: number };
    backends: {
        metrics: { backend: string; configured: boolean };
        loki: { configured: boolean };
        tempo: { configured: boolean };
        grafana: { configured: boolean; provisioned_at: string | null; last_error: string | null; dashboards: { uid: string; url: string | null }[] };
    };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Telemetry settings', href: '/telemetry/settings' }];

function Status({ ok, label }: { ok: boolean; label: string }) {
    return (
        <div className="flex items-center justify-between gap-2 py-1 text-sm">
            <span>{label}</span>
            <Badge variant={ok ? 'secondary' : 'outline'}>{ok ? 'configured' : 'not configured'}</Badge>
        </div>
    );
}

export default function Settings({ settings, defaults, backends, can }: Props) {
    const { errors: pageErrors, flash } = usePage<{ errors: Record<string, string>; flash?: { status?: string } }>().props;
    const [provisioning, setProvisioning] = useState(false);
    const form = useForm({
        otlp_endpoint: settings.otlp_endpoint ?? '',
        otlp_token: '',
        clear_otlp_token: false,
        environment: settings.environment ?? '',
        traces_ratio: settings.traces_ratio?.toString() ?? '',
        metrics_interval_s: settings.metrics_interval_s?.toString() ?? '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put('/telemetry/settings', { preserveScroll: true, onSuccess: () => form.reset('otlp_token', 'clear_otlp_token') });
    };

    const provision = () => {
        setProvisioning(true);
        router.post('/telemetry/grafana/provision', {}, { preserveScroll: true, onFinish: () => setProvisioning(false) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Telemetry settings" />
            <div className="space-y-6 p-4">
                <Heading title="Telemetry" description="Where agents ship OTLP traces, logs and metrics, and how Grafana is provisioned" />
                {flash?.status && <p className="text-sm text-emerald-600">{flash.status}</p>}
                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Agent pipeline</CardTitle>
                            <CardDescription>Saving pushes telemetry.configure to every active server of this organization.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="space-y-4">
                                <div className="space-y-1">
                                    <Label htmlFor="otlp_endpoint">OTLP/HTTP endpoint</Label>
                                    <Input
                                        id="otlp_endpoint"
                                        value={form.data.otlp_endpoint}
                                        onChange={(e) => form.setData('otlp_endpoint', e.target.value)}
                                        placeholder={defaults.otlp_endpoint}
                                        disabled={!can.manage}
                                    />
                                    <InputError message={form.errors.otlp_endpoint} />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="otlp_token">Bearer token</Label>
                                    <Input
                                        id="otlp_token"
                                        type="password"
                                        autoComplete="new-password"
                                        value={form.data.otlp_token}
                                        onChange={(e) => form.setData('otlp_token', e.target.value)}
                                        placeholder={
                                            settings.otlp_token_set
                                                ? '•••••••• (set — leave blank to keep)'
                                                : defaults.otlp_token_set
                                                  ? 'Using the installation default'
                                                  : 'Not set'
                                        }
                                        disabled={!can.manage}
                                    />
                                    {settings.otlp_token_set && can.manage && (
                                        <label className="text-muted-foreground flex items-center gap-2 text-xs">
                                            <Checkbox
                                                checked={form.data.clear_otlp_token}
                                                onCheckedChange={(c) => form.setData('clear_otlp_token', c === true)}
                                            />
                                            Remove the organization token
                                        </label>
                                    )}
                                    <InputError message={form.errors.otlp_token} />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-1">
                                        <Label htmlFor="environment">Environment</Label>
                                        <Input
                                            id="environment"
                                            value={form.data.environment}
                                            onChange={(e) => form.setData('environment', e.target.value)}
                                            placeholder={defaults.environment}
                                            disabled={!can.manage}
                                        />
                                        <InputError message={form.errors.environment} />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="traces_ratio">Trace sampling (0–1)</Label>
                                        <Input
                                            id="traces_ratio"
                                            type="number"
                                            step="0.01"
                                            min={0}
                                            max={1}
                                            value={form.data.traces_ratio}
                                            onChange={(e) => form.setData('traces_ratio', e.target.value)}
                                            placeholder={String(defaults.traces_ratio)}
                                            disabled={!can.manage}
                                        />
                                        <InputError message={form.errors.traces_ratio} />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="metrics_interval_s">Metrics interval (s)</Label>
                                        <Input
                                            id="metrics_interval_s"
                                            type="number"
                                            min={5}
                                            value={form.data.metrics_interval_s}
                                            onChange={(e) => form.setData('metrics_interval_s', e.target.value)}
                                            placeholder={String(defaults.metrics_interval_s)}
                                            disabled={!can.manage}
                                        />
                                        <InputError message={form.errors.metrics_interval_s} />
                                    </div>
                                </div>
                                {can.manage && (
                                    <Button type="submit" disabled={form.processing}>
                                        Save and reconfigure servers
                                    </Button>
                                )}
                            </form>
                        </CardContent>
                    </Card>
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Backends</CardTitle>
                                <CardDescription>Installation-wide (KILN_* environment variables).</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Status ok={backends.metrics.configured} label={`Metrics (${backends.metrics.backend})`} />
                                <Status ok={backends.loki.configured} label="Logs (Loki)" />
                                <Status ok={backends.tempo.configured} label="Traces (Tempo)" />
                                <Status ok={backends.grafana.configured} label="Grafana" />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Grafana</CardTitle>
                                <CardDescription>
                                    {backends.grafana.provisioned_at
                                        ? `Provisioned ${formatDistanceToNow(new Date(backends.grafana.provisioned_at), { addSuffix: true })}`
                                        : 'Not provisioned yet'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {backends.grafana.last_error && <p className="text-destructive text-xs">{backends.grafana.last_error}</p>}
                                <InputError message={pageErrors.grafana} />
                                <ul className="space-y-1 text-sm">
                                    {backends.grafana.dashboards.map((dashboard) => (
                                        <li key={dashboard.uid}>
                                            {dashboard.url ? (
                                                <a
                                                    href={dashboard.url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="inline-flex items-center gap-1 hover:underline"
                                                >
                                                    {dashboard.uid} <ExternalLink className="size-3" />
                                                </a>
                                            ) : (
                                                dashboard.uid
                                            )}
                                        </li>
                                    ))}
                                </ul>
                                {can.manage && (
                                    <Button variant="outline" onClick={provision} disabled={!backends.grafana.configured || provisioning}>
                                        <RefreshCw className={provisioning ? 'animate-spin' : undefined} /> Provision dashboards
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

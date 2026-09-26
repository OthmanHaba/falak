import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SiteLayout from '@/layouts/site-layout';
import { router, useForm } from '@inertiajs/react';
import { Copy, RefreshCw } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { type SitePageProps } from '../types';

interface Settings {
    strategy: string;
    batch_size: number;
    keep_releases: number;
    health_enabled: boolean;
    health_path: string | null;
    health_status: number;
    health_timeout_s: number;
    health_retries: number;
    health_retry_delay_s: number;
}

interface Props extends SitePageProps {
    settings: Settings;
    defaultHealthPath: string;
    strategies: { value: string; label: string; description: string }[];
    pushToDeploy: boolean;
    hasRepository: boolean;
    branch: string | null;
    hookUrl: string | null;
    hasHook: boolean;
    can: { manage: boolean };
}

function NumberField({
    id,
    label,
    value,
    onChange,
    error,
    disabled,
}: {
    id: string;
    label: string;
    value: number;
    onChange: (v: number) => void;
    error?: string;
    disabled: boolean;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Input id={id} type="number" value={value} onChange={(e) => onChange(Number(e.target.value))} disabled={disabled} />
            <InputError message={error} />
        </div>
    );
}

export default function DeploySettings({
    site,
    settings,
    defaultHealthPath,
    strategies,
    pushToDeploy,
    hasRepository,
    branch,
    hookUrl,
    hasHook,
    can,
}: Props) {
    const form = useForm<Settings>({ ...settings, health_path: settings.health_path ?? '' });
    const [copied, setCopied] = useState(false);
    const disabled = !can.manage;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(`/sites/${site.id}/deploy-settings`, { preserveScroll: true });
    };

    const copy = async () => {
        if (hookUrl) {
            await navigator.clipboard.writeText(hookUrl);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        }
    };

    return (
        <SiteLayout site={site} title="Deploy settings">
            <form onSubmit={submit} className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Strategy</CardTitle>
                        <CardDescription>How releases reach your servers.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {strategies.map((strategy) => (
                            <label key={strategy.value} className="has-[:checked]:border-primary flex cursor-pointer gap-3 rounded-md border p-3">
                                <input
                                    type="radio"
                                    name="strategy"
                                    value={strategy.value}
                                    checked={form.data.strategy === strategy.value}
                                    onChange={() => form.setData('strategy', strategy.value)}
                                    disabled={disabled}
                                    className="mt-1"
                                />
                                <span>
                                    <span className="font-medium">{strategy.label}</span>
                                    <span className="text-muted-foreground block text-sm">{strategy.description}</span>
                                </span>
                            </label>
                        ))}
                        <InputError message={form.errors.strategy} />
                        {form.data.strategy === 'rolling' && (
                            <NumberField
                                id="batch_size"
                                label="Servers per batch"
                                value={form.data.batch_size}
                                onChange={(v) => form.setData('batch_size', v)}
                                error={form.errors.batch_size}
                                disabled={disabled}
                            />
                        )}
                        <NumberField
                            id="keep_releases"
                            label="Releases to keep"
                            value={form.data.keep_releases}
                            onChange={(v) => form.setData('keep_releases', v)}
                            error={form.errors.keep_releases}
                            disabled={disabled}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Health check</CardTitle>
                        <CardDescription>
                            After activation the control plane requests every server; a failure rolls the deployment back.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.health_enabled}
                                onCheckedChange={(checked) => form.setData('health_enabled', checked === true)}
                                disabled={disabled}
                            />
                            Check health after activation
                        </label>
                        <div className="grid gap-1.5">
                            <Label htmlFor="health_path">Path</Label>
                            <Input
                                id="health_path"
                                value={form.data.health_path ?? ''}
                                placeholder={defaultHealthPath}
                                onChange={(e) => form.setData('health_path', e.target.value)}
                                disabled={disabled}
                            />
                            <InputError message={form.errors.health_path} />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <NumberField
                                id="health_status"
                                label="Expected status"
                                value={form.data.health_status}
                                onChange={(v) => form.setData('health_status', v)}
                                error={form.errors.health_status}
                                disabled={disabled}
                            />
                            <NumberField
                                id="health_timeout_s"
                                label="Timeout (s)"
                                value={form.data.health_timeout_s}
                                onChange={(v) => form.setData('health_timeout_s', v)}
                                error={form.errors.health_timeout_s}
                                disabled={disabled}
                            />
                            <NumberField
                                id="health_retries"
                                label="Attempts"
                                value={form.data.health_retries}
                                onChange={(v) => form.setData('health_retries', v)}
                                error={form.errors.health_retries}
                                disabled={disabled}
                            />
                            <NumberField
                                id="health_retry_delay_s"
                                label="Delay between attempts (s)"
                                value={form.data.health_retry_delay_s}
                                onChange={(v) => form.setData('health_retry_delay_s', v)}
                                error={form.errors.health_retry_delay_s}
                                disabled={disabled}
                            />
                        </div>
                    </CardContent>
                </Card>

                {can.manage && (
                    <div className="lg:col-span-2">
                        <Button type="submit" disabled={form.processing}>
                            Save settings
                        </Button>
                    </div>
                )}
            </form>

            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Push to deploy</CardTitle>
                        <CardDescription>
                            {hasRepository
                                ? `Deploy automatically when ${branch ?? 'the branch'} receives a push.`
                                : 'Connect a repository to enable push to deploy.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={pushToDeploy}
                                disabled={disabled || !hasRepository}
                                onCheckedChange={(checked) =>
                                    router.put(
                                        `/sites/${site.id}/deploy-settings/push-to-deploy`,
                                        { enabled: checked === true },
                                        { preserveScroll: true },
                                    )
                                }
                            />
                            Enabled
                        </label>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Deploy hook</CardTitle>
                        <CardDescription>
                            GET or POST this URL to deploy. Optional query parameters: <code>kiln_deploy_branch</code>,{' '}
                            <code>kiln_deploy_commit</code>, <code>kiln_deploy_author</code>, <code>kiln_deploy_message</code>; any other parameter
                            becomes <code>KILN_VAR_&lt;NAME&gt;</code> in the deploy script.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {hookUrl && (
                            <div className="flex gap-2">
                                <Input readOnly value={hookUrl} className="font-mono text-xs" onFocus={(e) => e.target.select()} />
                                <Button type="button" variant="outline" size="icon" onClick={() => void copy()} aria-label="Copy deploy hook URL">
                                    <Copy className="size-4" />
                                </Button>
                            </div>
                        )}
                        {copied && <p className="text-sm text-emerald-600">Copied.</p>}
                        {!hookUrl && hasHook && <p className="text-muted-foreground text-sm">A deploy hook is configured.</p>}
                        {can.manage && (
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => router.post(`/sites/${site.id}/deploy-settings/hook`, {}, { preserveScroll: true })}
                                >
                                    <RefreshCw className="size-4" /> {hasHook ? 'Regenerate URL' : 'Create URL'}
                                </Button>
                                {hasHook && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => router.delete(`/sites/${site.id}/deploy-settings/hook`, { preserveScroll: true })}
                                    >
                                        Disable
                                    </Button>
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </SiteLayout>
    );
}

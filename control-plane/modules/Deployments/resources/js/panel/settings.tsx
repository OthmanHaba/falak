import { Button, Callout, CopyButton, Field, Input, Section, Select, SkeletonRows, Switch, toast } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { HttpError, errorMessage, requestJson, type HttpMethod } from '@/lib/http';
import { type ServiceTabProps } from '@/lib/registry';
import { Link2Off, RefreshCw } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';

interface DeploySettings {
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

/** GET /sites/{site}/deploy-settings (JSON). */
interface DeploySettingsData {
    settings: DeploySettings;
    defaultHealthPath: string;
    strategies: { value: string; label: string; description: string }[];
    pushToDeploy: boolean;
    hasRepository: boolean;
    branch: string | null;
    hookUrl: string | null;
    hasHook: boolean;
    can: { manage: boolean };
}

const url = (siteId: string) => `/sites/${siteId}/deploy-settings`;

function useDeploySettings(ctx: ServiceTabProps['ctx']) {
    return useJson<DeploySettingsData>(url(ctx.service.ref_id));
}

async function send(
    method: HttpMethod,
    target: string,
    body: unknown,
    success: string,
    reload: () => Promise<void>,
): Promise<Record<string, string> | null> {
    try {
        await requestJson(target, method, body);
        toast.success(success);
        await reload();

        return null;
    } catch (error) {
        if (error instanceof HttpError && Object.keys(error.errors).length) return error.errors;
        toast.error('Could not save', errorMessage(error));

        return {};
    }
}

/** Deploy section: strategy, batch size, release retention and the health check gate. */
export function DeployStrategySettings({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useDeploySettings(ctx);
    const [form, setForm] = useState<DeploySettings | null>(data?.settings ?? null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => setForm(data?.settings ?? null), [data?.settings]);

    if (!data || !form) return error ? <Callout tone="danger">{error}</Callout> : <SkeletonRows rows={5} />;

    const manage = data.can.manage;
    const dirty = JSON.stringify(form) !== JSON.stringify(data.settings);
    const set = (patch: Partial<DeploySettings>) => setForm({ ...form, ...patch });
    const number = (key: keyof DeploySettings, label: string, hint?: string) => (
        <Field label={label} hint={hint} error={errors[key]}>
            <Input
                mono
                inputMode="numeric"
                disabled={!manage}
                value={String(form[key] ?? '')}
                onChange={(event) => set({ [key]: Number(event.target.value.replace(/\D/g, '') || 0) })}
            />
        </Field>
    );
    const strategy = data.strategies.find((item) => item.value === form.strategy);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setErrors((await send('PUT', url(ctx.service.ref_id), form, 'Deploy settings saved', reload)) ?? {});
        setSaving(false);
    };

    return (
        <Section
            title="Strategy & health check"
            description="How releases reach the servers, how many are kept for rollbacks, and what counts as healthy."
            footer={
                manage && (
                    <>
                        <Button variant="ghost" disabled={!dirty || saving} onClick={() => setForm(data.settings)}>
                            Reset
                        </Button>
                        <Button variant="primary" type="submit" form="deploy-strategy" loading={saving} disabled={!dirty}>
                            Save
                        </Button>
                    </>
                )
            }
        >
            <form id="deploy-strategy" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Field label="Strategy" error={errors.strategy} hint={strategy?.description} className="sm:col-span-2">
                    <Select
                        value={form.strategy}
                        disabled={!manage}
                        onValueChange={(value) => set({ strategy: value })}
                        options={data.strategies.map((item) => ({ value: item.value, label: item.label }))}
                    />
                </Field>
                {form.strategy === 'rolling' && number('batch_size', 'Batch size', 'Servers updated at a time.')}
                {number('keep_releases', 'Releases to keep', 'Older releases are pruned after each deploy.')}
                <div className="grid gap-3 sm:col-span-2">
                    <Field inline label="Health check gates activation (failed checks roll the server back)">
                        <Switch checked={form.health_enabled} disabled={!manage} onCheckedChange={(on) => set({ health_enabled: on })} />
                    </Field>
                </div>
                {form.health_enabled && (
                    <>
                        <Field label="Path" error={errors.health_path} hint={`Default ${data.defaultHealthPath}`}>
                            <Input
                                mono
                                disabled={!manage}
                                placeholder={data.defaultHealthPath}
                                value={form.health_path ?? ''}
                                onChange={(event) => set({ health_path: event.target.value || null })}
                            />
                        </Field>
                        {number('health_status', 'Expected status')}
                        {number('health_timeout_s', 'Timeout (s)')}
                        {number('health_retries', 'Attempts')}
                        {number('health_retry_delay_s', 'Delay between attempts (s)')}
                    </>
                )}
            </form>
        </Section>
    );
}

/** Deploy section: the secret deploy hook URL (rotate = refresh, disable). */
export function DeployHookSettings({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useDeploySettings(ctx);
    const [busy, setBusy] = useState<'rotate' | 'disable' | null>(null);

    if (!data) return error ? <Callout tone="danger">{error}</Callout> : <SkeletonRows rows={2} />;

    const run = async (kind: 'rotate' | 'disable') => {
        if (kind === 'disable' && !window.confirm('Disable the deploy hook? Anything calling it stops deploying.')) return;
        if (kind === 'rotate' && data.hasHook && !window.confirm('Generate a new URL? The current one stops working.')) return;
        setBusy(kind);
        await send(
            kind === 'rotate' ? 'POST' : 'DELETE',
            `${url(ctx.service.ref_id)}/hook`,
            {},
            kind === 'rotate' ? 'New deploy hook URL' : 'Deploy hook disabled',
            reload,
        );
        setBusy(null);
    };

    return (
        <Section
            title="Deploy hook"
            description="POST to this secret URL (CI, a CMS publish button…) to start a deployment. Treat it like a password."
            aside={
                data.can.manage && (
                    <>
                        {data.hasHook && (
                            <Button size="sm" variant="ghost" icon={<Link2Off />} loading={busy === 'disable'} onClick={() => void run('disable')}>
                                Disable
                            </Button>
                        )}
                        <Button size="sm" icon={<RefreshCw />} loading={busy === 'rotate'} onClick={() => void run('rotate')}>
                            {data.hasHook ? 'Refresh URL' : 'Create URL'}
                        </Button>
                    </>
                )
            }
        >
            {data.hookUrl ? (
                <div className="flex items-center gap-2">
                    <Input mono readOnly aria-label="Deploy hook URL" value={data.hookUrl} onFocus={(event) => event.target.select()} />
                    <CopyButton value={data.hookUrl} label="Copy deploy hook URL" />
                </div>
            ) : (
                <p className="text-fg-muted text-sm">
                    {data.hasHook ? 'A deploy hook is configured (only managers can see its URL).' : 'No deploy hook yet.'}
                </p>
            )}
        </Section>
    );
}

/** Source section: deploy on every push to the branch. */
export function PushToDeploySettings({ ctx }: ServiceTabProps) {
    const { data, error, reload } = useDeploySettings(ctx);
    const [saving, setSaving] = useState(false);

    if (!data) return error ? <Callout tone="danger">{error}</Callout> : <SkeletonRows rows={1} />;

    return (
        <Section title="Push to deploy">
            <Field
                inline
                label={
                    data.hasRepository
                        ? `Deploy automatically when ${data.branch ?? 'the branch'} receives a push`
                        : 'Connect a repository to deploy on push'
                }
            >
                <Switch
                    checked={data.pushToDeploy}
                    disabled={!data.can.manage || !data.hasRepository || saving}
                    onCheckedChange={async (enabled) => {
                        setSaving(true);
                        await send(
                            'PUT',
                            `${url(ctx.service.ref_id)}/push-to-deploy`,
                            { enabled },
                            enabled ? 'Push to deploy enabled' : 'Push to deploy disabled',
                            reload,
                        );
                        setSaving(false);
                    }}
                />
            </Field>
        </Section>
    );
}

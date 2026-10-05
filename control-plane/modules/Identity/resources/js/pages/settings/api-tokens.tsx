import { Button } from '@/components/falak/button';
import { Checkbox } from '@/components/falak/checkbox';
import { ConfirmDestructive } from '@/components/falak/confirm-destructive';
import { CopyButton } from '@/components/falak/copy-button';
import { DataTable } from '@/components/falak/data-table';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { RelativeTime } from '@/components/falak/relative-time';
import { Section } from '@/components/falak/section';
import { Tag } from '@/components/falak/tag';
import SettingsLayout from '@/layouts/settings/layout';
import { router, useForm } from '@inertiajs/react';
import { KeyRound, Trash2 } from 'lucide-react';
import { useMemo, useState, type FormEventHandler } from 'react';

interface Ability {
    name: string;
    description: string;
    group: string;
}

interface Token {
    id: number;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string | null;
}

interface ApiTokensProps {
    tokens: Token[];
    abilities: Ability[];
    plainTextToken: string | null;
}

interface TokenForm {
    name: string;
    abilities: string[];
    expires_in_days: string;
}

export default function ApiTokens({ tokens, abilities, plainTextToken }: ApiTokensProps) {
    const { data, setData, post, processing, errors, reset } = useForm<TokenForm>({ name: '', abilities: [], expires_in_days: '' });
    const [revoking, setRevoking] = useState<Token | null>(null);
    const [revokeProcessing, setRevokeProcessing] = useState(false);

    const groups = useMemo(() => {
        const map = new Map<string, Ability[]>();
        abilities.forEach((ability) => map.set(ability.group, [...(map.get(ability.group) ?? []), ability]));

        return [...map.entries()];
    }, [abilities]);

    const all = data.abilities.includes('*');

    const toggle = (name: string, checked: boolean) => {
        if (name === '*') {
            setData('abilities', checked ? ['*'] : []);

            return;
        }
        setData('abilities', checked ? [...data.abilities.filter((a) => a !== '*'), name] : data.abilities.filter((a) => a !== name));
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('api-tokens.store'), { preserveScroll: true, onSuccess: () => reset() });
    };

    const revoke = () => {
        if (!revoking) return;
        router.delete(route('api-tokens.destroy', revoking.id), {
            preserveScroll: true,
            onStart: () => setRevokeProcessing(true),
            onFinish: () => {
                setRevokeProcessing(false);
                setRevoking(null);
            },
        });
    };

    return (
        <SettingsLayout
            title="API tokens"
            description="Tokens authenticate the falak CLI and API clients. They are scoped to the current organization and never exceed your role."
            wide
        >
            {plainTextToken && (
                <div role="status" className="border-success/40 bg-success-soft grid gap-2 rounded-lg border p-4">
                    <p className="text-fg text-sm font-medium">Copy your new token now — it won't be shown again.</p>
                    <div className="border-border bg-canvas flex items-center gap-2 rounded-md border px-2 py-1.5">
                        <code className="text-fg min-w-0 flex-1 overflow-x-auto font-mono text-xs whitespace-nowrap" data-testid="plain-token">
                            {plainTextToken}
                        </code>
                        <CopyButton value={plainTextToken} label="Copy token" />
                    </div>
                </div>
            )}

            <form onSubmit={submit} className="max-w-3xl">
                <Section
                    title="Create token"
                    footer={
                        <Button variant="primary" type="submit" loading={processing}>
                            Create token
                        </Button>
                    }
                >
                    <div className="grid gap-4 sm:grid-cols-[1fr_160px]">
                        <Field label="Name" error={errors.name}>
                            <Input value={data.name} onChange={(event) => setData('name', event.target.value)} placeholder="CI deploys" />
                        </Field>
                        <Field label="Expires in (days)" error={errors.expires_in_days} hint="Empty = never">
                            <Input
                                type="number"
                                min={1}
                                max={3650}
                                value={data.expires_in_days}
                                onChange={(event) => setData('expires_in_days', event.target.value)}
                                placeholder="Never"
                            />
                        </Field>
                    </div>

                    <fieldset className="grid gap-3">
                        <legend className="text-fg mb-2 text-xs font-medium">Abilities</legend>
                        <Field
                            inline
                            label={
                                <span>
                                    All abilities <span className="text-fg-faint font-normal">— everything your role allows, now and later</span>
                                </span>
                            }
                        >
                            <Checkbox checked={all} onCheckedChange={(checked) => toggle('*', checked === true)} />
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {groups.map(([group, items]) => (
                                <fieldset key={group} className="border-border grid content-start gap-2 rounded-md border p-3" disabled={all}>
                                    <legend className="text-2xs text-fg-faint px-1 font-medium tracking-wide uppercase">{group}</legend>
                                    {items.map((ability) => (
                                        <Field
                                            key={ability.name}
                                            inline
                                            label={<span className="font-mono text-xs font-normal">{ability.name}</span>}
                                            hint={ability.description || undefined}
                                        >
                                            <Checkbox
                                                disabled={all}
                                                checked={all || data.abilities.includes(ability.name)}
                                                onCheckedChange={(checked) => toggle(ability.name, checked === true)}
                                            />
                                        </Field>
                                    ))}
                                </fieldset>
                            ))}
                        </div>
                        {errors.abilities && <p className="text-danger text-xs">{errors.abilities}</p>}
                    </fieldset>
                </Section>
            </form>

            <Section title="Active tokens" bare>
                <DataTable
                    label="Active API tokens"
                    rows={tokens}
                    rowKey={(token) => String(token.id)}
                    empty={{
                        icon: <KeyRound />,
                        title: 'No API tokens yet',
                        description: 'Create a token above to use the falak CLI or call the API from CI.',
                        size: 'sm',
                    }}
                    columns={[
                        {
                            id: 'name',
                            header: 'Name',
                            cell: (token) => <span className="font-medium">{token.name}</span>,
                            sortValue: (token) => token.name,
                        },
                        {
                            id: 'abilities',
                            header: 'Abilities',
                            hideOnMobile: true,
                            cell: (token) => (
                                <div className="flex max-w-xs flex-wrap gap-1 py-1">
                                    {token.abilities.map((ability) => (
                                        <Tag key={ability} mono>
                                            {ability}
                                        </Tag>
                                    ))}
                                </div>
                            ),
                        },
                        {
                            id: 'last_used',
                            header: 'Last used',
                            sortValue: (token) => token.last_used_at ?? '',
                            cell: (token) => <RelativeTime value={token.last_used_at} fallback="Never" className="text-fg-muted" />,
                        },
                        {
                            id: 'expires',
                            header: 'Expires',
                            hideOnMobile: true,
                            cell: (token) => <RelativeTime value={token.expires_at} fallback="Never" className="text-fg-muted" />,
                        },
                    ]}
                    rowActions={(token) => [{ label: 'Revoke', icon: <Trash2 />, danger: true, onSelect: () => setRevoking(token) }]}
                />
            </Section>

            <ConfirmDestructive
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
                title="Revoke API token"
                description="Clients using this token will stop working immediately."
                confirmText={revoking?.name ?? ''}
                confirmLabel="Revoke token"
                onConfirm={revoke}
                processing={revokeProcessing}
            />
        </SettingsLayout>
    );
}

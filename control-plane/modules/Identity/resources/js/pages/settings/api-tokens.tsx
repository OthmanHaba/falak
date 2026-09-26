import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { Check, Copy } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'API tokens', href: '/settings/api-tokens' }];

const relative = (value: string | null, fallback: string) => (value ? formatDistanceToNow(new Date(value), { addSuffix: true }) : fallback);

export default function ApiTokens({ tokens, abilities, plainTextToken }: ApiTokensProps) {
    const [copied, setCopied] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm<TokenForm>({ name: '', abilities: [], expires_in_days: '' });

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

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('api-tokens.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const copy = async () => {
        if (plainTextToken) {
            await navigator.clipboard.writeText(plainTextToken);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        }
    };

    const revoke = (token: Token) => {
        if (window.confirm(`Revoke the token "${token.name}"? Clients using it will stop working immediately.`)) {
            router.delete(route('api-tokens.destroy', token.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="API tokens" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Create API token"
                        description="Tokens authenticate the kiln CLI and API clients. They are scoped to the current organization and can never exceed your role."
                    />

                    {plainTextToken && (
                        <div className="space-y-2 rounded-lg border border-green-600/40 bg-green-50 p-4 dark:bg-green-950/30">
                            <p className="text-sm font-medium">Copy your new token now — it won't be shown again.</p>
                            <div className="flex items-center gap-2">
                                <code
                                    className="bg-background flex-1 overflow-x-auto rounded border px-2 py-1.5 font-mono text-xs"
                                    data-testid="plain-token"
                                >
                                    {plainTextToken}
                                </code>
                                <Button type="button" size="sm" variant="secondary" onClick={copy}>
                                    {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                                    {copied ? 'Copied' : 'Copy'}
                                </Button>
                            </div>
                        </div>
                    )}

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-4 sm:grid-cols-[1fr_160px]">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="CI deploys" />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="expires_in_days">Expires in (days)</Label>
                                <Input
                                    id="expires_in_days"
                                    type="number"
                                    min={1}
                                    max={3650}
                                    value={data.expires_in_days}
                                    onChange={(e) => setData('expires_in_days', e.target.value)}
                                    placeholder="Never"
                                />
                                <InputError message={errors.expires_in_days} />
                            </div>
                        </div>

                        <div className="space-y-3">
                            <Label>Abilities</Label>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={all} onCheckedChange={(checked) => toggle('*', checked === true)} />
                                <span className="font-medium">All abilities (*)</span>
                                <span className="text-muted-foreground">— everything your role allows, now and in the future</span>
                            </label>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {groups.map(([group, items]) => (
                                    <fieldset key={group} className="space-y-2 rounded-md border p-3" disabled={all}>
                                        <legend className="px-1 text-xs font-medium tracking-wide uppercase">{group}</legend>
                                        {items.map((ability) => (
                                            <label key={ability.name} className="flex items-start gap-2 text-sm">
                                                <Checkbox
                                                    className="mt-0.5"
                                                    disabled={all}
                                                    checked={all || data.abilities.includes(ability.name)}
                                                    onCheckedChange={(checked) => toggle(ability.name, checked === true)}
                                                />
                                                <span>
                                                    <span className="font-mono text-xs">{ability.name}</span>
                                                    {ability.description && (
                                                        <span className="text-muted-foreground block text-xs">{ability.description}</span>
                                                    )}
                                                </span>
                                            </label>
                                        ))}
                                    </fieldset>
                                ))}
                            </div>
                            <InputError message={errors.abilities} />
                        </div>

                        <Button disabled={processing}>Create token</Button>
                    </form>

                    <HeadingSmall title="Active tokens" />
                    {tokens.length === 0 ? (
                        <p className="text-muted-foreground text-sm">You have no API tokens for this organization.</p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Abilities</TableHead>
                                    <TableHead>Last used</TableHead>
                                    <TableHead>Expires</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {tokens.map((token) => (
                                    <TableRow key={token.id}>
                                        <TableCell className="font-medium">{token.name}</TableCell>
                                        <TableCell>
                                            <div className="flex max-w-xs flex-wrap gap-1">
                                                {token.abilities.map((ability) => (
                                                    <Badge key={ability} variant="secondary" className="font-mono text-[10px]">
                                                        {ability}
                                                    </Badge>
                                                ))}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">{relative(token.last_used_at, 'Never')}</TableCell>
                                        <TableCell className="text-muted-foreground">{relative(token.expires_at, 'Never')}</TableCell>
                                        <TableCell className="text-right">
                                            <Button size="sm" variant="ghost" className="text-destructive" onClick={() => revoke(token)}>
                                                Revoke
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

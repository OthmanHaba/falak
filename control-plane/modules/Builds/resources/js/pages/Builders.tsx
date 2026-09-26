import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface BuilderRow {
    id: string;
    name: string;
    kind: 'local' | 'server' | 'external';
    server_id: string | null;
    shared: boolean;
    modes: string[];
    enabled: boolean;
    online: boolean;
    reported_name: string | null;
    last_ip: string | null;
    last_seen_at: string | null;
}

interface Props {
    builders: BuilderRow[];
    localConfigured: boolean;
    panelUrl: string;
    plainToken: string | null;
    can: { manage: boolean };
}

export default function Builders({ builders, localConfigured, panelUrl, plainToken, can }: Props) {
    const form = useForm<{ name: string; modes: string[] }>({ name: '', modes: ['native', 'docker'] });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post('/builds/builders', { preserveScroll: true, onSuccess: () => form.reset('name') });
    };

    const toggleMode = (mode: string, on: boolean) =>
        form.setData('modes', on ? [...new Set([...form.data.modes, mode])] : form.data.modes.filter((m) => m !== mode));

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Builds', href: '/builds' },
                { title: 'Builders', href: '/builds/builders' },
            ]}
        >
            <Head title="Builders" />
            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">Builders</h1>
                    <p className="text-muted-foreground text-sm">
                        Builds run on the control-plane host {localConfigured ? '' : '(not configured: set KILN_LOCAL_BUILDER_TOKEN) '}or on servers
                        of type builder. Managed servers never build.
                    </p>
                </div>

                {plainToken && (
                    <Card className="border-emerald-500/40">
                        <CardHeader>
                            <CardTitle>Builder token</CardTitle>
                            <CardDescription>Shown once. Run the builder with:</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <pre className="bg-muted overflow-x-auto rounded-md p-3 text-xs">{`KILN_URL=${panelUrl} KILN_BUILDER_TOKEN=${plainToken} kiln-builder serve`}</pre>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardContent className="pt-6">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Kind</TableHead>
                                    <TableHead>Modes</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Last seen</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {builders.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-muted-foreground text-center">
                                            No builder has connected yet.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {builders.map((builder) => (
                                    <TableRow key={builder.id}>
                                        <TableCell className="font-medium">
                                            {builder.name}
                                            {builder.reported_name && builder.reported_name !== builder.name && (
                                                <span className="text-muted-foreground text-xs"> ({builder.reported_name})</span>
                                            )}
                                        </TableCell>
                                        <TableCell>{builder.shared ? 'control plane (shared)' : builder.kind}</TableCell>
                                        <TableCell>{builder.modes.join(', ')}</TableCell>
                                        <TableCell>
                                            {!builder.enabled ? (
                                                <Badge variant="outline">disabled</Badge>
                                            ) : builder.online ? (
                                                <Badge>online</Badge>
                                            ) : (
                                                <Badge variant="secondary">offline</Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>{builder.last_seen_at ? new Date(builder.last_seen_at).toLocaleString() : 'never'}</TableCell>
                                        <TableCell className="space-x-2 text-right">
                                            {can.manage && !builder.shared && (
                                                <>
                                                    {builder.kind === 'server' && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                router.post(`/builds/builders/${builder.id}/reinstall`, {}, { preserveScroll: true })
                                                            }
                                                        >
                                                            Reinstall
                                                        </Button>
                                                    )}
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.patch(
                                                                `/builds/builders/${builder.id}`,
                                                                { enabled: !builder.enabled },
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                    >
                                                        {builder.enabled ? 'Disable' : 'Enable'}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => router.delete(`/builds/builders/${builder.id}`, { preserveScroll: true })}
                                                    >
                                                        Remove
                                                    </Button>
                                                </>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {can.manage && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Add an external builder</CardTitle>
                            <CardDescription>Run kiln-builder on any machine with Docker; it only builds this organization's sites.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="flex flex-wrap items-end gap-4">
                                <div className="grid gap-1">
                                    <label htmlFor="builder-name" className="text-sm">
                                        Name
                                    </label>
                                    <Input
                                        id="builder-name"
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        className="w-56"
                                    />
                                    {form.errors.name && <p className="text-sm text-red-600">{form.errors.name}</p>}
                                </div>
                                {['native', 'docker'].map((mode) => (
                                    <label key={mode} className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={form.data.modes.includes(mode)}
                                            onCheckedChange={(checked) => toggleMode(mode, checked === true)}
                                        />
                                        {mode}
                                    </label>
                                ))}
                                <Button type="submit" disabled={form.processing}>
                                    Create token
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}

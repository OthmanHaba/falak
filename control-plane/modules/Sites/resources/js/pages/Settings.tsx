import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import SiteLayout, { type SiteHeader } from '@/layouts/site-layout';
import { useForm } from '@inertiajs/react';
import { Loader2, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { RepositoryPicker } from '../components/repository-picker';
import { Warnings } from '../components/site-ui';
import { TargetsCard } from '../components/targets-card';
import { type LaravelToggles, type SharedPathItem, type SiteOptions, type SiteTarget } from '../types';

interface Settings {
    name: string;
    framework: string;
    is_laravel: boolean;
    runtime: string;
    build_mode: string;
    php_version: string | null;
    node_version: string | null;
    source_connection_id: string | null;
    repository: string | null;
    branch: string | null;
    push_to_deploy: boolean;
    web_directory: string;
    app_port: number | null;
    docker_image: string | null;
    dockerfile: string | null;
    compose_file: string | null;
    health_check_path: string | null;
    test_domain_enabled: boolean;
    unix_user: string;
    isolated: boolean;
    laravel: LaravelToggles;
    shared_paths: SharedPathItem[];
}

interface Props {
    site: SiteHeader;
    settings: Settings;
    targets: SiteTarget[];
    options: SiteOptions;
    warnings: string[];
    can: { update: boolean; delete: boolean };
}

export default function SettingsPage({ site, settings, targets, options, warnings, can }: Props) {
    const [deleting, setDeleting] = useState(false);

    return (
        <SiteLayout site={site} title="Settings">
            <Warnings warnings={warnings} />
            <div className="grid max-w-4xl gap-6">
                <GeneralCard siteId={site.id} settings={settings} options={options} canUpdate={can.update} />
                <ServersCard siteId={site.id} targets={targets} options={options} canUpdate={can.update} />
                <TargetsCard siteId={site.id} targets={targets} canUpdate={can.update} />
                <SharedPathsCard siteId={site.id} paths={settings.shared_paths} canUpdate={can.update} />
                {settings.is_laravel && <LaravelCard siteId={site.id} toggles={settings.laravel} canUpdate={can.update} />}
                {can.delete && (
                    <Card className="border-red-500/40">
                        <CardHeader>
                            <CardTitle>Delete site</CardTitle>
                            <CardDescription>
                                Removes routes, PHP pools and the deploy key. Files under /srv/kiln/sites/{site.slug} stay on the servers.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button variant="destructive" onClick={() => setDeleting(true)}>
                                <Trash2 /> Delete site
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
            {deleting && <DeleteDialog siteId={site.id} name={site.name} onClose={() => setDeleting(false)} />}
        </SiteLayout>
    );
}

function GeneralCard({ siteId, settings, options, canUpdate }: { siteId: string; settings: Settings; options: SiteOptions; canUpdate: boolean }) {
    const form = useForm({
        name: settings.name,
        runtime: settings.runtime,
        build_mode: settings.build_mode,
        php_version: settings.php_version ?? '',
        node_version: settings.node_version ?? '',
        source_connection_id: settings.source_connection_id ?? '',
        repository: settings.repository ?? '',
        branch: settings.branch ?? '',
        push_to_deploy: settings.push_to_deploy,
        web_directory: settings.web_directory,
        app_port: settings.app_port ? String(settings.app_port) : '',
        docker_image: settings.docker_image ?? '',
        dockerfile: settings.dockerfile ?? '',
        compose_file: settings.compose_file ?? '',
        health_check_path: settings.health_check_path ?? '',
        test_domain_enabled: settings.test_domain_enabled,
    });
    const { data, setData, errors } = form;
    const framework = options.frameworks.find((item) => item.value === settings.framework);
    const runtime = options.runtimes.find((item) => item.value === data.runtime) ?? options.runtimes[0];
    const current = options.runtimes.find((item) => item.value === settings.runtime);
    const runtimes = options.runtimes.filter((item) => framework?.runtimes.includes(item.value) && item.container === current?.container);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((current) => ({
            ...current,
            php_version: runtime.is_php ? current.php_version : null,
            node_version: current.node_version || null,
            app_port: runtime.proxies && current.app_port ? Number(current.app_port) : null,
            source_connection_id: current.source_connection_id || null,
            repository: current.source_connection_id ? current.repository : null,
            branch: current.source_connection_id ? current.branch : null,
            docker_image: current.docker_image || null,
            dockerfile: current.dockerfile || null,
            compose_file: current.compose_file || null,
            health_check_path: current.health_check_path || null,
        }));
        form.patch(`/sites/${siteId}`, { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>General</CardTitle>
                <CardDescription>
                    Runs as <code>{settings.unix_user}</code>
                    {settings.isolated ? ' (isolated)' : ''}. Changes apply to the next deploy; runtime changes reconfigure the servers right away.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-6">
                    <fieldset disabled={!canUpdate} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label>Runtime</Label>
                                <Select value={data.runtime} onValueChange={(value) => setData('runtime', value)}>
                                    <SelectTrigger aria-label="Runtime">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {runtimes.map((item) => (
                                            <SelectItem key={item.value} value={item.value}>
                                                {item.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.runtime} />
                            </div>
                            <div className="grid gap-2">
                                <Label>Build</Label>
                                <Select value={data.build_mode} onValueChange={(value) => setData('build_mode', value)}>
                                    <SelectTrigger aria-label="Build mode">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.build_modes
                                            .filter((mode) => runtime.build_modes.includes(mode.value))
                                            .map((mode) => (
                                                <SelectItem key={mode.value} value={mode.value}>
                                                    {mode.label}
                                                </SelectItem>
                                            ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.build_mode} />
                            </div>
                            {runtime.is_php ? (
                                <div className="grid gap-2">
                                    <Label>PHP version</Label>
                                    <Select value={data.php_version || undefined} onValueChange={(value) => setData('php_version', value)}>
                                        <SelectTrigger aria-label="PHP version">
                                            <SelectValue placeholder="Pick a version" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.php_versions.map((version) => (
                                                <SelectItem key={version} value={version}>
                                                    PHP {version}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.php_version} />
                                </div>
                            ) : (
                                !runtime.container && (
                                    <div className="grid gap-2">
                                        <Label>Node version</Label>
                                        <Select value={data.node_version || undefined} onValueChange={(value) => setData('node_version', value)}>
                                            <SelectTrigger aria-label="Node version">
                                                <SelectValue placeholder="Default" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {options.node_versions.map((version) => (
                                                    <SelectItem key={version} value={version}>
                                                        Node {version}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.node_version} />
                                    </div>
                                )
                            )}
                            {(runtime.is_php || data.runtime === 'static') && (
                                <div className="grid gap-2">
                                    <Label htmlFor="web_directory">Web directory</Label>
                                    <Input id="web_directory" value={data.web_directory} onChange={(e) => setData('web_directory', e.target.value)} />
                                    <InputError message={errors.web_directory} />
                                </div>
                            )}
                            {runtime.proxies && (
                                <div className="grid gap-2">
                                    <Label htmlFor="app_port">App port</Label>
                                    <Input id="app_port" type="number" value={data.app_port} onChange={(e) => setData('app_port', e.target.value)} />
                                    <InputError message={errors.app_port} />
                                </div>
                            )}
                            {data.runtime === 'docker' && (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="docker_image">Image</Label>
                                        <Input
                                            id="docker_image"
                                            value={data.docker_image}
                                            onChange={(e) => setData('docker_image', e.target.value)}
                                        />
                                        <InputError message={errors.docker_image} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="dockerfile">Dockerfile</Label>
                                        <Input id="dockerfile" value={data.dockerfile} onChange={(e) => setData('dockerfile', e.target.value)} />
                                        <InputError message={errors.dockerfile} />
                                    </div>
                                </>
                            )}
                            {data.runtime === 'compose' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="compose_file">Compose file</Label>
                                    <Input id="compose_file" value={data.compose_file} onChange={(e) => setData('compose_file', e.target.value)} />
                                    <InputError message={errors.compose_file} />
                                </div>
                            )}
                            <div className="grid gap-2">
                                <Label htmlFor="health_check_path">Health check path</Label>
                                <Input
                                    id="health_check_path"
                                    value={data.health_check_path}
                                    onChange={(e) => setData('health_check_path', e.target.value)}
                                    placeholder="/up"
                                />
                                <InputError message={errors.health_check_path} />
                            </div>
                        </div>
                        {options.test_domain && (
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={data.test_domain_enabled}
                                    onCheckedChange={(checked) => setData('test_domain_enabled', checked === true)}
                                />
                                Serve the test domain <code className="text-xs">*.{options.test_domain}</code>
                            </label>
                        )}

                        <div className="space-y-2">
                            <Label>Repository</Label>
                            <RepositoryPicker
                                connections={options.connections}
                                value={data}
                                onChange={(value) => setData((current) => ({ ...current, ...value }))}
                                errors={errors}
                            />
                        </div>
                    </fieldset>
                    {canUpdate && (
                        <Button type="submit" disabled={form.processing || !form.isDirty}>
                            {form.processing && <Loader2 className="animate-spin" />} Save
                        </Button>
                    )}
                </form>
            </CardContent>
        </Card>
    );
}

function ServersCard({ siteId, targets, options, canUpdate }: { siteId: string; targets: SiteTarget[]; options: SiteOptions; canUpdate: boolean }) {
    const form = useForm({
        server_ids: targets.map((target) => target.server_id),
        leader_server_id: targets.find((target) => target.role === 'leader')?.server_id ?? '',
    });

    const toggle = (id: string, checked: boolean) => {
        form.setData((current) => {
            const server_ids = checked ? [...current.server_ids, id] : current.server_ids.filter((item) => item !== id);

            return { server_ids, leader_server_id: server_ids.includes(current.leader_server_id) ? current.leader_server_id : (server_ids[0] ?? '') };
        });
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(`/sites/${siteId}/targets`, { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Deployment group</CardTitle>
                <CardDescription>
                    Servers this site deploys to. New servers are prepared right away; removed servers keep their files.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-3">
                    {options.servers.map((server) => {
                        const selected = form.data.server_ids.includes(server.id);

                        return (
                            <div key={server.id} className="flex items-center gap-3 rounded-md border px-3 py-2 text-sm">
                                <Checkbox
                                    id={`target-${server.id}`}
                                    checked={selected}
                                    disabled={!canUpdate}
                                    onCheckedChange={(checked) => toggle(server.id, checked === true)}
                                />
                                <label htmlFor={`target-${server.id}`} className="flex-1">
                                    {server.name} <span className="text-muted-foreground text-xs">{server.ipv4}</span>
                                </label>
                                {selected && (
                                    <label className="flex items-center gap-1.5 text-xs">
                                        <input
                                            type="radio"
                                            name="leader"
                                            disabled={!canUpdate}
                                            checked={form.data.leader_server_id === server.id}
                                            onChange={() => form.setData('leader_server_id', server.id)}
                                        />
                                        Leader
                                    </label>
                                )}
                            </div>
                        );
                    })}
                    {Object.entries(form.errors).map(([key, message]) => (
                        <InputError key={key} message={message} />
                    ))}
                    {canUpdate && (
                        <Button type="submit" disabled={form.processing || !form.isDirty || form.data.server_ids.length === 0}>
                            {form.processing && <Loader2 className="animate-spin" />} Save servers
                        </Button>
                    )}
                </form>
            </CardContent>
        </Card>
    );
}

function SharedPathsCard({ siteId, paths, canUpdate }: { siteId: string; paths: SharedPathItem[]; canUpdate: boolean }) {
    const form = useForm<{ paths: SharedPathItem[] }>({ paths });

    const update = (index: number, value: Partial<SharedPathItem>) =>
        form.setData(
            'paths',
            form.data.paths.map((path, i) => (i === index ? { ...path, ...value } : path)),
        );

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(`/sites/${siteId}/shared-paths`, { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Shared paths</CardTitle>
                <CardDescription>Linked from every release into shared/ so they survive deploys.</CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-2">
                    {form.data.paths.map((path, index) => (
                        <div key={index} className="flex gap-2">
                            <Input
                                value={path.path}
                                disabled={!canUpdate}
                                onChange={(e) => update(index, { path: e.target.value })}
                                className="font-mono"
                                aria-label="Path"
                            />
                            <Select
                                value={path.type}
                                disabled={!canUpdate}
                                onValueChange={(value) => update(index, { type: value as SharedPathItem['type'] })}
                            >
                                <SelectTrigger className="w-36" aria-label="Type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="directory">Directory</SelectItem>
                                    <SelectItem value="file">File</SelectItem>
                                </SelectContent>
                            </Select>
                            {canUpdate && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Remove path"
                                    onClick={() =>
                                        form.setData(
                                            'paths',
                                            form.data.paths.filter((_, i) => i !== index),
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            )}
                        </div>
                    ))}
                    {Object.entries(form.errors).map(([key, message]) => (
                        <InputError key={key} message={message} />
                    ))}
                    {canUpdate && (
                        <div className="flex gap-2 pt-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => form.setData('paths', [...form.data.paths, { path: '', type: 'directory' }])}
                            >
                                <Plus /> Add path
                            </Button>
                            <Button type="submit" disabled={form.processing || !form.isDirty}>
                                Save paths
                            </Button>
                        </div>
                    )}
                </form>
            </CardContent>
        </Card>
    );
}

function LaravelCard({ siteId, toggles, canUpdate }: { siteId: string; toggles: LaravelToggles; canUpdate: boolean }) {
    const form = useForm<LaravelToggles>(toggles);

    const items: { key: keyof LaravelToggles; title: string; description: string }[] = [
        { key: 'scheduler', title: 'Scheduler', description: 'Run schedule:run every minute on the leader (with missed-run detection).' },
        { key: 'horizon', title: 'Horizon', description: 'Supervise php artisan horizon on every server.' },
        { key: 'octane', title: 'Octane', description: 'Serve through Octane / FrankenPHP worker mode.' },
        { key: 'maintenance', title: 'Maintenance mode', description: 'php artisan down on every server right now (up when turned off).' },
    ];

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(`/sites/${siteId}/laravel`, { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Laravel</CardTitle>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-3">
                    {items.map((item) => (
                        <label key={item.key} className="flex items-start gap-2 text-sm">
                            <Checkbox
                                checked={form.data[item.key]}
                                disabled={!canUpdate}
                                onCheckedChange={(checked) => form.setData(item.key, checked === true)}
                            />
                            <span>
                                {item.title}
                                <span className="text-muted-foreground block text-xs">{item.description}</span>
                            </span>
                        </label>
                    ))}
                    {Object.entries(form.errors).map(([key, message]) => (
                        <InputError key={key} message={message} />
                    ))}
                    {canUpdate && (
                        <Button type="submit" disabled={form.processing || !form.isDirty}>
                            {form.processing && <Loader2 className="animate-spin" />} Save
                        </Button>
                    )}
                </form>
            </CardContent>
        </Card>
    );
}

function DeleteDialog({ siteId, name, onClose }: { siteId: string; name: string; onClose: () => void }) {
    const form = useForm({ name: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.delete(`/sites/${siteId}`, { onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Delete {name}?</DialogTitle>
                        <DialogDescription>Type the site name to confirm. This cannot be undone.</DialogDescription>
                    </DialogHeader>
                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder={name} autoFocus />
                    <InputError message={form.errors.name} />
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="destructive" disabled={form.processing || form.data.name !== name}>
                            Delete
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

import { Button } from '@/components/kiln/button';
import { Field } from '@/components/kiln/field';
import { Input } from '@/components/kiln/input';
import { Select } from '@/components/kiln/select';
import { useForm } from '@inertiajs/react';
import { SquareTerminal } from 'lucide-react';
import { type FormEventHandler } from 'react';

const USER_PATTERN = /^[a-z_][a-z0-9_-]{0,31}$/;

interface ServerOption {
    id: string;
    name: string;
    ipv4: string | null;
}

/** Server (optional when fixed) + unix user → POST terminal.sessions.store, which opens the session page. */
export function OpenSessionForm({
    servers,
    serverId,
    defaultUser,
    unixUser,
}: {
    servers?: ServerOption[];
    serverId?: string;
    defaultUser: string;
    unixUser: string;
}) {
    const form = useForm({ server: serverId ?? servers?.[0]?.id ?? '', user: defaultUser });
    const userError = form.data.user !== '' && !USER_PATTERN.test(form.data.user) ? 'Lowercase letters, digits, - and _ only.' : null;
    const serverError = (form.errors as Record<string, string | undefined>).server;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        if (!form.data.server || userError) return;
        form.transform((data) => ({ user: data.user, cols: 120, rows: 32 }));
        form.post(route('terminal.sessions.store', form.data.server));
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-start gap-3">
            {servers && (
                <Field label="Server" className="min-w-56" error={serverError}>
                    <Select
                        value={form.data.server || undefined}
                        onValueChange={(value) => form.setData('server', value)}
                        placeholder="Choose a server"
                        options={servers.map((server) => ({ value: server.id, label: server.name, description: server.ipv4 ?? undefined }))}
                    />
                </Field>
            )}
            <Field label="Unix user" className="w-44" error={form.errors.user ?? userError ?? (servers ? undefined : serverError)}>
                <Select
                    value={['root', unixUser].includes(form.data.user) ? form.data.user : 'other'}
                    onValueChange={(value) => form.setData('user', value === 'other' ? '' : value)}
                    options={[
                        { value: 'root', label: 'root' },
                        { value: unixUser, label: unixUser },
                        { value: 'other', label: 'Other…' },
                    ].filter((option, index, all) => all.findIndex((item) => item.value === option.value) === index)}
                />
            </Field>
            {!['root', unixUser].includes(form.data.user) && (
                <Field label="Username" className="w-40">
                    <Input
                        value={form.data.user}
                        onChange={(event) => form.setData('user', event.target.value)}
                        mono
                        placeholder="deploy"
                        autoFocus
                    />
                </Field>
            )}
            <div className="grid gap-1.5">
                <span className="text-xs select-none" aria-hidden>
                    &nbsp;
                </span>
                <Button
                    type="submit"
                    variant="primary"
                    icon={<SquareTerminal />}
                    loading={form.processing}
                    disabled={!form.data.server || !form.data.user || Boolean(userError)}
                >
                    Open terminal
                </Button>
            </div>
        </form>
    );
}

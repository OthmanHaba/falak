import { Button, Dialog, Field, Input, Select, Switch } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { HttpError, errorMessage, requestJson } from '@/lib/http';
import { useEffect, useState, type FormEvent } from 'react';
import { type ScopeOption } from '../types';

/**
 * Promote a site variable: its value moves into a secret of the site's service and the variable becomes
 * `${{ secrets.NAME }}`. The browser only ever sees variable names.
 */
export function PromoteDialog({
    open,
    services,
    onClose,
    onPromoted,
}: {
    open: boolean;
    services: ScopeOption[];
    onClose: () => void;
    onPromoted: () => void;
}) {
    const [serviceId, setServiceId] = useState('');
    const [key, setKey] = useState('');
    const [name, setName] = useState('');
    const [sensitive, setSensitive] = useState(true);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const { data, loading } = useJson<{ keys: string[] }>(open && serviceId ? `/secrets/promotable?service_id=${serviceId}` : null);

    useEffect(() => {
        if (open) {
            setServiceId(services[0]?.id ?? '');
            setKey('');
            setName('');
            setSensitive(true);
            setErrors({});
        }
    }, [open, services]);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        try {
            await requestJson('/secrets/promote', 'POST', { service_id: serviceId, key, name, sensitive });
            onPromoted();
        } catch (error) {
            setErrors(error instanceof HttpError && Object.keys(error.errors).length > 0 ? error.errors : { key: errorMessage(error) });
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title="Promote a variable to a secret"
            description="The value moves into a secret of the service, and the variable is replaced by a reference to it. Takes effect on the next deployment."
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="promote-variable" loading={busy} disabled={!serviceId || !key || !name}>
                        Promote
                    </Button>
                </>
            }
        >
            <form id="promote-variable" onSubmit={submit} className="grid gap-4">
                <Field label="Service" error={errors.service_id}>
                    <Select
                        value={serviceId}
                        onValueChange={(value) => {
                            setServiceId(value);
                            setKey('');
                        }}
                        options={services.map((service) => ({ value: service.id, label: service.label }))}
                    />
                </Field>
                <Field label="Variable" hint="Only plain values can be promoted (not ${{ … }} references)." error={errors.key}>
                    <Select
                        value={key || undefined}
                        placeholder={loading ? 'Loading…' : (data?.keys.length ?? 0) === 0 ? 'No plain variables' : 'Choose a variable'}
                        onValueChange={(value) => {
                            setKey(value);
                            if (!name || name === key) setName(value.toUpperCase());
                        }}
                        options={(data?.keys ?? []).map((item) => ({ value: item, label: <span className="font-mono">{item}</span> }))}
                    />
                </Field>
                <Field label="Secret name" error={errors.name}>
                    <Input mono value={name} onChange={(event) => setName(event.target.value.toUpperCase())} />
                </Field>
                <Field label="Sensitive" inline hint="Write-only: the value can be replaced, never read back.">
                    <Switch checked={sensitive} onCheckedChange={setSensitive} />
                </Field>
            </form>
        </Dialog>
    );
}

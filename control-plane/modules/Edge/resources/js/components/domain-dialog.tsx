import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';
import { type DnsCredentialOption, type EdgeCertificate, type EdgeDomain, type Option, type TlsMode, type WwwRedirect } from '../types';

interface Props {
    siteId: string;
    /** Edit this domain; add a new one when null. */
    domain: EdgeDomain | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    tlsModes: Option[];
    certificates: EdgeCertificate[];
    dnsCredentials: DnsCredentialOption[];
}

const NONE = '__none__';

const WWW_OPTIONS: { value: WwwRedirect; label: string }[] = [
    { value: 'none', label: 'No www redirect' },
    { value: 'to_www', label: 'Serve www, redirect apex → www' },
    { value: 'to_apex', label: 'Serve apex, redirect www → apex' },
];

export function DomainDialog({ siteId, domain, open, onOpenChange, tlsModes, certificates, dnsCredentials }: Props) {
    const form = useForm({
        name: '',
        tls_mode: 'auto' as TlsMode,
        www_redirect: 'none' as WwwRedirect,
        certificate_id: '',
        dns_credential_id: '',
    });

    useEffect(() => {
        if (open) {
            form.clearErrors();
            form.setData({
                name: domain?.name ?? '',
                tls_mode: domain?.tls_mode ?? 'auto',
                www_redirect: domain?.www_redirect ?? 'none',
                certificate_id: domain?.certificate_id ?? '',
                dns_credential_id: domain?.dns_credential_id ?? '',
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, domain]);

    const wildcard = form.data.name.startsWith('*.');
    const wwwAllowed = !wildcard && !form.data.name.startsWith('www.') && (domain?.supports_www ?? true);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onOpenChange(false) };

        form.transform((data) => ({
            ...data,
            certificate_id: data.tls_mode === 'custom' ? data.certificate_id || null : null,
            dns_credential_id: data.tls_mode === 'dns' ? data.dns_credential_id || null : null,
            www_redirect: wwwAllowed ? data.www_redirect : 'none',
        }));

        if (domain) {
            form.patch(route('edge.domains.update', [siteId, domain.id]), options);
        } else {
            form.post(route('edge.domains.store', siteId), options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{domain ? `Edit ${domain.name}` : 'Add domain'}</DialogTitle>
                        <DialogDescription>
                            Point the domain&apos;s DNS (A/AAAA) at the site&apos;s server — or its load balancer — before enabling automatic TLS.
                        </DialogDescription>
                    </DialogHeader>

                    {!domain && (
                        <div className="grid gap-2">
                            <Label htmlFor="domain-name">Domain</Label>
                            <Input
                                id="domain-name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="example.com or *.example.com"
                                autoFocus
                                required
                            />
                            <InputError message={form.errors.name} />
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label>TLS</Label>
                        <Select value={form.data.tls_mode} onValueChange={(value) => form.setData('tls_mode', value as TlsMode)}>
                            <SelectTrigger aria-label="TLS mode">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {tlsModes.map((mode) => (
                                    <SelectItem key={mode.value} value={mode.value} disabled={wildcard && mode.value === 'auto'}>
                                        {mode.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {wildcard && (
                            <p className="text-muted-foreground text-xs">Wildcard domains need a DNS-01 challenge or a custom certificate.</p>
                        )}
                        <InputError message={form.errors.tls_mode} />
                    </div>

                    {form.data.tls_mode === 'custom' && (
                        <div className="grid gap-2">
                            <Label>Certificate</Label>
                            <Select
                                value={form.data.certificate_id || NONE}
                                onValueChange={(value) => form.setData('certificate_id', value === NONE ? '' : value)}
                            >
                                <SelectTrigger aria-label="Certificate">
                                    <SelectValue placeholder="Choose a certificate" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE} disabled>
                                        {certificates.length === 0 ? 'Upload a certificate first' : 'Choose a certificate'}
                                    </SelectItem>
                                    {certificates.map((certificate) => (
                                        <SelectItem key={certificate.id} value={certificate.id}>
                                            {certificate.domains.join(', ')}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.certificate_id} />
                        </div>
                    )}

                    {form.data.tls_mode === 'dns' && (
                        <div className="grid gap-2">
                            <Label>DNS provider credential</Label>
                            <Select
                                value={form.data.dns_credential_id || NONE}
                                onValueChange={(value) => form.setData('dns_credential_id', value === NONE ? '' : value)}
                            >
                                <SelectTrigger aria-label="DNS credential">
                                    <SelectValue placeholder="Choose a credential" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE} disabled>
                                        {dnsCredentials.length === 0 ? 'An admin must add a DNS credential first' : 'Choose a credential'}
                                    </SelectItem>
                                    {dnsCredentials.map((credential) => (
                                        <SelectItem key={credential.id} value={credential.id}>
                                            {credential.name} ({credential.provider})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.dns_credential_id} />
                        </div>
                    )}

                    {wwwAllowed && (
                        <div className="grid gap-2">
                            <Label>www redirect</Label>
                            <Select value={form.data.www_redirect} onValueChange={(value) => form.setData('www_redirect', value as WwwRedirect)}>
                                <SelectTrigger aria-label="www redirect">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {WWW_OPTIONS.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.www_redirect} />
                        </div>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {domain ? 'Save' : 'Add domain'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

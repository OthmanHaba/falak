import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm, usePage } from '@inertiajs/react';
import { FileLock2, Trash2, Upload } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { type EdgeCertificate } from '../types';
import { StatusBadge, formatDate } from './edge-ui';

interface Props {
    siteId: string;
    certificates: EdgeCertificate[];
    canManage: boolean;
}

export function CertificatesCard({ siteId, certificates, canManage }: Props) {
    const [open, setOpen] = useState(false);
    const form = useForm({ certificate: '', private_key: '', chain: '' });
    const pageErrors = usePage().props.errors;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('edge.certificates.store', siteId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    const remove = (certificate: EdgeCertificate) => {
        if (window.confirm(`Delete the certificate for ${certificate.domains.join(', ')}? It is removed from every server.`)) {
            router.delete(route('edge.certificates.destroy', [siteId, certificate.id]), { preserveScroll: true });
        }
    };

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-2">
                <div className="space-y-1.5">
                    <CardTitle>Custom certificates</CardTitle>
                    <CardDescription>Uploaded certificates are installed on every server routing this site.</CardDescription>
                </div>
                {canManage && (
                    <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                        <Upload /> Upload
                    </Button>
                )}
            </CardHeader>
            <CardContent className="space-y-2">
                {certificates.length === 0 && <p className="text-muted-foreground text-sm">No custom certificates. Automatic TLS needs none.</p>}
                {certificates.map((certificate) => (
                    <div key={certificate.id} className="flex items-start gap-3 rounded-md border p-3">
                        <FileLock2 className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                        <div className="min-w-0 flex-1 space-y-1">
                            <div className="text-sm font-medium break-all">{certificate.domains.join(', ')}</div>
                            <div className="text-muted-foreground text-xs">
                                {certificate.issuer ?? 'Unknown issuer'} · expires {formatDate(certificate.not_after)}
                                {certificate.expired && <span className="text-destructive"> (expired)</span>}
                            </div>
                            <div className="text-muted-foreground truncate font-mono text-xs">SHA-256 {certificate.fingerprint}</div>
                            <div className="flex flex-wrap gap-2 pt-1">
                                {certificate.installs.map((install) => (
                                    <span
                                        key={install.server_id}
                                        className="inline-flex items-center gap-1 text-xs"
                                        title={install.error ?? undefined}
                                    >
                                        {install.server_name} <StatusBadge status={install.status} />
                                    </span>
                                ))}
                            </div>
                        </div>
                        {canManage && (
                            <Button variant="ghost" size="icon" onClick={() => remove(certificate)} aria-label="Delete certificate">
                                <Trash2 />
                            </Button>
                        )}
                    </div>
                ))}
                {!open && <InputError message={pageErrors.certificate} />}
            </CardContent>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-2xl">
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Upload certificate</DialogTitle>
                            <DialogDescription>PEM encoded. The private key is stored encrypted and only sent to your servers.</DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="cert-pem">Certificate</Label>
                            <Textarea
                                id="cert-pem"
                                rows={6}
                                className="font-mono text-xs"
                                placeholder="-----BEGIN CERTIFICATE-----"
                                value={form.data.certificate}
                                onChange={(e) => form.setData('certificate', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.certificate} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="cert-key">Private key</Label>
                            <Textarea
                                id="cert-key"
                                rows={6}
                                className="font-mono text-xs"
                                placeholder="-----BEGIN PRIVATE KEY-----"
                                value={form.data.private_key}
                                onChange={(e) => form.setData('private_key', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.private_key} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="cert-chain">Intermediate chain (optional)</Label>
                            <Textarea
                                id="cert-chain"
                                rows={4}
                                className="font-mono text-xs"
                                value={form.data.chain}
                                onChange={(e) => form.setData('chain', e.target.value)}
                            />
                            <InputError message={form.errors.chain} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Upload
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

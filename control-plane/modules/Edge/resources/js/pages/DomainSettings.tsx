import { Callout } from '@/components/kiln/callout';
import { Section } from '@/components/kiln/section';
import { Select } from '@/components/kiln/select';
import { Tag } from '@/components/kiln/tag';
import SettingsLayout from '@/layouts/settings/layout';
import { router } from '@inertiajs/react';
import { Globe, Sparkles } from 'lucide-react';
import { useState } from 'react';

interface Props {
    settings: {
        /** 'default' | 'off' | a provider suffix */
        provider: string;
        effective_suffix: string | null;
        default_suffix: string | null;
        providers: string[];
        test_domain: string | null;
    };
    can: { manage: boolean };
}

/** Organization settings → Domains: how new services get a working domain before the user brings their own. */
export default function DomainSettings({ settings, can }: Props) {
    const [saving, setSaving] = useState(false);
    const providers = [...new Set([...settings.providers, ...(settings.default_suffix ? [settings.default_suffix] : [])])];
    const example = settings.effective_suffix ? `minio-files.63-182-218-247.${settings.effective_suffix}` : null;

    const save = (provider: string) => {
        setSaving(true);
        router.put('/settings/domains', { provider }, { preserveScroll: true, onFinish: () => setSaving(false) });
    };

    return (
        <SettingsLayout title="Domains" description="How new services get a public name before (or instead of) your own domain.">
            <Section
                title="Generated domains"
                description="A name that embeds the server’s IP address, answered by a wildcard DNS service: it works immediately, with a Let’s Encrypt certificate, and needs no DNS setup."
            >
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <label htmlFor="generated-provider" className="text-fg flex items-center gap-2 text-sm font-medium">
                        <Sparkles className="text-fg-muted size-4" aria-hidden />
                        Provider
                    </label>
                    <Select
                        id="generated-provider"
                        className="w-64"
                        value={settings.provider}
                        disabled={!can.manage || saving}
                        onValueChange={(value) => value && save(value)}
                        options={[
                            {
                                value: 'default',
                                label: settings.default_suffix ? `Server default (${settings.default_suffix})` : 'Server default (off)',
                            },
                            ...providers.map((provider) => ({ value: provider, label: provider })),
                            { value: 'off', label: 'Off' },
                        ]}
                    />
                </div>
                {example ? (
                    <p className="text-fg-muted text-xs">
                        Example: <span className="text-fg font-mono">{example}</span> → 63.182.218.247
                    </p>
                ) : (
                    <Callout tone="info">
                        Generated domains are off. New services use the test domain{settings.test_domain ? '' : ' (none is configured)'} or your own
                        domain.
                    </Callout>
                )}
                <p className="text-fg-faint text-xs">
                    Names under sslip.io and nip.io are shared with everyone who uses these services (common certificate rate limits, no cookie
                    isolation between sites): use them to try things out, and your own domain for production. Server operators can point{' '}
                    <span className="font-mono">KILN_GENERATED_DOMAIN_SUFFIX</span> at a self-hosted sslip.io server.
                </p>
                {!can.manage && <p className="text-fg-faint text-xs">Only organization admins can change the provider.</p>}
            </Section>

            <Section title="Test domain" description="A wildcard domain run by the operator of this Kiln install (KILN_TEST_DOMAIN).">
                <div className="flex items-center gap-2">
                    <Globe className="text-fg-muted size-4" aria-hidden />
                    {settings.test_domain ? (
                        <>
                            <span className="text-fg font-mono text-sm">*.{settings.test_domain}</span>
                            <Tag tone="success">configured</Tag>
                        </>
                    ) : (
                        <span className="text-fg-muted text-sm">Not configured — new services default to a generated domain.</span>
                    )}
                </div>
            </Section>
        </SettingsLayout>
    );
}

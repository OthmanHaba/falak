import { Button, CopyButton, Input, RelativeTime, Skeleton, StatusDot, Tooltip } from '@/components/kiln';
import { useJson } from '@/hooks/use-json';
import { errorMessage, requestJson } from '@/lib/http';
import { type DomainChoiceType, type DomainPickerProps } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { CircleAlert, CircleCheck, CircleDashed, Globe, Link2, Lock, RefreshCw, Sparkles, TriangleAlert } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { type DnsCheckData, type DomainOptionsData } from '../types';

const HOSTNAME = /^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/;
/** Re-check a custom domain this often while it does not point at the server yet. */
const POLL_MS = 15_000;
const MAX_POLLS = 40;

/** Same rule as Edge's GeneratedDomains::name (the server computes the authoritative name). */
export function generatedName(label: string, ipv4: string, suffix: string): string {
    const clean =
        label
            .toLowerCase()
            .replace(/[^a-z0-9-]+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '')
            .slice(0, 63)
            .replace(/-$/, '') || 'app';

    return `${clean}.${ipv4.replaceAll('.', '-')}.${suffix}`;
}

function normalize(name: string): string {
    return name
        .trim()
        .toLowerCase()
        .replace(/^[a-z][a-z0-9+.-]*:\/\//, '')
        .split('/')[0]
        .replace(/\.$/, '');
}

function query(params: Record<string, string | string[] | undefined | null>): string {
    const search = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
        if (Array.isArray(value)) value.forEach((item) => search.append(`${key}[]`, item));
        else if (value) search.set(key, value);
    });

    return search.toString();
}

function ReadOnlyName({ name, hint, icon }: { name: string; hint: ReactNode; icon: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <div className="border-border bg-surface-2 flex h-8 min-w-0 items-center gap-2 rounded-md border px-2.5">
                <span className="text-fg-faint shrink-0 [&_svg]:size-3.5">{icon}</span>
                <span className="text-fg min-w-0 flex-1 truncate font-mono text-xs" data-testid="domain-name">
                    {name}
                </span>
                <CopyButton value={name} label={`Copy ${name}`} />
            </div>
            <p className="text-fg-muted text-xs">{hint}</p>
        </div>
    );
}

const STATUS_ICON = {
    ok: <CircleCheck className="text-success size-4" aria-hidden />,
    mismatch: <CircleAlert className="text-danger size-4" aria-hidden />,
    proxied: <TriangleAlert className="text-warning size-4" aria-hidden />,
    missing: <CircleDashed className="text-fg-faint size-4" aria-hidden />,
    error: <CircleAlert className="text-warning size-4" aria-hidden />,
} as const;

/** The records to add, the live check (auto-polls until the name points here) and, for existing sites, TLS status. */
export function DnsInstructions({ name, serverIds, siteId, label }: { name: string; serverIds: string[]; siteId?: string | null; label: string }) {
    const [check, setCheck] = useState<DnsCheckData | null>(null);
    const [checking, setChecking] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const polls = useRef(0);
    // Keyed on the ids' content: callers often pass a fresh array every render.
    const servers = serverIds.join(',');
    const url = useMemo(
        () =>
            `/dns/check?${query({ name, server_ids: servers ? servers.split(',') : [], site: siteId ?? undefined, label, tls: siteId ? '1' : undefined })}`,
        [name, servers, siteId, label],
    );

    const run = useCallback(async () => {
        setChecking(true);
        try {
            const response = await requestJson<{ data: DnsCheckData }>(url);
            setCheck(response.data);
            setFailure(null);
        } catch (error) {
            setFailure(errorMessage(error));
        } finally {
            setChecking(false);
        }
    }, [url]);

    // Check shortly after typing stops, then keep polling (while the tab is visible) until DNS is right.
    useEffect(() => {
        polls.current = 0;
        setCheck(null);
        const timer = window.setTimeout(() => void run(), 600);

        return () => window.clearTimeout(timer);
    }, [run]);

    useEffect(() => {
        if (!check || check.status === 'ok' || polls.current >= MAX_POLLS) return;
        const timer = window.setInterval(() => {
            if (document.hidden) return;
            polls.current += 1;
            void run();
        }, POLL_MS);

        return () => window.clearInterval(timer);
    }, [check, run]);

    if (!check) {
        return failure ? <p className="text-danger text-xs">{failure}</p> : <Skeleton className="h-24" />;
    }

    const { instructions } = check;
    // Name the server per record only when the records point at several (round-robin).
    const severalTargets = new Set(instructions.records.map((record) => record.target)).size > 1;

    return (
        <div className="border-border bg-surface-1 grid gap-3 rounded-lg border p-3" aria-label={`DNS for ${name}`}>
            <div className="flex items-start justify-between gap-3">
                <div className="grid gap-0.5">
                    <span className="text-fg text-xs font-medium">
                        {instructions.managed_by
                            ? `Kiln creates ${instructions.records.length === 1 ? 'this record' : 'these records'} in Cloudflare`
                            : `Add ${instructions.records.length === 1 ? 'this record' : 'these records'} at your DNS provider`}
                    </span>
                    <span className="text-fg-faint text-[11px]">
                        Zone <span className="font-mono">{instructions.zone}</span> · TTL {instructions.ttl}s (or “Auto”)
                    </span>
                </div>
                <Button
                    size="sm"
                    variant="secondary"
                    icon={<RefreshCw className={cn(checking && 'animate-spin')} />}
                    onClick={() => void run()}
                    disabled={checking}
                >
                    Check DNS
                </Button>
            </div>

            {instructions.records.length > 0 ? (
                <div className="border-border overflow-x-auto rounded-md border">
                    <table className="w-full text-left text-xs">
                        <thead className="text-fg-faint bg-surface-2 text-[11px]">
                            <tr>
                                <th className="px-2.5 py-1.5 font-medium">Type</th>
                                <th className="px-2.5 py-1.5 font-medium">Name</th>
                                <th className="px-2.5 py-1.5 font-medium">Value</th>
                            </tr>
                        </thead>
                        <tbody className="divide-border divide-y">
                            {instructions.records.map((record) => (
                                <tr key={`${record.type}-${record.value}`}>
                                    <td className="text-fg px-2.5 py-1.5 font-mono">{record.type}</td>
                                    <td className="px-2.5 py-1.5">
                                        <span className="inline-flex items-center gap-1">
                                            <span className="text-fg font-mono">{record.host}</span>
                                            <CopyButton value={record.host} size="xs" label={`Copy name ${record.host}`} />
                                        </span>
                                    </td>
                                    <td className="px-2.5 py-1.5">
                                        <span className="inline-flex items-center gap-1">
                                            <span className="text-fg font-mono">{record.value}</span>
                                            <CopyButton value={record.value} size="xs" label={`Copy value ${record.value}`} />
                                            {severalTargets && record.target && (
                                                <span className="text-fg-faint whitespace-nowrap">{record.target}</span>
                                            )}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : null}

            {instructions.alternative && (
                <p className="text-fg-muted text-xs">
                    Or a <span className="font-mono">CNAME</span> from <span className="text-fg font-mono">{instructions.alternative.host}</span> to{' '}
                    <span className="text-fg font-mono break-all">{instructions.alternative.value}</span>{' '}
                    <CopyButton value={instructions.alternative.value} size="xs" label="Copy CNAME target" />
                </p>
            )}

            {instructions.notes.length > 0 && (
                <ul className="text-fg-muted grid list-disc gap-0.5 pl-4 text-[11px]">
                    {instructions.notes.map((note) => (
                        <li key={note}>{note}</li>
                    ))}
                </ul>
            )}

            <div className="border-border flex items-start gap-2 border-t pt-2.5" role="status" aria-live="polite" data-dns-status={check.status}>
                {STATUS_ICON[check.status]}
                <div className="grid min-w-0 gap-0.5">
                    <span
                        className={cn('text-xs', check.status === 'ok' ? 'text-fg' : check.status === 'mismatch' ? 'text-danger' : 'text-fg-muted')}
                    >
                        {check.status === 'ok' ? `✓ ${check.message}` : check.message}
                    </span>
                    <span className="text-fg-faint text-[11px]">
                        Checked <RelativeTime value={check.checked_at} />
                        {check.status !== 'ok' && polls.current < MAX_POLLS && ' · re-checking automatically'}
                    </span>
                </div>
            </div>

            {check.certificate && (
                <div className="flex items-center gap-2 text-xs">
                    <Lock className="text-fg-faint size-3.5" aria-hidden />
                    <StatusDot status={check.certificate.status === 'issued' ? 'active' : 'pending'} />
                    <span className="text-fg-muted">
                        {check.certificate.status === 'issued' ? 'Certificate issued' : 'Certificate pending'}
                        {check.certificate.issuer && ` · ${check.certificate.issuer}`}
                        {check.certificate.expires_at && (
                            <>
                                {' '}
                                · renews before <RelativeTime value={check.certificate.expires_at} />
                            </>
                        )}
                    </span>
                    {check.certificate.status === 'pending' && (
                        <Tooltip content={check.certificate.message}>
                            <span className="text-fg-faint underline decoration-dotted underline-offset-2">why?</span>
                        </Tooltip>
                    )}
                </div>
            )}
        </div>
    );
}

function useDomainOptions(serverIds: string[], siteId: string | null) {
    return useJson<DomainOptionsData>(
        serverIds.length > 0 || siteId ? `/domains/options?${query({ server_ids: serverIds, site: siteId ?? undefined })}` : null,
    );
}

function GeneratedHint({ data }: { data: DomainOptionsData }) {
    return (
        <>
            Resolves to {data.generated.target?.name} ({data.generated.ipv4}) through {data.generated.suffix} — no DNS setup. Let’s Encrypt issues the
            certificate on the first request. Good for trying things out; use your own domain for production.
        </>
    );
}

/** The generated name a site would get (Networking → Add domain → Generate one). */
export function GeneratedDomainPreview({ label, serverIds, siteId = null }: { label: string; serverIds: string[]; siteId?: string | null }) {
    const { data, error } = useDomainOptions(serverIds, siteId);

    if (error) return <p className="text-danger text-xs">{error}</p>;
    if (!data) return <Skeleton className="h-8" />;
    if (!data.generated.available || !data.generated.ipv4 || !data.generated.suffix) {
        return <p className="text-fg-muted text-xs">{data.generated.reason ?? 'Generated domains are not available.'}</p>;
    }

    return (
        <ReadOnlyName
            name={generatedName(label, data.generated.ipv4, data.generated.suffix)}
            icon={<Sparkles />}
            hint={<GeneratedHint data={data} />}
        />
    );
}

interface ChoiceButton {
    value: DomainChoiceType;
    label: string;
    icon: ReactNode;
    disabled?: string | null;
}

/**
 * Domain picker (registered through registerDomainPicker): generate `<label>.<ip>.sslip.io`, use the Kiln test domain,
 * or bring your own with DNS instructions and a live DNS check.
 */
export function DomainPicker({
    label,
    serverIds,
    siteId = null,
    testDomain: testDomainProp,
    value,
    onChange,
    error,
    ariaLabel,
    customOnly = false,
}: DomainPickerProps) {
    const optionsUrl = serverIds.length > 0 || siteId;
    const options = useDomainOptions(serverIds, siteId);
    const data = options.data;
    // Sites get <slug>.<base>; callers with another scheme (compose services) pass it explicitly.
    const testDomain = testDomainProp === undefined ? (data?.test_domain ? `${label}.${data.test_domain}` : null) : testDomainProp;
    const [draft, setDraft] = useState(value?.type === 'custom' ? (value.name ?? '') : '');
    const [debounced, setDebounced] = useState(normalize(draft));

    useEffect(() => {
        const timer = window.setTimeout(() => setDebounced(normalize(draft)), 400);

        return () => window.clearTimeout(timer);
    }, [draft]);

    const generatedAvailable = !!data?.generated.available;
    const choices: ChoiceButton[] = customOnly
        ? []
        : [
              {
                  value: 'generated',
                  label: data?.generated.suffix ? `Generate (${data.generated.suffix})` : 'Generate',
                  icon: <Sparkles />,
                  disabled: data && !generatedAvailable ? (data.generated.reason ?? 'Not available') : null,
              },
              ...(testDomain && data?.test_domain ? [{ value: 'test' as const, label: 'Test domain', icon: <Globe /> }] : []),
              { value: 'custom', label: 'Custom domain', icon: <Link2 /> },
          ];

    // Apply the organization default once the options are known (and fall back when a choice is unavailable).
    const type: DomainChoiceType | null = customOnly ? 'custom' : (value?.type ?? null);
    useEffect(() => {
        if (!data || customOnly) return;
        const unavailable = (type === 'generated' && !generatedAvailable) || (type === 'test' && !(testDomain && data.test_domain));
        if (type !== null && !unavailable) return;
        const fallback: DomainChoiceType =
            data.default === 'test' && testDomain && data.test_domain
                ? 'test'
                : data.default !== 'custom' && generatedAvailable
                  ? 'generated'
                  : 'custom';
        if (fallback !== type) onChange(fallback === 'custom' ? { type: 'custom', name: draft } : { type: fallback });
    }, [data, type, generatedAvailable, testDomain, customOnly, onChange, draft]);

    const generated =
        data?.generated.available && data.generated.ipv4 && data.generated.suffix
            ? generatedName(label, data.generated.ipv4, data.generated.suffix)
            : null;
    // A saved generated name (it comes back as a plain domain) shows as "Generate".
    const shown: DomainChoiceType | null = type === 'custom' && generated !== null && normalize(value?.name ?? '') === generated ? 'generated' : type;
    const customValid = HOSTNAME.test(debounced) && !debounced.startsWith('*.');

    return (
        <div className="grid gap-2.5">
            {choices.length > 0 && (
                <div
                    role="radiogroup"
                    aria-label={ariaLabel}
                    className="border-border bg-surface-1 grid grid-cols-1 gap-0.5 rounded-md border p-0.5 sm:auto-cols-fr sm:grid-flow-col"
                >
                    {choices.map((choice) => {
                        const active = shown === choice.value;
                        const button = (
                            <button
                                key={choice.value}
                                type="button"
                                role="radio"
                                aria-checked={active}
                                aria-disabled={!!choice.disabled}
                                disabled={!!choice.disabled}
                                onClick={() => onChange(choice.value === 'custom' ? { type: 'custom', name: draft } : { type: choice.value })}
                                className={cn(
                                    'inline-flex h-7 min-w-0 items-center justify-center gap-1.5 rounded-sm px-2 text-xs font-medium whitespace-nowrap transition-colors duration-150',
                                    'focus-visible:outline-primary focus-visible:outline-2 focus-visible:outline-offset-2 [&_svg]:size-3.5',
                                    active ? 'bg-surface-3 text-fg' : 'text-fg-muted hover:text-fg',
                                    choice.disabled && 'hover:text-fg-muted cursor-not-allowed opacity-50',
                                )}
                            >
                                {choice.icon}
                                <span className="truncate">{choice.label}</span>
                            </button>
                        );

                        return choice.disabled ? (
                            <Tooltip key={choice.value} content={choice.disabled}>
                                <span className="grid">{button}</span>
                            </Tooltip>
                        ) : (
                            button
                        );
                    })}
                </div>
            )}

            {!data && optionsUrl && !options.error && type !== 'custom' && <Skeleton className="h-8" />}
            {options.error && <p className="text-danger text-xs">{options.error}</p>}

            {shown === 'generated' && generated && (
                <ReadOnlyName name={generated} icon={<Sparkles />} hint={data ? <GeneratedHint data={data} /> : null} />
            )}

            {shown === 'test' && testDomain && (
                <ReadOnlyName name={testDomain} icon={<Globe />} hint="Kiln’s wildcard test domain. Add your own domain any time in Networking." />
            )}

            {shown === 'custom' && (
                <div className="grid gap-2.5">
                    <Input
                        aria-label={customOnly ? ariaLabel : `${ariaLabel}: custom domain`}
                        value={draft}
                        onChange={(event) => {
                            setDraft(event.target.value);
                            onChange({ type: 'custom', name: event.target.value });
                        }}
                        placeholder="app.example.com"
                        prefix={<Link2 aria-hidden />}
                        spellCheck={false}
                        autoCapitalize="none"
                        mono
                        aria-invalid={!!error}
                    />
                    {customValid ? (
                        <DnsInstructions name={debounced} serverIds={serverIds} siteId={siteId} label={label} />
                    ) : (
                        <p className="text-fg-muted text-xs">
                            {draft.trim() === ''
                                ? 'Enter the domain you own; Kiln shows the DNS record to add and checks it for you.'
                                : 'Enter a domain name like app.example.com.'}
                        </p>
                    )}
                </div>
            )}

            {error && (
                <p role="alert" className="text-danger text-xs">
                    {error}
                </p>
            )}
        </div>
    );
}

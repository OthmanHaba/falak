import { type LogLine, type PhaseRow } from '@/components/falak';
import { useEchoChannel } from '@/hooks/use-echo-channel';
import { errorMessage, requestJson } from '@/lib/http';
import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { type OutputLine, type Step, type StepStatus, type Target } from '../types';
import { TERMINAL, deploymentsUrl, type DeploymentDetail } from './api';

export const PHASES = [
    { id: 'build', label: 'Build' },
    { id: 'fetch', label: 'Fetch' },
    { id: 'prepare', label: 'Prepare' },
    { id: 'migrate', label: 'Migrate' },
    { id: 'activate', label: 'Activate' },
    { id: 'restart', label: 'Restart' },
    { id: 'healthcheck', label: 'Health' },
    { id: 'rollback', label: 'Rollback' },
];

/** Worst status of a phase's steps (failed > running > pending > succeeded > skipped). */
function phaseCell(steps: Step[]) {
    if (steps.length === 0) return undefined;
    const status =
        (['failed', 'running', 'pending', 'succeeded'] as StepStatus[]).find((candidate) => steps.some((step) => step.status === candidate)) ??
        'skipped';

    return { status, durationMs: steps.reduce((sum, step) => sum + (step.duration_ms ?? 0), 0) || null };
}

/** Per-server rows of the phase timeline (build is shared by every server). */
export function timelineRows(targets: Target[], globalSteps: Step[], label: (target: Target) => PhaseRow['label']): PhaseRow[] {
    const build = phaseCell(globalSteps.filter((step) => step.phase === 'build' || step.kind === 'build'));

    return targets.map((target) => ({
        id: target.id,
        label: label(target),
        cells: Object.fromEntries(
            PHASES.map((phase) => [phase.id, phase.id === 'build' ? build : phaseCell(target.steps.filter((step) => step.phase === phase.id))]),
        ),
    }));
}

/** Output line → LogViewer line (ISO time, stderr bar, "server · phase" group). */
export function toLogLine(line: OutputLine): LogLine {
    return {
        text: line.data.replace(/\n$/, ''),
        time: line.at,
        phase: [line.server ?? 'falak', line.phase].filter(Boolean).join(' · '),
        stream: line.stream,
    };
}

/** Live-ticking re-render (running durations). */
export function useTick(active: boolean) {
    const [, tick] = useReducer((n: number) => n + 1, 0);
    useEffect(() => {
        if (!active) return;
        const timer = window.setInterval(tick, 1000);

        return () => window.clearInterval(timer);
    }, [active]);
}

/**
 * One deployment with its targets, steps and output, streamed live (Reverb `deployments.{id}`, polling fallback).
 * `onFinished` runs once when the deployment reaches a terminal state while open (the canvas card follows it).
 */
export function useDeployment(siteId: string, deploymentId: string, onFinished?: () => void) {
    const [detail, setDetail] = useState<Omit<DeploymentDetail, 'lines'> | null>(null);
    const [lines, setLines] = useState<OutputLine[]>([]);
    const [error, setError] = useState<string | null>(null);
    const lastSeq = useRef(0);
    const url = `${deploymentsUrl(siteId)}/${deploymentId}`;

    const append = useCallback((incoming: OutputLine[]) => {
        const fresh = incoming.filter((line) => line.seq > lastSeq.current).sort((a, b) => a.seq - b.seq);
        if (fresh.length === 0) return;
        lastSeq.current = fresh[fresh.length - 1].seq;
        setLines((current) => [...current, ...fresh]);
    }, []);

    useEffect(() => {
        let cancelled = false;
        lastSeq.current = 0;
        setLines([]);
        requestJson<{ data: DeploymentDetail }>(url)
            .then((body) => {
                if (cancelled) return;
                const { lines: initial, ...rest } = body.data;
                setDetail(rest);
                append(initial);
            })
            .catch((e: unknown) => !cancelled && setError(errorMessage(e, 'Could not load the deployment')));

        return () => {
            cancelled = true;
        };
    }, [url, append]);

    const refresh = useCallback(async () => {
        try {
            const body = await requestJson<{ data: Omit<DeploymentDetail, 'can'> }>(`${url}/state?after=${lastSeq.current}`);
            setDetail((current) =>
                current ? { ...current, deployment: body.data.deployment, targets: body.data.targets, steps: body.data.steps } : current,
            );
            append(body.data.lines);
        } catch {
            // Transient: the next tick retries.
        }
    }, [url, append]);

    const live = useEchoChannel<{ lines?: OutputLine[] }>(
        `deployments.${deploymentId}`,
        ['deployment.updated', 'deployment.output'],
        (event, payload) => {
            if (event === 'deployment.output' && payload.lines) append(payload.lines);
            else void refresh();
        },
    );

    const deployment = detail?.deployment;
    const terminal = deployment ? TERMINAL.includes(deployment.status) : false;
    // A live release's watch window keeps the view fresh (countdown, trigger status) after the deployment finished.
    const watching = deployment?.watch?.status === 'watching';
    useTick(Boolean(deployment && (!terminal || watching)));

    useEffect(() => {
        if (!deployment || (terminal && !watching)) return;
        const timer = window.setInterval(() => void refresh(), terminal ? (live ? 30000 : 10000) : live ? 10000 : 2000);

        return () => window.clearInterval(timer);
    }, [deployment, terminal, watching, live, refresh]);

    const wasTerminal = useRef(terminal);
    const finished = useRef(onFinished);
    finished.current = onFinished;
    useEffect(() => {
        if (terminal && !wasTerminal.current) finished.current?.();
        wasTerminal.current = terminal;
    }, [terminal]);

    const buildLines = useMemo(() => lines.filter((line) => line.phase === 'build').map(toLogLine), [lines]);
    // Servers deploy in parallel, so their output interleaves: group it per server (deployment order, orchestrator
    // lines first), chronological inside a server, so each line sits under its own "server · phase" header.
    const serverOrder = useMemo(() => (detail?.targets ?? []).map((target) => target.server_id), [detail?.targets]);
    const deployLines = useMemo(() => {
        const rank = (line: OutputLine) => (line.server_id ? serverOrder.indexOf(line.server_id) + 1 || serverOrder.length + 1 : 0);

        return lines
            .filter((line) => line.phase !== 'build')
            .map((line, index) => ({ line, index }))
            .sort((a, b) => rank(a.line) - rank(b.line) || a.index - b.index)
            .map(({ line }) => toLogLine(line));
    }, [lines, serverOrder]);

    return { url, detail, error, terminal, refresh, buildLines, deployLines };
}

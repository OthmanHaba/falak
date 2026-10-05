import { Section } from '@/components/falak/section';
import ServerLayout, { type ServerHeader } from '@/layouts/server-layout';
import { usePoll } from '@inertiajs/react';
import { Film, SquareTerminal } from 'lucide-react';
import { OpenSessionForm } from '../components/open-session-form';
import { LiveSessionsTable, RecordingsTable } from '../components/session-tables';
import { formatDuration } from '../lib';
import { type TerminalSessionData } from '../types';

interface Props {
    server: ServerHeader;
    unixUser: string;
    sessions: TerminalSessionData[];
    recordings: TerminalSessionData[];
    defaultUser: string;
    idleTimeout: number;
    serverActive: boolean;
    can: { open: boolean; replay: boolean };
}

export default function Server({ server, unixUser, sessions, recordings, defaultUser, idleTimeout, serverActive, can }: Props) {
    usePoll(sessions.length > 0 ? 10_000 : 60_000, { only: ['sessions', 'recordings'] });

    return (
        <ServerLayout server={server} tab="terminal" reloadOnly={['server', 'sessions', 'recordings']}>
            <Section
                title="Open a shell"
                description={`A browser terminal through the Falak agent — no inbound SSH needed. Sessions are recorded and close after ${formatDuration(idleTimeout)} idle.`}
            >
                {can.open ? (
                    <OpenSessionForm serverId={server.id} defaultUser={defaultUser} unixUser={unixUser} />
                ) : (
                    <p className="text-fg-muted text-sm">
                        {serverActive
                            ? 'You need the terminal.open permission to start sessions.'
                            : 'Terminals can be opened once the server is active.'}
                    </p>
                )}
            </Section>

            <Section title="Live sessions" bare>
                <LiveSessionsTable
                    sessions={sessions}
                    showServer={false}
                    empty={{
                        icon: <SquareTerminal />,
                        title: 'No live sessions',
                        description: 'Open a shell above; shared sessions of teammates show up here too.',
                        size: 'sm',
                    }}
                />
            </Section>

            <Section title="Recordings" description="Every session is recorded as asciicast for replay and audit." bare>
                <RecordingsTable
                    recordings={recordings}
                    showServer={false}
                    canReplayAll={can.replay}
                    empty={{
                        icon: <Film />,
                        title: 'No recordings yet',
                        description: 'Closed sessions on this server appear here for playback.',
                        size: 'sm',
                    }}
                />
            </Section>
        </ServerLayout>
    );
}

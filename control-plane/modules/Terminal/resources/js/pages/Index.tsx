import { EmptyState } from '@/components/kiln/empty-state';
import { Section } from '@/components/kiln/section';
import InfrastructureLayout from '@/layouts/infrastructure-layout';
import { usePoll } from '@inertiajs/react';
import { Film, SquareTerminal } from 'lucide-react';
import { OpenSessionForm } from '../components/open-session-form';
import { LiveSessionsTable, RecordingsTable } from '../components/session-tables';
import { formatDuration } from '../lib';
import { type TerminalSessionData } from '../types';

interface ServerOption {
    id: string;
    name: string;
    ipv4: string | null;
    unix_user: string;
}

interface Props {
    servers: ServerOption[];
    sessions: TerminalSessionData[];
    recordings: TerminalSessionData[];
    defaultUser: string;
    idleTimeout: number;
    can: { open: boolean };
}

export default function Index({ servers, sessions, recordings, defaultUser, idleTimeout, can }: Props) {
    usePoll(sessions.length > 0 ? 10_000 : 60_000, { only: ['sessions', 'recordings'] });

    return (
        <InfrastructureLayout section="terminal" description="Browser shells on your servers through the Kiln agent. Every session is recorded.">
            {can.open && (
                <Section title="Open a shell" description={`Sessions close after ${formatDuration(idleTimeout)} without activity.`}>
                    {servers.length === 0 ? (
                        <EmptyState
                            size="sm"
                            icon={<SquareTerminal />}
                            title="No active servers"
                            description="Terminals open on servers whose agent is connected."
                        />
                    ) : (
                        <OpenSessionForm servers={servers} defaultUser={defaultUser} unixUser={servers[0]?.unix_user ?? 'kiln'} />
                    )}
                </Section>
            )}

            <Section title="Live sessions" bare>
                <LiveSessionsTable
                    sessions={sessions}
                    empty={{
                        icon: <SquareTerminal />,
                        title: 'No live sessions',
                        description: 'Your open sessions and ones teammates share with you appear here.',
                        size: 'sm',
                    }}
                />
            </Section>

            <Section title="Recordings" description="Closed sessions, replayable as asciicast." bare>
                <RecordingsTable
                    recordings={recordings}
                    canReplayAll
                    empty={{
                        icon: <Film />,
                        title: 'No recordings yet',
                        description: 'Recordings of closed sessions appear here for playback and audit.',
                        size: 'sm',
                    }}
                />
            </Section>
        </InfrastructureLayout>
    );
}

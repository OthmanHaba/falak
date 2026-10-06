import { Button, toast } from '@/components/falak';
import ServerLayout, { type ServerHeader } from '@/layouts/server-layout';
import { errorMessage, requestJson } from '@/lib/http';
import { router, usePoll } from '@inertiajs/react';
import { Plus, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { NewVolumeDialog, VolumesTable } from '../components/volume-ui';
import { type CreationProps, type Volume } from '../types';

interface Props extends CreationProps {
    server: ServerHeader;
    volumes: Volume[];
}

const RELOAD = ['server', 'volumes'];

/** /servers/{id}/volumes: every volume on the server, with its usage, services and backups. */
export default function Server({ server, volumes, ...creation }: Props) {
    const [creating, setCreating] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const busy = volumes.some((volume) => volume.status === 'pending' || volume.status === 'deleting');

    usePoll(busy ? 3_000 : 60_000, { only: RELOAD });

    const refresh = async () => {
        setRefreshing(true);
        try {
            await requestJson(`/servers/${server.id}/volumes/refresh`, 'POST', {});
            toast.success('Measuring usage', 'The server reports its volumes in a few seconds.');
            window.setTimeout(() => router.reload({ only: RELOAD }), 5_000);
        } catch (error) {
            toast.error('Could not refresh', errorMessage(error));
        } finally {
            setRefreshing(false);
        }
    };

    return (
        <ServerLayout
            server={server}
            tab="volumes"
            reloadOnly={RELOAD}
            actions={
                creation.can.manage && (
                    <>
                        <Button icon={<RefreshCw />} onClick={() => void refresh()} loading={refreshing}>
                            Refresh usage
                        </Button>
                        <Button variant="primary" icon={<Plus />} onClick={() => setCreating(true)}>
                            New volume
                        </Button>
                    </>
                )
            }
        >
            <VolumesTable volumes={volumes} onCreate={creation.can.manage ? () => setCreating(true) : undefined} />
            <NewVolumeDialog open={creating} onOpenChange={setCreating} creation={creation} serverId={server.id} />
        </ServerLayout>
    );
}

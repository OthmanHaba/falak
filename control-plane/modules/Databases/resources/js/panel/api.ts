import { toast } from '@/components/kiln';
import { useJson } from '@/hooks/use-json';
import { errorMessage, requestJson, type HttpMethod } from '@/lib/http';
import { type ServicePanelContext } from '@/lib/registry';
import {
    type BackupRow,
    type Connection,
    type DatabaseRow,
    type DatabaseServer,
    type DatabaseUserRow,
    type RestoreRow,
    type ScheduleRow,
    type StorageOption,
} from '../types';

/** GET /databases/databases/{database} (JSON): everything the database panel shows. */
export interface DatabasePanelData {
    database: DatabaseRow;
    server: DatabaseServer;
    connection: Connection;
    users: DatabaseUserRow[];
    schedules: ScheduleRow[];
    backups: BackupRow[];
    restores: RestoreRow[];
    storage_providers: StorageOption[];
    restore_targets: { id: string; label: string }[];
    options: {
        privileges: string[];
        versions: string[];
        compressions: string[];
        /** Redis / Valkey */
        evictions: string[];
        persistences: string[];
        max_memory_mb: number | null;
    };
    can: { manage: boolean; reveal: boolean; restore: boolean; manage_storage: boolean };
}

const BUSY = ['pending', 'running', 'deleting'];

/** One shared, polled request for all database tabs (fast while something is in progress). */
export function useDatabasePanel(ctx: ServicePanelContext) {
    const url = `/databases/databases/${ctx.service.ref_id}`;
    const first = useJson<DatabasePanelData>(url, { interval: 20000 });
    const data = first.data;
    const busy = Boolean(
        data &&
        (BUSY.includes(data.database.status) ||
            data.users.some((user) => BUSY.includes(user.status)) ||
            data.backups.some((backup) => BUSY.includes(backup.status)) ||
            data.restores.some((restore) => BUSY.includes(restore.status))),
    );
    // A second subscription only adds the fast poll while busy (same cached URL).
    useJson<DatabasePanelData>(busy ? url : null, { interval: 3000 });

    return first;
}

/** Run a Databases mutation (they answer with a redirect back; the panel re-fetches instead). */
export async function mutate(
    method: HttpMethod,
    url: string,
    body: unknown,
    done: { success: string; reload: () => Promise<void> },
): Promise<boolean> {
    try {
        await requestJson(url, method, body);
        toast.success(done.success);
        await done.reload();

        return true;
    } catch (error) {
        toast.error('Something went wrong', errorMessage(error));

        return false;
    }
}

export function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(value < 10 && unit > 0 ? 1 : 0)} ${units[unit]}`;
}

/** Status words of the Databases module in the Kiln status language. */
export function resourceStatus(status: string): string {
    return status === 'pending' ? 'provisioning' : status === 'deleting' ? 'removed' : status;
}

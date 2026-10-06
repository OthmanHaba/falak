import { describe, expect, it } from 'vitest';
import { retargetRestore, type RestoreTarget } from './types';

const targets: RestoreTarget[] = [
    { id: 'redis-a', label: 'app-1 · Redis', engine: 'redis', instances: ['cache', 'sessions'] },
    { id: 'valkey-b', label: 'app-2 · Valkey', engine: 'valkey', instances: ['queue'] },
] as RestoreTarget[];

describe('retargetRestore', () => {
    it('clears an instance the new server does not have, and the confirmation', () => {
        expect(retargetRestore({ database_server_id: 'redis-a', database: 'cache', confirm: 'cache' }, 'valkey-b', true, targets)).toEqual({
            database_server_id: 'valkey-b',
            database: '',
            confirm: '',
        });
    });

    it('keeps an instance the new server has too', () => {
        const data = { database_server_id: 'valkey-b', database: 'cache', confirm: 'cache' };
        expect(retargetRestore(data, 'redis-a', true, targets)).toEqual({ ...data, database_server_id: 'redis-a' });
    });

    it('keeps the typed database name for SQL (created when missing)', () => {
        expect(retargetRestore({ database_server_id: 'pg-1', database: 'shop', confirm: 'shop' }, 'pg-2', false, [])).toEqual({
            database_server_id: 'pg-2',
            database: 'shop',
            confirm: 'shop',
        });
    });
});

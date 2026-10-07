import { describe, expect, it } from 'vitest';
import { AGE_IDENTITY, AGE_RECIPIENT, instanceState, needsIdentity, retargetRestore, type RestoreTarget } from './types';

const targets: RestoreTarget[] = [
    { id: 'pg-a', label: 'shop (PostgreSQL 17 on app-1)', engine: 'postgresql', databases: ['shop', 'analytics'] },
    { id: 'pg-b', label: 'blog (PostgreSQL 17 on app-2)', engine: 'postgresql', databases: ['blog'] },
];

describe('retargetRestore', () => {
    it('clears a database the new target does not have, and the confirmation', () => {
        expect(retargetRestore({ database_instance_id: 'pg-a', database: 'shop', confirm: 'shop' }, 'pg-b', targets)).toEqual({
            database_instance_id: 'pg-b',
            database: '',
            confirm: '',
        });
    });

    it('keeps a database the new target has too', () => {
        const data = { database_instance_id: 'pg-b', database: 'shop', confirm: 'shop' };
        expect(retargetRestore(data, 'pg-a', targets)).toEqual({ ...data, database_instance_id: 'pg-a' });
    });
});

describe('instanceState', () => {
    it('shows provisioning while the container is created', () => {
        expect(instanceState({ status: 'pending', health: null })).toBe('provisioning');
    });

    it('shows the heartbeat health of a running container', () => {
        expect(instanceState({ status: 'active', health: 'unhealthy' })).toBe('degraded');
        expect(instanceState({ status: 'active', health: 'missing' })).toBe('offline');
        expect(instanceState({ status: 'active', health: 'healthy' })).toBe('active');
    });
});

describe('backup keys', () => {
    it('recognizes age recipients and identities', () => {
        expect(AGE_RECIPIENT.test('age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p')).toBe(true);
        expect(AGE_RECIPIENT.test('ssh-ed25519 AAAAC3Nz')).toBe(false);
        expect(AGE_IDENTITY.test('AGE-SECRET-KEY-1' + 'Q'.repeat(58))).toBe(true);
        expect(AGE_IDENTITY.test('AGE-SECRET-KEY-1nope')).toBe(false);
    });

    it('asks for the identity only for customer-held backups', () => {
        expect(needsIdentity({ encryption_mode: 'customer' })).toBe(true);
        expect(needsIdentity({ encryption_mode: 'cp' })).toBe(false);
    });
});

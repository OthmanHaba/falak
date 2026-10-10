import { describe, expect, it } from 'vitest';
import {
    AGE_IDENTITY,
    AGE_RECIPIENT,
    ageOf,
    inRecoveryRange,
    instanceState,
    isoToUtcInput,
    needsIdentity,
    retargetRestore,
    timelineBar,
    utcInputToIso,
    type PitrTimeline,
    type RestoreTarget,
} from './types';

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

const timeline: PitrTimeline = {
    ranges: [
        { from: '2026-10-09T08:00:30.000Z', to: '2026-10-09T09:00:00.000Z', base_id: 'b1' },
        { from: '2026-10-09T10:30:20.000Z', to: '2026-10-09T11:00:00.000Z', base_id: 'b2' },
    ],
    gaps: [{ at: '2026-10-09T09:30:00.000Z', detail: 'segments lost', resolved: true }],
    from: '2026-10-09T08:00:30.000Z',
    to: '2026-10-09T11:00:00.000Z',
    last_segment_at: '2026-10-09T11:00:00.000Z',
};

describe('point-in-time recovery', () => {
    it('knows which times are recoverable (not across a gap)', () => {
        expect(inRecoveryRange(timeline, '2026-10-09T08:30:00Z')).toBe(true);
        expect(inRecoveryRange(timeline, '2026-10-09T09:45:00Z')).toBe(false);
        expect(inRecoveryRange(timeline, '2026-10-09T11:00:00Z')).toBe(true);
        expect(inRecoveryRange(null, '2026-10-09T08:30:00Z')).toBe(false);
    });

    it('places ranges and gaps on the bar, clipped to the window', () => {
        const start = Date.parse('2026-10-09T08:00:00Z');
        const end = Date.parse('2026-10-09T12:00:00Z');
        const bar = timelineBar(timeline, start, end);
        expect(bar.ranges).toHaveLength(2);
        expect(bar.ranges[0].left).toBeCloseTo(0.208, 2);
        expect(bar.gaps[0].left).toBeCloseTo(37.5, 5);
        expect(timelineBar(timeline, Date.parse('2026-10-09T10:00:00Z'), end).ranges).toHaveLength(1);
    });

    it('reads the picker as UTC, to the second for MySQL / MariaDB', () => {
        expect(utcInputToIso('2026-10-09T08:30')).toBe('2026-10-09T08:30:00Z');
        expect(utcInputToIso('2026-10-09T08:30:05.250')).toBe('2026-10-09T08:30:05.250Z');
        expect(utcInputToIso('2026-10-09T08:30:05.250', true)).toBe('2026-10-09T08:30:05Z');
        expect(utcInputToIso('yesterday')).toBeNull();
        expect(isoToUtcInput('2026-10-09T08:30:05.250Z')).toBe('2026-10-09T08:30:05');
    });

    it('says how long ago the last segment shipped', () => {
        const now = Date.parse('2026-10-09T12:00:00Z');
        expect(ageOf('2026-10-09T11:59:48Z', now)).toBe('12 s');
        expect(ageOf('2026-10-09T11:50:00Z', now)).toBe('10 min');
        expect(ageOf(null, now)).toBe('—');
    });
});

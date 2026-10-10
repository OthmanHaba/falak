<?php

namespace Falak\Kernel\Security;

use InvalidArgumentException;

/**
 * Backup encryption keys (docs/BACKUPS.md). Every backup (database dump, Redis snapshot, volume archive) is an FKB1
 * file encrypted by the agent with a random 32-byte data key of its own:
 *
 *  - cp (default): the control plane generates the key, seals it under the organization's data key with the AAD
 *    ("backup", organization, backup id) and keeps that envelope on the backup row. The raw key only travels inside the
 *    command payload (sealed at rest, masked by the agent, forgotten once the command settled).
 *  - customer: the schedule holds an age X25519 recipient; the agent generates the key and stores it in the file's
 *    header, encrypted to that recipient. The control plane never has it: a restore needs the customer's identity
 *    (AGE-SECRET-KEY-1…), given for that one command, or works offline with falak-restore.
 */
final class BackupKeys
{
    use RedactsKeyMaterial;

    public const CP = 'cp';

    public const CUSTOMER = 'customer';

    /** An age X25519 recipient (bech32, 32 bytes). */
    public const RECIPIENT_PATTERN = '/^age1[02-9ac-hj-np-z]{58}$/';

    /** An age X25519 identity (bech32, upper case). */
    public const IDENTITY_PATTERN = '/^AGE-SECRET-KEY-1[02-9AC-HJ-NP-Z]{58}$/';

    public function __construct(private readonly Sealer $sealer) {}

    /**
     * The AAD a data key is sealed under. A point-in-time recovery segment's names its instance too
     * ("pitr-segment", organization, instance, segment): a key opens only for that segment of that instance.
     */
    public static function aad(string $organizationId, string $backupId, ?string $instanceId = null): string
    {
        return $instanceId === null
            ? Sealer::aad('backup', $organizationId, $backupId)
            : Sealer::aad('pitr-segment', $organizationId, $instanceId, $backupId);
    }

    /**
     * A new data key for a backup: the payload's `encryption` object (with the raw key) and the sealed envelope to keep
     * on the backup row.
     *
     * $instanceId: a point-in-time recovery segment's instance (bound into the AAD).
     *
     * @return array{0: array{mode: string, key_id: string, key: string}, 1: string}
     */
    public function generate(string $organizationId, string $backupId, ?string $instanceId = null): array
    {
        $key = random_bytes(Aead::KEY_BYTES);

        try {
            return [
                ['mode' => self::CP, 'key_id' => $backupId, 'key' => base64_encode($key)],
                $this->sealer->seal($key, self::aad($organizationId, $backupId, $instanceId), $organizationId),
            ];
        } finally {
            sodium_memzero($key);
        }
    }

    /**
     * The raw data key of a cp-mode backup.
     *
     * @throws DecryptionFailed when the envelope belongs to another backup or organization, or was changed
     */
    public function unwrap(string $wrappedKey, string $organizationId, string $backupId, ?string $instanceId = null): string
    {
        $key = $this->sealer->open($wrappedKey, self::aad($organizationId, $backupId, $instanceId), $organizationId);

        if (strlen($key) !== Aead::KEY_BYTES) {
            throw new DecryptionFailed('The backup key is not 32 bytes.');
        }

        return $key;
    }

    /**
     * The `encryption` object that opens a backup: cp with its unwrapped key, customer with the identity given.
     *
     * @return array{mode: string, key_id: string, key?: string, identity?: string}
     */
    public function opening(string $mode, ?string $wrappedKey, string $organizationId, string $backupId, #[\SensitiveParameter] ?string $identity = null, ?string $instanceId = null): array
    {
        if ($mode === self::CUSTOMER) {
            $identity = trim((string) $identity);

            if (! self::validIdentity($identity)) {
                throw new InvalidArgumentException('An age identity (AGE-SECRET-KEY-1…) is required.');
            }

            return ['mode' => 'age', 'key_id' => $backupId, 'identity' => $identity];
        }

        if ($wrappedKey === null) {
            throw new DecryptionFailed('The backup has no key.');
        }

        $key = $this->unwrap($wrappedKey, $organizationId, $backupId, $instanceId);

        try {
            return ['mode' => self::CP, 'key_id' => $backupId, 'key' => base64_encode($key)];
        } finally {
            sodium_memzero($key);
        }
    }

    /**
     * The `encryption` object of a customer-held backup: the agent generates the key for the recipient.
     *
     * @return array{mode: string, key_id: string, recipient: string}
     */
    public static function sealing(string $backupId, string $recipient): array
    {
        return ['mode' => 'age', 'key_id' => $backupId, 'recipient' => $recipient];
    }

    public static function validRecipient(?string $recipient): bool
    {
        return is_string($recipient) && preg_match(self::RECIPIENT_PATTERN, $recipient) === 1;
    }

    public static function validIdentity(?string $identity): bool
    {
        return is_string($identity) && preg_match(self::IDENTITY_PATTERN, $identity) === 1;
    }

    /**
     * An exported key file for falak-restore --key-file: a comment naming the backup, then the key in hex.
     */
    public static function keyFile(string $backupId, #[\SensitiveParameter] string $key): string
    {
        return "# Falak backup key for {$backupId} (falak-restore --key-file). Keep it secret.\n".bin2hex($key)."\n";
    }
}

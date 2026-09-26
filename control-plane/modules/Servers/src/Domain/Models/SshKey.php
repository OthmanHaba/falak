<?php

namespace Kiln\Servers\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use InvalidArgumentException;

/**
 * An organization SSH public key that can be synced to servers (system.ssh_key.sync).
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $user_id
 * @property string $name
 * @property string $public_key
 * @property string $fingerprint SHA256:<base64>
 */
class SshKey extends Model
{
    use HasUlids;

    public const TYPES = [
        'ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521',
        'sk-ssh-ed25519@openssh.com', 'sk-ecdsa-sha2-nistp256@openssh.com',
    ];

    protected $table = 'servers_ssh_keys';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return BelongsToMany<Server, $this>
     */
    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'servers_server_ssh_keys')->withPivot('unix_user')->withTimestamps();
    }

    /**
     * Normalize an OpenSSH public key line and compute its SHA256 fingerprint.
     *
     * @return array{public_key: string, fingerprint: string, comment: ?string}
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $line): array
    {
        $parts = preg_split('/\s+/', trim($line), 3) ?: [];

        if (count($parts) < 2 || ! in_array($parts[0], self::TYPES, true)) {
            throw new InvalidArgumentException('Not a supported OpenSSH public key (ed25519, rsa, ecdsa, or security keys).');
        }

        $blob = base64_decode($parts[1], true);

        if ($blob === false || strlen($blob) < 20) {
            throw new InvalidArgumentException('The key data is not valid base64.');
        }

        // The blob starts with the length-prefixed key type; it must match the declared type.
        $length = unpack('N', substr($blob, 0, 4))[1] ?? 0;

        if (substr($blob, 4, $length) !== $parts[0]) {
            throw new InvalidArgumentException('The key data does not match its type.');
        }

        if ($parts[0] === 'ssh-rsa' && strlen($blob) < 270) {
            throw new InvalidArgumentException('RSA keys must be at least 2048 bits.');
        }

        return [
            'public_key' => $parts[0].' '.$parts[1],
            'fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '='),
            'comment' => $parts[2] ?? null,
        ];
    }
}

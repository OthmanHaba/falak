<?php

namespace Falak\SourceControl\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A GitHub App registered for a Falak organization through the manifest flow. Every credential GitHub returned
 * is stored encrypted; the private key and secrets never leave SourceControl.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $app_id GitHub's numeric app id
 * @property string $slug
 * @property string $name
 * @property ?string $owner_login
 * @property ?string $owner_type User | Organization
 * @property ?string $html_url
 * @property ?string $client_id
 * @property ?string $client_secret
 * @property string $webhook_secret
 * @property string $private_key PEM
 * @property ?string $created_by
 * @property ?Carbon $last_delivery_at
 * @property Carbon $created_at
 */
class GitHubApp extends Model
{
    use HasUlids;

    protected $table = 'source_control_github_apps';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['client_secret', 'webhook_secret', 'private_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'private_key' => 'encrypted',
            'last_delivery_at' => 'datetime',
        ];
    }
}

<?php

namespace Falak\Secrets\Infrastructure\Providers;

use Falak\Secrets\Domain\Enums\ProviderType;
use InvalidArgumentException;

/**
 * Parses linked secret references into the parts a driver needs, and refuses malformed ones (checked when a
 * linked secret is saved, and again before every lookup):
 *
 *   vault://<mount>/data/<path>#<key>        KV v2 (KV v1: vault://<mount>/<path>#<key>)
 *   aws-sm://<secret id or ARN>[#<json key>]
 *   aws-ssm://<parameter name or ARN>        (aws-ssm:///prod/db/pass; a leading "/" is added to hierarchical names)
 *   op://<vault>/<item>[/<section>]/<field>
 *   doppler://<project>/<config>/<KEY>
 *   infisical://<project id>/<environment>[/<path>…]/<KEY>
 *   https://<under the webhook's base URL>
 */
final class References
{
    private const SEGMENT = '/^[A-Za-z0-9._~@+=:,-]+$/';

    /**
     * @param  array<string, mixed>|null  $settings  the provider's settings (null: check the form only)
     * @return array<string, string>
     *
     * @throws InvalidArgumentException with a message for the user
     */
    public static function parse(ProviderType $type, string $reference, ?array $settings = null): array
    {
        $reference = trim($reference);
        $scheme = ProviderType::schemeOf($reference);

        if ($scheme !== $type->scheme()) {
            throw new InvalidArgumentException("{$type->label()} references look like {$type->example()}.");
        }

        if (strlen($reference) > 2000 || preg_match('/[\x00-\x1f\x7f]/', $reference) === 1) {
            throw new InvalidArgumentException('The reference is too long or contains control characters.');
        }

        $rest = substr($reference, strlen($scheme) + 3);

        return match ($type) {
            ProviderType::Vault => self::vault($rest, (string) ($settings['kv_version'] ?? '2') === '1'),
            ProviderType::AwsSecretsManager => self::awsSecretsManager($rest),
            ProviderType::AwsSsm => self::awsSsm($rest),
            ProviderType::OnePassword => self::onePassword($rest),
            ProviderType::Doppler => self::doppler($rest),
            ProviderType::Infisical => self::infisical($rest),
            ProviderType::Http => self::http($reference, $settings),
        };
    }

    /**
     * @return array{path: string, key: string}
     */
    private static function vault(string $rest, bool $kv1): array
    {
        [$path, $key] = array_pad(explode('#', $rest, 2), 2, '');
        $segments = self::segments($path);

        if ($key === '' || $segments === null || count($segments) < 2) {
            throw new InvalidArgumentException('Vault references look like vault://<mount>/data/<path>#<key> (KV v2) or vault://<mount>/<path>#<key> (KV v1).');
        }

        $data = array_search('data', array_slice($segments, 1), true);

        if (! $kv1 && ($data === false || $data + 2 >= count($segments))) {
            throw new InvalidArgumentException('KV v2 references include /data/ after the mount: vault://<mount>/data/<path>#<key>.');
        }

        return ['path' => implode('/', $segments), 'key' => $key];
    }

    /**
     * @return array{secret_id: string, key: string}
     */
    private static function awsSecretsManager(string $rest): array
    {
        [$id, $key] = array_pad(explode('#', $rest, 2), 2, '');

        if (preg_match('#^[A-Za-z0-9/_+=.@:-]{1,2048}$#', $id) !== 1) {
            throw new InvalidArgumentException('AWS Secrets Manager references look like aws-sm://<secret name or ARN>#<JSON key> (the key is optional).');
        }

        return ['secret_id' => $id, 'key' => $key];
    }

    /**
     * @return array{name: string}
     */
    private static function awsSsm(string $rest): array
    {
        if (preg_match('#^[A-Za-z0-9/_.:-]{1,2048}$#', $rest) !== 1 || str_contains($rest, '#')) {
            throw new InvalidArgumentException('SSM references look like aws-ssm:///<parameter name> (or an ARN).');
        }

        // Hierarchical names start with "/": aws-ssm://prod/db is /prod/db.
        $name = ! str_starts_with($rest, 'arn:') && str_contains($rest, '/') && ! str_starts_with($rest, '/') ? "/{$rest}" : $rest;

        return ['name' => $name];
    }

    /**
     * @return array{vault: string, item: string, section: string, field: string}
     */
    private static function onePassword(string $rest): array
    {
        $parts = array_map(fn (string $part) => trim(rawurldecode($part)), explode('/', $rest));

        if (count($parts) < 3 || count($parts) > 4 || in_array('', $parts, true) || max(array_map('strlen', $parts)) > 200 || preg_match('/["\\\\]/', implode('', $parts)) === 1) {
            throw new InvalidArgumentException('1Password references look like op://<vault>/<item>/<field> (or op://<vault>/<item>/<section>/<field>).');
        }

        return count($parts) === 3
            ? ['vault' => $parts[0], 'item' => $parts[1], 'section' => '', 'field' => $parts[2]]
            : ['vault' => $parts[0], 'item' => $parts[1], 'section' => $parts[2], 'field' => $parts[3]];
    }

    /**
     * @return array{project: string, config: string, name: string}
     */
    private static function doppler(string $rest): array
    {
        $parts = explode('/', $rest);

        if (count($parts) !== 3 || preg_grep('/^[A-Za-z0-9_.-]{1,200}$/', $parts) !== $parts) {
            throw new InvalidArgumentException('Doppler references look like doppler://<project>/<config>/<KEY>.');
        }

        return ['project' => $parts[0], 'config' => $parts[1], 'name' => $parts[2]];
    }

    /**
     * @return array{project_id: string, environment: string, path: string, name: string}
     */
    private static function infisical(string $rest): array
    {
        $segments = self::segments($rest);

        if ($segments === null || count($segments) < 3 || preg_match('/^[A-Za-z0-9_-]+$/', $segments[0].$segments[1]) !== 1) {
            throw new InvalidArgumentException('Infisical references look like infisical://<project id>/<environment>/<path>/<KEY> (the path is optional).');
        }

        return [
            'project_id' => $segments[0],
            'environment' => $segments[1],
            'path' => '/'.implode('/', array_slice($segments, 2, -1)),
            'name' => $segments[count($segments) - 1],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $settings
     * @return array{ref: string}
     */
    private static function http(string $reference, ?array $settings): array
    {
        if (filter_var($reference, FILTER_VALIDATE_URL) === false || parse_url($reference, PHP_URL_USER) !== null) {
            throw new InvalidArgumentException('Webhook references are https:// URLs under the provider\'s base URL.');
        }

        if ($settings === null) {
            return ['ref' => $reference];
        }

        $base = rtrim((string) ($settings['base_url'] ?? ''), '/');

        if ($base === '' || ! (str_starts_with($reference, $base.'/') || $reference === $base)) {
            throw new InvalidArgumentException("Webhook references start with the provider's base URL ({$base}/…).");
        }

        $ref = trim(substr($reference, strlen($base)), '/');

        if ($ref === '') {
            throw new InvalidArgumentException("Add the name of the value after the base URL: {$base}/<name>.");
        }

        return ['ref' => $ref];
    }

    /**
     * @return list<string>|null null when a segment is empty, "." / ".." or has other characters
     */
    private static function segments(string $path): ?array
    {
        $segments = explode('/', trim($path, '/'));

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..' || preg_match(self::SEGMENT, $segment) !== 1) {
                return null;
            }
        }

        return $segments;
    }
}

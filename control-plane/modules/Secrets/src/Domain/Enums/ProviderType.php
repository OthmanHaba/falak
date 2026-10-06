<?php

namespace Falak\Secrets\Domain\Enums;

/**
 * The kinds of external secret provider, each with the reference scheme its linked secrets use.
 */
enum ProviderType: string
{
    case Vault = 'vault';
    case AwsSecretsManager = 'aws_secrets_manager';
    case AwsSsm = 'aws_ssm';
    case OnePassword = 'onepassword';
    case Doppler = 'doppler';
    case Infisical = 'infisical';
    case Http = 'http';

    public function label(): string
    {
        return match ($this) {
            self::Vault => 'HashiCorp Vault / OpenBao',
            self::AwsSecretsManager => 'AWS Secrets Manager',
            self::AwsSsm => 'AWS SSM Parameter Store',
            self::OnePassword => '1Password (Connect)',
            self::Doppler => 'Doppler',
            self::Infisical => 'Infisical',
            self::Http => 'HTTPS webhook',
        };
    }

    /** The scheme of its references (`vault://…`); the HTTPS webhook takes `https://` URLs under its base URL. */
    public function scheme(): string
    {
        return match ($this) {
            self::Vault => 'vault',
            self::AwsSecretsManager => 'aws-sm',
            self::AwsSsm => 'aws-ssm',
            self::OnePassword => 'op',
            self::Doppler => 'doppler',
            self::Infisical => 'infisical',
            self::Http => 'https',
        };
    }

    /** An example reference, for hints and errors. */
    public function example(): string
    {
        return match ($this) {
            self::Vault => 'vault://kv/data/app#DB_PASS',
            self::AwsSecretsManager => 'aws-sm://prod/db#password',
            self::AwsSsm => 'aws-ssm:///prod/db/password',
            self::OnePassword => 'op://Vault/Item/field',
            self::Doppler => 'doppler://project/config/KEY',
            self::Infisical => 'infisical://<project id>/prod/app/KEY',
            self::Http => 'https://secrets.example.com/v1/app/DB_PASS',
        };
    }

    /** Whether it can run on a private network (self-hosted), so "allow private network" applies. */
    public function selfHostable(): bool
    {
        return in_array($this, [self::Vault, self::OnePassword, self::Infisical, self::Http], true);
    }

    public static function forScheme(string $scheme): ?self
    {
        foreach (self::cases() as $type) {
            if ($type->scheme() === strtolower($scheme)) {
                return $type;
            }
        }

        return null;
    }

    /** The scheme of a reference (`vault` for `vault://…`), or null when it has none. */
    public static function schemeOf(string $reference): ?string
    {
        return preg_match('#^([a-z][a-z0-9+.-]{0,31})://#i', $reference, $m) === 1 ? strtolower($m[1]) : null;
    }
}

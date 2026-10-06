<?php

use Falak\Secrets\Domain\Enums\ProviderType;
use Falak\Secrets\Infrastructure\Providers\References;

it('parses each provider\'s references', function (ProviderType $type, string $reference, array $parts, array $config = []) {
    expect(References::parse($type, $reference, $config !== [] ? $config : null))->toBe($parts);
})->with([
    'vault kv2' => [ProviderType::Vault, 'vault://kv/data/app/prod#DB_PASS', ['path' => 'kv/data/app/prod', 'key' => 'DB_PASS']],
    'vault kv1' => [ProviderType::Vault, 'vault://secret/app#token', ['path' => 'secret/app', 'key' => 'token'], ['kv_version' => '1']],
    'aws-sm with key' => [ProviderType::AwsSecretsManager, 'aws-sm://prod/db#password', ['secret_id' => 'prod/db', 'key' => 'password']],
    'aws-sm arn' => [ProviderType::AwsSecretsManager, 'aws-sm://arn:aws:secretsmanager:eu-west-1:123456789012:secret:db-AbCd', ['secret_id' => 'arn:aws:secretsmanager:eu-west-1:123456789012:secret:db-AbCd', 'key' => '']],
    'aws-ssm hierarchical' => [ProviderType::AwsSsm, 'aws-ssm:///prod/db/pass', ['name' => '/prod/db/pass']],
    'aws-ssm without the slash' => [ProviderType::AwsSsm, 'aws-ssm://prod/db/pass', ['name' => '/prod/db/pass']],
    'aws-ssm flat' => [ProviderType::AwsSsm, 'aws-ssm://DB_PASS', ['name' => 'DB_PASS']],
    '1password' => [ProviderType::OnePassword, 'op://Production/Stripe Keys/secret key', ['vault' => 'Production', 'item' => 'Stripe Keys', 'section' => '', 'field' => 'secret key']],
    '1password section' => [ProviderType::OnePassword, 'op://Prod/DB/admin/password', ['vault' => 'Prod', 'item' => 'DB', 'section' => 'admin', 'field' => 'password']],
    'doppler' => [ProviderType::Doppler, 'doppler://backend/prd/STRIPE_KEY', ['project' => 'backend', 'config' => 'prd', 'name' => 'STRIPE_KEY']],
    'infisical with path' => [ProviderType::Infisical, 'infisical://6512abc/prod/app/db/DB_PASS', ['project_id' => '6512abc', 'environment' => 'prod', 'path' => '/app/db', 'name' => 'DB_PASS']],
    'infisical root' => [ProviderType::Infisical, 'infisical://6512abc/dev/API_KEY', ['project_id' => '6512abc', 'environment' => 'dev', 'path' => '/', 'name' => 'API_KEY']],
    'https' => [ProviderType::Http, 'https://secrets.example.com/v1/app/DB_PASS', ['ref' => 'app/DB_PASS'], ['base_url' => 'https://secrets.example.com/v1']],
]);

it('refuses malformed references and references of another type', function (ProviderType $type, string $reference, array $config = []) {
    expect(fn () => References::parse($type, $reference, $config !== [] ? $config : null))->toThrow(InvalidArgumentException::class);
})->with([
    'wrong scheme' => [ProviderType::Vault, 'aws-sm://prod/db#password'],
    'vault without key' => [ProviderType::Vault, 'vault://kv/data/app'],
    'kv2 without data' => [ProviderType::Vault, 'vault://kv/app#KEY'],
    'vault traversal' => [ProviderType::Vault, 'vault://kv/data/../sys/raw#KEY'],
    'vault query' => [ProviderType::Vault, 'vault://kv/data/app?x=1#KEY'],
    'doppler two parts' => [ProviderType::Doppler, 'doppler://backend/KEY'],
    'infisical short' => [ProviderType::Infisical, 'infisical://proj/KEY'],
    '1password short' => [ProviderType::OnePassword, 'op://Vault/Item'],
    'https elsewhere' => [ProviderType::Http, 'https://evil.example.net/v1/app/KEY', ['base_url' => 'https://secrets.example.com/v1']],
    'https prefix trick' => [ProviderType::Http, 'https://secrets.example.com/v1evil/KEY', ['base_url' => 'https://secrets.example.com/v1']],
    'https no ref' => [ProviderType::Http, 'https://secrets.example.com/v1', ['base_url' => 'https://secrets.example.com/v1']],
    'control characters' => [ProviderType::Doppler, "doppler://a/b/C\nD"],
]);

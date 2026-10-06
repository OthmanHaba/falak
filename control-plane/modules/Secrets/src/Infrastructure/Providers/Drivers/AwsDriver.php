<?php

namespace Falak\Secrets\Infrastructure\Providers\Drivers;

use Falak\Kernel\Support\Aws\SigV4Signer;
use Falak\Secrets\Domain\Enums\ProviderType;
use Falak\Secrets\Domain\Models\SecretProvider;
use Falak\Secrets\Infrastructure\Providers\ProviderClient;
use Falak\Secrets\Infrastructure\Providers\ProviderFailure;
use Falak\Secrets\Infrastructure\Providers\ProviderTokens;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

/**
 * AWS Secrets Manager (GetSecretValue, optionally one key of a JSON secret) and SSM Parameter Store
 * (GetParameter WithDecryption), signed with SigV4. Credentials: an access key (with an optional session
 * token), or the control plane's EC2 instance profile (IMDSv2) when the instance allows it; either can assume
 * a role (STS AssumeRole, the session kept until shortly before it expires).
 *
 * @see https://docs.aws.amazon.com/secretsmanager/latest/apireference/API_GetSecretValue.html
 * @see https://docs.aws.amazon.com/systems-manager/latest/APIReference/API_GetParameter.html
 */
final class AwsDriver implements ProviderDriver
{
    public const REGION_PATTERN = '/^[a-z]{2}(-[a-z]+)+-\d{1,2}$/';

    private const IMDS = 'http://169.254.169.254';

    public function __construct(
        private readonly ProviderClient $client,
        private readonly ProviderTokens $tokens,
    ) {}

    public function fetch(SecretProvider $provider, array $reference, string $display): string
    {
        if ($provider->type === ProviderType::AwsSsm) {
            $json = $this->call($provider, 'ssm', 'AmazonSSM.GetParameter', ['Name' => $reference['name'], 'WithDecryption' => true], $display);

            return Values::scalar($json['Parameter']['Value'] ?? null, $display);
        }

        $json = $this->call($provider, 'secretsmanager', 'secretsmanager.GetSecretValue', ['SecretId' => $reference['secret_id']], $display);
        $secret = $json['SecretString'] ?? null;

        if (! is_string($secret)) {
            throw new ProviderFailure("{$display} is a binary secret; only text secrets can be linked.");
        }

        return $reference['key'] !== '' ? Values::jsonKey($secret, $reference['key'], $display) : $secret;
    }

    public function test(SecretProvider $provider): void
    {
        // Needs no permission: proves the credentials (and the assumed role) are valid.
        $this->sts($provider, ['Action' => 'GetCallerIdentity', 'Version' => '2011-06-15'], $this->credentials($provider), 'GetCallerIdentity');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(SecretProvider $provider, string $service, string $target, array $payload, string $display): array
    {
        $region = $this->region($provider);
        $url = "https://{$service}.{$region}.amazonaws.com/";
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        [$key, $secret, $session] = $this->credentials($provider);

        $headers = (new SigV4Signer($key, $secret, $region, $service, $session, s3: false))->signHeaders('POST', $url, [
            'Content-Type' => 'application/x-amz-json-1.1',
            'X-Amz-Target' => $target,
        ], hash('sha256', $body));

        $response = $this->client->send($provider, 'POST', $url, ['headers' => $headers, 'body' => $body, 'content_type' => 'application/x-amz-json-1.1']);

        if (! $response->successful()) {
            throw $this->failure($provider, $response, $display);
        }

        return (array) $response->json();
    }

    /**
     * @return array{0: string, 1: string, 2: ?string} access key id, secret access key, session token
     */
    private function credentials(SecretProvider $provider): array
    {
        $base = $provider->setting('auth_method', 'keys') === 'instance_profile'
            ? $this->instanceProfile($provider)
            : [
                $provider->setting('access_key_id') ?? throw new ProviderFailure('The AWS provider has no access key.'),
                $provider->setting('secret_access_key') ?? throw new ProviderFailure('The AWS provider has no secret access key.'),
                $provider->setting('session_token'),
            ];

        $role = $provider->setting('role_arn');

        if ($role === null) {
            return $base;
        }

        $session = $this->tokens->remember($provider, 'aws-assume-role', function () use ($provider, $role, $base) {
            $credentials = $this->sts($provider, array_filter([
                'Action' => 'AssumeRole',
                'Version' => '2011-06-15',
                'RoleArn' => $role,
                'RoleSessionName' => 'falak-secrets',
                'DurationSeconds' => '3600',
                'ExternalId' => $provider->setting('external_id'),
            ]), $base, 'AssumeRole');

            return [json_encode($credentials, JSON_THROW_ON_ERROR), 3600];
        });

        return array_values(json_decode($session, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, string>  $form
     * @param  array{0: string, 1: string, 2: ?string}  $credentials
     * @return array{0: string, 1: string, 2: ?string} the assumed role's credentials (AssumeRole), else the ones given
     */
    private function sts(SecretProvider $provider, array $form, array $credentials, string $action): array
    {
        $region = $this->region($provider);
        $url = "https://sts.{$region}.amazonaws.com/";
        $body = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        $headers = (new SigV4Signer($credentials[0], $credentials[1], $region, 'sts', $credentials[2], s3: false))
            ->signHeaders('POST', $url, ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'], hash('sha256', $body));

        $response = $this->client->send($provider, 'POST', $url, ['headers' => $headers, 'body' => $body, 'content_type' => 'application/x-www-form-urlencoded; charset=utf-8']);

        if (! $response->successful()) {
            throw $this->failure($provider, $response, "STS {$action}");
        }

        if ($action !== 'AssumeRole') {
            return $credentials;
        }

        $assumed = $response->json('AssumeRoleResponse.AssumeRoleResult.Credentials');

        if (! is_array($assumed)) {
            $xml = @simplexml_load_string($response->body());
            $node = $xml !== false ? $xml->AssumeRoleResult->Credentials ?? null : null;
            $assumed = $node !== null ? ['AccessKeyId' => (string) $node->AccessKeyId, 'SecretAccessKey' => (string) $node->SecretAccessKey, 'SessionToken' => (string) $node->SessionToken] : [];
        }

        if (($assumed['AccessKeyId'] ?? '') === '' || ($assumed['SecretAccessKey'] ?? '') === '') {
            throw new ProviderFailure('STS AssumeRole returned no credentials.');
        }

        return [(string) $assumed['AccessKeyId'], (string) $assumed['SecretAccessKey'], ($assumed['SessionToken'] ?? '') !== '' ? (string) $assumed['SessionToken'] : null];
    }

    /**
     * The instance profile's credentials from IMDSv2 (only when the instance allows organizations to use the
     * control plane's own role: secrets.providers.allow_instance_profile).
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function instanceProfile(SecretProvider $provider): array
    {
        if (! config('secrets.providers.allow_instance_profile', false)) {
            throw new ProviderFailure('This Falak instance does not allow providers to use its instance profile; use an access key.');
        }

        $json = $this->tokens->remember($provider, 'aws-instance-profile', function () use ($provider) {
            $token = $this->client->send($provider, 'PUT', self::IMDS.'/latest/api/token', ['unguarded' => true, 'headers' => ['X-aws-ec2-metadata-token-ttl-seconds' => '21600']]);

            if (! $token->successful()) {
                throw new ProviderFailure('The instance metadata service is not available (is the control plane running on EC2 with IMDSv2?).');
            }

            $headers = ['X-aws-ec2-metadata-token' => $token->body()];
            $role = trim(strtok($this->client->send($provider, 'GET', self::IMDS.'/latest/meta-data/iam/security-credentials/', ['unguarded' => true, 'headers' => $headers])->body(), "\n") ?: '');

            if ($role === '' || preg_match('/^[\w+=,.@-]+$/', $role) !== 1) {
                throw new ProviderFailure('The control plane\'s instance has no instance profile role.');
            }

            $credentials = $this->client->send($provider, 'GET', self::IMDS."/latest/meta-data/iam/security-credentials/{$role}", ['unguarded' => true, 'headers' => $headers]);
            $data = (array) json_decode($credentials->body(), true);

            if (($data['AccessKeyId'] ?? '') === '' || ($data['SecretAccessKey'] ?? '') === '') {
                throw new ProviderFailure('The instance metadata service returned no credentials.');
            }

            $expires = isset($data['Expiration']) ? (int) max(0, now()->diffInSeconds(Carbon::parse((string) $data['Expiration']))) : 900;

            return [json_encode([(string) $data['AccessKeyId'], (string) $data['SecretAccessKey'], (string) ($data['Token'] ?? '')], JSON_THROW_ON_ERROR), $expires];
        });

        [$key, $secret, $session] = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return [$key, $secret, $session !== '' ? $session : null];
    }

    private function region(SecretProvider $provider): string
    {
        $region = (string) $provider->setting('region');

        if (preg_match(self::REGION_PATTERN, $region) !== 1) {
            throw new ProviderFailure('The AWS provider has no valid region.');
        }

        return $region;
    }

    private function failure(SecretProvider $provider, Response $response, string $what): ProviderFailure
    {
        $type = (string) ($response->json('__type') ?? '');
        $type = str_contains($type, '#') ? substr($type, strrpos($type, '#') + 1) : $type;
        $type = preg_replace('/[^A-Za-z.]/', '', $type) ?? '';

        return match ($type) {
            'ResourceNotFoundException', 'ParameterNotFound' => new ProviderFailure("{$what} was not found at {$provider->type->label()}."),
            'AccessDeniedException', 'AccessDenied' => new ProviderFailure("{$provider->type->label()} refused access to {$what} ({$type}): check the IAM policy."),
            'UnrecognizedClientException', 'InvalidSignatureException', 'ExpiredTokenException', 'InvalidClientTokenId', 'SignatureDoesNotMatch' => new ProviderFailure("AWS refused the credentials ({$type})."),
            '' => ProviderClient::failure($provider, $response, $what),
            default => new ProviderFailure("{$provider->type->label()} answered HTTP {$response->status()} ({$type}) for {$what}."),
        };
    }
}

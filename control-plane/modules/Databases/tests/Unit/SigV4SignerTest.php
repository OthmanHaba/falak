<?php

use Kiln\Databases\Infrastructure\ObjectStorage\EndpointGuard;
use Kiln\Databases\Infrastructure\ObjectStorage\SigV4Signer;

/*
| Vectors from the AWS documentation:
|  - S3 "Signature Calculations for the Authorization Header" examples (GET object, PUT object, GET ?lifecycle, list objects)
|    https://docs.aws.amazon.com/AmazonS3/latest/API/sig-v4-header-based-auth.html
|  - S3 "Authenticating Requests: Using Query Parameters" presigned GET example
|    https://docs.aws.amazon.com/AmazonS3/latest/API/sigv4-query-string-auth.html
|  - The generic SigV4 test suite (get-vanilla, get-vanilla-query-order-key-case, post-vanilla)
*/

const S3_ACCESS_KEY = 'AKIAIOSFODNN7EXAMPLE';
const S3_SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

function s3Signer(): SigV4Signer
{
    return new SigV4Signer(S3_ACCESS_KEY, S3_SECRET_KEY, 'us-east-1');
}

function s3Now(): DateTimeImmutable
{
    return new DateTimeImmutable('2013-05-24T00:00:00Z');
}

it('signs the S3 GET object example', function () {
    $headers = s3Signer()->signHeaders('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', ['Range' => 'bytes=0-9'], SigV4Signer::EMPTY_PAYLOAD_SHA256, s3Now());

    expect($headers['Authorization'])->toBe(
        'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, '
        .'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, '
        .'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41'
    )->and($headers['x-amz-date'])->toBe('20130524T000000Z')
        ->and($headers['x-amz-content-sha256'])->toBe(SigV4Signer::EMPTY_PAYLOAD_SHA256);
});

it('signs the S3 PUT object example (special characters in the key, signed body)', function () {
    $headers = s3Signer()->signHeaders('PUT', 'https://examplebucket.s3.amazonaws.com/test$file.text', [
        'Date' => 'Fri, 24 May 2013 00:00:00 GMT',
        'x-amz-storage-class' => 'REDUCED_REDUNDANCY',
    ], hash('sha256', 'Welcome to Amazon S3.'), s3Now());

    expect($headers['Authorization'])->toEndWith(
        'SignedHeaders=date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class, '
        .'Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd'
    );
});

it('signs the S3 GET bucket lifecycle example (valueless query parameter)', function () {
    $headers = s3Signer()->signHeaders('GET', 'https://examplebucket.s3.amazonaws.com/?lifecycle', [], null, s3Now());

    expect($headers['Authorization'])->toEndWith('Signature=fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543');
});

it('signs the S3 list objects example (sorted query parameters)', function () {
    $headers = s3Signer()->signHeaders('GET', 'https://examplebucket.s3.amazonaws.com/?max-keys=2&prefix=J', [], null, s3Now());

    expect($headers['Authorization'])->toEndWith('Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7');
});

it('presigns the S3 query-string GET example', function () {
    $url = s3Signer()->presign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', 86400, s3Now());

    expect($url)->toBe(
        'https://examplebucket.s3.amazonaws.com/test.txt'
        .'?X-Amz-Algorithm=AWS4-HMAC-SHA256'
        .'&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request'
        .'&X-Amz-Date=20130524T000000Z'
        .'&X-Amz-Expires=86400'
        .'&X-Amz-SignedHeaders=host'
        .'&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404'
    );
});

describe('generic SigV4 test suite', function () {
    $signer = fn () => new SigV4Signer('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'service', s3: false);
    $now = new DateTimeImmutable('2015-08-30T12:36:00Z');

    it('signs get-vanilla', function () use ($signer, $now) {
        $headers = $signer()->signHeaders('GET', 'https://example.amazonaws.com/', [], null, $now);

        expect($headers['Authorization'])->toBe(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, '
            .'SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31'
        )->and($headers)->not->toHaveKey('x-amz-content-sha256');
    });

    it('signs get-vanilla-query-order-key-case', function () use ($signer, $now) {
        $headers = $signer()->signHeaders('GET', 'https://example.amazonaws.com/?Param2=value2&Param1=value1', [], null, $now);

        expect($headers['Authorization'])->toEndWith('Signature=b97d918cfa904a5beff61c982a1b6f458b799221646efd99d3219ec94cdf2500');
    });

    it('signs post-vanilla', function () use ($signer, $now) {
        $headers = $signer()->signHeaders('POST', 'https://example.amazonaws.com/', [], null, $now);

        expect($headers['Authorization'])->toEndWith('Signature=5da7c1a2acd57cee7505fc6676e4e544621c30862966e37dddb68e92efbe5d6b');
    });
});

it('includes the session token and rejects out-of-range expiry', function () {
    $signer = new SigV4Signer('AKID', 'secret', 'auto', 's3', 'token/with+chars');
    $url = $signer->presign('PUT', 'https://acct.r2.cloudflarestorage.com/bucket/a b/c.sql.gz', 3600, s3Now());

    expect($url)->toStartWith('https://acct.r2.cloudflarestorage.com/bucket/a%20b/c.sql.gz?')
        ->and($url)->toContain('X-Amz-Security-Token=token%2Fwith%2Bchars')
        ->and(fn () => $signer->presign('GET', 'https://h.example/x', SigV4Signer::MAX_EXPIRES + 1))->toThrow(InvalidArgumentException::class);
});

it('keeps non-default ports in the signed host', function () {
    $signer = new SigV4Signer('AKID', 'secret', 'us-east-1');
    $url = $signer->presign('GET', 'https://minio.internal:9000/bucket/key', 60, s3Now());

    expect($url)->toStartWith('https://minio.internal:9000/bucket/key?');
});

it('guards control-plane storage requests against private addresses', function () {
    $guard = new EndpointGuard(false, fn (string $host) => match ($host) {
        'internal.example' => ['10.0.0.5'],
        'mixed.example' => ['93.184.216.34', '192.168.1.1'],
        'nowhere.example' => [],
        default => ['93.184.216.34'],
    });

    expect($guard->refusal('https://bucket.s3.amazonaws.com/x'))->toBeNull()
        ->and($guard->refusal('https://internal.example/x'))->toContain('10.0.0.5')
        ->and($guard->refusal('https://mixed.example/x'))->toContain('192.168.1.1')
        ->and($guard->refusal('https://nowhere.example/x'))->toContain('does not resolve')
        ->and($guard->refusal('https://127.0.0.1:9000/x'))->not->toBeNull()
        ->and($guard->refusal('https://[::1]/x'))->not->toBeNull()
        ->and((new EndpointGuard(true))->refusal('https://127.0.0.1/x'))->toBeNull();
});

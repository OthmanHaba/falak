<?php

namespace Falak\Alerting\Application;

/**
 * Scrubs credentials out of alert text before it is stored or sent: error messages quote URLs, and a presigned or
 * basic-auth URL is a credential.
 */
final class AlertText
{
    public static function redact(string $text): string
    {
        // https://user:password@host → https://***@host
        $text = (string) preg_replace('#\b([a-z][a-z0-9+.-]*://)[^\s/@:]+(?::[^\s/@]*)?@#i', '$1***@', $text);

        // Query parameters that carry signatures, keys or tokens (presigned S3 URLs: X-Amz-Signature, X-Amz-Credential).
        $text = (string) preg_replace(
            '/([?&](?:x-amz-[a-z-]+|signature|sig|token|access_token|api_key|apikey|key|secret|password|credential|awsaccesskeyid)=)[^&\s"\'<>]+/i',
            '$1***',
            $text,
        );

        // Authorization headers echoed in errors.
        return (string) preg_replace('/\b(Bearer|Basic|token)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 ***', $text);
    }
}

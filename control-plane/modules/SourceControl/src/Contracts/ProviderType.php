<?php

namespace Kiln\SourceControl\Contracts;

enum ProviderType: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Bitbucket => 'Bitbucket',
            self::Custom => 'Custom Git',
        };
    }

    /** Whether repositories, branches, deploy keys and webhooks can be managed through a provider API. */
    public function hasApi(): bool
    {
        return $this !== self::Custom;
    }
}

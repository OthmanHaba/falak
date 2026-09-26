<?php

namespace Kiln\Sites\Contracts;

/**
 * How a site runs on its servers (ARCHITECTURE §5).
 */
enum SiteRuntime: string
{
    case FrankenPhp = 'frankenphp';
    case PhpFpm = 'php-fpm';
    case Node = 'node';
    case Bun = 'bun';
    case Deno = 'deno';
    case Static = 'static';
    case Docker = 'docker';
    case Compose = 'compose';

    public function label(): string
    {
        return match ($this) {
            self::FrankenPhp => 'FrankenPHP',
            self::PhpFpm => 'PHP-FPM',
            self::Node => 'Node.js',
            self::Bun => 'Bun',
            self::Deno => 'Deno',
            self::Static => 'Static',
            self::Docker => 'Docker',
            self::Compose => 'Docker Compose',
        };
    }

    public function isPhp(): bool
    {
        return $this === self::FrankenPhp || $this === self::PhpFpm;
    }

    /** Runtimes whose app listens on a local port that the edge reverse-proxies to. */
    public function proxiesToPort(): bool
    {
        return in_array($this, [self::Node, self::Bun, self::Deno, self::Docker, self::Compose], true);
    }

    public function isContainer(): bool
    {
        return $this === self::Docker || $this === self::Compose;
    }

    /** Whether the edge serves files from a document root. */
    public function servesFiles(): bool
    {
        return $this->isPhp() || $this === self::Static;
    }

    /**
     * @return list<BuildMode>
     */
    public function buildModes(): array
    {
        return $this->isContainer() ? [BuildMode::Docker] : [BuildMode::Native, BuildMode::OnServer];
    }
}

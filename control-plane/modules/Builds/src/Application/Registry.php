<?php

namespace Kiln\Builds\Application;

/**
 * The built-in image registry (docker build mode). Images are pushed as
 * <url>/<namespace>/<site-slug>:<build-id> and deployed pinned by digest.
 */
final class Registry
{
    public function url(): string
    {
        return rtrim((string) preg_replace('#^https?://#i', '', (string) config('builds.registry.url')), '/');
    }

    public function repository(string $siteSlug): string
    {
        $namespace = trim((string) config('builds.registry.namespace', 'kiln'), '/');

        return $this->url().($namespace !== '' ? '/'.$namespace : '').'/'.$siteSlug;
    }

    public function image(string $siteSlug, string $buildId): string
    {
        return $this->repository($siteSlug).':'.strtolower($buildId);
    }

    /**
     * @return ?array{server: string, username: string, password: string}
     */
    public function auth(): ?array
    {
        $username = (string) config('builds.registry.username');
        $password = (string) config('builds.registry.password');

        if ($username === '' || $password === '') {
            return null;
        }

        return ['server' => $this->url(), 'username' => $username, 'password' => $password];
    }
}

<?php

namespace Falak\Servers\Contracts;

enum ServerType: string
{
    case App = 'app';
    case Web = 'web';
    case Database = 'db';
    case Cache = 'cache';
    case Worker = 'worker';
    case LoadBalancer = 'lb';
    case Builder = 'builder';

    public function label(): string
    {
        return match ($this) {
            self::App => 'App server',
            self::Web => 'Web server',
            self::Database => 'Database server',
            self::Cache => 'Cache server',
            self::Worker => 'Worker server',
            self::LoadBalancer => 'Load balancer',
            self::Builder => 'Builder',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::App => 'All-in-one: PHP, Node, database and cache on one machine.',
            self::Web => 'Serves sites behind Caddy / FrankenPHP; database and cache live elsewhere.',
            self::Database => 'Dedicated database engine.',
            self::Cache => 'Dedicated Redis / Valkey.',
            self::Worker => 'Runs queue workers, daemons and scheduled tasks; no public web traffic.',
            self::LoadBalancer => 'Caddy load balancer in front of web servers.',
            self::Builder => 'Builds releases and images (Railpack / BuildKit) for other servers.',
        };
    }

    /** Whether sites (and therefore PHP/Node runtimes) can be hosted. */
    public function hostsSites(): bool
    {
        return in_array($this, [self::App, self::Web, self::Worker], true);
    }

    /** Whether the machine terminates HTTP traffic (Caddy / FrankenPHP). */
    public function servesHttp(): bool
    {
        return in_array($this, [self::App, self::Web, self::LoadBalancer], true);
    }

    /**
     * Stack components allowed for the type.
     *
     * @return list<'php'|'node'|'database'|'cache'|'docker'>
     */
    public function allowedComponents(): array
    {
        return match ($this) {
            self::App => ['php', 'node', 'database', 'cache', 'docker'],
            self::Web, self::Worker => ['php', 'node', 'docker'],
            self::Database => ['database'],
            self::Cache => ['cache'],
            self::LoadBalancer => [],
            self::Builder => ['node', 'docker'],
        };
    }
}

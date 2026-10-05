<?php

namespace Falak\Apm\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Falak\Apm\Recorder;
use Falak\Apm\Span;

final class QueryWatcher
{
    private const SYSTEMS = [
        'mysql' => 'mysql',
        'mariadb' => 'mariadb',
        'pgsql' => 'postgresql',
        'sqlite' => 'sqlite',
        'sqlsrv' => 'microsoft.sql_server',
    ];

    public function __construct(private Recorder $recorder)
    {
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, function (QueryExecuted $event) {
            if (! $this->recorder->recording('query')) {
                return;
            }

            $end = $this->recorder->now();
            $connection = $event->connection;
            $driver = $connection->getDriverName();

            // Bindings are intentionally never captured: the SQL keeps its placeholders.
            $this->recorder->record('query', $driver, Span::KIND_CLIENT, $end - (int) ($event->time * 1_000_000), $end, [
                'db.system.name' => self::SYSTEMS[$driver] ?? $driver,
                'db.query.text' => $event->sql,
                'db.namespace' => (string) $connection->getDatabaseName(),
                'falak.query.connection' => $event->connectionName,
            ]);
        });
    }
}

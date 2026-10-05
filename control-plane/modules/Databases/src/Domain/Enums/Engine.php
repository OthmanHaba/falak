<?php

namespace Falak\Databases\Domain\Enums;

/**
 * Database engine flavours. The agent protocol only distinguishes the wire engine (`mysql` | `postgres`):
 * MariaDB is driven through the MySQL client tools. Redis and Valkey are key-value engines (db.redis.*): a "database"
 * of theirs is an instance (its own process, port and password), see {@see EngineKind}.
 */
enum Engine: string
{
    case MySql = 'mysql';
    case MariaDb = 'mariadb';
    case PostgreSql = 'postgresql';
    case Redis = 'redis';
    case Valkey = 'valkey';

    /** Map a Servers stack / facts runtime key to an engine. */
    public static function fromStack(?string $value): ?self
    {
        return match (strtolower((string) $value)) {
            'mysql' => self::MySql,
            'mariadb' => self::MariaDb,
            'postgresql', 'postgres', 'pgsql' => self::PostgreSql,
            'redis' => self::Redis,
            'valkey' => self::Valkey,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MySql => 'MySQL',
            self::MariaDb => 'MariaDB',
            self::PostgreSql => 'PostgreSQL',
            self::Redis => 'Redis',
            self::Valkey => 'Valkey',
        };
    }

    public function kind(): EngineKind
    {
        return match ($this) {
            self::Redis, self::Valkey => EngineKind::KeyValue,
            default => EngineKind::Sql,
        };
    }

    public function isKeyValue(): bool
    {
        return $this->kind() === EngineKind::KeyValue;
    }

    /** The `engine` value of db.* agent commands (db.redis.*: redis | valkey). */
    public function protocol(): string
    {
        return match ($this) {
            self::PostgreSql => 'postgres',
            self::MySql, self::MariaDb => 'mysql',
            self::Redis => 'redis',
            self::Valkey => 'valkey',
        };
    }

    public function isMysqlFamily(): bool
    {
        return $this === self::MySql || $this === self::MariaDb;
    }

    /** The engine's stock port (key-value engines: the stock instance; Falak's instances get their own ports). */
    public function defaultPort(): int
    {
        return match ($this) {
            self::PostgreSql => 5432,
            self::MySql, self::MariaDb => 3306,
            self::Redis, self::Valkey => 6379,
        };
    }

    /** Keys the agent may use for the engine in facts.runtimes. */
    public function factKeys(): array
    {
        return match ($this) {
            self::MySql => ['mysql'],
            self::MariaDb => ['mariadb'],
            self::PostgreSql => ['postgresql', 'postgres'],
            self::Redis => ['redis'],
            self::Valkey => ['valkey'],
        };
    }

    /** URL scheme / Laravel driver name. */
    public function driver(): string
    {
        return match ($this) {
            self::MySql => 'mysql',
            self::MariaDb => 'mariadb',
            self::PostgreSql => 'pgsql',
            self::Redis, self::Valkey => 'redis',
        };
    }

    public function defaultCharset(): ?string
    {
        return $this->isMysqlFamily() ? 'utf8mb4' : null;
    }

    public function defaultCollation(): ?string
    {
        return match ($this) {
            self::MySql => 'utf8mb4_0900_ai_ci',
            self::MariaDb => 'utf8mb4_unicode_ci',
            default => null,
        };
    }

    /**
     * Names that must never be created, dropped or granted.
     *
     * @return list<string>
     */
    public function reservedNames(): array
    {
        return match ($this) {
            self::MySql, self::MariaDb => ['mysql', 'information_schema', 'performance_schema', 'sys', 'root', 'debian-sys-maint', 'mariadb.sys', 'mysql.sys', 'mysql.session', 'mysql.infoschema'],
            self::PostgreSql => ['postgres', 'template0', 'template1', 'pg_signal_backend', 'pg_monitor'],
            self::Redis, self::Valkey => ['default', 'falak'],
        };
    }

    /**
     * Privileges offered in the UI (db.user.apply accepts `^[A-Z ]+$`).
     *
     * @return list<string>
     */
    public function privileges(): array
    {
        if ($this->isKeyValue()) {
            return [];
        }

        return $this->isMysqlFamily()
            ? ['ALL PRIVILEGES', 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'REFERENCES', 'CREATE TEMPORARY TABLES', 'LOCK TABLES', 'EXECUTE', 'CREATE VIEW', 'SHOW VIEW', 'CREATE ROUTINE', 'ALTER ROUTINE', 'EVENT', 'TRIGGER']
            : ['ALL PRIVILEGES', 'CONNECT', 'CREATE', 'TEMPORARY'];
    }
}

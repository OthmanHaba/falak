<?php

namespace Kiln\Databases\Domain\Enums;

/**
 * Database engine flavours. The agent protocol only distinguishes the wire engine (`mysql` | `postgres`):
 * MariaDB is driven through the MySQL client tools.
 */
enum Engine: string
{
    case MySql = 'mysql';
    case MariaDb = 'mariadb';
    case PostgreSql = 'postgresql';

    /** Map a Servers stack / facts runtime key to an engine. */
    public static function fromStack(?string $value): ?self
    {
        return match (strtolower((string) $value)) {
            'mysql' => self::MySql,
            'mariadb' => self::MariaDb,
            'postgresql', 'postgres', 'pgsql' => self::PostgreSql,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MySql => 'MySQL',
            self::MariaDb => 'MariaDB',
            self::PostgreSql => 'PostgreSQL',
        };
    }

    /** The `engine` value of db.* agent commands. */
    public function protocol(): string
    {
        return $this === self::PostgreSql ? 'postgres' : 'mysql';
    }

    public function isMysqlFamily(): bool
    {
        return $this !== self::PostgreSql;
    }

    public function defaultPort(): int
    {
        return $this === self::PostgreSql ? 5432 : 3306;
    }

    /** Keys the agent may use for the engine in facts.runtimes. */
    public function factKeys(): array
    {
        return match ($this) {
            self::MySql => ['mysql'],
            self::MariaDb => ['mariadb'],
            self::PostgreSql => ['postgresql', 'postgres'],
        };
    }

    /** URL scheme / Laravel driver name. */
    public function driver(): string
    {
        return match ($this) {
            self::MySql => 'mysql',
            self::MariaDb => 'mariadb',
            self::PostgreSql => 'pgsql',
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
            self::PostgreSql => null,
        };
    }

    /**
     * Names that must never be created, dropped or granted.
     *
     * @return list<string>
     */
    public function reservedNames(): array
    {
        return $this->isMysqlFamily()
            ? ['mysql', 'information_schema', 'performance_schema', 'sys', 'root', 'debian-sys-maint', 'mariadb.sys', 'mysql.sys', 'mysql.session', 'mysql.infoschema']
            : ['postgres', 'template0', 'template1', 'pg_signal_backend', 'pg_monitor'];
    }

    /**
     * Privileges offered in the UI (db.user.apply accepts `^[A-Z ]+$`).
     *
     * @return list<string>
     */
    public function privileges(): array
    {
        return $this->isMysqlFamily()
            ? ['ALL PRIVILEGES', 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'REFERENCES', 'CREATE TEMPORARY TABLES', 'LOCK TABLES', 'EXECUTE', 'CREATE VIEW', 'SHOW VIEW', 'CREATE ROUTINE', 'ALTER ROUTINE', 'EVENT', 'TRIGGER']
            : ['ALL PRIVILEGES', 'CONNECT', 'CREATE', 'TEMPORARY'];
    }
}

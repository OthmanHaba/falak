// Package dbhelper is falak-db, the helper inside every Falak database image (images/db). The agent drives it with
// `docker exec <ctr> falak-db <op>`: config rendering, health, backups and restores, and spooling WAL segments and
// binlogs for point-in-time recovery. See docs/DB_IMAGES.md for the CLI contract.
package dbhelper

import (
	"fmt"
	"os"
	"path/filepath"
)

// Engine is a database engine an image is built for (FALAK_DB_ENGINE in the image).
type Engine string

const (
	Postgres Engine = "postgres"
	MySQL    Engine = "mysql"
	MariaDB  Engine = "mariadb"
	Redis    Engine = "redis"
	Valkey   Engine = "valkey"
)

// Engines lists every supported engine.
var Engines = []Engine{Postgres, MySQL, MariaDB, Redis, Valkey}

// ParseEngine validates an engine name.
func ParseEngine(s string) (Engine, error) {
	for _, e := range Engines {
		if string(e) == s {
			return e, nil
		}
	}
	return "", usageErr("unknown engine %q (postgres, mysql, mariadb, redis, valkey)", s)
}

func (e Engine) mysqlFamily() bool { return e == MySQL || e == MariaDB }
func (e Engine) kv() bool          { return e == Redis || e == Valkey }

// user is the unprivileged account the official image runs the server as.
func (e Engine) user() string {
	switch e {
	case Postgres:
		return "postgres"
	case Valkey:
		return "valkey"
	case Redis:
		return "redis"
	}
	return "mysql"
}

// server is the server binary the official entrypoint expects as its first argument.
func (e Engine) server() string {
	switch e {
	case Postgres:
		return "postgres"
	case MySQL:
		return "mysqld"
	case MariaDB:
		return "mariadbd"
	case Redis:
		return "redis-server"
	}
	return "valkey-server"
}

// client returns the engine's name for a MySQL-family client tool ("mysql", "mysqldump", ...). MariaDB 11 images
// ship only the mariadb-* names.
func (e Engine) client(tool string) string {
	if e != MariaDB {
		return tool
	}
	switch tool {
	case "mysql":
		return "mariadb"
	case "mysqldump":
		return "mariadb-dump"
	case "mysqladmin":
		return "mariadb-admin"
	case "mysqlbinlog":
		return "mariadb-binlog"
	}
	return tool
}

func (e Engine) kvCLI() string {
	if e == Valkey {
		return "valkey-cli"
	}
	return "redis-cli"
}

// Fixed paths inside the image. The ports are the container's: publishing is the agent's business.
const (
	ConfigDir    = "/etc/falak/db"
	pgConfPath   = ConfigDir + "/postgresql.conf"
	pgHBAPath    = ConfigDir + "/pg_hba.conf"
	myConfPath   = "/etc/mysql/conf.d/zz-falak.cnf"
	kvConfPath   = ConfigDir + "/kv/server.conf"
	kvACLPath    = ConfigDir + "/kv-acl/users.acl"
	tlsDir       = ConfigDir + "/tls"
	runDir       = "/run/falak-db"
	kvSocket     = runDir + "/server.sock"
	pgSocketDir  = "/var/run/postgresql"
	mySocket     = "/var/run/mysqld/mysqld.sock"
	mySlowLog    = "/var/log/falak-db/slow.log" // mysqld cannot log to /dev/stderr (a pipe)
	pgPort       = 5432
	myPort       = 3306
	kvPort       = 6379
	defaultSpool = "/var/lib/falak/db/spool"
	defaultTLS   = "/run/falak/db/tls"
	recoveryConf = "falak-recovery.conf"
)

// Env is the process environment (os.Getenv in production, a map in tests).
type Env func(string) string

func (env Env) or(key, def string) string {
	if v := env(key); v != "" {
		return v
	}
	return def
}

// dataDir is where the engine keeps its data: PGDATA (the official images set it; 18 moved it), /var/lib/mysql or
// /data.
func dataDir(e Engine, env Env) string {
	switch e {
	case Postgres:
		return env.or("PGDATA", "/var/lib/postgresql/data")
	case MySQL, MariaDB:
		return env.or("FALAK_DB_DATA_DIR", "/var/lib/mysql")
	}
	return env.or("FALAK_DB_DATA_DIR", "/data")
}

func spoolDir(env Env) string { return env.or("FALAK_DB_SPOOL", defaultSpool) }

// passwordFile is where the superuser password lives. Passwords only ever come from files (Docker secrets or a
// mounted tmpfs), never from environment values.
func passwordFile(e Engine, env Env) string {
	if f := env("FALAK_DB_PASSWORD_FILE"); f != "" {
		return f
	}
	switch e {
	case Postgres:
		return env("POSTGRES_PASSWORD_FILE")
	case MySQL:
		return env("MYSQL_ROOT_PASSWORD_FILE")
	case MariaDB:
		return env.or("MARIADB_ROOT_PASSWORD_FILE", env("MYSQL_ROOT_PASSWORD_FILE"))
	}
	return ""
}

// readPassword reads a password file, dropping trailing newlines like the official entrypoints' file_env does.
func readPassword(e Engine, env Env) (string, error) {
	f := passwordFile(e, env)
	if f == "" {
		return "", fmt.Errorf("no password file: set FALAK_DB_PASSWORD_FILE")
	}
	b, err := os.ReadFile(filepath.Clean(f))
	if err != nil {
		return "", fmt.Errorf("password file: %w", err)
	}
	for len(b) > 0 && (b[len(b)-1] == '\n' || b[len(b)-1] == '\r') {
		b = b[:len(b)-1]
	}
	if len(b) == 0 {
		return "", fmt.Errorf("password file %s is empty", f)
	}
	return string(b), nil
}

// forbiddenEnv are variables that would put a password (or no password at all) in the container's environment,
// visible to `docker inspect`. The *_FILE variants are the only way in.
var forbiddenEnv = []string{
	"POSTGRES_PASSWORD", "POSTGRES_HOST_AUTH_METHOD",
	"MYSQL_ROOT_PASSWORD", "MYSQL_PASSWORD", "MYSQL_ALLOW_EMPTY_PASSWORD", "MYSQL_RANDOM_ROOT_PASSWORD",
	"MARIADB_ROOT_PASSWORD", "MARIADB_PASSWORD", "MARIADB_ALLOW_EMPTY_ROOT_PASSWORD", "MARIADB_RANDOM_ROOT_PASSWORD",
	"REDIS_PASSWORD", "VALKEY_PASSWORD",
}

func checkEnv(env Env) error {
	for _, k := range forbiddenEnv {
		if env(k) != "" {
			return fmt.Errorf("%s is set: pass passwords as files (%s_FILE / FALAK_DB_PASSWORD_FILE), never as values", k, k)
		}
	}
	return nil
}

package db

import (
	"context"

	"github.com/OthmanHaba/falak/agent/internal/commands"
)

// sqlEngine checks an instance and SQL engine of a db.create / db.drop / db.user.apply payload.
func sqlEngine(instance, engine string) error {
	if err := checkID("instance", instance); err != nil {
		return err
	}
	if err := checkEngine(engine); err != nil {
		return err
	}
	if isKeyValue(engine) {
		return payloadErr("%s instances hold no SQL databases or users", engine)
	}
	return nil
}

// CreatePayload is db.create.
type CreatePayload struct {
	Instance  string `json:"instance"`
	Engine    string `json:"engine"`
	Name      string `json:"name"`
	Charset   string `json:"charset"`
	Collation string `json:"collation"`
	Owner     string `json:"owner"`
}

// Create creates a database in the instance if missing (falak-db database create).
func (db *DB) Create(ctx context.Context, p CreatePayload, _ commands.Stream) (any, error) {
	if err := sqlEngine(p.Instance, p.Engine); err != nil {
		return nil, err
	}
	defer db.lock(p.Instance)()
	if err := checkIdent("name", p.Name); err != nil {
		return nil, err
	}
	args := []string{"database", "create", "--name", p.Name}
	for _, f := range [][2]string{{"--charset", p.Charset}, {"--collation", p.Collation}, {"--owner", p.Owner}} {
		flag, v := f[0], f[1]
		if v == "" {
			continue
		}
		if err := checkIdent(flag[2:], v); err != nil {
			return nil, err
		}
		args = append(args, flag, v)
	}
	out, _, err := db.exec(ctx, p.Instance, nil, nil, nil, args...)
	if err != nil {
		return nil, err
	}
	return ChangedResult{Changed: changed(out)}, nil
}

// DropPayload is db.drop.
type DropPayload struct {
	Instance string `json:"instance"`
	Engine   string `json:"engine"`
	Name     string `json:"name"`
}

// Drop drops a database of the instance if present.
func (db *DB) Drop(ctx context.Context, p DropPayload, _ commands.Stream) (any, error) {
	if err := sqlEngine(p.Instance, p.Engine); err != nil {
		return nil, err
	}
	defer db.lock(p.Instance)()
	if err := checkIdent("name", p.Name); err != nil {
		return nil, err
	}
	out, _, err := db.exec(ctx, p.Instance, nil, nil, nil, "database", "drop", "--name", p.Name)
	if err != nil {
		return nil, err
	}
	return ChangedResult{Changed: changed(out)}, nil
}

// Grant is one database grant.
type Grant struct {
	Database   string   `json:"database"`
	Privileges []string `json:"privileges"`
}

// UserSpec is a user's full desired state: the spec file of `falak-db user apply` (grants not listed are revoked).
type UserSpec struct {
	Username string  `json:"username"`
	Password string  `json:"password,omitempty"`
	Host     string  `json:"host,omitempty"`
	Grants   []Grant `json:"grants"`
	State    string  `json:"state,omitempty"`
}

// UserPayload is db.user.apply.
type UserPayload struct {
	Instance string  `json:"instance"`
	Engine   string  `json:"engine"`
	Username string  `json:"username"`
	Password string  `json:"password"`
	Host     string  `json:"host"`
	Grants   []Grant `json:"grants"`
	State    string  `json:"state"`
}

// Secrets are the payload's secret values.
func (p UserPayload) Secrets() []string { return []string{p.Password} }

// UserApply converges a user of the instance. The spec (with the password) goes through a file in the instance's
// secrets directory, removed right after.
func (db *DB) UserApply(ctx context.Context, p UserPayload, _ commands.Stream) (any, error) {
	if err := sqlEngine(p.Instance, p.Engine); err != nil {
		return nil, err
	}
	defer db.lock(p.Instance)()
	state := p.State
	if state == "" {
		state = "present"
	}
	if state == "present" && p.Password == "" {
		return nil, payloadErr("password is required when state=present")
	}
	host := p.Host
	if p.Engine == "postgres" {
		host = ""
	}
	c, err := db.userApply(ctx, p.Instance, UserSpec{Username: p.Username, Password: p.Password, Host: host, Grants: p.Grants, State: state})
	if err != nil {
		return nil, err
	}
	return ChangedResult{Changed: c}, nil
}

func (db *DB) userApply(ctx context.Context, instance string, u UserSpec) (bool, error) {
	if err := checkIdent("username", u.Username); err != nil {
		return false, err
	}
	for _, g := range u.Grants {
		if err := checkIdent("database", g.Database); err != nil {
			return false, err
		}
	}
	if u.Grants == nil {
		u.Grants = []Grant{}
	}
	var out string
	err := db.withSpecFile(instance, u, func(path string) error {
		var err error
		out, _, err = db.exec(ctx, instance, nil, nil, nil, "user", "apply", "--spec", path)
		return err
	})
	return changed(out), err
}

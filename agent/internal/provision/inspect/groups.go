package inspect

import (
	"slices"
	"strings"
)

// groupDB maps /etc/group: gid → name, and user → supplementary groups.
type groupDB struct {
	names   map[string]string
	members map[string][]string
}

func (in *Inspector) groups() groupDB {
	db := groupDB{names: map[string]string{}, members: map[string][]string{}}
	b, err := in.d.FS.ReadFile("/etc/group")
	if err != nil {
		return db
	}
	for _, line := range strings.Split(string(b), "\n") {
		f := strings.Split(line, ":")
		if len(f) < 4 {
			continue
		}
		db.names[f[2]] = f[0]
		for _, m := range strings.Split(f[3], ",") {
			if m != "" {
				db.members[m] = append(db.members[m], f[0])
			}
		}
	}
	return db
}

// of returns a user's primary group (by gid) followed by its supplementary groups.
func (db groupDB) of(user, gid string) []string {
	out := []string{}
	if n, ok := db.names[gid]; ok {
		out = append(out, n)
	}
	for _, g := range db.members[user] {
		if !slices.Contains(out, g) {
			out = append(out, g)
		}
	}
	return out
}

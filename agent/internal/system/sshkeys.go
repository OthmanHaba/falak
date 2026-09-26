package system

import (
	"context"
	"fmt"
	"os"
	"path"
	"strings"

	"github.com/kiln/agent/internal/commands"
)

// SSHKey is one key.
type SSHKey struct {
	ID        string `json:"id"`
	Name      string `json:"name"`
	PublicKey string `json:"public_key"`
}

// SSHKeyPayload is system.ssh_key.sync.
type SSHKeyPayload struct {
	User      string   `json:"user"`
	Keys      []SSHKey `json:"keys"`
	Exclusive *bool    `json:"exclusive"`
}

// SSHKeyResult is its result.
type SSHKeyResult struct {
	Changed bool `json:"changed"`
	Count   int  `json:"count"`
}

const (
	blockBegin = "# BEGIN kiln-managed"
	blockEnd   = "# END kiln-managed"
)

// SSHKeySync converges authorized_keys.
func (s *System) SSHKeySync(ctx context.Context, p SSHKeyPayload, _ commands.Stream) (any, error) {
	pw, ok, err := LookupPasswd(ctx, s.d.Runner, p.User)
	if err != nil {
		return nil, err
	}
	if !ok {
		return nil, fmt.Errorf("user %s does not exist", p.User)
	}
	var lines []string
	for _, k := range p.Keys {
		f := strings.Fields(k.PublicKey)
		if len(f) < 2 {
			return nil, &commands.PayloadError{Err: fmt.Errorf("key %s: malformed public key", k.ID)}
		}
		lines = append(lines, fmt.Sprintf("%s %s kiln:%s", f[0], f[1], k.ID))
	}
	sshDir := path.Join(pw.Home, ".ssh")
	file := path.Join(sshDir, "authorized_keys")
	var content string
	if p.Exclusive == nil || *p.Exclusive {
		content = "# Managed by Kiln — manual changes will be overwritten\n" + joinLines(lines)
	} else {
		cur, _ := s.d.FS.ReadFile(file)
		content = replaceBlock(string(cur), blockBegin+"\n"+joinLines(lines)+blockEnd+"\n")
	}
	created := false
	if !s.d.FS.Exists(sshDir) {
		if err := s.d.FS.MkdirAll(sshDir, 0o700); err != nil {
			return nil, err
		}
		created = true
	}
	if err := os.Chmod(s.d.FS.P(sshDir), 0o700); err != nil {
		return nil, err
	}
	changed, err := s.d.FS.WriteFile(file, []byte(content), 0o600)
	if err != nil {
		return nil, err
	}
	if s.d.FS.IsReal() {
		if err := s.d.FS.Chown(sshDir, p.User, ""); err != nil {
			return nil, err
		}
		if err := s.d.FS.Chown(file, p.User, ""); err != nil {
			return nil, err
		}
	}
	return SSHKeyResult{Changed: changed || created, Count: len(lines)}, nil
}

func joinLines(l []string) string {
	if len(l) == 0 {
		return ""
	}
	return strings.Join(l, "\n") + "\n"
}

// replaceBlock swaps (or appends) the managed block in content.
func replaceBlock(content, block string) string {
	i := strings.Index(content, blockBegin)
	j := strings.Index(content, blockEnd)
	if i >= 0 && j > i {
		end := j + len(blockEnd)
		if end < len(content) && content[end] == '\n' {
			end++
		}
		return content[:i] + block + content[end:]
	}
	if content != "" && !strings.HasSuffix(content, "\n") {
		content += "\n"
	}
	return content + block
}

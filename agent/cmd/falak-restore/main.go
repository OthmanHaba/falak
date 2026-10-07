// Command falak-restore decrypts a Falak backup (FKB1, docs/BACKUPS.md) without the control plane: with the backup's
// data key (exported by an admin, "Export backup key") or, for customer-held keys, the age identity file. It checks
// every segment and the trailer, decompresses, and writes the dump (pg_dump -Fc, a SQL dump, an RDB snapshot or a
// volume's tar) to stdout or a file.
package main

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"flag"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/backupcrypt"
	"github.com/OthmanHaba/falak/agent/internal/version"
)

const usage = `usage: falak-restore [--key-file FILE | --identity FILE] [--out FILE] [--expect-sha256 HEX] BACKUP
       falak-restore --info BACKUP

Decrypts and decompresses a Falak backup file (FKB1). BACKUP may be - for stdin.

  --key-file FILE      the backup's data key (hex or base64), for control-plane-held keys ("Export backup key")
  --identity FILE      an age identity file (AGE-SECRET-KEY-1...), for customer-held keys
  --out FILE           write the plaintext here (created 0600; nothing is left when verification fails). Default: stdout
  --expect-sha256 HEX  also require the plaintext's SHA-256 (the backup's "plaintext SHA-256")
  --info               print the header (key mode, key id) and exit

Exit codes: 0 verified, 1 failed (wrong key, corrupt or truncated file), 2 usage.
On stdout, data is written as each segment is verified: when the exit code is not 0, discard the output.
`

func main() {
	os.Exit(run(os.Args[1:], os.Stdin, os.Stdout, os.Stderr))
}

func run(args []string, stdin io.Reader, stdout, stderr io.Writer) int {
	fs := flag.NewFlagSet("falak-restore", flag.ContinueOnError)
	fs.SetOutput(stderr)
	fs.Usage = func() { fmt.Fprint(stderr, usage) }
	keyFile := fs.String("key-file", "", "")
	identityFile := fs.String("identity", "", "")
	out := fs.String("out", "", "")
	expect := fs.String("expect-sha256", "", "")
	info := fs.Bool("info", false, "")
	showVersion := fs.Bool("version", false, "")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	if *showVersion {
		fmt.Fprintln(stdout, "falak-restore", version.Version)
		return 0
	}
	if fs.NArg() != 1 {
		fmt.Fprint(stderr, usage)
		return 2
	}
	if !*info && (*keyFile == "") == (*identityFile == "") {
		fmt.Fprintln(stderr, "falak-restore: give exactly one of --key-file or --identity")
		return 2
	}
	if *expect != "" {
		if b, err := hex.DecodeString(*expect); err != nil || len(b) != sha256.Size {
			fmt.Fprintln(stderr, "falak-restore: --expect-sha256 must be 64 hex characters")
			return 2
		}
	}

	var src io.Reader = stdin
	if name := fs.Arg(0); name != "-" {
		f, err := os.Open(name)
		if err != nil {
			fmt.Fprintf(stderr, "falak-restore: %v\n", err)
			return 1
		}
		defer f.Close()
		src = f
	}
	h, err := backupcrypt.ReadHeader(src)
	if err != nil {
		fmt.Fprintf(stderr, "falak-restore: %v\n", err)
		return 1
	}
	if *info {
		fmt.Fprintf(stdout, "format: FKB1 (AES-256-GCM, zstd, %d-byte segments)\nkey mode: %s\nkey id: %s\n", h.SegmentSize, h.Mode, h.KeyID)
		return 0
	}

	if h.Mode == backupcrypt.ModeAge && *expect == "" {
		// The header holds the data key encrypted to a public key: anyone who can write to the bucket can make a file
		// that opens with the same identity. Only the SHA-256 Falak recorded tells the real backup from such a file.
		fmt.Fprintln(stderr, "falak-restore: warning: without --expect-sha256 (the backup's plaintext SHA-256 in Falak), a customer-held "+
			"backup can't be told from a file forged by anyone with write access to the storage")
	}
	key, err := loadKey(h, *keyFile, *identityFile)
	if err != nil {
		fmt.Fprintf(stderr, "falak-restore: %v\n", err)
		return 1
	}
	r, err := backupcrypt.Open(h, src, key)
	clear(key)
	if err != nil {
		fmt.Fprintf(stderr, "falak-restore: %v\n", err)
		return 1
	}
	defer r.Close()

	sum, err := write(r, *out, stdout, *expect)
	if err != nil {
		fmt.Fprintf(stderr, "falak-restore: %v\n", err)
		if *out == "" {
			fmt.Fprintln(stderr, "falak-restore: verification failed: discard what was written to stdout")
		}
		return 1
	}
	fmt.Fprintf(stderr, "verified: %d bytes, sha256 %x (key id %s)\n", sum.Bytes, sum.SHA256, h.KeyID)
	return 0
}

// loadKey reads the data key for the header's mode.
func loadKey(h *backupcrypt.Header, keyFile, identityFile string) ([]byte, error) {
	switch {
	case h.Mode == backupcrypt.ModeCP && keyFile != "":
		b, err := os.ReadFile(keyFile)
		if err != nil {
			return nil, err
		}
		defer clear(b)
		return backupcrypt.DecodeKey(keyLine(string(b)))
	case h.Mode == backupcrypt.ModeAge && identityFile != "":
		b, err := os.ReadFile(identityFile)
		if err != nil {
			return nil, err
		}
		defer clear(b)
		return backupcrypt.UnwrapAge(h.Wrapped, string(b))
	case h.Mode == backupcrypt.ModeAge:
		return nil, errors.New("this backup's key is customer-held: pass --identity with the age identity file")
	default:
		return nil, errors.New("this backup's key is held by the control plane: pass --key-file with the exported key")
	}
}

// keyLine takes the key from an exported key file: the first line that is not a comment.
func keyLine(s string) string {
	for _, l := range strings.Split(s, "\n") {
		if l = strings.TrimSpace(l); l != "" && !strings.HasPrefix(l, "#") {
			return l
		}
	}
	return ""
}

// write copies the verified plaintext to out (a 0600 file, renamed into place only once verified) or stdout.
func write(r *backupcrypt.Reader, out string, stdout io.Writer, expect string) (backupcrypt.Summary, error) {
	check := func(s backupcrypt.Summary) error {
		if expect != "" && !strings.EqualFold(hex.EncodeToString(s.SHA256[:]), expect) {
			return fmt.Errorf("the plaintext's sha256 is %x, not %s", s.SHA256, expect)
		}
		return nil
	}
	if out == "" {
		if _, err := io.Copy(stdout, r); err != nil {
			return backupcrypt.Summary{}, err
		}
		return r.Summary(), check(r.Summary())
	}
	tmp, err := os.CreateTemp(filepath.Dir(out), "."+filepath.Base(out)+".partial-*")
	if err != nil {
		return backupcrypt.Summary{}, err
	}
	defer os.Remove(tmp.Name())
	if _, err := io.Copy(tmp, r); err != nil {
		tmp.Close()
		return backupcrypt.Summary{}, err
	}
	if err := tmp.Close(); err != nil {
		return backupcrypt.Summary{}, err
	}
	if err := check(r.Summary()); err != nil {
		return backupcrypt.Summary{}, err
	}
	return r.Summary(), os.Rename(tmp.Name(), out)
}

package backupcrypt

import (
	"bytes"
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"regexp"
	"strings"

	"filippo.io/age"
)

// Encryption is the `encryption` object of the backup and restore commands (contracts/agent-protocol: db.backup,
// db.restore, db.drill, volume.archive, volume.restore, volume.drill).
//
//   - cp: the control plane generated the data key and sends it (Key, base64) inside the command payload; the agent
//     keeps it in memory only.
//   - age: backups name the customer's X25519 Recipient; the agent generates the data key and stores it in the header,
//     encrypted to the recipient (the control plane never sees it). Restores carry the customer's Identity
//     (AGE-SECRET-KEY-1…), given for that restore only.
type Encryption struct {
	Mode      string `json:"mode"`
	KeyID     string `json:"key_id"`
	Key       string `json:"key,omitempty"`
	Recipient string `json:"recipient,omitempty"`
	Identity  string `json:"identity,omitempty"`
}

// Secrets are the values the command output must never show.
func (e Encryption) Secrets() []string { return []string{e.Key, e.Identity} }

var (
	keyIDRe     = regexp.MustCompile(`^[0-9A-Za-z][0-9A-Za-z._:-]{0,127}$`)
	recipientRe = regexp.MustCompile(`^age1[02-9ac-hj-np-z]{58}$`)
)

// ValidRecipient reports whether s is an age X25519 recipient (age1…).
func ValidRecipient(s string) bool {
	if !recipientRe.MatchString(s) {
		return false
	}
	_, err := age.ParseX25519Recipient(s)
	return err == nil
}

// Check validates the object for a backup (sealing) or a restore (opening).
func (e Encryption) Check(sealing bool) error {
	if !keyIDRe.MatchString(e.KeyID) {
		return errors.New("encryption.key_id is invalid")
	}
	switch e.Mode {
	case "cp":
		if _, err := DecodeKey(e.Key); err != nil {
			return fmt.Errorf("encryption.key: %w", err)
		}
		if e.Recipient != "" || e.Identity != "" {
			return errors.New("encryption: cp mode takes only key")
		}
	case "age":
		if e.Key != "" {
			return errors.New("encryption: age mode never takes a key")
		}
		if sealing {
			if !ValidRecipient(e.Recipient) {
				return errors.New("encryption.recipient must be an age X25519 recipient (age1…)")
			}
		} else if _, err := age.ParseX25519Identity(strings.TrimSpace(e.Identity)); err != nil {
			return errors.New("encryption.identity must be an age X25519 identity (AGE-SECRET-KEY-1…)")
		}
	default:
		return fmt.Errorf("unknown encryption mode %q", e.Mode)
	}
	return nil
}

// Seal starts an encrypted, compressed stream into w.
func (e Encryption) Seal(w io.Writer) (*Writer, error) {
	if err := e.Check(true); err != nil {
		return nil, err
	}
	o := Options{KeyID: e.KeyID}
	var key []byte
	switch e.Mode {
	case "cp":
		o.Mode = ModeCP
		key, _ = DecodeKey(e.Key)
	case "age":
		o.Mode = ModeAge
		key = make([]byte, KeyBytes)
		if _, err := rand.Read(key); err != nil {
			return nil, err
		}
		wrapped, err := WrapAge(key, e.Recipient)
		if err != nil {
			return nil, err
		}
		o.Wrapped = wrapped
	}
	defer clear(key)
	return NewWriter(w, key, o)
}

// Open reads the header of r, checks it belongs to this backup (key id, mode) and opens the stream.
func (e Encryption) Open(r io.Reader) (*Reader, error) {
	if err := e.Check(false); err != nil {
		return nil, err
	}
	h, err := ReadHeader(r)
	if err != nil {
		return nil, err
	}
	if h.KeyID != e.KeyID {
		return nil, fmt.Errorf("the backup's key id is %q, not %q: another backup's file", h.KeyID, e.KeyID)
	}
	var key []byte
	switch {
	case e.Mode == "cp" && h.Mode == ModeCP:
		key, _ = DecodeKey(e.Key)
	case e.Mode == "age" && h.Mode == ModeAge:
		if key, err = UnwrapAge(h.Wrapped, e.Identity); err != nil {
			return nil, err
		}
	default:
		return nil, fmt.Errorf("the backup's key is held in mode %s, not %s", h.Mode, e.Mode)
	}
	defer clear(key)
	return Open(h, r, key)
}

// DecodeKey reads a 32-byte data key written as base64 (standard or URL, padded or not) or hex.
func DecodeKey(s string) ([]byte, error) {
	s = strings.TrimSpace(s)
	if len(s) == 2*KeyBytes {
		if b, err := hex.DecodeString(s); err == nil {
			return b, nil
		}
	}
	for _, enc := range []*base64.Encoding{base64.StdEncoding, base64.RawStdEncoding, base64.URLEncoding, base64.RawURLEncoding} {
		if b, err := enc.DecodeString(s); err == nil {
			if len(b) != KeyBytes {
				return nil, fmt.Errorf("the key is %d bytes, not %d", len(b), KeyBytes)
			}
			return b, nil
		}
	}
	return nil, errors.New("the key is neither hex nor base64")
}

// WrapAge encrypts the data key to an age X25519 recipient (a complete age file: `age -d` opens it too).
func WrapAge(key []byte, recipient string) ([]byte, error) {
	r, err := age.ParseX25519Recipient(strings.TrimSpace(recipient))
	if err != nil {
		return nil, fmt.Errorf("age recipient: %w", err)
	}
	var b bytes.Buffer
	w, err := age.Encrypt(&b, r)
	if err != nil {
		return nil, err
	}
	if _, err := w.Write(key); err != nil {
		return nil, err
	}
	if err := w.Close(); err != nil {
		return nil, err
	}
	return b.Bytes(), nil
}

// UnwrapAge decrypts a data key wrapped by WrapAge with the identity (AGE-SECRET-KEY-1…, or an identity file's
// content: comments and blank lines are skipped, every identity is tried).
func UnwrapAge(wrapped []byte, identity string) ([]byte, error) {
	ids, err := age.ParseIdentities(strings.NewReader(identity))
	if err != nil {
		return nil, errors.New("age identity: not an AGE-SECRET-KEY-1… key")
	}
	r, err := age.Decrypt(bytes.NewReader(wrapped), ids...)
	if err != nil {
		var nm *age.NoIdentityMatchError
		if errors.As(err, &nm) {
			return nil, errors.New("the age identity does not match the backup's recipient")
		}
		return nil, fmt.Errorf("age: %w", err)
	}
	key, err := io.ReadAll(io.LimitReader(r, KeyBytes+1))
	if err != nil {
		return nil, fmt.Errorf("age: %w", err)
	}
	if len(key) != KeyBytes {
		return nil, errors.New("the wrapped data key is not 32 bytes")
	}
	return key, nil
}

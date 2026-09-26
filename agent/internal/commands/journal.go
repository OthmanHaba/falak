package commands

import (
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"time"
)

// DefaultJournalSize is the number of completed commands kept on disk.
const DefaultJournalSize = 1000

// maxJournalResult bounds the persisted result of one command; larger results are dropped from the
// journal (the re-emitted finished event then carries exit_code/error but no result).
const maxJournalResult = 64 << 10

// journalEntry is one completed command in the on-disk journal.
type journalEntry struct {
	CommandID       string    `json:"command_id"`
	IdempotencyKey  string    `json:"idempotency_key,omitempty"`
	OK              bool      `json:"ok"`
	StartedAt       time.Time `json:"started_at"`
	Finished        Event     `json:"finished"`
	ResultTruncated bool      `json:"result_truncated,omitempty"`
}

type journalFile struct {
	Version int            `json:"version"`
	Entries []journalEntry `json:"entries"`
}

// Persist enables a durable journal of the last n completed commands at path, and loads any existing
// journal into the dedupe caches. After an agent restart a redelivered command id — or a new command
// id carrying the idempotency key of an already *successful* command — is answered from the journal
// instead of being executed again. A corrupt journal is discarded (the error is returned for logging;
// the dispatcher keeps working with an empty cache). Call before the first Submit.
func (d *Dispatcher) Persist(path string, n int) error {
	if n <= 0 {
		n = DefaultJournalSize
	}
	d.mu.Lock()
	defer d.mu.Unlock()
	d.journalPath, d.journalCap = path, n
	if d.done.cap < n {
		d.done.cap = n
	}
	if d.byKey.cap < n {
		d.byKey.cap = n
	}
	b, err := os.ReadFile(path)
	if errors.Is(err, fs.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	var jf journalFile
	if err := json.Unmarshal(b, &jf); err != nil {
		return fmt.Errorf("command journal %s is corrupt, starting empty: %w", path, err)
	}
	if len(jf.Entries) > n {
		jf.Entries = jf.Entries[len(jf.Entries)-n:]
	}
	d.journal = jf.Entries
	for _, e := range jf.Entries {
		rec := record{finished: e.Finished, startedAt: e.StartedAt}
		d.done.put(e.CommandID, rec)
		if e.OK && e.IdempotencyKey != "" {
			d.byKey.put(e.IdempotencyKey, rec)
		}
	}
	return nil
}

// journalAppendLocked records a completion and returns the snapshot to write (nil when disabled).
func (d *Dispatcher) journalAppendLocked(env Envelope, rec record, ok bool) []byte {
	if d.journalPath == "" {
		return nil
	}
	e := journalEntry{CommandID: env.ID, IdempotencyKey: env.IdempotencyKey, OK: ok, StartedAt: rec.startedAt, Finished: rec.finished}
	if e.Finished.Result != nil {
		if b, err := json.Marshal(e.Finished.Result); err != nil || len(b) > maxJournalResult {
			e.Finished.Result, e.ResultTruncated = nil, true
		}
	}
	d.journal = append(d.journal, e)
	if over := len(d.journal) - d.journalCap; over > 0 {
		d.journal = append([]journalEntry(nil), d.journal[over:]...)
	}
	b, err := json.Marshal(journalFile{Version: 1, Entries: d.journal})
	if err != nil {
		d.log.Warn("encode command journal", "err", err)
		return nil
	}
	return b
}

// writeJournal atomically replaces the journal file (serialized by journalMu, newest snapshot wins).
func (d *Dispatcher) writeJournal(seq uint64, b []byte) {
	d.journalMu.Lock()
	defer d.journalMu.Unlock()
	if seq < d.journalWritten {
		return // a newer snapshot was already written
	}
	d.journalWritten = seq
	dir := filepath.Dir(d.journalPath)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		d.log.Warn("command journal dir", "err", err)
		return
	}
	tmp, err := os.CreateTemp(dir, ".commands.json.tmp-*")
	if err != nil {
		d.log.Warn("command journal", "err", err)
		return
	}
	defer os.Remove(tmp.Name())
	_ = tmp.Chmod(0o600) // results may contain sensitive output
	if _, err := tmp.Write(b); err == nil {
		err = tmp.Sync()
	}
	if err := tmp.Close(); err != nil {
		d.log.Warn("command journal", "err", err)
		return
	}
	if err := os.Rename(tmp.Name(), d.journalPath); err != nil {
		d.log.Warn("command journal", "err", err)
	}
}

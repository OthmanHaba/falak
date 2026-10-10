package dbhelper

import (
	"errors"
	"fmt"
)

// Exit codes (part of the CLI contract, docs/DB_IMAGES.md).
const (
	ExitOK          = 0 // done; the JSON result is on stdout (or on stderr for streaming commands)
	ExitFailed      = 1 // the operation failed; the reason is on stderr
	ExitUsage       = 2 // bad arguments or settings
	ExitUnsupported = 3 // the operation does not exist for this engine
	ExitConflict    = 4 // refused: a spooled file differs from the new one, or a target directory is not empty
	ExitNotFound    = 5 // wal-fetch: the segment is not in the source directory
)

// codedError carries an exit code.
type codedError struct {
	code int
	msg  string
}

func (e *codedError) Error() string { return e.msg }

func usageErr(format string, a ...any) error {
	return &codedError{ExitUsage, fmt.Sprintf(format, a...)}
}

func unsupported(e Engine, op string) error {
	return &codedError{ExitUnsupported, fmt.Sprintf("%s is not supported for %s", op, e)}
}

func conflict(format string, a ...any) error {
	return &codedError{ExitConflict, fmt.Sprintf(format, a...)}
}

func notFound(format string, a ...any) error {
	return &codedError{ExitNotFound, fmt.Sprintf(format, a...)}
}

// ExitCode maps an error to the process exit code.
func ExitCode(err error) int {
	if err == nil {
		return ExitOK
	}
	var c *codedError
	if errors.As(err, &c) {
		return c.code
	}
	return ExitFailed
}

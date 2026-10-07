// Package backupcrypt is Falak's backup file format, FKB1: the data is compressed with zstd, then encrypted with
// AES-256-GCM in 64 KiB segments (the STREAM construction), so a backup of any size streams through in constant memory
// and any change, truncation or reordering is detected. Every backup has its own random 32-byte data key: held by the
// control plane (wrapped under the organization's key) or, for customer-held keys, encrypted with age to the
// customer's X25519 recipient and stored in the header (docs/BACKUPS.md).
//
//	header   "FKB1" | version 1 | cipher 1 (AES-256-GCM) | compression 1 (zstd) | key mode (1 cp, 2 age)
//	         | segment size u32 | key id len u8 | key id | wrapped key len u16 | wrapped key | base nonce (12)
//	segments length u32 | AES-256-GCM(compressed data[length]) (length+16 bytes), 1 ≤ length ≤ segment size
//	trailer  0x80000028 (the high bit marks it, length 40) | AES-256-GCM(uncompressed length u64 | SHA-256)
//
// Segment i (the trailer included, counted from 0) is sealed with nonce = base nonce XOR i (the low 8 bytes, big
// endian), its first byte XOR 0x80 for the trailer, and AAD = SHA-256(header) | kind (0 data, 1 trailer). So the header
// can't be changed (every segment fails), segments can't be dropped, repeated or reordered (counter), and the file
// can't end early or grow (only the trailer may be last, and nothing may follow it). The encryption key is
// HKDF-SHA256(data key, salt = base nonce, info "falak backup FKB1"). The trailer authenticates the uncompressed
// length and SHA-256, which the reader checks at the end of the stream.
package backupcrypt

import (
	"bytes"
	"crypto/aes"
	"crypto/cipher"
	"crypto/hkdf"
	"crypto/sha256"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
)

// Format constants.
const (
	Magic          = "FKB1"
	Version        = 1
	CipherAES256   = 1
	CompressZstd   = 1
	SegmentSize    = 64 << 10
	KeyBytes       = 32
	nonceBytes     = 12
	tagBytes       = 16
	trailerBytes   = 8 + sha256.Size
	maxKeyID       = 255
	maxWrapped     = 8 << 10
	maxSegmentSize = 1 << 20
	hkdfInfo       = "falak backup FKB1"
)

// Mode is who holds the data key.
type Mode byte

const (
	// ModeCP: the control plane keeps the data key, wrapped under the organization's key.
	ModeCP Mode = 1
	// ModeAge: the data key is encrypted with age to the customer's X25519 recipient, in the header.
	ModeAge Mode = 2
)

func (m Mode) String() string {
	switch m {
	case ModeCP:
		return "cp"
	case ModeAge:
		return "age"
	}
	return fmt.Sprintf("mode(%d)", byte(m))
}

// ErrAuth is any authentication failure: a wrong key, a changed, truncated or reordered file.
var ErrAuth = errors.New("backup is corrupt, truncated or encrypted with another key")

// ErrFormat is a header that is not FKB1 (or not a version this build reads).
var ErrFormat = errors.New("not a Falak backup (FKB1)")

// Header is a parsed FKB1 header.
type Header struct {
	Mode        Mode
	SegmentSize int
	KeyID       string
	// Wrapped is the age-encrypted data key (ModeAge); empty for ModeCP.
	Wrapped   []byte
	BaseNonce [nonceBytes]byte
	raw       []byte
}

// Summary is what a finished stream authenticated: the uncompressed data's length and SHA-256.
type Summary struct {
	Bytes  int64
	SHA256 [sha256.Size]byte
}

func (h *Header) marshal() ([]byte, error) {
	if len(h.KeyID) == 0 || len(h.KeyID) > maxKeyID {
		return nil, fmt.Errorf("key id must be 1-%d bytes", maxKeyID)
	}
	if len(h.Wrapped) > maxWrapped {
		return nil, fmt.Errorf("wrapped key is larger than %d bytes", maxWrapped)
	}
	if h.SegmentSize < 1 || h.SegmentSize > maxSegmentSize {
		return nil, fmt.Errorf("invalid segment size %d", h.SegmentSize)
	}
	var b bytes.Buffer
	b.WriteString(Magic)
	b.Write([]byte{Version, CipherAES256, CompressZstd, byte(h.Mode)})
	_ = binary.Write(&b, binary.BigEndian, uint32(h.SegmentSize))
	b.WriteByte(byte(len(h.KeyID)))
	b.WriteString(h.KeyID)
	_ = binary.Write(&b, binary.BigEndian, uint16(len(h.Wrapped)))
	b.Write(h.Wrapped)
	b.Write(h.BaseNonce[:])
	return b.Bytes(), nil
}

// ReadHeader reads and validates an FKB1 header; the reader is left at the first segment.
func ReadHeader(r io.Reader) (*Header, error) {
	var fixed [12]byte
	if _, err := io.ReadFull(r, fixed[:]); err != nil {
		return nil, fmt.Errorf("%w: %v", ErrFormat, err)
	}
	if string(fixed[:4]) != Magic {
		return nil, ErrFormat
	}
	if fixed[4] != Version {
		return nil, fmt.Errorf("%w: version %d is not supported", ErrFormat, fixed[4])
	}
	if fixed[5] != CipherAES256 || fixed[6] != CompressZstd {
		return nil, fmt.Errorf("%w: cipher %d / compression %d are not supported", ErrFormat, fixed[5], fixed[6])
	}
	h := &Header{Mode: Mode(fixed[7]), SegmentSize: int(binary.BigEndian.Uint32(fixed[8:12]))}
	if h.Mode != ModeCP && h.Mode != ModeAge {
		return nil, fmt.Errorf("%w: unknown key mode %d", ErrFormat, fixed[7])
	}
	if h.SegmentSize < 1 || h.SegmentSize > maxSegmentSize {
		return nil, fmt.Errorf("%w: invalid segment size %d", ErrFormat, h.SegmentSize)
	}
	var n [1]byte
	if _, err := io.ReadFull(r, n[:]); err != nil {
		return nil, fmt.Errorf("%w: %v", ErrFormat, err)
	}
	if n[0] == 0 {
		return nil, fmt.Errorf("%w: empty key id", ErrFormat)
	}
	id := make([]byte, n[0])
	if _, err := io.ReadFull(r, id); err != nil {
		return nil, fmt.Errorf("%w: %v", ErrFormat, err)
	}
	h.KeyID = string(id)
	var wl [2]byte
	if _, err := io.ReadFull(r, wl[:]); err != nil {
		return nil, fmt.Errorf("%w: %v", ErrFormat, err)
	}
	if l := binary.BigEndian.Uint16(wl[:]); l > 0 {
		if l > maxWrapped {
			return nil, fmt.Errorf("%w: wrapped key too large", ErrFormat)
		}
		h.Wrapped = make([]byte, l)
		if _, err := io.ReadFull(r, h.Wrapped); err != nil {
			return nil, fmt.Errorf("%w: %v", ErrFormat, err)
		}
	}
	if (h.Mode == ModeAge) != (len(h.Wrapped) > 0) {
		return nil, fmt.Errorf("%w: key mode %s with %d bytes of wrapped key", ErrFormat, h.Mode, len(h.Wrapped))
	}
	if _, err := io.ReadFull(r, h.BaseNonce[:]); err != nil {
		return nil, fmt.Errorf("%w: %v", ErrFormat, err)
	}
	raw, err := h.marshal()
	if err != nil {
		return nil, fmt.Errorf("%w: %v", ErrFormat, err)
	}
	h.raw = raw
	return h, nil
}

// segmentCipher seals and opens the segments of one stream.
type segmentCipher struct {
	aead  cipher.AEAD
	base  [nonceBytes]byte
	hhash [sha256.Size]byte
}

func newSegmentCipher(key []byte, h *Header) (*segmentCipher, error) {
	if len(key) != KeyBytes {
		return nil, fmt.Errorf("data key must be %d bytes", KeyBytes)
	}
	sub, err := hkdf.Key(sha256.New, key, h.BaseNonce[:], hkdfInfo, KeyBytes)
	if err != nil {
		return nil, err
	}
	block, err := aes.NewCipher(sub)
	if err != nil {
		return nil, err
	}
	aead, err := cipher.NewGCM(block)
	if err != nil {
		return nil, err
	}
	return &segmentCipher{aead: aead, base: h.BaseNonce, hhash: sha256.Sum256(h.raw)}, nil
}

func (c *segmentCipher) nonce(counter uint64, last bool) []byte {
	n := c.base
	var ctr [8]byte
	binary.BigEndian.PutUint64(ctr[:], counter)
	for i := range ctr {
		n[4+i] ^= ctr[i]
	}
	if last {
		n[0] ^= 0x80
	}
	return n[:]
}

func (c *segmentCipher) aad(last bool) []byte {
	a := make([]byte, 0, sha256.Size+1)
	a = append(a, c.hhash[:]...)
	if last {
		return append(a, 1)
	}
	return append(a, 0)
}

func (c *segmentCipher) seal(dst, plain []byte, counter uint64, last bool) []byte {
	return c.aead.Seal(dst, c.nonce(counter, last), plain, c.aad(last))
}

func (c *segmentCipher) open(dst, sealed []byte, counter uint64, last bool) ([]byte, error) {
	out, err := c.aead.Open(dst, c.nonce(counter, last), sealed, c.aad(last))
	if err != nil {
		return nil, ErrAuth
	}
	return out, nil
}

package backupcrypt

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/binary"
	"errors"
	"fmt"
	"hash"
	"io"

	"github.com/klauspost/compress/zstd"
)

const lastFlag = 1 << 31

// Options describe a new FKB1 stream.
type Options struct {
	Mode  Mode
	KeyID string
	// Wrapped is the age-encrypted data key (ModeAge).
	Wrapped []byte
	// SegmentSize defaults to SegmentSize (tests use small ones).
	SegmentSize int
}

// Writer compresses and encrypts what is written to it. Close finishes the stream (the trailer); Summary is then
// what it authenticated.
type Writer struct {
	seg     *segmentWriter
	zw      *zstd.Encoder
	h       hash.Hash
	n       int64
	closed  bool
	err     error
	summary Summary
}

// NewWriter writes the header to w and returns the stream's writer. key is the 32-byte data key.
func NewWriter(w io.Writer, key []byte, o Options) (*Writer, error) {
	if o.SegmentSize == 0 {
		o.SegmentSize = SegmentSize
	}
	h := &Header{Mode: o.Mode, SegmentSize: o.SegmentSize, KeyID: o.KeyID, Wrapped: o.Wrapped}
	if h.Mode != ModeCP && h.Mode != ModeAge {
		return nil, fmt.Errorf("unknown key mode %d", o.Mode)
	}
	if (h.Mode == ModeAge) != (len(h.Wrapped) > 0) {
		return nil, errors.New("the age mode needs the wrapped key, and only it")
	}
	if _, err := rand.Read(h.BaseNonce[:]); err != nil {
		return nil, err
	}
	raw, err := h.marshal()
	if err != nil {
		return nil, err
	}
	h.raw = raw
	c, err := newSegmentCipher(key, h)
	if err != nil {
		return nil, err
	}
	if _, err := w.Write(raw); err != nil {
		return nil, err
	}
	seg := &segmentWriter{w: w, c: c, buf: make([]byte, 0, h.SegmentSize), size: h.SegmentSize}
	zw, err := zstd.NewWriter(seg, zstd.WithEncoderLevel(zstd.SpeedDefault))
	if err != nil {
		return nil, err
	}
	return &Writer{seg: seg, zw: zw, h: sha256.New()}, nil
}

func (w *Writer) Write(p []byte) (int, error) {
	if w.closed {
		return 0, errors.New("backupcrypt: write after close")
	}
	if w.err != nil {
		return 0, w.err
	}
	n, err := w.zw.Write(p)
	w.h.Write(p[:n])
	w.n += int64(n)
	if err != nil {
		w.err = err
	}
	return n, err
}

// Close flushes the compressor and the last segment, then writes the trailer. It does not close the underlying writer.
func (w *Writer) Close() error {
	if w.closed {
		return w.err
	}
	w.closed = true
	if w.err != nil {
		w.zw.Close()
		return w.err
	}
	if err := w.zw.Close(); err != nil {
		w.err = err
		return err
	}
	w.summary.Bytes = w.n
	copy(w.summary.SHA256[:], w.h.Sum(nil))
	w.err = w.seg.finish(w.summary)
	return w.err
}

// Summary is the uncompressed length and SHA-256 the trailer holds (valid after a successful Close).
func (w *Writer) Summary() Summary { return w.summary }

// segmentWriter seals the compressed stream into segments.
type segmentWriter struct {
	w       io.Writer
	c       *segmentCipher
	buf     []byte
	size    int
	counter uint64
	out     []byte
}

func (s *segmentWriter) Write(p []byte) (int, error) {
	written := 0
	for len(p) > 0 {
		n := min(len(p), s.size-len(s.buf))
		s.buf = append(s.buf, p[:n]...)
		p = p[n:]
		written += n
		if len(s.buf) == s.size {
			if err := s.flush(); err != nil {
				return written, err
			}
		}
	}
	return written, nil
}

func (s *segmentWriter) flush() error {
	if len(s.buf) == 0 {
		return nil
	}
	err := s.record(s.buf, false)
	s.buf = s.buf[:0]
	return err
}

func (s *segmentWriter) record(plain []byte, last bool) error {
	if s.counter == 1<<63 {
		return errors.New("backupcrypt: too many segments")
	}
	l := uint32(len(plain))
	if last {
		l |= lastFlag
	}
	s.out = binary.BigEndian.AppendUint32(s.out[:0], l)
	s.out = s.c.seal(s.out, plain, s.counter, last)
	s.counter++
	_, err := s.w.Write(s.out)
	return err
}

func (s *segmentWriter) finish(sum Summary) error {
	if err := s.flush(); err != nil {
		return err
	}
	t := make([]byte, 0, trailerBytes)
	t = binary.BigEndian.AppendUint64(t, uint64(sum.Bytes))
	t = append(t, sum.SHA256[:]...)
	return s.record(t, true)
}

// Reader decrypts and decompresses an FKB1 stream. Data is only returned once its segment is authenticated; the end
// of the stream (io.EOF) is only reported once the trailer is authenticated and matches what was read.
type Reader struct {
	Header  *Header
	seg     *segmentReader
	zr      *zstd.Decoder
	h       hash.Hash
	n       int64
	err     error
	summary Summary
}

// NewReader reads the header from r and opens the stream with the 32-byte data key.
func NewReader(r io.Reader, key []byte) (*Reader, error) {
	h, err := ReadHeader(r)
	if err != nil {
		return nil, err
	}
	return Open(h, r, key)
}

// Open opens the stream after a header read with ReadHeader (to pick the key from it first).
func Open(h *Header, r io.Reader, key []byte) (*Reader, error) {
	c, err := newSegmentCipher(key, h)
	if err != nil {
		return nil, err
	}
	seg := &segmentReader{r: r, c: c, size: h.SegmentSize}
	zr, err := zstd.NewReader(seg, zstd.WithDecoderConcurrency(1), zstd.WithDecoderLowmem(true), zstd.WithDecoderMaxWindow(64<<20))
	if err != nil {
		return nil, err
	}
	return &Reader{Header: h, seg: seg, zr: zr, h: sha256.New()}, nil
}

func (r *Reader) Read(p []byte) (int, error) {
	if r.err != nil {
		return 0, r.err
	}
	n, err := r.zr.Read(p)
	r.h.Write(p[:n])
	r.n += int64(n)
	switch {
	case err == nil:
		return n, nil
	case errors.Is(err, io.EOF):
		r.err = r.end()
		if r.err == nil {
			r.err = io.EOF
		}
		return n, r.err
	default:
		// A failed segment surfaces as the decoder's error: report the authentication failure itself.
		if r.seg.err != nil && !errors.Is(r.seg.err, io.EOF) {
			err = r.seg.err
		}
		r.err = err
		return n, err
	}
}

// end checks the trailer once the decompressor reached the end of its input.
func (r *Reader) end() error {
	if !r.seg.done {
		// The compressed data ended but segments remain: only an (authenticated) trailer may follow.
		if _, err := io.Copy(io.Discard, r.seg); err != nil {
			return err
		}
		if !r.seg.done {
			return ErrAuth
		}
	}
	var got [sha256.Size]byte
	copy(got[:], r.h.Sum(nil))
	if r.n != r.seg.summary.Bytes || got != r.seg.summary.SHA256 {
		return fmt.Errorf("%w: the data does not match the trailer's length and SHA-256", ErrAuth)
	}
	r.summary = r.seg.summary
	return nil
}

// Summary is the authenticated length and SHA-256 of the data (valid once Read returned io.EOF).
func (r *Reader) Summary() Summary { return r.summary }

// Close releases the decompressor.
func (r *Reader) Close() error {
	r.zr.Close()
	return nil
}

// segmentReader authenticates segments and yields the compressed stream; io.EOF only after the trailer and the end of
// the input.
type segmentReader struct {
	r       io.Reader
	c       *segmentCipher
	size    int
	counter uint64
	plain   []byte
	pbuf    []byte
	sealed  []byte
	done    bool
	err     error
	summary Summary
}

func (s *segmentReader) Read(p []byte) (int, error) {
	for len(s.plain) == 0 {
		if s.err != nil {
			return 0, s.err
		}
		if s.done {
			s.err = io.EOF
			return 0, io.EOF
		}
		s.err = s.next()
	}
	n := copy(p, s.plain)
	s.plain = s.plain[n:]
	return n, nil
}

func (s *segmentReader) next() error {
	var lb [4]byte
	if _, err := io.ReadFull(s.r, lb[:]); err != nil {
		// The input ended before the trailer: truncated.
		return fmt.Errorf("%w: missing trailer (%v)", ErrAuth, err)
	}
	l := binary.BigEndian.Uint32(lb[:])
	last := l&lastFlag != 0
	l &^= lastFlag
	if last && l != trailerBytes || !last && (l == 0 || int(l) > s.size) {
		return fmt.Errorf("%w: bad segment length", ErrAuth)
	}
	if cap(s.sealed) < int(l)+tagBytes {
		s.sealed = make([]byte, int(l)+tagBytes)
	}
	s.sealed = s.sealed[:int(l)+tagBytes]
	if _, err := io.ReadFull(s.r, s.sealed); err != nil {
		return fmt.Errorf("%w: truncated segment (%v)", ErrAuth, err)
	}
	plain, err := s.c.open(s.pbuf[:0], s.sealed, s.counter, last)
	if err != nil {
		return err
	}
	s.counter++
	if !last {
		s.pbuf = plain // reused: s.plain is consumed before the next segment is read
		s.plain = plain
		return nil
	}
	// Nothing may follow the trailer.
	var extra [1]byte
	if n, _ := io.ReadFull(s.r, extra[:]); n != 0 {
		return fmt.Errorf("%w: data after the trailer", ErrAuth)
	}
	s.summary.Bytes = int64(binary.BigEndian.Uint64(plain[:8]))
	copy(s.summary.SHA256[:], plain[8:])
	s.done = true
	return nil
}

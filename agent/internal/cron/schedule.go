// Package cron is kiln-agent's built-in scheduler (replaces crontab juggling). It runs the desired job
// set from cron.apply and emits a heartbeat for every run so the control plane can detect missed runs.
package cron

import (
	"fmt"
	"strconv"
	"strings"
	"time"
)

// Schedule computes activation times.
type Schedule interface {
	// Next returns the first activation strictly after t, in t's location semantics of the schedule.
	Next(t time.Time) time.Time
}

// Parse parses a schedule expression evaluated in loc: 5-field cron, a descriptor
// (@hourly, @daily, @midnight, @weekly, @monthly, @yearly, @annually) or "@every <duration>".
func Parse(expr string, loc *time.Location) (Schedule, error) {
	if loc == nil {
		loc = time.UTC
	}
	expr = strings.TrimSpace(expr)
	if strings.HasPrefix(expr, "@every") {
		d, err := time.ParseDuration(strings.TrimSpace(strings.TrimPrefix(expr, "@every")))
		if err != nil {
			return nil, fmt.Errorf("invalid @every duration: %w", err)
		}
		if d < time.Second {
			return nil, fmt.Errorf("@every interval must be ≥ 1s")
		}
		return every{d}, nil
	}
	switch expr {
	case "@yearly", "@annually":
		expr = "0 0 1 1 *"
	case "@monthly":
		expr = "0 0 1 * *"
	case "@weekly":
		expr = "0 0 * * 0"
	case "@daily", "@midnight":
		expr = "0 0 * * *"
	case "@hourly":
		expr = "0 * * * *"
	}
	if strings.HasPrefix(expr, "@") {
		return nil, fmt.Errorf("unknown descriptor %q", expr)
	}
	f := strings.Fields(expr)
	if len(f) != 5 {
		return nil, fmt.Errorf("expected 5 fields, got %d in %q", len(f), expr)
	}
	s := &spec{loc: loc}
	var err error
	if s.minute, err = parseField(f[0], 0, 59, nil); err != nil {
		return nil, fmt.Errorf("minute: %w", err)
	}
	if s.hour, err = parseField(f[1], 0, 23, nil); err != nil {
		return nil, fmt.Errorf("hour: %w", err)
	}
	if s.dom, err = parseField(f[2], 1, 31, nil); err != nil {
		return nil, fmt.Errorf("day-of-month: %w", err)
	}
	if s.month, err = parseField(f[3], 1, 12, monthNames); err != nil {
		return nil, fmt.Errorf("month: %w", err)
	}
	if s.dow, err = parseField(f[4], 0, 7, dowNames); err != nil {
		return nil, fmt.Errorf("day-of-week: %w", err)
	}
	if s.dow&(1<<7) != 0 { // 7 == Sunday
		s.dow |= 1
		s.dow &^= 1 << 7
	}
	s.domStar = strings.HasPrefix(f[2], "*") || f[2] == "?"
	s.dowStar = strings.HasPrefix(f[4], "*") || f[4] == "?"
	return s, nil
}

var monthNames = map[string]int{"jan": 1, "feb": 2, "mar": 3, "apr": 4, "may": 5, "jun": 6, "jul": 7, "aug": 8, "sep": 9, "oct": 10, "nov": 11, "dec": 12}
var dowNames = map[string]int{"sun": 0, "mon": 1, "tue": 2, "wed": 3, "thu": 4, "fri": 5, "sat": 6}

func parseField(field string, min, max int, names map[string]int) (uint64, error) {
	var bits uint64
	for _, part := range strings.Split(field, ",") {
		if part == "" {
			return 0, fmt.Errorf("empty list element in %q", field)
		}
		rng, stepStr, hasStep := strings.Cut(part, "/")
		step := 1
		if hasStep {
			n, err := strconv.Atoi(stepStr)
			if err != nil || n <= 0 {
				return 0, fmt.Errorf("invalid step %q", stepStr)
			}
			step = n
		}
		lo, hi := min, max
		switch {
		case rng == "*" || rng == "?":
		default:
			a, b, isRange := strings.Cut(rng, "-")
			var err error
			if lo, err = value(a, min, max, names); err != nil {
				return 0, err
			}
			if isRange {
				if hi, err = value(b, min, max, names); err != nil {
					return 0, err
				}
				if hi < lo {
					return 0, fmt.Errorf("range %q is reversed", rng)
				}
			} else if hasStep {
				hi = max // "a/n" = from a to max every n
			} else {
				hi = lo
			}
		}
		for v := lo; v <= hi; v += step {
			bits |= 1 << uint(v)
		}
	}
	return bits, nil
}

func value(s string, min, max int, names map[string]int) (int, error) {
	if n, ok := names[strings.ToLower(s)]; ok {
		return n, nil
	}
	n, err := strconv.Atoi(s)
	if err != nil {
		return 0, fmt.Errorf("invalid value %q", s)
	}
	if n < min || n > max {
		return 0, fmt.Errorf("value %d out of range [%d,%d]", n, min, max)
	}
	return n, nil
}

type every struct{ d time.Duration }

func (e every) Next(t time.Time) time.Time { return t.Truncate(time.Second).Add(e.d) }

type spec struct {
	minute, hour, dom, month, dow uint64
	domStar, dowStar              bool
	loc                           *time.Location
}

func (s *spec) dayMatches(t time.Time) bool {
	d := s.dom&(1<<uint(t.Day())) != 0
	w := s.dow&(1<<uint(t.Weekday())) != 0
	if s.domStar || s.dowStar {
		return d && w
	}
	return d || w // vixie cron: both restricted → either matches
}

// Next walks forward in absolute time (so DST gaps are skipped and progress is guaranteed) using
// wall-clock fields in s.loc. DST gaps skip the missing wall-clock times.
func (s *spec) Next(t time.Time) time.Time {
	t0 := t.In(s.loc)
	t = t0.Add(time.Minute - time.Duration(t0.Second())*time.Second - time.Duration(t0.Nanosecond()))
	limit := t0.AddDate(5, 0, 0)
	for t.Before(limit) {
		prev := t
		switch {
		case s.month&(1<<uint(t.Month())) == 0:
			t = time.Date(t.Year(), t.Month()+1, 1, 0, 0, 0, 0, s.loc)
		case !s.dayMatches(t):
			t = time.Date(t.Year(), t.Month(), t.Day()+1, 0, 0, 0, 0, s.loc)
		case s.hour&(1<<uint(t.Hour())) == 0:
			t = t.Add(time.Duration(60-t.Minute()) * time.Minute)
		case s.minute&(1<<uint(t.Minute())) == 0:
			t = t.Add(time.Minute)
		default:
			// DST fall-back repeats an hour of wall-clock time. Like vixie cron, jobs pinned to specific hours
			// do not run again in the repeated hour; jobs with a wildcard hour keep running by absolute time.
			if s.hour != allHours && t.Sub(t0) < 2*time.Hour && wallBeforeEq(t, t0) {
				t = t.Add(time.Minute)
				break
			}
			return t
		}
		if !t.After(prev) { // pathological zone transitions: force progress
			t = prev.Add(time.Minute)
		}
	}
	return time.Time{}
}

const allHours = 1<<24 - 1

// wallBeforeEq reports whether a's wall clock is not after b's (same day), i.e. a repeated wall time.
func wallBeforeEq(a, b time.Time) bool {
	if a.Year() != b.Year() || a.YearDay() != b.YearDay() {
		return false
	}
	return a.Hour()*60+a.Minute() <= b.Hour()*60+b.Minute()
}

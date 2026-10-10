package metrics

import (
	"bufio"
	"bytes"
	"sort"
	"strconv"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// DiskUsage is one mounted filesystem in the heartbeat (`disks`): what df shows, so the control plane can alert per
// mount (used / (used + available)) and forecast when it fills.
type DiskUsage struct {
	Mount          string `json:"mount"`
	UsedBytes      int64  `json:"used_bytes"`
	AvailableBytes int64  `json:"available_bytes"`
	TotalBytes     int64  `json:"total_bytes"`
}

// MaxDisks caps the mounts in a heartbeat.
const MaxDisks = 20

// diskFSTypes are the filesystems that hold data (not tmpfs, overlay, squashfs, vfat EFI partitions, network mounts).
var diskFSTypes = map[string]bool{"ext2": true, "ext3": true, "ext4": true, "xfs": true, "btrfs": true, "zfs": true, "f2fs": true, "jfs": true}

// skipMounts are mounts reported elsewhere (Falak volumes alert on their own limit) or not the host's (Docker's).
var skipMounts = []string{"/var/lib/docker/", "/var/lib/falak/volumes/", "/snap/", "/run/", "/proc/", "/sys/", "/dev/"}

// ParseMounts returns the mount points of data filesystems in a /proc/mounts document, one per device (its first
// mount: later ones are bind mounts), sorted, at most MaxDisks.
func ParseMounts(b []byte) []string {
	byDevice := map[string]string{}
	sc := bufio.NewScanner(bytes.NewReader(b))
	for sc.Scan() {
		f := strings.Fields(sc.Text())
		if len(f) < 3 || !diskFSTypes[f[2]] {
			continue
		}
		mount := unescapeMount(f[1])
		if skipMount(mount) {
			continue
		}
		// The first mount of a device is the original; later ones are bind mounts. zfs datasets are keyed by name.
		if _, ok := byDevice[f[0]]; !ok {
			byDevice[f[0]] = mount
		}
	}
	seen := map[string]bool{}
	var out []string
	for _, m := range byDevice {
		if !seen[m] {
			seen[m] = true
			out = append(out, m)
		}
	}
	sort.Strings(out)
	if len(out) > MaxDisks {
		out = out[:MaxDisks]
	}
	return out
}

func skipMount(mount string) bool {
	for _, p := range skipMounts {
		if strings.HasPrefix(mount+"/", p) {
			return true
		}
	}
	return false
}

// unescapeMount decodes the octal escapes /proc/mounts uses for spaces, tabs, newlines and backslashes (\040).
func unescapeMount(s string) string {
	if !strings.Contains(s, `\`) {
		return s
	}
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] == '\\' && i+4 <= len(s) {
			if n, err := strconv.ParseUint(s[i+1:i+4], 8, 8); err == nil {
				b.WriteByte(byte(n))
				i += 3
				continue
			}
		}
		b.WriteByte(s[i])
	}
	return b.String()
}

// ReadDisks reports usage of the host's data filesystems (PID 1's mount table, so a containerised agent sees the
// host's); nil when the table is unreadable.
func ReadDisks(fs hostfs.FS) []DiskUsage {
	b, err := readFile(fs, "/proc/1/mounts")
	if err != nil {
		if b, err = readFile(fs, "/proc/mounts"); err != nil {
			return nil
		}
	}
	var out []DiskUsage
	for _, m := range ParseMounts(b) {
		u, err := ReadFS(fs, m)
		if err != nil || u.Total <= 0 {
			continue
		}
		out = append(out, DiskUsage{Mount: m, UsedBytes: u.Used, AvailableBytes: u.Free, TotalBytes: u.Total})
	}
	return out
}

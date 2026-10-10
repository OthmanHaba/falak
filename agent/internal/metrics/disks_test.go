package metrics

import (
	"os"
	"path/filepath"
	"reflect"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

const procMounts = `sysfs /sys sysfs rw,nosuid 0 0
proc /proc proc rw 0 0
/dev/sda1 / ext4 rw,relatime 0 0
/dev/sda15 /boot/efi vfat rw 0 0
/dev/sda2 /boot ext4 rw 0 0
tmpfs /run tmpfs rw 0 0
overlay /var/lib/docker/overlay2/abc/merged overlay rw 0 0
/dev/sda1 /var/lib/docker ext4 rw 0 0
/dev/sdb /mnt/data\040disk xfs rw 0 0
/dev/sdb /srv/bind xfs rw 0 0
/dev/loop3 /var/lib/falak/volumes/01hzyvol xfs rw 0 0
/dev/loop4 /snap/core/1 squashfs ro 0 0
tank/data /tank/data zfs rw 0 0
`

func TestParseMounts(t *testing.T) {
	got := ParseMounts([]byte(procMounts))
	want := []string{"/", "/boot", "/mnt/data disk", "/tank/data"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("mounts %q, want %q", got, want)
	}
}

func TestParseMountsCaps(t *testing.T) {
	var b []byte
	for i := 0; i < MaxDisks+5; i++ {
		b = append(b, []byte("/dev/sd"+string(rune('a'+i))+" /data"+string(rune('a'+i))+" ext4 rw 0 0\n")...)
	}
	if n := len(ParseMounts(b)); n != MaxDisks {
		t.Errorf("%d mounts, want %d", n, MaxDisks)
	}
}

func TestReadDisks(t *testing.T) {
	root := t.TempDir()
	os.MkdirAll(filepath.Join(root, "proc", "1"), 0o755)
	os.WriteFile(filepath.Join(root, "proc", "1", "mounts"), []byte("/dev/sda1 / ext4 rw 0 0\n/dev/sdz /gone ext4 rw 0 0\n"), 0o644)
	d := ReadDisks(hostfs.FS{Root: root})
	if len(d) != 1 || d[0].Mount != "/" || d[0].TotalBytes <= 0 || d[0].UsedBytes+d[0].AvailableBytes > d[0].TotalBytes {
		t.Errorf("disks %+v", d)
	}
	if ReadDisks(hostfs.FS{Root: t.TempDir()}) != nil {
		t.Error("disks without a mount table")
	}
}

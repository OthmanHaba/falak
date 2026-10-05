package deploy

import (
	"encoding/binary"
	"os"
)

// POSIX ACL xattr encoding (linux/posix_acl_xattr.h): a little-endian version header followed by
// {tag u16, perm u16, id u32} entries sorted by tag.
const (
	aclXattrVersion = 2
	aclUserObj      = 0x01
	aclUser         = 0x02
	aclGroupObj     = 0x04
	aclMask         = 0x10
	aclOther        = 0x20
	aclUndefinedID  = 0xFFFFFFFF
)

type aclEntry struct {
	tag, perm uint16
	id        uint32
}

func encodeACL(entries ...aclEntry) []byte {
	b := make([]byte, 4+len(entries)*8)
	binary.LittleEndian.PutUint32(b, aclXattrVersion)
	for i, e := range entries {
		off := 4 + i*8
		binary.LittleEndian.PutUint16(b[off:], e.tag)
		binary.LittleEndian.PutUint16(b[off+2:], e.perm)
		binary.LittleEndian.PutUint32(b[off+4:], e.id)
	}
	return b
}

// groupSharedDefaultACL is the default ACL "user::rwx group::rwx other::r-x" for a site's writable directories.
// Files and directories created under it get the owner's and the group's permissions as requested by the
// creating process (fopen asks for 0666) whatever its umask: PHP under FrankenPHP (the edge user, a member of
// the site group), PHP-FPM, workers and cron (the site user) can all append to the same storage/logs file
// whoever created it. Other users are kept out by the closed release and shared directories above them
// (closeDir); "other" read stays for a Caddy edge that serves public uploads without being in the site group.
func groupSharedDefaultACL() []byte {
	return encodeACL(aclEntry{aclUserObj, 7, aclUndefinedID}, aclEntry{aclGroupObj, 7, aclUndefinedID}, aclEntry{aclOther, 5, aclUndefinedID})
}

// edgeAccessACL is the access ACL of a closed directory (mode 0750): owner and group as the mode says, the edge
// user r-x (it serves static files and, under FrankenPHP, runs PHP), nobody else.
func edgeAccessACL(mode os.FileMode, edgeUID uint32) []byte {
	g := uint16(mode>>3) & 7
	return encodeACL(
		aclEntry{aclUserObj, uint16(mode>>6) & 7, aclUndefinedID},
		aclEntry{aclUser, 5, edgeUID},
		aclEntry{aclGroupObj, g, aclUndefinedID},
		aclEntry{aclMask, g | 5, aclUndefinedID},
		aclEntry{aclOther, uint16(mode) & 7, aclUndefinedID},
	)
}

// EdgeUser is the system user of falak-edge (Caddy / FrankenPHP), granted access to closed site directories.
var EdgeUser = "caddy"

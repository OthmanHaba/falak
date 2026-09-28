package deploy

import "encoding/binary"

// POSIX ACL xattr encoding (linux/posix_acl_xattr.h): a little-endian version header followed by
// {tag u16, perm u16, id u32} entries sorted by tag.
const (
	aclXattrVersion = 2
	aclUserObj      = 0x01
	aclGroupObj     = 0x04
	aclOther        = 0x20
	aclUndefinedID  = 0xFFFFFFFF
)

// groupSharedDefaultACL is the default ACL "user::rwx group::rwx other::---" for a site's writable directories.
// Files and directories created under it get the owner's and the group's permissions as requested by the
// creating process (fopen asks for 0666) whatever its umask, and nothing for other users: PHP under
// FrankenPHP (the edge user, a member of the site group), PHP-FPM, workers and cron (the site user) can all
// append to the same storage/logs file whoever created it, and other local users cannot read it.
func groupSharedDefaultACL() []byte {
	b := make([]byte, 4+3*8)
	binary.LittleEndian.PutUint32(b, aclXattrVersion)
	for i, e := range [][2]uint16{{aclUserObj, 7}, {aclGroupObj, 7}, {aclOther, 0}} {
		off := 4 + i*8
		binary.LittleEndian.PutUint16(b[off:], e[0])
		binary.LittleEndian.PutUint16(b[off+2:], e[1])
		binary.LittleEndian.PutUint32(b[off+4:], aclUndefinedID)
	}
	return b
}

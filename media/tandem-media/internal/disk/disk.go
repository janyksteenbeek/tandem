// Package disk reports free space and writability of the recordings volume.
package disk

import (
	"os"
	"syscall"
)

// Usage returns the bytes available to this process and the size of the filesystem holding dir.
func Usage(dir string) (free, total uint64, err error) {
	var stat syscall.Statfs_t
	if err := syscall.Statfs(dir, &stat); err != nil {
		return 0, 0, err
	}

	blockSize := uint64(stat.Bsize) //nolint:gosec,unconvert // differs per platform
	return uint64(stat.Bavail) * blockSize, uint64(stat.Blocks) * blockSize, nil
}

// Writable creates and removes a probe file to prove the directory accepts writes.
func Writable(dir string) error {
	probe, err := os.CreateTemp(dir, ".probe-*")
	if err != nil {
		return err
	}

	name := probe.Name()
	_ = probe.Close()

	return os.Remove(name)
}

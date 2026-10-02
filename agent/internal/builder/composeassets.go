package builder

import (
	"encoding/base64"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strings"
)

// ValidAssetPath: a relative repository path without ".", ".." or empty segments, backslashes or control
// characters (any other name, e.g. "logo@2x.png" or "my file.txt", is fine). Same rule as the agent's
// docker.compose.* assets and the control plane.
func ValidAssetPath(p string) bool {
	if p == "" || len(p) > 512 || strings.HasPrefix(p, "/") || strings.ContainsRune(p, '\\') {
		return false
	}
	for _, r := range p {
		if r < 0x20 || r == 0x7f {
			return false
		}
	}
	for _, seg := range strings.Split(p, "/") {
		if seg == "" || seg == "." || seg == ".." {
			return false
		}
	}
	return true
}

// readRepoFile reads a root-relative file of the checkout; symlinks must stay inside it.
func readRepoFile(root, rel string) ([]byte, error) {
	full, err := insideRoot(root, rel)
	if err != nil {
		return nil, err
	}
	info, err := os.Stat(full)
	if err != nil {
		return nil, err
	}
	if !info.Mode().IsRegular() {
		return nil, fmt.Errorf("%s is not a file", rel)
	}
	if info.Size() > maxComposeAssetBytes {
		return nil, fmt.Errorf("%s is larger than %d KB", rel, maxComposeAssetBytes>>10)
	}
	return os.ReadFile(full)
}

// insideRoot resolves rel (and any symlinks on the way) and refuses paths that end up outside root.
func insideRoot(root, rel string) (string, error) {
	realRoot, err := filepath.EvalSymlinks(root)
	if err != nil {
		return "", err
	}
	real, err := filepath.EvalSymlinks(filepath.Join(root, filepath.FromSlash(rel)))
	if err != nil {
		return "", err // fs.ErrNotExist for missing paths
	}
	if real != realRoot && !strings.HasPrefix(real, realRoot+string(filepath.Separator)) {
		return "", fmt.Errorf("%s points outside the repository", rel)
	}
	return real, nil
}

// collectComposeAssets reads the referenced paths (files, or every file of a folder) for shipping; paths the
// checkout doesn't have are returned as missing (data folders, untracked .env files).
func collectComposeAssets(root string, refs []string) ([]ComposeAsset, []string, error) {
	var assets []ComposeAsset
	var missing []string
	seen := map[string]bool{}
	total := int64(0)
	add := func(rel, full string, info os.FileInfo) error {
		if seen[rel] {
			return nil
		}
		seen[rel] = true
		if !ValidAssetPath(rel) {
			// Names the servers can't write (control characters, backslashes): reported, not shipped.
			missing = append(missing, rel)
			return nil
		}
		if len(assets) >= maxComposeAssets {
			return fmt.Errorf("the compose project mounts more than %d repository files; mount fewer or bake them into an image", maxComposeAssets)
		}
		if info.Size() > maxComposeAssetBytes {
			return fmt.Errorf("%s is larger than %d KB; bake it into an image instead of mounting it", rel, maxComposeAssetBytes>>10)
		}
		total += info.Size()
		if total > maxComposeAssetsBytes {
			return fmt.Errorf("the repository files the compose project mounts exceed %d MB; bake large files into an image", maxComposeAssetsBytes>>20)
		}
		data, err := os.ReadFile(full)
		if err != nil {
			return err
		}
		mode := 0o644
		if info.Mode()&0o111 != 0 {
			mode = 0o755
		}
		assets = append(assets, ComposeAsset{Path: rel, Content: base64.StdEncoding.EncodeToString(data), Mode: mode})
		return nil
	}
	for _, rel := range refs {
		full, err := insideRoot(root, rel)
		if errors.Is(err, fs.ErrNotExist) {
			missing = append(missing, rel)
			continue
		}
		if err != nil {
			return nil, nil, err
		}
		info, err := os.Stat(full)
		if err != nil {
			return nil, nil, err
		}
		if info.Mode().IsRegular() {
			if err := add(rel, full, info); err != nil {
				return nil, nil, err
			}
			continue
		}
		if !info.IsDir() {
			continue
		}
		err = filepath.WalkDir(full, func(p string, d fs.DirEntry, err error) error {
			if err != nil {
				return err
			}
			if d.IsDir() {
				if d.Name() == ".git" {
					return filepath.SkipDir
				}
				return nil
			}
			sub, _ := filepath.Rel(full, p)
			subRel := path.Join(rel, filepath.ToSlash(sub))
			target := p
			if d.Type()&fs.ModeSymlink != 0 {
				if target, err = insideRoot(root, subRel); err != nil {
					return err
				}
			}
			info, err := os.Stat(target)
			if err != nil || !info.Mode().IsRegular() {
				return nil
			}
			return add(subRel, target, info)
		})
		if err != nil {
			return nil, nil, err
		}
	}
	sort.Slice(assets, func(a, b int) bool { return assets[a].Path < assets[b].Path })
	return assets, missing, nil
}

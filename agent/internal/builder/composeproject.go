package builder

import (
	"errors"
	"fmt"
	"io/fs"
	"path"
	"sort"
	"strings"

	"gopkg.in/yaml.v3"
)

// A compose project as `docker compose -f a.yml -f b.yml --profile p config` would see it, loaded from a
// checkout: `include` and `extends` resolved, the files merged in order, services outside the active profiles
// dropped. Every relative path (build contexts, bind mounts, env_file, configs/secrets `file:`) is rebased to the
// repository root ("./docker/nginx.conf"), so the result no longer depends on where its files were. The control
// plane's preview (Sites ComposeProject) applies the same rules; both are tested against
// contracts/compose/merge-cases.json.

// ReadFunc reads a repository file by its root-relative path; fs.ErrNotExist when missing.
type ReadFunc func(rel string) ([]byte, error)

const maxComposeDepth = 8

// LoadComposeProject merges files (repository-relative, in -f order; empty = the default file names) and keeps
// the services of the given profiles. It returns the merged document and every compose file it read.
func LoadComposeProject(read ReadFunc, files, profiles []string) (map[string]any, []string, error) {
	if len(files) == 0 {
		for _, f := range DefaultComposeFiles {
			if _, err := read(f); err == nil {
				files = []string{f}
				break
			}
		}
		if len(files) == 0 {
			return nil, nil, fmt.Errorf("no compose file found (tried %s)", strings.Join(DefaultComposeFiles, ", "))
		}
	}
	l := &composeLoader{read: read, cache: map[string]map[string]any{}}
	// Relative paths of every -f file resolve against the first file's directory (the project directory).
	projectDir := path.Dir(cleanRel(files[0]))
	merged := map[string]any{}
	for _, f := range files {
		rel, err := repoPath(".", f)
		if err != nil {
			return nil, nil, fmt.Errorf("compose file %q: %w", f, err)
		}
		doc, err := l.file(rel, projectDir, 0)
		if err != nil {
			return nil, nil, err
		}
		merged = mergeComposeDocs(merged, doc)
	}
	applyProfiles(merged, profiles)
	return merged, l.files, nil
}

type composeLoader struct {
	read  ReadFunc
	cache map[string]map[string]any // raw parsed files
	files []string
}

func (l *composeLoader) raw(rel string) (map[string]any, error) {
	if doc, ok := l.cache[rel]; ok {
		return deepCopy(doc).(map[string]any), nil
	}
	content, err := l.read(rel)
	if errors.Is(err, fs.ErrNotExist) {
		return nil, fmt.Errorf("compose file %q not found in the repository", rel)
	}
	if err != nil {
		return nil, err
	}
	var doc map[string]any
	if err := yaml.Unmarshal(content, &doc); err != nil {
		return nil, fmt.Errorf("compose file %s: %w", rel, err)
	}
	if doc == nil {
		doc = map[string]any{}
	}
	l.cache[rel] = doc
	l.files = append(l.files, rel)
	return deepCopy(doc).(map[string]any), nil
}

// file loads one compose file with its includes and extends resolved; relative paths in it resolve against base.
func (l *composeLoader) file(rel, base string, depth int) (map[string]any, error) {
	if depth > maxComposeDepth {
		return nil, fmt.Errorf("compose file %s: include/extends nested too deeply", rel)
	}
	doc, err := l.raw(rel)
	if err != nil {
		return nil, err
	}
	out := map[string]any{}
	// include: each entry is a project of its own, merged before this file; its paths resolve against the
	// included file's directory (or its project_directory).
	if inc, ok := doc["include"]; ok {
		delete(doc, "include")
		entries, _ := inc.([]any)
		if entries == nil {
			return nil, fmt.Errorf("compose file %s: include must be a list", rel)
		}
		for _, e := range entries {
			var paths []string
			projectDir := ""
			switch v := e.(type) {
			case string:
				paths = []string{v}
			case map[string]any:
				switch p := v["path"].(type) {
				case string:
					paths = []string{p}
				case []any:
					for _, x := range p {
						if s, ok := x.(string); ok {
							paths = append(paths, s)
						}
					}
				}
				projectDir, _ = v["project_directory"].(string)
			}
			if len(paths) == 0 {
				return nil, fmt.Errorf("compose file %s: include entry without a path", rel)
			}
			// Several paths act like `-f a -f b`: they share the first one's directory, unless project_directory says.
			first, err := repoPath(path.Dir(rel), paths[0])
			if err != nil {
				return nil, fmt.Errorf("compose file %s: include %q: %w", rel, paths[0], err)
			}
			incBase := path.Dir(first)
			if projectDir != "" {
				if incBase, err = repoPath(path.Dir(rel), projectDir); err != nil {
					return nil, fmt.Errorf("compose file %s: include: %w", rel, err)
				}
			}
			for _, p := range paths {
				incRel, err := repoPath(path.Dir(rel), p)
				if err != nil {
					return nil, fmt.Errorf("compose file %s: include %q: %w", rel, p, err)
				}
				sub, err := l.file(incRel, incBase, depth+1)
				if err != nil {
					return nil, err
				}
				out = mergeComposeDocs(out, sub)
			}
		}
	}
	rebaseDoc(doc, base)
	services, _ := doc["services"].(map[string]any)
	for name := range services {
		svc, err := l.extend(rel, base, doc, name, depth, map[string]bool{})
		if err != nil {
			return nil, err
		}
		services[name] = svc
	}
	return mergeComposeDocs(out, doc), nil
}

// extend resolves `extends` of one (already rebased) service of doc, read from rel.
func (l *composeLoader) extend(rel, base string, doc map[string]any, name string, depth int, seen map[string]bool) (map[string]any, error) {
	services, _ := doc["services"].(map[string]any)
	svc, _ := deepCopy(services[name]).(map[string]any)
	if svc == nil {
		svc = map[string]any{}
	}
	ext, ok := svc["extends"]
	if !ok {
		return svc, nil
	}
	key := rel + "#" + name
	if seen[key] {
		return nil, fmt.Errorf("compose service %s: extends loops", name)
	}
	seen[key] = true
	delete(svc, "extends")
	var target, file string
	switch v := ext.(type) {
	case string:
		target = v
	case map[string]any:
		target, _ = v["service"].(string)
		file, _ = v["file"].(string)
	}
	if target == "" {
		return nil, fmt.Errorf("compose service %s: extends needs a service", name)
	}
	var parent map[string]any
	if file == "" {
		p, err := l.extend(rel, base, doc, target, depth, seen)
		if err != nil {
			return nil, err
		}
		if _, ok := services[target]; !ok {
			return nil, fmt.Errorf("compose service %s: extends unknown service %s", name, target)
		}
		parent = p
	} else {
		extRel, err := repoPath(path.Dir(rel), file)
		if err != nil {
			return nil, fmt.Errorf("compose service %s: extends file %q: %w", name, file, err)
		}
		if depth+1 > maxComposeDepth {
			return nil, fmt.Errorf("compose service %s: extends nested too deeply", name)
		}
		other, err := l.raw(extRel)
		if err != nil {
			return nil, err
		}
		delete(other, "include")
		// Paths of the extended file resolve against its own directory.
		rebaseDoc(other, path.Dir(extRel))
		osvc, _ := other["services"].(map[string]any)
		if _, ok := osvc[target]; !ok {
			return nil, fmt.Errorf("compose service %s: extends unknown service %s in %s", name, target, extRel)
		}
		p, err := l.extend(extRel, path.Dir(extRel), other, target, depth+1, seen)
		if err != nil {
			return nil, err
		}
		parent = p
	}
	parent = deepCopy(parent).(map[string]any)
	// depends_on, links and volumes_from are never inherited (Compose spec).
	for _, k := range []string{"depends_on", "links", "volumes_from"} {
		delete(parent, k)
	}
	return mergeService(parent, svc), nil
}

// ---- paths ------------------------------------------------------------------------------------------------------

func cleanRel(p string) string { return path.Clean(strings.TrimPrefix(p, "./")) }

// repoPath resolves p (relative to the repository directory dir) to a clean root-relative path that stays inside
// the repository.
func repoPath(dir, p string) (string, error) {
	if path.IsAbs(p) || strings.HasPrefix(p, "~") {
		return "", errors.New("must be a path inside the repository")
	}
	clean := path.Clean(path.Join(dir, p))
	if clean == ".." || strings.HasPrefix(clean, "../") {
		return "", errors.New("leaves the repository")
	}
	return clean, nil
}

// rebased turns a path relative to base into "./<root-relative>" (or "." for the root). Absolute paths, home
// paths and interpolated ones stay as written; paths leaving the repository become "../…" (the policy rejects them).
func rebased(base, p string) string {
	if p == "" || path.IsAbs(p) || strings.HasPrefix(p, "~") || strings.Contains(p, "$") {
		return p
	}
	clean := path.Clean(path.Join(base, p))
	if clean == "." {
		return "."
	}
	if clean == ".." || strings.HasPrefix(clean, "../") {
		return clean
	}
	return "./" + clean
}

func isBindSource(s string) bool {
	return strings.HasPrefix(s, ".") || strings.HasPrefix(s, "/") || strings.HasPrefix(s, "~") || strings.Contains(s, "/")
}

func rebaseDoc(doc map[string]any, base string) {
	services, _ := doc["services"].(map[string]any)
	for _, s := range services {
		if svc, ok := s.(map[string]any); ok {
			rebaseService(svc, base)
		}
	}
	for _, kind := range []string{"configs", "secrets"} {
		items, _ := doc[kind].(map[string]any)
		for _, it := range items {
			if m, ok := it.(map[string]any); ok {
				if f, ok := m["file"].(string); ok {
					m["file"] = rebased(base, f)
				}
			}
		}
	}
}

func rebaseService(svc map[string]any, base string) {
	switch b := svc["build"].(type) {
	case string:
		svc["build"] = rebased(base, b)
	case map[string]any:
		ctx, _ := b["context"].(string)
		if ctx == "" {
			ctx = "."
		}
		if !strings.Contains(ctx, "://") && !strings.HasPrefix(ctx, "git@") {
			b["context"] = rebased(base, ctx)
		}
	}
	if vols, ok := svc["volumes"].([]any); ok {
		for i, v := range vols {
			switch m := v.(type) {
			case string:
				parts := strings.SplitN(m, ":", 2)
				if len(parts) == 2 && isBindSource(parts[0]) {
					vols[i] = rebased(base, parts[0]) + ":" + parts[1]
				}
			case map[string]any:
				if t, _ := m["type"].(string); t == "bind" {
					if src, ok := m["source"].(string); ok {
						m["source"] = rebased(base, src)
					}
				}
			}
		}
	}
	switch e := svc["env_file"].(type) {
	case string:
		svc["env_file"] = rebased(base, e)
	case []any:
		for i, x := range e {
			switch v := x.(type) {
			case string:
				e[i] = rebased(base, v)
			case map[string]any:
				if p, ok := v["path"].(string); ok {
					v["path"] = rebased(base, p)
				}
			}
		}
	}
}

// ---- merging (Compose's merge rules for -f files) --------------------------------------------------------------

func mergeComposeDocs(base, over map[string]any) map[string]any {
	out := deepCopy(base).(map[string]any)
	for k, v := range over {
		switch k {
		case "services":
			bs, _ := out[k].(map[string]any)
			if bs == nil {
				bs = map[string]any{}
			}
			os, _ := v.(map[string]any)
			for name, s := range os {
				sm, _ := s.(map[string]any)
				if sm == nil {
					sm = map[string]any{}
				}
				if existing, ok := bs[name].(map[string]any); ok {
					bs[name] = mergeService(existing, sm)
				} else {
					bs[name] = deepCopy(sm)
				}
			}
			out[k] = bs
		case "volumes", "networks", "configs", "secrets":
			out[k] = mergeMaps(asMap(out[k]), asMap(v))
		default:
			out[k] = deepCopy(v)
		}
	}
	return out
}

// Keys whose sequences are appended (duplicates dropped); other sequences are replaced.
var appendKeys = map[string]bool{
	"ports": true, "expose": true, "dns": true, "dns_search": true, "dns_opt": true, "tmpfs": true, "cap_add": true,
	"cap_drop": true, "external_links": true, "security_opt": true, "env_file": true, "devices": true, "group_add": true,
	"links": true, "volumes_from": true, "secrets": true, "configs": true,
}

// Keys whose list form ("K=V" / names) is a mapping.
var mappingKeys = map[string]bool{"environment": true, "labels": true, "annotations": true, "sysctls": true, "extra_hosts": true, "depends_on": true, "networks": true}

func mergeService(base, over map[string]any) map[string]any {
	out := deepCopy(base).(map[string]any)
	for k, v := range over {
		cur, has := out[k]
		switch {
		case !has || v == nil:
			out[k] = deepCopy(v)
		case mappingKeys[k]:
			out[k] = mergeMaps(normalizeMapping(k, cur), normalizeMapping(k, v))
		case k == "volumes":
			out[k] = mergeVolumes(cur, v)
		case k == "build":
			out[k] = mergeMaps(buildMap(cur), buildMap(v))
		case k == "command" || k == "entrypoint" || k == "profiles":
			out[k] = deepCopy(v)
		case appendKeys[k]:
			out[k] = appendUnique(asList(cur), asList(v))
		default:
			cm, cok := cur.(map[string]any)
			vm, vok := v.(map[string]any)
			if cok && vok {
				out[k] = mergeNested(cm, vm)
			} else {
				out[k] = deepCopy(v)
			}
		}
	}
	return out
}

// mergeNested merges mappings recursively; sequences and scalars are replaced (healthcheck.test, deploy, …).
func mergeNested(base, over map[string]any) map[string]any {
	out := deepCopy(base).(map[string]any)
	for k, v := range over {
		bm, bok := out[k].(map[string]any)
		vm, vok := v.(map[string]any)
		if bok && vok {
			out[k] = mergeNested(bm, vm)
		} else {
			out[k] = deepCopy(v)
		}
	}
	return out
}

func mergeMaps(base, over map[string]any) map[string]any {
	if base == nil && over == nil {
		return nil
	}
	return mergeNested(orEmpty(base), orEmpty(over))
}

func orEmpty(m map[string]any) map[string]any {
	if m == nil {
		return map[string]any{}
	}
	return m
}

func asMap(v any) map[string]any {
	m, _ := v.(map[string]any)
	return m
}

func asList(v any) []any {
	switch l := v.(type) {
	case []any:
		return l
	case nil:
		return nil
	default:
		return []any{l}
	}
}

func buildMap(v any) map[string]any {
	if s, ok := v.(string); ok {
		return map[string]any{"context": s}
	}
	return asMap(v)
}

// normalizeMapping turns the list form of environment/labels/… ("K=V", or names for depends_on/networks) into a map.
func normalizeMapping(key string, v any) map[string]any {
	switch m := v.(type) {
	case map[string]any:
		return m
	case []any:
		out := map[string]any{}
		for _, x := range m {
			s := fmt.Sprint(x)
			switch key {
			case "depends_on":
				out[s] = map[string]any{"condition": "service_started"}
			case "networks":
				out[s] = nil
			case "extra_hosts":
				if h, ip, ok := strings.Cut(s, ":"); ok {
					out[h] = ip
				} else if h, ip, ok := strings.Cut(s, "="); ok {
					out[h] = ip
				}
			default:
				if k, val, ok := strings.Cut(s, "="); ok {
					out[k] = val
				} else {
					out[s] = nil
				}
			}
		}
		return out
	}
	return map[string]any{}
}

func volumeTarget(v any) string {
	switch m := v.(type) {
	case string:
		parts := strings.Split(m, ":")
		if len(parts) == 1 {
			return parts[0]
		}
		return parts[1]
	case map[string]any:
		t, _ := m["target"].(string)
		return t
	}
	return fmt.Sprint(v)
}

// mergeVolumes keeps one mount per container path; the later file wins.
func mergeVolumes(base, over any) []any {
	out := []any{}
	index := map[string]int{}
	for _, v := range append(append([]any{}, asList(base)...), asList(over)...) {
		t := volumeTarget(v)
		if i, ok := index[t]; ok {
			out[i] = deepCopy(v)
			continue
		}
		index[t] = len(out)
		out = append(out, deepCopy(v))
	}
	return out
}

func appendUnique(base, over []any) []any {
	out := []any{}
	seen := map[string]bool{}
	for _, v := range append(append([]any{}, base...), over...) {
		k := fmt.Sprintf("%#v", v)
		if seen[k] {
			continue
		}
		seen[k] = true
		out = append(out, deepCopy(v))
	}
	return out
}

// applyProfiles drops services whose profiles aren't active, and depends_on entries pointing at dropped services.
// `profiles` is removed from the services kept (the servers run `compose up` without --profile).
func applyProfiles(doc map[string]any, active []string) {
	services, _ := doc["services"].(map[string]any)
	on := map[string]bool{}
	for _, p := range active {
		on[p] = true
	}
	for name, s := range services {
		svc, _ := s.(map[string]any)
		profiles := asList(svc["profiles"])
		if len(profiles) == 0 {
			continue
		}
		keep := false
		for _, p := range profiles {
			keep = keep || on[fmt.Sprint(p)]
		}
		if keep {
			delete(svc, "profiles")
		} else {
			delete(services, name)
		}
	}
	for _, s := range services {
		svc, _ := s.(map[string]any)
		deps, ok := svc["depends_on"]
		if !ok {
			continue
		}
		m := normalizeMapping("depends_on", deps)
		dropped := false
		for dep := range m {
			if _, ok := services[dep]; !ok {
				delete(m, dep)
				dropped = true
			}
		}
		if !dropped {
			continue
		}
		if len(m) == 0 {
			delete(svc, "depends_on")
		} else {
			svc["depends_on"] = m
		}
	}
}

func deepCopy(v any) any {
	switch t := v.(type) {
	case map[string]any:
		out := make(map[string]any, len(t))
		for k, x := range t {
			out[k] = deepCopy(x)
		}
		return out
	case []any:
		out := make([]any, len(t))
		for i, x := range t {
			out[i] = deepCopy(x)
		}
		return out
	default:
		return t
	}
}

// ---- files the project needs on the server ----------------------------------------------------------------------

// ComposeReferences lists the repository paths a merged project reads at runtime: bind-mount sources inside the
// repository, env_file entries and configs/secrets `file:` (root-relative, without "./", sorted, unique).
func ComposeReferences(doc map[string]any) []string {
	set := map[string]bool{}
	add := func(p string) {
		if strings.HasPrefix(p, "./") || p == "." {
			if c := cleanRel(p); c != "." && c != ".." && !strings.HasPrefix(c, "../") {
				set[c] = true
			}
		}
	}
	services, _ := doc["services"].(map[string]any)
	for _, s := range services {
		svc, _ := s.(map[string]any)
		for _, v := range asList(svc["volumes"]) {
			switch m := v.(type) {
			case string:
				if src, _, ok := strings.Cut(m, ":"); ok {
					add(src)
				}
			case map[string]any:
				if t, _ := m["type"].(string); t == "bind" {
					src, _ := m["source"].(string)
					add(src)
				}
			}
		}
		for _, e := range asList(svc["env_file"]) {
			switch v := e.(type) {
			case string:
				add(v)
			case map[string]any:
				p, _ := v["path"].(string)
				add(p)
			}
		}
	}
	for _, kind := range []string{"configs", "secrets"} {
		for _, it := range asMap(doc[kind]) {
			if f, ok := asMap(it)["file"].(string); ok {
				add(f)
			}
		}
	}
	out := make([]string, 0, len(set))
	for p := range set {
		out = append(out, p)
	}
	sort.Strings(out)
	return out
}

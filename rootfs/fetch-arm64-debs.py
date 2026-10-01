#!/usr/bin/env python3
# fetch-arm64-debs.py — unduh closure .deb ARM64 langsung dari ports.ubuntu.com
# Tanpa apt host-resolver (anti konflik bash:arm64 vs bash amd64 di runner).
import gzip, re, sys, urllib.request, os

PORTS = "http://ports.ubuntu.com/ubuntu-ports"
DIST = "noble"
POCKETS = ["noble", "noble-updates"]
COMPS = ["main", "universe"]

def fetch_packages():
    idx, provides = {}, {}
    for pocket in POCKETS:
        for comp in COMPS:
            url = f"{PORTS}/dists/{pocket}/{comp}/binary-arm64/Packages.gz"
            raw = None
            for attempt in range(1, 4):
                try:
                    with urllib.request.urlopen(url, timeout=60) as r:
                        raw = gzip.decompress(r.read()).decode("utf-8", "replace")
                    break
                except Exception as e:
                    if attempt == 3:
                        print(f"   ! skip {pocket}/{comp}: {e}", flush=True)
                    continue
            if raw is None:
                continue
            cur = {}
            def commit(cur):
                if "Package" not in cur:
                    return
                name = cur["Package"]
                if name not in idx:
                    idx[name] = cur
                for pv in cur.get("Provides", "").split(","):
                    pv = pv.strip().split("(")[0].strip().split("=")[0].strip()
                    if pv and pv not in idx and pv not in provides:
                        provides[pv] = name
            for line in raw.splitlines() + [""]:
                if not line.strip():
                    commit(cur)
                    cur = {}
                    continue
                if line[0] in (" ", "\t"):
                    continue
                if ":" not in line:
                    continue
                k, v = line.split(":", 1)
                cur[k.strip()] = v.strip()
    return idx, provides

def dep_names(field):
    out = []
    for clause in (field or "").split(","):
        alts = [a.strip() for a in clause.split("|")]
        picked = None
        for a in alts:
            m = re.match(r"^([A-Za-z0-9_.+\-]+)(?::[A-Za-z0-9\-]+)?(?:\s*\(.*\))?", a)
            if m:
                picked = m.group(1)
                break
        if picked:
            out.append(picked)
    return out

SKIP = {"debconf", "debconf-2.0", "ucf", "init-system-helpers",
        "systemd", "systemd-sysv", "sysvinit-utils", "lsof"}

def closure(idx, provides, wanted):
    seen, stack = set(), list(wanted)
    while stack:
        p = stack.pop()
        if p in seen or p in SKIP:
            continue
        if p not in idx:
            if p in provides:
                p = provides[p]
                if p in seen:
                    continue
            else:
                print(f"   ! dep tak ada di ports (skip): {p}", flush=True)
                seen.add("__missing__" + p)
                continue
        seen.add(p)
        meta = idx[p]
        for f in (meta.get("Depends", ""), meta.get("Pre-Depends", "")):
            for d in dep_names(f):
                if d not in seen and d not in SKIP:
                    stack.append(d)
    return {x for x in seen if not x.startswith("__missing__")}

def _download(url, out, tries=3):
    # urlretrieve tanpa timeout = gantung selamanya kalau koneksi stall
    # (CI rootfs pernah hang >10 mnt). Timeout + retry + buang file parsial.
    import time
    for attempt in range(1, tries + 1):
        try:
            with urllib.request.urlopen(url, timeout=60) as r, \
                    open(out, "wb") as f:
                while True:
                    chunk = r.read(65536)
                    if not chunk:
                        break
                    f.write(chunk)
            return None
        except Exception as e:
            try:
                if os.path.exists(out):
                    os.remove(out)
            except OSError:
                pass
            if attempt == tries:
                return e
            print(f"   ! retry {attempt}/{tries}: {url.split('/')[-1]}", flush=True)
            time.sleep(2 * attempt)
    return "unreachable"

def main():
    dest = sys.argv[1]
    wanted = sys.argv[2:]
    os.makedirs(dest, exist_ok=True)
    print(f">> fetch index ports ({DIST} arm64)...", flush=True)
    idx, provides = fetch_packages()
    print(f"   index: {len(idx)} paket", flush=True)
    miss = [w for w in wanted if w not in idx]
    if miss:
        print(f"   ! paket induk tak ada: {miss}", flush=True)
    pkgs = closure(idx, provides, [w for w in wanted if w in idx])
    print(f"   closure: {len(pkgs)} paket", flush=True)
    ok, fail = 0, []
    for p in sorted(pkgs):
        fn = idx[p].get("Filename")
        if not fn:
            fail.append(p)
            continue
        url = f"{PORTS}/{fn}"
        out = os.path.join(dest, os.path.basename(fn))
        if os.path.exists(out):
            ok += 1
            continue
        err = _download(url, out)
        if err is None:
            ok += 1
        else:
            fail.append(f"{p} ({err})")
    print(f"   unduh OK: {ok}, gagal: {len(fail)}", flush=True)
    for f in fail[:20]:
        print(f"   - {f}", flush=True)
    if fail:
        sys.exit(3)

if __name__ == "__main__":
    main()

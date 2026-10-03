#!/usr/bin/env python3
"""Validate SKILL.md sebelum di-ingest agent. Exit 0=valid, 1=cacat."""
import os
import re
import sys

REQ = ["name", "description"]
OPT = ["version", "author", "license", "metadata"]


def lint(path):
    errs, warns = [], []
    if not os.path.isfile(path):
        return False, ["file tidak ada: %s" % path], []
    try:
        with open(path, encoding="utf-8") as f:
            txt = f.read()
    except OSError as e:
        return False, [str(e)[:150]], []
    if len(txt) > 100 * 1024:
        errs.append("file >100KB (%dB), pecah jadi beberapa skill" % len(txt))
    m = re.match(r"^---\n(.*?)\n---\n", txt, re.S)
    if not m:
        return False, ["frontmatter --- tidak ketemu di baris 1"], []
    fm = m.group(1)
    body = txt[m.end():]
    for k in REQ:
        if not re.search(r"^%s\s*:" % re.escape(k), fm, re.M):
            errs.append("frontmatter wajib ada '%s:'" % k)
    name = re.search(r"^name\s*:\s*(.+)$", fm, re.M)
    if name and not re.fullmatch(r"[a-z0-9][a-z0-9-]{2,60}", name.group(1).strip()):
        errs.append("name harus kebab-case [a-z0-9-], 3-60 char")
    desc = re.search(r"^description\s*:\s*(.+)$", fm, re.M)
    if desc and len(desc.group(1).strip()) < 10:
        errs.append("description terlalu pendek (<10 char)")
    if not re.search(r"^#\s+\S+", body, re.M):
        errs.append("body butuh 1 judul '# ...'")
    if "```" in body and body.count("```") % 2 != 0:
        errs.append("code fence ``` tidak seimbang")
    for k in OPT:
        if not re.search(r"^%s\s*:" % k, fm, re.M):
            warns.append("opsional '%s' disarankan" % k)
    if len(body.strip()) < 200:
        warns.append("body tipis (<200 char), tambah contoh pemakaian")
    return not errs, errs, warns


def main(argv):
    targets = [a for a in argv[1:] if not a.startswith("-")] or [os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "skills")]
    files = []
    for t in targets:
        if os.path.isfile(t):
            files.append(t)
        elif os.path.isdir(t):
            for dp, _, fns in os.walk(t):
                if "SKILL.md" in fns:
                    files.append(os.path.join(dp, "SKILL.md"))
    bad, total = 0, 0
    for f in sorted(files):
        ok, errs, warns = lint(f)
        total += 1
        tag = "OK " if ok else "FAIL"
        print("%s %s" % (tag, os.path.relpath(f, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))))
        for e in errs:
            print("  - E: %s" % e)
        for w in warns:
            if "--strict" in argv:
                print("  - W: %s" % w)
        if not ok:
            bad += 1
    print("---\n%d/%d valid" % (total - bad, total))
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))

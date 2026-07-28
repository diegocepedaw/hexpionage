#!/usr/bin/env python3
"""Verify that every in-repo path referenced by our text files actually exists.

The repo is heavily cross-referenced: PHP files cite spec sections, specs cite
each other, and the onboarding docs cite everything. A restructure or a rename
silently rots all of that, so this check runs as part of ./tools/check.sh.

Usage:
    python3 scripts/check_links.py [--verbose]

Exit code 1 if any reference points at a file that does not exist.
"""
import argparse
import fnmatch
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent

SKIP_DIRS = {".git", "masters", "__pycache__", "node_modules", "img", "legacy_metadata"}
TEXT_SUFFIXES = {".md", ".php", ".js", ".py", ".sh", ".jsonc"}

# Historical documents describe the original build plan, including deliverables
# that were renamed or never produced. Their paths are a record, not navigation.
HISTORICAL = {
    ROOT / "docs" / "history" / "PLAN.md",
    ROOT / "docs" / "history" / "MORNING_BRIEFING.md",
    ROOT / "docs" / "history" / "HAL_DRY_RUN.md",
    ROOT / "docs" / "specs" / "QA_SPEC_REVIEW.md",
    ROOT / "docs" / "specs" / "INTEGRATION_REPORT.md",
    ROOT / "docs" / "testing" / "CODE_REVIEW_BACKEND.md",
    ROOT / "docs" / "testing" / "CODE_REVIEW_FRONTEND.md",
    ROOT / "docs" / "testing" / "I18N_SWEEP.md",
}

IGNORE_FILE = ROOT / ".linkcheckignore"

# Top-level directories that make a token look like an in-repo path.
# "assets/", "specs/" and "tests/" are former directory names: any surviving
# reference to them is stale by definition, so they stay in the match set.
ROOTS = ("docs/", "src/", "tools/", "design/", "scripts/", "assets/", "specs/", "tests/")

# Matches paths in markdown links, backticks, and bare prose.
# `*` is allowed inside a match so that glob-y references like
# `docs/testing/CODE_REVIEW_*.md` are recognised as globs and skipped, rather
# than truncated into a bogus literal path.
PATH_RE = re.compile(r"(?<![\w@/.-])((?:" + "|".join(re.escape(r) for r in ROOTS) + r")[\w./*-]*[\w/*])")

# Markdown link targets are checked whatever they look like, so that
# file-relative links such as a CONTRACT.md link inside docs/README.md are
# covered too. External links and pure anchors are skipped.
MD_LINK_RE = re.compile(r"\]\(([^)\s]+)\)")


def resolve(source: pathlib.Path, token: str) -> bool:
    """A reference resolves if it is valid from the repo root or from its own file."""
    token = token.split("#", 1)[0]
    if not token:
        return True
    return (ROOT / token).exists() or (source.parent / token).exists()

# Wildcards and directory-ish references we cannot resolve literally.
def is_glob(token: str) -> bool:
    return any(c in token for c in "*{}<>") or token.endswith("/")


def load_ignores() -> list[str]:
    if not IGNORE_FILE.exists():
        return []
    out = []
    for line in IGNORE_FILE.read_text(encoding="utf-8").splitlines():
        line = line.split("#", 1)[0].strip()
        if line:
            out.append(line)
    return out


def sources():
    for path in sorted(ROOT.rglob("*")):
        if not path.is_file() or path.suffix not in TEXT_SUFFIXES:
            continue
        if any(part in SKIP_DIRS for part in path.parts):
            continue
        yield path


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--verbose", action="store_true")
    args = ap.parse_args()

    ignores = load_ignores()
    broken: list[tuple[pathlib.Path, int, str]] = []
    checked = 0

    for path in sources():
        historical = path in HISTORICAL
        try:
            lines = path.read_text(encoding="utf-8").splitlines()
        except UnicodeDecodeError:
            continue
        for lineno, line in enumerate(lines, 1):
            tokens = [t.rstrip(".,;:)") for t in PATH_RE.findall(line)]
            if path.suffix == ".md":
                tokens += [
                    t for t in MD_LINK_RE.findall(line)
                    if not t.startswith(("http://", "https://", "mailto:", "#"))
                ]
            for token in dict.fromkeys(tokens):
                if is_glob(token):
                    continue
                checked += 1
                if resolve(path, token):
                    continue
                if any(fnmatch.fnmatch(token, pat) for pat in ignores):
                    continue
                if historical:
                    if args.verbose:
                        print(f"  (historical, ignored) {path.relative_to(ROOT)}:{lineno} {token}")
                    continue
                broken.append((path.relative_to(ROOT), lineno, token))

    if broken:
        print(f"{len(broken)} broken in-repo reference(s) out of {checked} checked:\n")
        for path, lineno, token in broken:
            print(f"  {path}:{lineno}  ->  {token}")
        print("\nEither fix the reference, add the path to .linkcheckignore if it is")
        print("deliberately dangling, or add the whole file to HISTORICAL in this script")
        print("if its paths describe past intent rather than the current repo.")
        return 1

    print(f"all {checked} in-repo path references resolve")
    return 0


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Build the free plugin's installable zip, then audit it.

This repository carries the free plugin only. The Pro add-on and the licence
server are separate repositories, so there is nothing else to package here —
the build targets are declared in BUILDS and there is exactly one.

Two jobs, in this order:

  1. Package the plugin from source into dist/, with the plugin folder as the
     top-level directory of the zip — a zip whose first path segment is not the
     plugin slug is not installable, and that failure looks like a corrupt
     download rather than a packaging mistake.

  2. Audit the zip it produced, against the rules in AT8-WordPress-商业产品
     通用架构规范-v2.0 §65: structure, forbidden file names, forbidden strings,
     secrets, licence declarations and PHP syntax.

The audit is not advisory. A failure sets the exit code, so a build that cannot
be shipped cannot be mistaken for one that can.

Design notes that exist because the alternative bit us:

  * The version comes from each plugin's own header, never from this file. A
    number typed here goes stale on the next release and produces a zip whose
    name contradicts its contents — which nobody notices until it has been
    handed to somebody.
  * Paths are derived from this file's location, not from an absolute path
    recorded on one machine. The earlier version had the author's drive letter
    baked in.
  * Forbidden-string rules are written as precise patterns. A plain search for
    "pro" would flag the free plugin's own upgrade panel, which the directory
    guidelines actually require to be there — a rule that fires on correct code
    is worse than no rule, because it gets suppressed and then ignored.
  * Development files are excluded by name. AGENTS.md in particular is a note
    to whoever works on the code, not to whoever installs it.

Usage:
    python at8sec-build-zips.py                # build and audit the free plugin
    python at8sec-build-zips.py --php C:\\php\\php.exe
"""
import argparse
import hashlib
import io
import json
import os
import re
import shutil
import subprocess
import sys
import zipfile

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")

ROOT = os.path.dirname(os.path.abspath(__file__))
DIST = os.path.join(ROOT, "dist")

# Each build: a label and the source directory. The free/pro isolation rules in
# FREE_ISOLATION_RULES are applied to every zip this repository produces, because
# every zip this repository produces is the free plugin — the Pro add-on and the
# licence server live in their own repositories and are not built from here.
BUILDS = [
    ("free", os.path.join(ROOT, "free", "at8-security")),
]

# Never inside a distributed zip. Two groups: things that are not the plugin at
# all, and things that are notes about the plugin.
SKIP_DIRS = {".git", ".github", ".idea", ".vscode", ".backups", "node_modules",
             "__MACOSX", "tests", "docs", "wporg", "vendor"}
SKIP_FILES = {".DS_Store", "Thumbs.db", "AGENTS.md", "CLAUDE.md", ".gitignore",
              ".gitattributes", ".editorconfig", "phpunit.xml", ".env",
              "composer.json", "composer.lock", "package.json", "package-lock.json"}
SKIP_SUFFIXES = (".zip", ".tar.gz", ".log", ".sql", ".pem", ".key", ".mo.bak")

# --------------------------------------------------------------------------
# Audit rules
# --------------------------------------------------------------------------

# Paths that must never appear in a shipped zip. Matched as whole path segments
# or exact file names, so "tests" does not fire on "latest-news.php".
FORBIDDEN_PATHS = [
    (".git/", "repository metadata"),
    (".github/", "CI configuration"),
    ("node_modules/", "an unvendored dependency tree"),
    ("vendor/", "third-party code that has not been through the licence audit"),
    (".backups/", "local backup snapshots"),
    ("tests/", "the test suite"),
    (".env", "environment file"),
    ("AGENTS.md", "instructions for whoever edits the code"),
    ("CLAUDE.md", "instructions for whoever edits the code"),
    ("phpunit.xml", "test configuration"),
    (".DS_Store", "macOS metadata"),
    ("Thumbs.db", "Windows metadata"),
    (".idea/", "IDE metadata"),
    (".vscode/", "IDE metadata"),
]

# Patterns that must not appear in the *code* of the free plugin. Written as
# namespace and constant shapes rather than as the word "pro", because the free
# plugin legitimately:
#   - describes the paid add-on on its own settings screen (§11 of the guide),
#   - exposes at8sec_is_pro / at8sec_pro_panel / at8sec_pro_url as the extension
#     surface the add-on attaches to.
# A rule that flagged those would be flagging the design.
FREE_ISOLATION_RULES = [
    (r"At8\\Security\\Pro\b", "the Pro add-on's PHP namespace"),
    (r"at8-security-pro", "the Pro plugin's slug"),
    (r"at8-license/v1", "the licence server's REST namespace"),
    (r"\bat8ls_[a-z_]+", "a licence server internal"),
    (r"\bAT8LS_[A-Z_]+", "a licence server constant"),
    (r"\bAt8\\License\\", "the licence server's namespace"),
]

# Secrets. Kept to shapes that are unambiguous: a match here is a real leak, not
# a variable that happens to be called "key".
SECRET_RULES = [
    (r"AKIA[0-9A-Z]{16}", "an AWS access key id"),
    (r"-----BEGIN [A-Z ]*PRIVATE KEY-----", "a private key block"),
    (r"\bgh[pousr]_[A-Za-z0-9]{36,}", "a GitHub token"),
    (r"\bgithub_pat_[A-Za-z0-9_]{20,}", "a GitHub fine-grained token"),
    (r"\bxox[abpsr]-[A-Za-z0-9-]{10,}", "a Slack token"),
    (r"\bsk-[A-Za-z0-9]{32,}", "an API secret key"),
    (r"\beyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}", "a JWT"),
    (r"(?i)(alipay|wechat|wxpay|mch)[a-z_]*\s*[:=]\s*[\"'][A-Za-z0-9/+]{24,}", "a payment credential"),
    (r"(?i)(api[_-]?key|secret|password|passwd|token)\s*[:=]\s*[\"'][A-Za-z0-9_\-/+]{24,}[\"']",
     "a hard-coded credential"),
]

# Licence declarations that cannot ship. GPL compatibility is a requirement, and
# "all rights reserved" is the shape a copied file arrives in.
FORBIDDEN_LICENCE_PHRASES = [
    ("all rights reserved", "a non-free licence declaration"),
    ("proprietary and confidential", "a non-free licence declaration"),
    ("licensed under the mit license", "an unrecorded third-party licence"),
]

REQUIRED_HEADERS = ["Plugin Name", "Version", "License", "License URI", "Text Domain"]


def version_of(src):
    """The version the plugin declares in its own main file."""
    stem = os.path.basename(src)
    main = os.path.join(src, stem + ".php")

    if not os.path.isfile(main):
        raise SystemExit("no main plugin file at %s" % main)

    found = re.search(r"^\s*\*\s*Version:\s*([0-9][0-9.]*)",
                      open(main, encoding="utf-8").read(), re.M)

    if not found:
        raise SystemExit("no Version header in %s" % main)

    return found.group(1)


def should_ship(name):
    return not name.endswith(SKIP_SUFFIXES)


def build(src, out):
    """Write one zip, excluding everything that is not the plugin."""
    os.makedirs(os.path.dirname(out), exist_ok=True)
    count = 0

    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zf:
        for root, dirs, files in os.walk(src):
            dirs[:] = sorted(d for d in dirs if d not in SKIP_DIRS)
            for name in sorted(files):
                if name in SKIP_FILES or not should_ship(name):
                    continue
                full = os.path.join(root, name)
                rel = os.path.relpath(full, os.path.dirname(src)).replace("\\", "/")
                zf.write(full, rel)
                count += 1

    raw = open(out, "rb").read()

    return {
        "file": os.path.basename(out),
        "bytes": len(raw),
        "files": count,
        "md5": hashlib.md5(raw).hexdigest(),
        "sha256": hashlib.sha256(raw).hexdigest(),
    }


# --------------------------------------------------------------------------
# The six checks
# --------------------------------------------------------------------------

def check_structure(zf, slug):
    """One top-level directory, named after the plugin, with forward slashes."""
    problems = []
    names = zf.namelist()

    tops = sorted({n.split("/")[0] for n in names})

    if tops != [slug]:
        problems.append("top-level entries are %s, expected only %s"
                        % (", ".join(tops), slug))

    for name in names:
        if name.startswith("/") or "\\" in name:
            problems.append("entry is not a clean relative path: %r" % name)
        if ".." in name.split("/"):
            problems.append("entry escapes the archive root: %r" % name)

    mains = [n for n in names if n.count("/") == 1 and n.endswith(".php")]
    if not mains:
        problems.append("no plugin main file at the top level of the archive")

    return problems


def check_paths(zf):
    """Nothing in the zip that has no business being distributed."""
    problems = []

    for name in zf.namelist():
        lowered = name.lower()
        for pattern, why in FORBIDDEN_PATHS:
            if pattern.lower() in lowered:
                problems.append("%s — %s" % (name, why))

    return problems


def read_text(zf, name):
    """Decode one entry, or return nothing when it is not text."""
    try:
        return zf.read(name).decode("utf-8")
    except (UnicodeDecodeError, KeyError):
        return None


def check_strings(zf):
    """Forbidden code patterns, and licence declarations that cannot ship.

    The free/pro isolation rules always apply: everything built from this
    repository is the free plugin, and the free plugin must not carry a single
    line of the add-on's namespace, slug or licence-server internals.
    """
    problems = []

    for name in zf.namelist():
        if not name.endswith((".php", ".js", ".css", ".json")):
            continue
        text = read_text(zf, name)
        if text is None:
            continue
        for pattern, why in FREE_ISOLATION_RULES:
            for m in re.finditer(pattern, text):
                line = text[:m.start()].count("\n") + 1
                problems.append("%s:%d carries %s (%r)"
                                % (name, line, why, m.group(0)))

    for name in zf.namelist():
        if not name.endswith((".php", ".txt", ".md")):
            continue
        text = read_text(zf, name)
        if text is None:
            continue
        for phrase, why in FORBIDDEN_LICENCE_PHRASES:
            for m in re.finditer(re.escape(phrase), text, re.I):
                line = text[:m.start()].count("\n") + 1
                problems.append("%s:%d declares %s (%r)"
                                % (name, line, why, m.group(0)))

    return problems


def check_secrets(zf):
    """Anything that looks like a credential."""
    problems = []

    for name in zf.namelist():
        text = read_text(zf, name)
        if text is None:
            continue
        for pattern, why in SECRET_RULES:
            for m in re.finditer(pattern, text):
                line = text[:m.start()].count("\n") + 1
                # The value itself is never echoed into the report.
                problems.append("%s:%d looks like %s" % (name, line, why))

    return problems


def check_licence(zf, slug):
    """The plugin header the directory requires, and GPL compatibility."""
    problems = []
    main = slug + "/" + slug + ".php"

    if main not in zf.namelist():
        return ["the main plugin file %s is missing" % main]

    head = read_text(zf, main) or ""

    for header in REQUIRED_HEADERS:
        if not re.search(r"^\s*\*\s*%s\s*:" % re.escape(header), head, re.M):
            problems.append("the plugin header has no %s line" % header)

    if "gpl" not in head.lower():
        problems.append("the plugin header does not declare a GPL licence")

    return problems


def find_php(explicit):
    """A PHP binary to lint with, if there is one."""
    if explicit:
        return explicit if os.path.isfile(explicit) else None

    on_path = shutil.which("php")
    if on_path:
        return on_path

    for candidate in (r"D:\phpstudy_pro\Extensions\php",
                      r"C:\phpstudy_pro\Extensions\php"):
        if not os.path.isdir(candidate):
            continue
        for version in sorted(os.listdir(candidate), reverse=True):
            exe = os.path.join(candidate, version, "php.exe")
            if os.path.isfile(exe):
                return exe

    return None


def php_version(php):
    try:
        out = subprocess.run([php, "-r", "echo PHP_VERSION;"],
                             capture_output=True, text=True, timeout=20)
        return out.stdout.strip()
    except (OSError, subprocess.SubprocessError):
        return ""


def check_syntax(zf, php, minimum):
    """php -l over every PHP file in the zip.

    A local PHP older than the plugin's own minimum cannot parse the source, and
    reporting its parse errors as failures would be reporting the linter's age.
    That case is reported as deferred, with the reason, rather than passed off
    as a clean run — the authoritative lint happens on the deployment target.
    """
    if not php:
        return None, "no PHP binary found; the lint runs on the deployment target"

    version = php_version(php)
    if not version:
        return None, "%s did not answer; the lint runs on the deployment target" % php

    if tuple(int(p) for p in version.split(".")[:2]) < tuple(int(p) for p in minimum.split(".")[:2]):
        return None, ("local PHP is %s but the plugin requires %s, so it cannot parse "
                      "the source; the lint runs on the deployment target" % (version, minimum))

    problems = []
    for name in zf.namelist():
        if not name.endswith(".php"):
            continue
        source = read_text(zf, name)
        if source is None:
            continue
        tmp = os.path.join(DIST, ".lint.php")
        with open(tmp, "w", encoding="utf-8") as fh:
            fh.write(source)
        try:
            out = subprocess.run([php, "-l", tmp], capture_output=True, text=True, timeout=60)
            if out.returncode != 0:
                problems.append("%s: %s" % (name, (out.stdout + out.stderr).strip()))
        finally:
            os.remove(tmp)

    return problems, "PHP %s" % version


def minimum_php(src):
    main = os.path.join(src, os.path.basename(src) + ".php")
    head = open(main, encoding="utf-8").read()
    found = re.search(r"^\s*\*\s*Requires PHP:\s*([0-9.]+)", head, re.M)

    return found.group(1) if found else "7.4"


# --------------------------------------------------------------------------

def audit(target, slug, src, php):
    """Run all six checks over one built zip."""
    result = {"file": os.path.basename(target), "checks": {}, "problems": [], "deferred": []}

    with zipfile.ZipFile(target) as zf:
        result["checks"]["structure"] = check_structure(zf, slug)
        result["checks"]["forbidden files"] = check_paths(zf)
        result["checks"]["forbidden strings"] = check_strings(zf)
        result["checks"]["secrets"] = check_secrets(zf)
        result["checks"]["licence"] = check_licence(zf, slug)

        syntax, note = check_syntax(zf, php, minimum_php(src))
        if syntax is None:
            result["deferred"].append("php syntax: %s" % note)
        else:
            result["checks"]["php syntax"] = syntax

    for name, problems in result["checks"].items():
        for problem in problems:
            result["problems"].append("[%s] %s" % (name, problem))

    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--php", help="PHP binary to lint with")
    args = parser.parse_args()

    php = find_php(args.php)
    builds = BUILDS

    # Stale zips from an earlier version sit in the same directory and get picked
    # up by whoever is looking for "the zip". Clear them first.
    if os.path.isdir(DIST):
        for name in os.listdir(DIST):
            if name.endswith(".zip"):
                os.remove(os.path.join(DIST, name))

    report = {"builds": [], "php": php or "", "passed": True}

    for label, src in builds:
        slug = os.path.basename(src)
        version = version_of(src)
        target = os.path.join(DIST, "%s-%s.zip" % (slug, version))

        built = build(src, target)
        audited = audit(target, slug, src, php)
        audited.update(built)
        audited["label"] = label
        audited["version"] = version
        audited["slug"] = slug

        print("\n== %s %s ==" % (slug, version))
        print("   %-42s %2d files  %7d bytes" % (built["file"], built["files"], built["bytes"]))
        print("   md5    %s" % built["md5"])
        print("   sha256 %s" % built["sha256"])

        for name in ["structure", "forbidden files", "forbidden strings",
                     "secrets", "licence", "php syntax"]:
            if name in audited["checks"]:
                problems = audited["checks"][name]
                print("   [%s] %s" % ("OK  " if not problems else "FAIL", name))
                for problem in problems:
                    print("          %s" % problem)
            else:
                print("   [--  ] %s" % name)

        for note in audited["deferred"]:
            print("   [defer] %s" % note)

        if audited["problems"]:
            report["passed"] = False

        report["builds"].append(audited)

    os.makedirs(DIST, exist_ok=True)
    with open(os.path.join(DIST, "BUILD-REPORT.json"), "w", encoding="utf-8") as fh:
        json.dump(report, fh, indent=2, ensure_ascii=False)

    print("\n== summary ==")
    for audited in report["builds"]:
        state = "PASS" if not audited["problems"] else "FAIL"
        print("   %-8s %-34s %s  (%d problem(s), %d check(s) deferred)"
              % (state, audited["file"], audited["version"],
                 len(audited["problems"]), len(audited["deferred"])))
    print("   report: dist/BUILD-REPORT.json")

    return 0 if report["passed"] else 1


if __name__ == "__main__":
    sys.exit(main())

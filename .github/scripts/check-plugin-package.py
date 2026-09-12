#!/usr/bin/env python3
"""Verify runner-built plugin contents against the tracked release allowlist."""

from __future__ import annotations

import argparse
import hashlib
import stat
import subprocess
from pathlib import Path
from zipfile import ZipFile


SLUG = "enginescript-site-exporter"
RELEASE_FILES = {
    f"{SLUG}.php", "readme.txt", "README.md", "CHANGELOG.md", "LICENSE"
}
RELEASE_DIRS = {"includes", "css", "js", "languages"}


def expected_contents(root: Path) -> dict[str, bytes]:
    """Read only tracked regular production/public files, never vendor output."""
    tracked = subprocess.run(
        ["git", "ls-files", "-z"], cwd=root, check=True, capture_output=True
    ).stdout.decode("utf-8").split("\0")
    names = {
        name for name in tracked
        if name in RELEASE_FILES or Path(name).parts[:1] in
        {(directory,) for directory in RELEASE_DIRS}
    }
    required = RELEASE_FILES | {
        "css/admin.css", "js/admin.js", f"languages/{SLUG}.pot"
    }
    if not required <= names or not any(name.startswith("includes/") for name in names):
        raise ValueError("Tracked release sources are incomplete.")
    contents = {}
    for name in sorted(names):
        path = root / name
        if any(part.is_symlink() for part in (path, *path.parents)) or not path.is_file():
            raise ValueError(f"Release source is not a regular file: {name}")
        contents[name] = path.read_bytes()
    return contents


def validate_package(root: Path, build: Path, archive: Path | None = None) -> int:
    """Reject missing, extra, linked, or altered files in the directory and ZIP."""
    expected = expected_contents(root)
    if build.name != SLUG or build.is_symlink() or not build.is_dir():
        raise ValueError("Unexpected plugin build root.")
    actual = {}
    allowed_dirs = {"."}
    for name in expected:
        allowed_dirs.update(parent.as_posix() for parent in Path(name).parents)
    for path in build.rglob("*"):
        name = path.relative_to(build).as_posix()
        if path.is_symlink():
            raise ValueError(f"Linked package member: {name}")
        if path.is_dir() and name in allowed_dirs:
            continue
        if not path.is_file() or name not in expected:
            raise ValueError(f"Unexpected package member: {name}")
        actual[name] = path.read_bytes()
    if actual != expected:
        raise ValueError("Package file set or bytes differ from tracked sources.")
    if archive is not None:
        with ZipFile(archive) as zipped:
            members = zipped.infolist()
            names = [member.filename for member in members]
            if len(names) != len(set(names)):
                raise ValueError("Duplicate ZIP members.")
            zip_files = {}
            for member in members:
                mode = member.external_attr >> 16
                if stat.S_ISLNK(mode):
                    raise ValueError("Linked ZIP member.")
                if member.is_dir():
                    if member.filename not in {
                        f"{SLUG}/" if directory == "." else f"{SLUG}/{directory}/"
                        for directory in allowed_dirs
                    }:
                        raise ValueError("Unexpected ZIP directory.")
                    continue
                prefix = f"{SLUG}/"
                if not member.filename.startswith(prefix):
                    raise ValueError("ZIP member outside plugin root.")
                name = member.filename[len(prefix):]
                if name not in expected or member.file_size != len(expected[name]):
                    raise ValueError("Unexpected ZIP member or size.")
                zip_files[name] = zipped.read(member)
            if zip_files != expected:
                raise ValueError("ZIP file set or bytes differ from tracked sources.")
        print(f"ZIP SHA-256: {hashlib.sha256(archive.read_bytes()).hexdigest()}")
    for name, content in sorted(expected.items()):
        print(f"{hashlib.sha256(content).hexdigest()}  {name}")
    return len(expected)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("build", type=Path)
    parser.add_argument("--zip", dest="archive", type=Path)
    args = parser.parse_args()
    count = validate_package(Path.cwd(), args.build, args.archive)
    print(f"Verified {count} packaged files against tracked sources.")

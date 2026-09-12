#!/usr/bin/env python3
"""Check WordPress "Tested up to" metadata against the latest release."""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
from pathlib import Path


WORDPRESS_LATEST_VERSION = os.environ.get("WORDPRESS_LATEST_VERSION")
WORDPRESS_VERSION_CHECK_FILE = Path("wordpress-version-check.json")
METADATA_PATHS = {Path("enginescript-site-exporter.php"), Path("readme.txt")}
DEFAULT_EXCLUDED_DIRS = {
    ".git",
    "build",
    "coverage",
    "dist",
    "node_modules",
    "plugin-check-build",
    "__pycache__",
    "vendor",
}
TESTED_UP_TO_PATTERN = re.compile(
    r"^\s*(?:\*\s*)?tested\s+up\s+to\s*:\s*([0-9]+(?:\.[0-9]+){1,2})\s*$",
    re.IGNORECASE,
)
TESTED_UP_TO_LABEL_PATTERN = re.compile(r"^\s*(?:\*\s*)?tested\s+up\s+to\s*:", re.IGNORECASE)
VERSION_PATTERN = re.compile(r"^[0-9]+(?:\.[0-9]+){1,2}$")


def main() -> int:
    args = parse_args()
    latest_version = get_latest_wordpress_major_minor()
    excluded_dirs = get_excluded_dirs()
    findings = find_tested_up_to_entries(excluded_dirs)

    if not findings:
        message = "No Tested up to metadata was found in the authoritative plugin/readme headers."
        print_github_error(message)
        write_summary(latest_version, [], [message])
        return 1

    failures = get_failures(latest_version, findings)
    updated_paths = []

    if args.fix and failures:
        if any(finding["version"] is None for finding in findings):
            raise ValueError("Invalid metadata requires manual correction; no files changed.")
        updated_paths = update_tested_up_to_entries(findings, latest_version)
        findings = find_tested_up_to_entries(excluded_dirs)
        failures = get_failures(latest_version, findings)

    if failures:
        for failure in failures:
            print_github_error(failure["message"], failure["path"], failure["line"])

    write_summary(latest_version, findings, failures, updated_paths)

    if failures:
        entry_label = "entry" if len(failures) == 1 else "entries"
        print(f"Found {len(failures)} stale or invalid Tested up to {entry_label}.")
        for failure in failures:
            print(f"- {format_failure(failure)}")
        return 1

    if updated_paths:
        path_label = "file" if len(updated_paths) == 1 else "files"
        print(
            f"Updated Tested up to metadata to WordPress {latest_version} "
            f"in {len(updated_paths)} {path_label}."
        )
        for path in updated_paths:
            print(f"- {path}")
        return 0

    print(f"All Tested up to entries match WordPress {latest_version}.")
    return 0


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description='Check WordPress "Tested up to" metadata against the latest release.'
    )
    parser.add_argument(
        "--fix",
        action="store_true",
        help="Update stale Tested up to headers; invalid metadata requires manual correction.",
    )

    return parser.parse_args()


def get_latest_wordpress_major_minor() -> str:
    if WORDPRESS_LATEST_VERSION:
        return normalize_major_minor(WORDPRESS_LATEST_VERSION)

    if not WORDPRESS_VERSION_CHECK_FILE.is_file():
        raise RuntimeError(
            "WORDPRESS_LATEST_VERSION must be set, or wordpress-version-check.json must exist."
        )

    payload = json.loads(WORDPRESS_VERSION_CHECK_FILE.read_text(encoding="utf-8"))
    versions = []

    for offer in payload.get("offers", []):
        if offer.get("response") != "upgrade":
            continue
        version = offer.get("current") or offer.get("version")
        if not isinstance(version, str) or not VERSION_PATTERN.fullmatch(version):
            raise ValueError("Invalid stable WordPress offer.")
        versions.append(version)

    if len(set(versions)) != 1:
        raise RuntimeError("Expected exactly one stable WordPress version.")

    latest = max(versions, key=version_sort_key)
    return normalize_major_minor(latest)


def normalize_major_minor(version: str) -> str:
    parts = version.split(".")

    if not VERSION_PATTERN.fullmatch(version):
        raise ValueError(f"Invalid WordPress version: {version}")

    return ".".join(parts[:2])


def version_sort_key(version: str) -> tuple[int, ...]:
    return tuple(int(part) for part in version.split("."))


def get_excluded_dirs() -> set[str]:
    configured = os.environ.get("WORDPRESS_TESTED_UP_TO_EXCLUDE_DIRS", "")
    extra_dirs = {item.strip() for item in configured.split(",") if item.strip()}

    return DEFAULT_EXCLUDED_DIRS | extra_dirs


def find_tested_up_to_entries(
    excluded_dirs: set[str],
) -> list[dict[str, str | int | None]]:
    findings = []

    for path in get_scanned_files(excluded_dirs):
        if not should_scan(path, excluded_dirs):
            continue

        lines = path.read_text(encoding="utf-8").splitlines()
        count = 0

        for line_number, line in enumerate(lines, 1):
            if (path.suffix == ".php" and "*/" in line) or (path.suffix == ".txt" and not line.strip()):
                break
            if not TESTED_UP_TO_LABEL_PATTERN.search(line):
                continue

            match = TESTED_UP_TO_PATTERN.search(line)
            count += 1
            findings.append(
                {
                    "path": path.as_posix(),
                    "line": line_number,
                    "version": match.group(1) if match else None,
                }
            )
        if count != 1:
            raise ValueError(f"Expected one Tested up to header in {path}.")

    return findings


def get_scanned_files(excluded_dirs: set[str]) -> list[Path]:
    tracked = subprocess.run(
        ["git", "ls-files", "-z"], check=True, capture_output=True
    ).stdout.decode("utf-8").split("\0")
    if not all(path.as_posix() in tracked for path in METADATA_PATHS):
        raise ValueError("Authoritative metadata files must both be tracked.")
    for path in METADATA_PATHS:
        if path.is_symlink() or not path.is_file():
            raise ValueError(f"Unsafe metadata path: {path}")
    return sorted(METADATA_PATHS)


def should_scan(path: Path, excluded_dirs: set[str]) -> bool:
    if path not in METADATA_PATHS:
        return False

    return not any(part in excluded_dirs for part in path.parts)


def get_failures(
    latest_version: str,
    findings: list[dict[str, str | int | None]],
) -> list[dict[str, str | int]]:
    failures = []

    for finding in findings:
        if finding["version"] is None:
            failures.append(
                {
                    "path": str(finding["path"]),
                    "line": int(finding["line"]),
                    "message": "Could not parse Tested up to version.",
                }
            )
            continue

        tested_version = normalize_major_minor(str(finding["version"]))
        if tested_version != latest_version:
            failures.append(
                {
                    "path": str(finding["path"]),
                    "line": int(finding["line"]),
                    "message": (
                        f"Tested up to is {finding['version']}; expected "
                        f"{latest_version} for the latest WordPress release."
                    ),
                }
            )

    return failures


def update_tested_up_to_entries(
    findings: list[dict[str, str | int | None]],
    latest_version: str,
) -> list[str]:
    paths_to_update = {
        str(finding["path"])
        for finding in findings
        if finding["version"] is None
        or normalize_major_minor(str(finding["version"])) != latest_version
    }

    for path_string in paths_to_update:
        path_findings = [
            finding for finding in findings if str(finding["path"]) == path_string
        ]
        path = Path(path_string)
        lines = path.read_text(encoding="utf-8", errors="replace").splitlines(
            keepends=True
        )

        for finding in path_findings:
            line_index = int(finding["line"]) - 1
            lines[line_index] = replace_tested_up_to_line(
                lines[line_index], latest_version
            )

        path.write_text("".join(lines), encoding="utf-8")

    return sorted(paths_to_update)


def replace_tested_up_to_line(line: str, latest_version: str) -> str:
    match = TESTED_UP_TO_PATTERN.search(line)
    if match:
        return f"{line[:match.start(1)]}{latest_version}{line[match.end(1):]}"

    raise ValueError("Refusing to rewrite an invalid metadata line.")


def format_failure(failure: dict[str, str | int]) -> str:
    return f"{failure['path']}:{failure['line']}: {failure['message']}"


def write_summary(
    latest_version: str,
    findings: list[dict[str, str | int | None]],
    failures: list[dict[str, str | int] | str],
    updated_paths: list[str] | None = None,
) -> None:
    summary_path = os.environ.get("GITHUB_STEP_SUMMARY")
    if not summary_path:
        return

    lines = [
        "# WordPress Tested Up To Check",
        "",
        f"Latest WordPress major.minor release: `{latest_version}`",
        f"Metadata entries checked: `{len(findings)}`",
        "",
    ]

    if failures:
        lines.append("## Failures")
        lines.extend(
            f"- {format_failure(failure) if isinstance(failure, dict) else failure}"
            for failure in failures
        )
    elif updated_paths:
        lines.append("## Updates")
        lines.append(f"Updated Tested up to metadata in `{len(updated_paths)}` file(s).")
        lines.extend(f"- `{path}`" for path in updated_paths)
    else:
        lines.append("All Tested up to entries are current.")

    with open(summary_path, "a", encoding="utf-8") as summary:
        summary.write("\n".join(lines))
        summary.write("\n")


def print_github_error(message: str, path: str | None = None, line: int | None = None) -> None:
    if path is None:
        print(f"::error::{escape_github_command_data(message)}")
        return

    properties = f"file={escape_github_command_property(path)}"
    if line is not None:
        properties += f",line={line}"

    print(f"::error {properties}::{escape_github_command_data(message)}")


def escape_github_command_property(value: str) -> str:
    return (
        value.replace("%", "%25")
        .replace("\r", "%0D")
        .replace("\n", "%0A")
        .replace(":", "%3A")
        .replace(",", "%2C")
    )


def escape_github_command_data(value: str) -> str:
    return value.replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        print_github_error(str(exc))
        sys.exit(1)

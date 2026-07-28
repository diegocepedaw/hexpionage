#!/usr/bin/env python3
"""Upload Hexpionage src/ tree to BGA Studio via SFTP.

Credentials are read from environment variables so they are never persisted.
Required env vars:
    BGA_SFTP_HOST     (e.g. 1.studio.boardgamearena.com)
    BGA_SFTP_PORT     (e.g. 2022)
    BGA_SFTP_USER     (your BGA Studio SFTP username)
    BGA_SFTP_PASSWORD

Optional flags:
    --dry-run          List the files that would be uploaded; do not connect.
    --verify           After upload, list remote root and report total file count.
    --remote-root /    Override the remote target dir (default '.', BGA's project root).

Usage:
    BGA_SFTP_HOST=1.studio.boardgamearena.com \\
    BGA_SFTP_PORT=2022 \\
    BGA_SFTP_USER=YourName \\
    BGA_SFTP_PASSWORD=secret \\
    python3 scripts/upload_to_bga.py [--dry-run|--verify]
"""

import os
import sys
import argparse
from pathlib import Path
from typing import List, Tuple

REPO = Path(__file__).resolve().parent.parent
LOCAL_SRC = REPO / "src"


def gather_uploads(local_src: Path) -> List[Tuple[Path, str]]:
    """Return [(local_path, remote_relative_path), ...] for every file under src/."""
    uploads = []
    for path in sorted(local_src.rglob("*")):
        if path.is_file():
            rel = path.relative_to(local_src).as_posix()
            uploads.append((path, rel))
    return uploads


def remote_dirs(remote_paths: List[str]) -> List[str]:
    """Return the unique parent directories needed on the remote."""
    seen = set()
    for rp in remote_paths:
        parts = rp.split("/")
        for i in range(1, len(parts)):
            seen.add("/".join(parts[:i]))
    return sorted(d for d in seen if d)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--dry-run", action="store_true",
                        help="Print the upload plan without connecting.")
    parser.add_argument("--verify", action="store_true",
                        help="After upload, list remote root.")
    parser.add_argument("--remote-root", default=".",
                        help="Remote base directory (default: '.').")
    args = parser.parse_args()

    if not LOCAL_SRC.is_dir():
        sys.exit(f"src/ not found at {LOCAL_SRC}")

    uploads = gather_uploads(LOCAL_SRC)
    print(f"== Upload plan ==")
    print(f"Local: {LOCAL_SRC}")
    print(f"Files: {len(uploads)}")
    print()
    total_bytes = 0
    for local, remote in uploads:
        size = local.stat().st_size
        total_bytes += size
        print(f"  {remote:60s}  {size:>10,} bytes")
    print()
    print(f"Total: {total_bytes:,} bytes ({total_bytes/1024:.1f} KB)")

    if args.dry_run:
        print("\n--dry-run: not connecting.")
        return

    # Lazy import — only needed when actually uploading
    import paramiko

    host = os.environ.get("BGA_SFTP_HOST")
    port = int(os.environ.get("BGA_SFTP_PORT", "2022"))
    user = os.environ.get("BGA_SFTP_USER")
    password = os.environ.get("BGA_SFTP_PASSWORD")
    if not all([host, user, password]):
        sys.exit("Missing env vars: BGA_SFTP_HOST, BGA_SFTP_USER, BGA_SFTP_PASSWORD required.")

    print(f"\n== Connecting to {user}@{host}:{port} ==")

    transport = paramiko.Transport((host, port))
    transport.connect(username=user, password=password)
    sftp = paramiko.SFTPClient.from_transport(transport)

    try:
        # Change to remote root
        try:
            sftp.chdir(args.remote_root)
        except IOError:
            print(f"Remote root '{args.remote_root}' inaccessible; using cwd.")
        cwd = sftp.getcwd() or "(home)"
        print(f"Remote cwd: {cwd}")

        # Ensure remote subdirectories exist
        remote_paths = [r for _, r in uploads]
        for d in remote_dirs(remote_paths):
            try:
                sftp.stat(d)
            except IOError:
                print(f"  mkdir {d}")
                sftp.mkdir(d)

        # Upload files
        print(f"\n== Uploading {len(uploads)} files ==")
        for i, (local, remote) in enumerate(uploads, 1):
            sftp.put(str(local), remote)
            sz = local.stat().st_size
            print(f"  [{i:2d}/{len(uploads)}] {remote:60s}  {sz:>10,} bytes")

        if args.verify:
            print(f"\n== Remote root listing ==")
            for entry in sorted(sftp.listdir()):
                print(f"  {entry}")
            print(f"\n== Remote modules/php/States/ ==")
            try:
                for entry in sorted(sftp.listdir("modules/php/States")):
                    print(f"  {entry}")
            except IOError as e:
                print(f"  (could not list: {e})")
    finally:
        sftp.close()
        transport.close()
    print("\nDone.")


if __name__ == "__main__":
    main()

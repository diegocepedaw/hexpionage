#!/usr/bin/env python3
"""Upload the Hexpionage src/ tree to BGA Studio via SFTP.

The *contents* of src/ map 1:1 to the Studio project root, so src/ itself is not
uploaded: src/modules/php/Game.php lands at modules/php/Game.php on the remote.

AUTHENTICATION
--------------
Two ways in, in order of preference:

1. SSH key (recommended).  Upload your public key at
   https://studio.boardgamearena.com/controlpanel , then:

       python3 scripts/upload_to_bga.py --identity ~/.ssh/id_ed25519

   This uses the system `sftp` binary, so ssh-agent and ~/.ssh/config work
   normally and no secret ever touches this repo, your shell history, or an
   environment variable.  Note that BGA disables password auth once a key is
   uploaded.

2. Password.  From the SFTP welcome email BGA sent when your dev account was
   created (a different email, and different credentials, from your Studio
   website login):

       BGA_SFTP_USER=you BGA_SFTP_PASSWORD=secret \\
           python3 scripts/upload_to_bga.py

   Requires `pip install paramiko`.

Settings can also live in a git-ignored `.env.bga` at the repo root:

    BGA_SFTP_HOST=1.studio.boardgamearena.com
    BGA_SFTP_PORT=2022
    BGA_SFTP_USER=yourname
    BGA_SFTP_KEY=~/.ssh/id_ed25519

USAGE
-----
    python3 scripts/upload_to_bga.py --dry-run     # list files, do not connect
    python3 scripts/upload_to_bga.py --check       # test the connection only
    python3 scripts/upload_to_bga.py --verify      # upload, then list the remote
"""

import argparse
import os
import subprocess
import sys
import tempfile
from pathlib import Path
from typing import List, Tuple

REPO = Path(__file__).resolve().parent.parent
LOCAL_SRC = REPO / "src"
ENV_FILE = REPO / ".env.bga"

DEFAULT_HOST = "1.studio.boardgamearena.com"
DEFAULT_PORT = "2022"
# SFTP drops you in a home directory that *contains* your project folders, so
# the upload target is the project dir, not the home dir.
DEFAULT_REMOTE_ROOT = "hexpionage"


def load_env_file() -> None:
    """Load .env.bga into os.environ without overriding real env vars."""
    if not ENV_FILE.exists():
        return
    for line in ENV_FILE.read_text(encoding="utf-8").splitlines():
        line = line.split("#", 1)[0].strip()
        if not line or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip("'\""))


def gather_uploads(local_src: Path) -> List[Tuple[Path, str]]:
    """Return [(local_path, remote_relative_path), ...] for every file under src/."""
    uploads = []
    for path in sorted(local_src.rglob("*")):
        if path.is_file() and not path.name.startswith("."):
            uploads.append((path, path.relative_to(local_src).as_posix()))
    return uploads


def remote_dirs(remote_paths: List[str]) -> List[str]:
    """Return the unique parent directories needed on the remote, parents first."""
    seen = set()
    for rp in remote_paths:
        parts = rp.split("/")
        for i in range(1, len(parts)):
            seen.add("/".join(parts[:i]))
    return sorted(d for d in seen if d)


def print_plan(uploads: List[Tuple[Path, str]]) -> int:
    print("== Upload plan ==")
    print(f"Local:  {LOCAL_SRC}")
    print(f"Files:  {len(uploads)}")
    print()
    total = 0
    for local, remote in uploads:
        size = local.stat().st_size
        total += size
        print(f"  {remote:60s}  {size:>10,} bytes")
    print()
    print(f"Total: {total:,} bytes ({total / 1024:.1f} KB)")
    return total


# --------------------------------------------------------------------------
# Backend 1: system `sftp` binary (SSH key / agent auth)
# --------------------------------------------------------------------------

def upload_via_ssh(uploads, host, port, user, identity, remote_root, verify, check):
    batch = []
    if remote_root not in (".", ""):
        batch.append(f"cd {remote_root}")
    if check:
        batch.append("pwd")
        batch.append("ls")
    else:
        # A leading '-' tells sftp to continue when the command fails, which is
        # what we want for directories that already exist.
        for d in remote_dirs([r for _, r in uploads]):
            batch.append(f"-mkdir {d}")
        for local, remote in uploads:
            batch.append(f"put {local} {remote}")
        if verify:
            batch.append("ls")
            batch.append("ls modules/php/States")
    batch.append("bye")

    # accept-new records the host key on first connect. Without it the very
    # first upload always dies on "Host key verification failed", because the
    # batch file occupies stdin so the yes/no prompt can never be answered.
    cmd = [
        "sftp", "-P", str(port),
        "-o", "StrictHostKeyChecking=accept-new",
    ]
    if identity:
        # IdentitiesOnly stops ssh-agent from offering every other key you hold
        # to a third-party server before it gets to this one, which both leaks
        # unrelated public keys and can trip "Too many authentication failures".
        cmd += ["-i", str(Path(identity).expanduser()),
                "-o", "IdentitiesOnly=yes"]
    cmd += ["-b", "-", f"{user}@{host}"]

    print(f"\n== {'Checking' if check else 'Uploading to'} {user}@{host}:{port} (ssh key) ==")
    proc = subprocess.run(cmd, input="\n".join(batch) + "\n", text=True)
    if proc.returncode != 0:
        sys.exit(f"sftp exited {proc.returncode}")


# --------------------------------------------------------------------------
# Backend 2: paramiko (password auth)
# --------------------------------------------------------------------------

def upload_via_password(uploads, host, port, user, password, remote_root, verify, check):
    try:
        import paramiko
    except ImportError:
        sys.exit(
            "Password auth needs paramiko:  pip install paramiko\n"
            "Or use an SSH key instead:     --identity ~/.ssh/id_ed25519"
        )

    print(f"\n== {'Checking' if check else 'Connecting to'} {user}@{host}:{port} (password) ==")
    transport = paramiko.Transport((host, port))
    transport.connect(username=user, password=password)
    sftp = paramiko.SFTPClient.from_transport(transport)
    try:
        try:
            sftp.chdir(remote_root)
        except IOError:
            print(f"Remote root '{remote_root}' inaccessible; using home.")
        print(f"Remote cwd: {sftp.getcwd() or '(home)'}")

        if check:
            print("\n== Remote root listing ==")
            for entry in sorted(sftp.listdir()):
                print(f"  {entry}")
            return

        for d in remote_dirs([r for _, r in uploads]):
            try:
                sftp.stat(d)
            except IOError:
                print(f"  mkdir {d}")
                sftp.mkdir(d)

        print(f"\n== Uploading {len(uploads)} files ==")
        for i, (local, remote) in enumerate(uploads, 1):
            sftp.put(str(local), remote)
            print(f"  [{i:2d}/{len(uploads)}] {remote:60s}  {local.stat().st_size:>10,} bytes")

        if verify:
            print("\n== Remote root listing ==")
            for entry in sorted(sftp.listdir()):
                print(f"  {entry}")
            print("\n== Remote modules/php/States/ ==")
            try:
                for entry in sorted(sftp.listdir("modules/php/States")):
                    print(f"  {entry}")
            except IOError as exc:
                print(f"  (could not list: {exc})")
    finally:
        sftp.close()
        transport.close()


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Upload src/ to the BGA Studio project root.")
    parser.add_argument("--dry-run", action="store_true",
                        help="Print the upload plan without connecting.")
    parser.add_argument("--check", action="store_true",
                        help="Connect and list the remote root, but upload nothing.")
    parser.add_argument("--verify", action="store_true",
                        help="After upload, list the remote root.")
    parser.add_argument("--identity", metavar="KEYFILE",
                        help="SSH private key (default: $BGA_SFTP_KEY, else ssh-agent).")
    parser.add_argument("--password", action="store_true",
                        help="Force password auth via $BGA_SFTP_PASSWORD.")
    parser.add_argument("--remote-root",
                        default=os.environ.get("BGA_REMOTE_ROOT", DEFAULT_REMOTE_ROOT),
                        help=f"Remote project directory (default: '{DEFAULT_REMOTE_ROOT}').")
    args = parser.parse_args()

    # Our own prints block-buffer when stdout is a pipe, which would interleave
    # them wrongly with the sftp subprocess's output.
    sys.stdout.reconfigure(line_buffering=True)

    load_env_file()
    if "--remote-root" not in sys.argv:
        args.remote_root = os.environ.get("BGA_REMOTE_ROOT", DEFAULT_REMOTE_ROOT)

    if not LOCAL_SRC.is_dir():
        sys.exit(f"src/ not found at {LOCAL_SRC}")

    uploads = gather_uploads(LOCAL_SRC)
    if not args.check:
        print_plan(uploads)
    if args.dry_run:
        print("\n--dry-run: not connecting.")
        return

    host = os.environ.get("BGA_SFTP_HOST", DEFAULT_HOST)
    port = os.environ.get("BGA_SFTP_PORT", DEFAULT_PORT)
    user = os.environ.get("BGA_SFTP_USER")
    identity = args.identity or os.environ.get("BGA_SFTP_KEY")
    password = os.environ.get("BGA_SFTP_PASSWORD")

    if not user:
        sys.exit(
            "No SFTP username. Set BGA_SFTP_USER, or put it in .env.bga.\n"
            "It is in the SFTP email BGA sent you — not your Studio website login."
        )

    use_password = args.password or (password and not identity)
    if use_password:
        if not password:
            sys.exit("--password given but BGA_SFTP_PASSWORD is not set.")
        upload_via_password(uploads, host, int(port), user, password,
                            args.remote_root, args.verify, args.check)
    else:
        upload_via_ssh(uploads, host, port, user, identity,
                       args.remote_root, args.verify, args.check)

    print("\nDone.")


if __name__ == "__main__":
    main()

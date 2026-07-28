# Hexpionage — Board Game Arena port

A port of **Hexpionage**, a 2-player hex-grid game of spies and stolen intel, to
[Board Game Arena](https://boardgamearena.com).
([BGG entry](https://boardgamegeek.com/boardgame/307967/hexpionage))

This repo holds the deployable BGA game, the design specs it was built from, an
offline test harness that runs the game logic without BGA Studio, and the print
masters for the physical edition.

**Status: working prototype.** The rules engine is implemented and verified
offline — 300 simulated games, 15 database invariants checked after every action,
zero violations. It has not yet been played on a live BGA Studio table. See
[`ONBOARDING.md`](ONBOARDING.md) for the full picture.

---

## Quick start

```bash
git lfs install          # once per machine — design/masters/ is LFS-backed
git clone <remote> hexpionage && cd hexpionage

brew install php         # PHP 8.1+; node and python3 are also used
./tools/check.sh         # lint + contract check + link check + 40 simulated games
```

A green `./tools/check.sh` means the game logic, the PHP↔JS contract, and every
in-repo cross-reference are all consistent. It takes about 15 seconds.

```bash
./tools/check.sh 300                          # slower, more thorough (~70 s)
php tools/harness/run_tests.php --games=1 --seed=42 --verbose   # replay one game
```

New to the project? Read [`ONBOARDING.md`](ONBOARDING.md).
Working on it as an AI agent? Read [`AGENTS.md`](AGENTS.md).
Handing the project to a new agent session? Paste [`HANDOVER.md`](HANDOVER.md).

---

## What is in here

| Path | What it is |
|---|---|
| [`src/`](src/) | **The deployable game.** Its *contents* map 1:1 to the BGA Studio project root. PHP server logic, JS client, CSS, DB model, `.jsonc` config, runtime art. |
| [`tools/`](tools/) | Offline test rig — runs the real `src/` PHP against a stubbed BGA framework. `check.sh` is the entry point; see [`tools/harness/README.md`](tools/harness/README.md). |
| [`docs/`](docs/) | Specs, rules, decisions, and test plans. [`docs/rulebook.md`](docs/rulebook.md) and [`docs/specs/`](docs/specs/) are the source of truth for behaviour. |
| [`design/`](design/) | Art pipeline and the print masters for the physical game (`design/masters/`, Git LFS). |
| [`scripts/`](scripts/) | Operational scripts: upload to BGA Studio, cross-reference checker. |

Full directory-by-directory tour: [`ONBOARDING.md` §3](ONBOARDING.md).

### Key documents

| Document | Read it when |
|---|---|
| [`ONBOARDING.md`](ONBOARDING.md) | You are picking this project up. Start here. |
| [`AGENTS.md`](AGENTS.md) | You are an AI agent working in this repo. |
| [`HANDOVER.md`](HANDOVER.md) | You are bootstrapping a fresh agent session — paste it as the opening prompt. |
| [`docs/rulebook.md`](docs/rulebook.md) | You need to know what the game actually does. Canonical. |
| [`docs/DECISIONS.md`](docs/DECISIONS.md) | You hit a rules ambiguity — it was probably already adjudicated (D-01…D-26). |
| [`docs/specs/CONTRACT.md`](docs/specs/CONTRACT.md) | You are changing anything that crosses the server/client boundary. |
| [`docs/specs/STATE_MACHINE.md`](docs/specs/STATE_MACHINE.md) | You are changing states, actions, or state args. |
| [`docs/testing/SCENARIOS.md`](docs/testing/SCENARIOS.md) | You are testing on a Studio table. 15 playtest scenarios + 40 illegal-action cases. |

---

## Development loop

1. Change `src/`.
2. `./tools/check.sh` — catches syntax errors, PHP↔JS contract drift, rules-invariant
   violations, and broken cross-references.
3. Upload to Studio and play: `python3 scripts/upload_to_bga.py --verify`
   (see [`ONBOARDING.md` §5.2](ONBOARDING.md) for credentials and the first-upload steps).

The offline harness proves the rules engine. It cannot prove rendering, animations,
or exact BGA framework API fidelity — those need a Studio test table.

## Deploying

`src/`'s **contents** go to the Studio project root; `src/` itself is not uploaded.

```bash
python3 scripts/upload_to_bga.py --dry-run    # what would be sent, no connection
python3 scripts/upload_to_bga.py --check      # test credentials, upload nothing
python3 scripts/upload_to_bga.py --verify     # upload, then list the remote
```

Credentials come from a git-ignored `.env.bga` at the repo root, or from the
environment. **SSH-key auth is preferred** — upload a public key at
[the Studio control panel](https://studio.boardgamearena.com/controlpanel) and no
secret ever needs to exist in this repo or your shell history:

```
# .env.bga
BGA_SFTP_HOST=1.studio.boardgamearena.com
BGA_SFTP_PORT=2022
BGA_SFTP_USER=yourname
BGA_SFTP_KEY=~/.ssh/id_ed25519_bga
```

Password auth still works (`BGA_SFTP_PASSWORD`, needs `pip install paramiko`); note
that BGA disables it once you upload a key. See
[`ONBOARDING.md` §5.2](ONBOARDING.md) for where these credentials come from.

## License and rights

Hexpionage — its rules, art, and text — is the owner's original work. The contents of
`design/masters/` are print-production masters for the published physical game and are
not licensed for reuse. No open-source license is granted for this repository.

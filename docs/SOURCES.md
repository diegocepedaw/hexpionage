# Source artifacts and reference material

## Print masters — now in this repo

The physical-game masters used to live only in `~/Downloads/final_printing/`. They
were imported into `design/masters/` (2026-07) so the repo is self-contained. They
are stored via **Git LFS** — run `git lfs install` once before cloning or pulling.

| What | Where |
|---|---|
| Game board (print PNG, layered PSD, print template PDF) | `design/masters/board/` |
| Box top/bottom art + dielines + template | `design/masters/box/` |
| Rulebook page PNGs, layered PSDs, booklet PDF | `design/masters/rulebook/` |
| Back cover | `design/masters/rulebook/back-cover/` |
| Agent + intel tiles (finished PNGs, icons, PSDs) | `design/masters/punchboard/agents-and-intel/` |
| Blockade / pinned / arrow tokens | `design/masters/punchboard/tokens/` |
| Pre-production document (printer spec) | `design/masters/pre-production/` |

`design/MANIFEST.md` is the annotated inventory: what each file is, whether the BGA
port uses it, and what transformation it needs. Directory and file names were
normalized on import (spaces removed, `punchboard 1` → `agents-and-intel`,
`punchboard 2` → `tokens`, `game board` → `board`), so paths quoted in older
documents may differ.

## Rules documents in this repo

- `docs/rulebook.md` — implementation-grade rules spec. **Canonical.**
- `docs/FAQ.md` — the owner's rules FAQ, imported alongside the masters.
- `docs/DECISIONS.md` — owner adjudications D-01 through D-26.

## BGA Studio documentation

Modern framework — what this project targets:

- https://en.doc.boardgamearena.com/Studio
- https://en.doc.boardgamearena.com/Tutorial_reversi
- https://en.doc.boardgamearena.com/State_classes:_State_directory
- https://en.doc.boardgamearena.com/Game_database_model:_dbmodel.sql
- https://en.doc.boardgamearena.com/Notify_player
- https://en.doc.boardgamearena.com/BGA_Studio_Cookbook
- https://en.doc.boardgamearena.com/Tools_for_translators

Legacy framework — read only for context, since this project has migrated away
from these patterns:

- https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php
- https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js
- https://en.doc.boardgamearena.com/Game_metadata:_gameinfos.inc.php
- https://en.doc.boardgamearena.com/Player_actions:_yourgamename.action.php

## External entries

- BGG: https://boardgamegeek.com/boardgame/307967/hexpionage (BGG ID 307967)
- Printer (Panda GM), pre-production reference only: https://pandagm.com/tools

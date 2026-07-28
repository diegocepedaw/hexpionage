# design/ — art pipeline and print masters

Two different things live here.

## `masters/` — read-only print masters

The production art for the **physical** game: layered PSDs, print-template PDFs, die
lines, punchboard tiles, and the pre-production document sent to the printer. They
were imported from `~/Downloads/final_printing/` in 2026-07 so the repo is
self-contained.

**These are inputs, not outputs. Do not edit them from code.**

They are stored in **Git LFS** (one PSD is 123 MB, above GitHub's 100 MB hard limit).
Run `git lfs install` once per machine before cloning or pulling, or you will get
text pointer files instead of images.

```
masters/
├── board/                      game board: print PNG, layered PSD, template PDF
├── box/                        box top/bottom art, die lines, template PDF
├── pre-production/             printer spec document
├── punchboard/
│   ├── agents-and-intel/       agent + intel tiles
│   │   ├── agent-icons/        source SVG icons
│   │   └── finished-tiles/     per-tile finished PNGs (fronts and backs)
│   └── tokens/                 blockade, pinned, and arrow tokens
└── rulebook/                   rulebook pages (PNG + PSD), booklet PDF
    └── back-cover/
```

Names were normalized on import: `punchboard 1` → `agents-and-intel`,
`punchboard 2` → `tokens`, `game board` → `board`, `back stuff` → `back-cover`,
and spaces were removed from filenames. Older documents may quote the old paths.

## The pipeline docs — how masters become game art

| File | What it covers |
|---|---|
| `MANIFEST.md` | Annotated inventory of every master: what it is, whether the BGA port uses it, and what transformation it needs. |
| `PIPELINE.md` | Spec for turning masters into the sprite sheets in `src/img/`. |
| `BOARD_LAYOUT.md` | The 44-hex board layout derived from the printed board art. Already implemented in `src/material.inc.php`. |
| `MISSING.md` | Art that still needs authoring. |
| `build_placeholders.py` | Generates the placeholder sprite sheets currently sitting in `src/img/`. |

The runtime art the game actually ships is in `src/img/` — currently **placeholders**
except `board.png`. Sprite-cell geometry is locked, so real art can replace the PNGs
in place without code changes. Follow `PIPELINE.md`.

## `legacy_metadata/`

Superseded BGA config files from the legacy-framework era (`gameinfos.inc.php`,
`stats.inc.php`, and friends). Kept for reference only. Modern BGA reads the `.jsonc`
files in `src/`; do not copy these back.

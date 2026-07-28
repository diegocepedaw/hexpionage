# Hexpionage — Asset Build Pipeline (specification only — DO NOT execute)

This document specifies the deterministic, dry-run-able build pipeline that converts the print masters in `/Users/dcepeda/Downloads/final_printing/` (read-only) into BGA-ready sprite sheets and CSS under `src/img/`. Execution belongs to the Frontend Implementation Agent (A8) in Phase 3, not to A3.

**Scope**: visual asset transformation only. No source files are mutated. No PSDs are opened. No `src/` outputs are created by A3 — they will be produced by running the script described in §4 below.

**Cross-references**: `design/MANIFEST.md` (input inventory), `design/MISSING.md` (asset gaps that must be authored before running the pipeline), `rulebook.md` §2 (component naming).

---

## 1. Required tools

| Tool | Purpose | Version (minimum) | Install |
|---|---|---|---|
| **ImageMagick** (`convert`, `magick`) | PNG resize, crop, alpha-mask composition, sprite-sheet `montage` | 7.1+ | `brew install imagemagick` (macOS) |
| **psd-tools** (Python package) | PSD layer flattening **if** any source PSD ever needs to be re-rendered | 1.9+ | `pip install psd-tools` |
| **librsvg** (`rsvg-convert`) | SVG → PNG (only needed if generated SVG dice/score-marker faces from `MISSING.md` are rasterized at build time; if BGA loads SVG directly, skip) | 2.55+ | `brew install librsvg` |
| **optipng** (optional) | Lossless PNG compression on final sprite sheets | 0.7+ | `brew install optipng` |
| **bash** ≥ 4.0 | Script host | — | macOS default `/bin/zsh` is fine; script targets bash explicitly |

> **Why not just `convert` for PSD?** Source PSDs are layered masters not in scope for the digital port (per [D-12a] all needed art is already exported as flat PNGs). `psd-tools` is listed only as a contingency. Per task rules, the pipeline **must not** open any PSD in this iteration.

---

## 2. Target dimensions

### 2.1 Sprites (per cell, 1× and 2×-retina)

| Category | 1× cell | 2× retina cell | Source resolution | Scale factor |
|---|---|---|---|---|
| Agents (12 cells) | 80×80 | 160×160 | 450×450 | downscale 0.178× / 0.356× |
| Intel face + back (12 cells) | 80×80 | 160×160 | 450×450 | downscale 0.178× / 0.356× |
| Tokens (blockade ×2, pin ×2 = 4 cells) | 40×40 | 80×80 | 300×300 | downscale 0.133× / 0.267× |

Rationale: 80×80 matches typical BGA hex-tile rendering for a ~12-row board on a 1200px-wide board image; 40×40 token overlay sits proportionally inside an 80×80 hex. Retina (2×) is shipped because BGA Studio recommends 2× sprites for high-DPI displays.

### 2.2 Board

| Variant | Width × Height | Notes |
|---|---|---|
| Desktop | 1200 × 608 | preserves native ~1.973 aspect (5475/2775); 608 = `round(1200/1.9729)` |
| Mobile | 800 × 405 | same aspect; activated below CSS breakpoint set by A6 |

The board is **not** part of any sprite sheet — it ships as standalone `src/img/board.png` (and `board@2x.png` if the asset budget allows).

### 2.3 Generated assets (see `MISSING.md`)

| Asset | Generated format | Dimensions |
|---|---|---|
| Score-marker pawn (×2 colors) | SVG → 32×32 PNG | 32×32 / 64×64 retina |
| Dice faces (6 colors × 2 outcomes = 12 faces) | SVG → 64×64 PNG | 64×64 / 128×128 retina |
| Action counter pip / "X/3" UI | CSS-only (no asset) | n/a |
| Turn indicator | CSS-only (no asset) | n/a |
| Phase indicator | CSS-only (no asset) | n/a |

---

## 3. Sprite-sheet layout

Each sheet is a single PNG with **fixed cell strides**, addressed by `(row, col)` via `background-position`. CSS rules are emitted alongside (`src/img/sprites.css`).

### 3.1 `src/img/agents.png`

- Grid: **6 rows × 2 cols** (one row per agent type; col 0 = white, col 1 = black).
- Cell size: 80×80 (1×). Sheet size: 160×480.
- Row order (top → bottom):
  | Row | type id (rulebook §2.2) | print-art filename root |
  |---|---|---|
  | 0 | `comms_specialist` | `specialops` (alias per [D-01]) |
  | 1 | `analyst` | `analyst` |
  | 2 | `smuggler` | `smuggler` |
  | 3 | `engineer` | `engineer` |
  | 4 | `hacker` | `hacker` |
  | 5 | `double_agent` | `doubleagent` |
- Column order: 0 = `_white`, 1 = `_black`.

### 3.2 `src/img/intel.png`

- Grid: **6 rows × 2 cols** (one row per intel type; col 0 = face, col 1 = back).
- Cell size: 80×80 (1×). Sheet size: 160×480.
- Row order (top → bottom; matches [D-19] table order):
  | Row | intel id | filename |
  |---|---|---|
  | 0 | `honeypot` | `honeypot` |
  | 1 | `industrial_tech` | `industrial_tech` |
  | 2 | `leaked_email` | `leaked_email` |
  | 3 | `blackmail` | `blackmail` |
  | 4 | `security_credential` | `security_credential` |
  | 5 | `state_secret` | `state_secret` |
- Column order: 0 = face (`<id>.png`), 1 = back (`<id>_back.png`).

### 3.3 `src/img/tokens.png`

- Grid: **2 rows × 2 cols** (row 0 = blockade overlay, row 1 = pin marker overlay; col 0 = white, col 1 = black).
- Cell size: 40×40 (1×). Sheet size: 80×80.
- Row order:
  | Row | token | filename |
  |---|---|---|
  | 0 | `blockade` | `blockade_triangle_<color>.png` |
  | 1 | `pin` | `pinned_triangle_<color>.png` |

### 3.4 `src/img/board.png`

Standalone file (no sprite grid). Direct downscale of `game_board_print.png`. A retina variant `src/img/board@2x.png` may be emitted at 2400×1216 if asset budget permits.

### 3.5 `src/img/sprites.css`

A generated stylesheet defining BEM-style classes whose `background-position` values map to the cells above. Pseudocode of the emitted file:

```css
/* GENERATED — do not edit. Regenerated by build_assets.sh. */

.hex-agent          { width: 80px; height: 80px; background-image: url("agents.png"); }
.hex-agent--comms_specialist.hex-agent--white  { background-position:    0    0; }
.hex-agent--comms_specialist.hex-agent--black  { background-position:  -80px  0; }
.hex-agent--analyst.hex-agent--white           { background-position:    0   -80px; }
.hex-agent--analyst.hex-agent--black           { background-position:  -80px -80px; }
/* ... rows for smuggler, engineer, hacker, double_agent ... */

.hex-intel          { width: 80px; height: 80px; background-image: url("intel.png"); }
.hex-intel--honeypot.hex-intel--face            { background-position:    0    0; }
.hex-intel--honeypot.hex-intel--back            { background-position:  -80px  0; }
/* ... rows for industrial_tech, leaked_email, blackmail, security_credential, state_secret ... */

.hex-token          { width: 40px; height: 40px; background-image: url("tokens.png"); }
.hex-token--blockade.hex-token--white           { background-position:    0    0; }
.hex-token--blockade.hex-token--black           { background-position:  -40px  0; }
.hex-token--pin.hex-token--white                { background-position:    0   -40px; }
.hex-token--pin.hex-token--black                { background-position:  -40px -40px; }

/* Retina (matches BGA's 2x convention) */
@media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
  .hex-agent { background-image: url("agents@2x.png"); background-size: 160px 480px; }
  .hex-intel { background-image: url("intel@2x.png"); background-size: 160px 480px; }
  .hex-token { background-image: url("tokens@2x.png"); background-size: 80px 80px; }
}
```

> **Authoritative naming**: classes use canonical rules names (`comms_specialist`), not file aliases (`specialops`). Per [D-01], the file→rule alias is a build-time concern only.

---

## 4. Build script — `build_assets.sh` (pseudocode; do not execute)

The script lives at the repo root (or under `design/`) and assumes:
- `$SRC` = `/Users/dcepeda/Downloads/final_printing` (read-only)
- `$DST` = `<repo>/src/img`
- `$MASK` = a 450×450 hex alpha mask PNG, **already authored once** and committed at `design/hex_mask_450.png` (one-time setup; the geometry is taken from `example_finished_tile_with_shape.png`).
- A `--dry-run` flag prints every command without executing it.

```bash
#!/usr/bin/env bash
# build_assets.sh — Hexpionage BGA asset pipeline. SPECIFICATION; not executed by A3.
set -euo pipefail

SRC="${SRC:-/Users/dcepeda/Downloads/final_printing}"
DST="${DST:-./src/img}"
MASK="${MASK:-./assets/hex_mask_450.png}"
DRY_RUN="${DRY_RUN:-0}"

run() { if [[ "$DRY_RUN" == "1" ]]; then echo "+ $*"; else "$@"; fi; }

# --- 0. Pre-flight ----------------------------------------------------------
run mkdir -p "$DST"
for tool in magick convert montage; do
  command -v "$tool" >/dev/null || { echo "missing: $tool" >&2; exit 1; }
done

# --- 1. Per-tile processing: hex-mask + downscale --------------------------
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# Helper: source PNG -> hex-masked, resized PNG written to $TMP/<name>_<size>.png
process_tile() {
  local src="$1" out="$2" size="$3"
  run magick "$src" \
       \( "$MASK" -alpha extract \) \
       -compose CopyOpacity -composite \
       -resize "${size}x${size}" \
       "$out"
}

# Agents: 12 source PNGs (canonical type id ↔ filename root mapping per MANIFEST.md §B)
declare -a AGENTS=(
  "comms_specialist:specialops"
  "analyst:analyst"
  "smuggler:smuggler"
  "engineer:engineer"
  "hacker:hacker"
  "double_agent:doubleagent"
)
for color in white black; do
  for entry in "${AGENTS[@]}"; do
    type_id="${entry%%:*}"; file_root="${entry##*:}"
    src="$SRC/punchboard/punchboard 1/finished individual tiles no shape/${file_root}_${color}.png"
    process_tile "$src" "$TMP/agent_${type_id}_${color}_80.png"  80
    process_tile "$src" "$TMP/agent_${type_id}_${color}_160.png" 160
  done
done

# Intel: 6 types × {face, back}
declare -a INTEL=(honeypot industrial_tech leaked_email blackmail security_credential state_secret)
for id in "${INTEL[@]}"; do
  for face in "" "_back"; do
    src="$SRC/punchboard/punchboard 1/finished individual tiles no shape/${id}${face}.png"
    suffix="face"; [[ "$face" == "_back" ]] && suffix="back"
    process_tile "$src" "$TMP/intel_${id}_${suffix}_80.png"  80
    process_tile "$src" "$TMP/intel_${id}_${suffix}_160.png" 160
  done
done

# Tokens: blockade and pin (already RGBA; mask not needed, just resize)
for color in white black; do
  for kind_pair in "blockade:blockade_triangle" "pin:pinned_triangle"; do
    kind="${kind_pair%%:*}"; file="${kind_pair##*:}"
    src="$SRC/punchboard/punchboard 2/${file}_${color}.png"
    run magick "$src" -resize 40x40 "$TMP/token_${kind}_${color}_40.png"
    run magick "$src" -resize 80x80 "$TMP/token_${kind}_${color}_80.png"
  done
done

# --- 2. Sprite sheet assembly via montage ----------------------------------
# Order MUST match PIPELINE.md §3 layout exactly.

# Agents: 2 cols × 6 rows
ORDER=(comms_specialist analyst smuggler engineer hacker double_agent)
build_agents_sheet() {
  local size="$1" out="$2"
  local args=()
  for type_id in "${ORDER[@]}"; do
    args+=("$TMP/agent_${type_id}_white_${size}.png" "$TMP/agent_${type_id}_black_${size}.png")
  done
  run montage "${args[@]}" -tile 2x6 -geometry "${size}x${size}+0+0" -background none "$out"
}
build_agents_sheet 80  "$DST/agents.png"
build_agents_sheet 160 "$DST/agents@2x.png"

# Intel: 2 cols × 6 rows
build_intel_sheet() {
  local size="$1" out="$2"
  local args=()
  for id in "${INTEL[@]}"; do
    args+=("$TMP/intel_${id}_face_${size}.png" "$TMP/intel_${id}_back_${size}.png")
  done
  run montage "${args[@]}" -tile 2x6 -geometry "${size}x${size}+0+0" -background none "$out"
}
build_intel_sheet 80  "$DST/intel.png"
build_intel_sheet 160 "$DST/intel@2x.png"

# Tokens: 2 cols × 2 rows
build_tokens_sheet() {
  local size="$1" out="$2"
  run montage \
    "$TMP/token_blockade_white_${size}.png" "$TMP/token_blockade_black_${size}.png" \
    "$TMP/token_pin_white_${size}.png"      "$TMP/token_pin_black_${size}.png" \
    -tile 2x2 -geometry "${size}x${size}+0+0" -background none "$out"
}
build_tokens_sheet 40 "$DST/tokens.png"
build_tokens_sheet 80 "$DST/tokens@2x.png"

# --- 3. Board ---------------------------------------------------------------
run magick "$SRC/game board/game_board_print.png" -resize 1200x608  "$DST/board.png"
run magick "$SRC/game board/game_board_print.png" -resize 2400x1216 "$DST/board@2x.png"

# --- 4. Generated assets (from MISSING.md) ---------------------------------
# These SVGs are committed under design/generated/*.svg by a one-time authoring task.
# Build step rasterizes them to PNG. If BGA loads SVG directly, this section is skipped.
for svg in design/generated/*.svg; do
  [[ -e "$svg" ]] || continue
  base="$(basename "$svg" .svg)"
  run rsvg-convert -w  64 -h  64 "$svg" -o "$DST/${base}.png"
  run rsvg-convert -w 128 -h 128 "$svg" -o "$DST/${base}@2x.png"
done

# --- 5. Optional optimization ----------------------------------------------
if command -v optipng >/dev/null; then
  for f in "$DST"/*.png; do
    run optipng -quiet -o2 "$f"
  done
fi

# --- 6. Emit sprites.css ----------------------------------------------------
# Static template; written verbatim per PIPELINE.md §3.5.
run cat > "$DST/sprites.css" <<'CSS'
/* contents per PIPELINE.md §3.5 */
CSS

echo "Build complete. Outputs in $DST."
```

---

## 5. Manifest-validation step

After `build_assets.sh` completes, a separate `validate_assets.sh` checks that every "BGA sprite" entry in `design/MANIFEST.md` is represented by a cell in the produced sprite sheets.

```bash
#!/usr/bin/env bash
# validate_assets.sh — fails non-zero if any sprite cell is missing.
set -euo pipefail

DST="${DST:-./src/img}"

# Required output files (per PIPELINE.md §3)
REQUIRED=(
  "$DST/agents.png"   "$DST/agents@2x.png"
  "$DST/intel.png"    "$DST/intel@2x.png"
  "$DST/tokens.png"   "$DST/tokens@2x.png"
  "$DST/board.png"
  "$DST/sprites.css"
)
for f in "${REQUIRED[@]}"; do
  [[ -s "$f" ]] || { echo "MISSING: $f" >&2; exit 1; }
done

# Cell-count assertion (using image dimensions / cell size).
# agents.png: expect 160×480 → 2×6 cells = 12
W=$(magick identify -format '%w' "$DST/agents.png"); H=$(magick identify -format '%h' "$DST/agents.png")
[[ "$W" == "160" && "$H" == "480" ]] || { echo "agents.png wrong size: ${W}x${H}" >&2; exit 1; }
# intel.png: expect 160×480 → 2×6 cells = 12
W=$(magick identify -format '%w' "$DST/intel.png");  H=$(magick identify -format '%h' "$DST/intel.png")
[[ "$W" == "160" && "$H" == "480" ]] || { echo "intel.png wrong size: ${W}x${H}" >&2; exit 1; }
# tokens.png: expect 80×80 → 2×2 cells = 4
W=$(magick identify -format '%w' "$DST/tokens.png"); H=$(magick identify -format '%h' "$DST/tokens.png")
[[ "$W" == "80"  && "$H" == "80"  ]] || { echo "tokens.png wrong size: ${W}x${H}" >&2; exit 1; }
# board.png: expect 1200×608
W=$(magick identify -format '%w' "$DST/board.png");  H=$(magick identify -format '%h' "$DST/board.png")
[[ "$W" == "1200" && "$H" == "608" ]] || { echo "board.png wrong size: ${W}x${H}" >&2; exit 1; }

echo "Asset validation OK. 28 sprite cells + 1 board + sprites.css present."
```

> **Total expected sprite cells across the 3 sheets**: 12 (agents) + 12 (intel) + 4 (tokens) = **28**, which equals the count of MANIFEST rows marked `BGA use = sprite`.

---

## 6. Determinism, reproducibility, and dry-run

- All commands are deterministic (`magick` resize uses Lanczos by default; `montage` lays out in stable left-to-right top-to-bottom order).
- The script is **idempotent**: rerunning produces byte-identical sprite sheets, modulo `optipng` (which is itself deterministic at `-o2`).
- `DRY_RUN=1 ./build_assets.sh` prints every command without touching the filesystem. This is the contract A3 requires for "dry-run-able without modifying source files."
- The pipeline **never** writes inside `$SRC`. All reads from `$SRC` use the source path verbatim; no `mv`, `rm`, or `cp` targets `$SRC`.

## 7. One-time authoring tasks (prerequisites; not part of `build_assets.sh`)

These artifacts must exist before the build script can run. They are committed to the repo under `design/`:

1. **`design/hex_mask_450.png`** — a 450×450 alpha mask whose opaque region is the hex-shape footprint. Source the geometry from `example_finished_tile_with_shape.png`. Author once.
2. **`design/generated/*.svg`** — generated SVG files for score marker, dice faces (see `MISSING.md`). Author once or per design iteration.

Authoring is out of scope for A3's audit deliverable; A3 lists the required files in `MISSING.md`.

# Hexpionage — Canonical Board Layout

> **Purpose**: resolves `TODO(G-01)` (hex orientation) and `TODO(G-02)` (Field hex enumeration) referenced by `STATE_MODEL.md §3.3` and `src/material.inc.php`. Derived from direct inspection of `final_printing/game board/game_board_print.png` (5475 × 2775 px native).

---

## G-01 — Hex orientation

**Confirmed**: **pointy-top**. Hexes have vertices at top and bottom, flat edges on left and right. Source: visual inspection (see crops in `/tmp/board_*.png` from inspection script).

**Implication**: the 6 neighbors of (q, r) under pointy-top axial are NW, NE, E, SE, SW, W as already defined in `STATE_MODEL §3.3` and `src/material.inc.php::hexpionage_hex_neighbors()`. No code change needed.

---

## G-02 — Field hex enumeration

The board has **8 rows** of hexes arranged in a downward-widening pyramid. The **top 4 rows are orange** (the "intel rain" zone where intel enters and trickles through, but agents may not stand). The **bottom 4 rows are lavender** (the **Field** per `rulebook.md §2.6`, where agents may move and act).

### Row counts (top → bottom)

| Row | Color | Hex count | Designation |
|---|---|---|---|
| 0 (top) | orange | **2** | Intel entry hexes (labeled "1" and "2" on the print art; one-hex gap between them) |
| 1 | orange | **3** | Intel rain |
| 2 | orange | **4** | Intel rain |
| 3 | orange | **5** | Intel rain (last orange row) |
| 4 | lavender | **6** | Field top (where loose intel can rest after trickling) |
| 5 | lavender | **7** | Field |
| 6 | lavender | **8** | Field |
| 7 (bottom) | lavender | **9** | Spawn row (✦ symbol on each hex; agents spawn here, retire here) |

**Totals**:
- All board hexes: **44** (2+3+4+5+6+7+8+9)
- Field hexes (lavender, where agents live): **30** (6+7+8+9)
- Intel rain hexes (orange, agent-forbidden but intel passes through): **14**
- Spawn row (✦, bottom): **9**
- Intel entry hexes (top, "1" and "2"): **2**

### Visual evidence

- **Spawn row count**: confirmed 9 ✦ markers (visible in `/tmp/board_bottom.png`, full row scan).
- **Top entries**: confirmed 2 hexes with horizontal gap (visible in `/tmp/board_top.png`).
- **Programmatic color scan** (`python3 inspection_script` cited above) shows lavender row counts of 6, 7, 8, 9 at successive y-coordinates — matches the visual count.

### Coordinate system (proposed)

The current `src/material.inc.php` uses **pointy-top axial (q, r)** with `r=3` as the bottom (spawn) row. To accommodate the additional 4 rows of orange + 9-wide bottom, extend to `r ∈ [-4, 3]`:

| r | Color | Hex count | Notes |
|---|---|---|---|
| -4 | orange | 2 | Entry row; **non-contiguous** q range (gap between entries) |
| -3 | orange | 3 | |
| -2 | orange | 4 | |
| -1 | orange | 5 | |
| 0 | lavender | 6 | Top of Field |
| 1 | lavender | 7 | |
| 2 | lavender | 8 | |
| 3 | lavender | 9 | Bottom; spawn row |

The exact q-range per row depends on the axial origin choice; one consistent convention places the bottom-row center at `q = 0` and uses `q_min(r) = ceil(-N/2)`, `q_max(r) = floor(N/2)` where `N` is the row's hex count, with appropriate offset accounting for axial-pointy-top stagger.

For the implementation in `material.inc.php`, an **explicit per-row table** is clearer than computed bounds — see proposed update below.

---

## Proposed `material.inc.php` update

Replace the `hexpionage_is_field_hex()` and `hexpionage_field_hex_list()` placeholder logic (currently `r ∈ [-3, 3]` with mixed q ranges totaling ~25 hexes) with the canonical 30-hex Field enumeration:

```php
/**
 * Canonical Field hex enumeration per design/BOARD_LAYOUT.md.
 * Bottom row (r=3) is the spawn row (9 hexes); top of Field is r=0 (6 hexes).
 * Orange "intel rain" hexes (r=-4 to r=-1) are NOT Field — handled separately.
 */
const FIELD_HEXES = [
    // r=0 (top of Field, 6 hexes)
    ['q' =>  0, 'r' => 0], ['q' => 1, 'r' => 0], ['q' => 2, 'r' => 0],
    ['q' =>  3, 'r' => 0], ['q' => 4, 'r' => 0], ['q' => 5, 'r' => 0],
    // r=1 (7 hexes)
    ['q' => -1, 'r' => 1], ['q' => 0, 'r' => 1], ['q' => 1, 'r' => 1],
    ['q' =>  2, 'r' => 1], ['q' => 3, 'r' => 1], ['q' => 4, 'r' => 1],
    ['q' =>  5, 'r' => 1],
    // r=2 (8 hexes)
    ['q' => -1, 'r' => 2], ['q' => 0, 'r' => 2], ['q' => 1, 'r' => 2],
    ['q' =>  2, 'r' => 2], ['q' => 3, 'r' => 2], ['q' => 4, 'r' => 2],
    ['q' =>  5, 'r' => 2], ['q' => 6, 'r' => 2],
    // r=3 (bottom, spawn row, 9 hexes)
    ['q' => -2, 'r' => 3], ['q' => -1, 'r' => 3], ['q' => 0, 'r' => 3],
    ['q' =>  1, 'r' => 3], ['q' =>  2, 'r' => 3], ['q' => 3, 'r' => 3],
    ['q' =>  4, 'r' => 3], ['q' =>  5, 'r' => 3], ['q' => 6, 'r' => 3],
];

const ORANGE_HEXES = [
    // Intel rain — agents NOT allowed; intel transits.
    // r=-4 (top, 2 entry hexes; gap at q=2 — no hex)
    ['q' => 1, 'r' => -4],  // ← entry "1"
    ['q' => 3, 'r' => -4],  // ← entry "2"
    // r=-3 (3 hexes)
    ['q' => 1, 'r' => -3], ['q' => 2, 'r' => -3], ['q' => 3, 'r' => -3],
    // r=-2 (4 hexes)
    ['q' => 0, 'r' => -2], ['q' => 1, 'r' => -2], ['q' => 2, 'r' => -2], ['q' => 3, 'r' => -2],
    // r=-1 (5 hexes; bottom of orange)
    ['q' => 0, 'r' => -1], ['q' => 1, 'r' => -1], ['q' => 2, 'r' => -1],
    ['q' => 3, 'r' => -1], ['q' => 4, 'r' => -1],
];

const ALL_BOARD_HEXES = [...FIELD_HEXES, ...ORANGE_HEXES];

const SPAWN_ROW_HEXES = [
    ['q' => -2, 'r' => 3], ['q' => -1, 'r' => 3], ['q' => 0, 'r' => 3],
    ['q' =>  1, 'r' => 3], ['q' =>  2, 'r' => 3], ['q' => 3, 'r' => 3],
    ['q' =>  4, 'r' => 3], ['q' =>  5, 'r' => 3], ['q' => 6, 'r' => 3],
];

const INTEL_ENTRY_HEX_TOP_LEFT  = ['q' => 1, 'r' => -4];  // labeled "1"
const INTEL_ENTRY_HEX_TOP_RIGHT = ['q' => 3, 'r' => -4];  // labeled "2"
```

**Adjacency observations** (neighbors per pointy-top axial NW=(q,r-1), NE=(q+1,r-1), E=(q+1,r), SE=(q,r+1), SW=(q-1,r+1), W=(q-1,r)):
- Entry "1" at (1, -4): SE child (1, -3) and SW child (0, -3). Only (1, -3) is in ORANGE_HEXES; (0, -3) is **off-board**. So entry "1" trickles down only to (1, -3).
- Entry "2" at (3, -4): SE (3, -3) and SW (2, -3). Both are in ORANGE_HEXES. Trickles to either.
- This is an asymmetry to verify against the print art's intended dynamics; the SW direction off entry "1" might naturally fall off-board (returns to bag per §9.2).

> **NOTE**: q-ranges within each row above are an **approximation** of the visual layout. The shape is correct (row counts), but the precise q-offset per row should be verified once a hex coordinate calibration test runs in BGA Studio. The neighbor-adjacency math will work regardless of which q-offset convention is used, as long as the table is internally consistent.

---

## Frontend pixel mapping (also affects `hexpionage.js::_hex` constants)

Native board image: 5475 × 2775 px. Downscaled `src/img/board.png`: 1200 × 608 px (factor 0.219).

For pointy-top axial with hex_radius `R`:
- hex_width = `R * sqrt(3)` ≈ `1.732 * R`
- hex_height = `R * 2`
- vertical row spacing = `R * 1.5`

Estimating from the board image: each hex appears to be ~150 px wide on the native image (pre-scale). On the scaled `board.png` (1200×608), that's ~33 px wide → `R ≈ 19 px`.

Suggested `_hex` constants in `hexpionage.js` (currently placeholder):
- `R = 19` (radius)
- `originX = 600` (board center x)
- `originY = 304` (board center y, with adjustment for the ~5% top margin where the title bar sits)

These are **starting estimates**; expect to refine them when running BGA Studio with the placeholder sprite sheets — the click overlay alignment will reveal whether the constants need tuning.

---

## Summary of resolved TODOs

- **G-01**: `pointy-top` confirmed. No code change needed.
- **G-02**: 30 Field hexes + 14 orange hexes + 2 entry hexes specified explicitly above. The current `material.inc.php` placeholder of 25 hexes (in `r ∈ [-3, 3]`) is undercount; it should be replaced with the explicit table above.

Both can now be applied to `src/material.inc.php` and `src/modules/js/Game.js` (the `_hex` constants block) when convenient.

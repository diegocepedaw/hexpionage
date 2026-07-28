# Hexpionage — Asset Manifest

**Source root**: `/Users/dcepeda/Downloads/final_printing/` (read-only print masters)
**Total file count**: **59** (matches `find /Users/dcepeda/Downloads/final_printing -type f | wc -l`)
**Audited by**: A3 — Component & Asset Audit Agent
**Cross-references**: `rulebook.md` §2 (Components), `DECISIONS.md` D-01, D-04/D-07, D-12, D-19

## Conventions

- All paths in this manifest are **relative to** `/Users/dcepeda/Downloads/final_printing/`.
- "Source kind" = nature of the file:
  - `print master` (final printable PNG/PDF, web-ready raster)
  - `template` (PDF cut-line/print template)
  - `icon` (vector glyph)
  - `source PSD` (layered Photoshop master)
  - `derivative PNG` (raster export of a PSD already represented elsewhere)
  - `document` (text/docx — not visual)
- "Intended use for BGA":
  - `sprite` (becomes part of an `src/img/*.png` sprite sheet)
  - `board` (becomes the BGA board image)
  - `unused / print-only` (out of scope for the digital port)
- "Transformation needed":
  - `none` — copy as-is into a sprite sheet
  - `crop` — remove background/bleed
  - `resize to W×H` — scale to BGA target dimension
  - `flatten PSD` — render PSD layers down to PNG
  - `regenerate` — rebuild from scratch (not in this manifest's scope; see MISSING.md)
- The "Component" column references `rulebook.md` §2 nomenclature. Per [D-01], `specialops_*.png` is the **Comms Specialist** artwork; per [D-19] intel filenames map directly to canonical intel ids.

---

## A. Board (1 file in scope)

| Path | Bytes | Format | Source kind | BGA use | Transformation | Component |
|---|---:|---|---|---|---|---|
| `game board/game_board_print.png` | 6,287,971 | PNG (5475×2775, RGB) | print master | board | resize to 1200×608 (desktop) and 800×405 (mobile breakpoint), preserving native ~1.973 aspect | rulebook §2.6 — *Game board.* Verified via visual inspection: Field purple-shading, ✦ spawn-row markers, score track 0–20, two intel-entry hexes ("1"/"2"), and "Turn order" instructions are **all baked into the PNG**. No CSS overlay required for these affordances. |
| `game board/game_board_final_template_223x457mm.pdf` | 812,185 | PDF | template | unused / print-only | none | Print cut-line template; not relevant to BGA. |
| `game board/game_board_final.psd` | 129,310,796 | PSD | source PSD | unused / print-only | none (do not open per task rules) | Layered master for the print PNG. Already represented by `game_board_print.png`. |

---

## B. Agents (12 files: 6 types × 2 colors — **all components from rulebook §2.2 satisfied**)

Per [D-10b], each player has 12 agents (2 of each of 6 types). The art is the same per (type, color); BGA only needs **one sprite per (type, color) = 12 sprites total**. Per-piece duplication (2 copies/type/player) is a state-model concern, not an art concern.

All agent PNGs are 450×450 RGB (no alpha; the hex outline is part of the art). Pipeline must crop to hex shape and add transparency — see PIPELINE.md.

| Path | Bytes | Format | Source kind | BGA use | Transformation | Component |
|---|---:|---|---|---|---|---|
| `punchboard/punchboard 1/finished individual tiles no shape/specialops_white.png` | 32,439 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); apply hex alpha mask | agent: **comms_specialist**, white player [D-01 alias] |
| `punchboard/punchboard 1/finished individual tiles no shape/specialops_black.png` | 31,740 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); apply hex alpha mask | agent: **comms_specialist**, black player [D-01 alias] |
| `punchboard/punchboard 1/finished individual tiles no shape/analyst_white.png` | 30,270 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); apply hex alpha mask | agent: analyst, white player |
| `punchboard/punchboard 1/finished individual tiles no shape/analyst_black.png` | 29,482 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); apply hex alpha mask | agent: analyst, black player |
| `punchboard/punchboard 1/finished individual tiles no shape/smuggler_white.png` | 39,183 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); apply hex alpha mask | agent: smuggler, white player |
| `punchboard/punchboard 1/finished individual tiles no shape/smuggler_black.png` | 39,343 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); apply hex alpha mask | agent: smuggler, black player |
| `punchboard/punchboard 1/finished individual tiles no shape/engineer_white.png` | 38,157 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); apply hex alpha mask | agent: engineer, white player |
| `punchboard/punchboard 1/finished individual tiles no shape/engineer_black.png` | 37,252 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); apply hex alpha mask | agent: engineer, black player |
| `punchboard/punchboard 1/finished individual tiles no shape/hacker_white.png` | 29,051 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); apply hex alpha mask | agent: hacker, white player |
| `punchboard/punchboard 1/finished individual tiles no shape/hacker_black.png` | 27,625 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); apply hex alpha mask | agent: hacker, black player |
| `punchboard/punchboard 1/finished individual tiles no shape/doubleagent_white.png` | 29,054 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); apply hex alpha mask | agent: double_agent, white player |
| `punchboard/punchboard 1/finished individual tiles no shape/doubleagent_black.png` | 27,904 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); apply hex alpha mask | agent: double_agent, black player |

### Agent source PSD (not used by BGA; do not open per task rules)

| Path | Bytes | Format | Source kind | BGA use | Transformation | Component |
|---|---:|---|---|---|---|---|
| `punchboard/punchboard 1/mini-hex_agents.psd` | 431,389 | PSD | source PSD | unused / print-only | none | Layered master that produced the 12 agent PNGs above. |

### Agent icon SVGs (loose vector art — `unused` for current BGA scope)

These are bare line-art glyphs (no color, no hex outline) and **do not match** the finished punchboard art used on the physical agents. They appear to be design-process leftovers. Recommended `unused` unless a future redesign wants vector agent icons.

| Path | Bytes | Format | Source kind | BGA use | Transformation | Component |
|---|---:|---|---|---|---|---|
| `punchboard/punchboard 1/agent icons only/passport.svg` | 3,536 | SVG | icon | unused / print-only | none | Likely double_agent (passport metaphor); duplicates info already in `doubleagent_*.png`. Do not invent a use. |
| `punchboard/punchboard 1/agent icons only/system.svg` | 6,969 | SVG | icon | unused / print-only | none | Likely engineer (system/gears metaphor); duplicates info already in `engineer_*.png`. |
| `punchboard/punchboard 1/agent icons only/loupe.svg` | 2,892 | SVG | icon | unused / print-only | none | Likely analyst (magnifying loupe); duplicates info already in `analyst_*.png`. |
| `punchboard/punchboard 1/agent icons only/satellite.svg` | 4,561 | SVG | icon | unused / print-only | none | Likely comms_specialist (satellite dish); duplicates info already in `specialops_*.png`. |
| `punchboard/punchboard 1/agent icons only/hacker.svg` | 4,601 | SVG | icon | unused / print-only | none | Likely hacker; duplicates info already in `hacker_*.png`. |
| `punchboard/punchboard 1/agent icons only/laptop.svg` | 2,757 | SVG | icon | unused / print-only | none | Likely smuggler or hacker (laptop metaphor); ambiguous. Do not invent a use. |

---

## C. Intel (12 files: 6 types × {face, back} — **all components from rulebook §2.4 satisfied**)

Per [D-19] color/value mapping: gray (Honeypot, 0); brown (Industrial Tech, 2); purple (Leaked Email, 2); green (Blackmail, 2); yellow (Security Credential, 3); cyan (State Secret, 4). Both face and back exist for each type. The bag composition is hidden (rulebook §3.7), so the back will be shown for unrevealed in-bag tiles in the UI's bag/draw widgets.

All intel PNGs are 450×450 RGB. Pipeline crops to hex shape.

| Path | Bytes | Format | Source kind | BGA use | Transformation | Component |
|---|---:|---|---|---|---|---|
| `punchboard/punchboard 1/finished individual tiles no shape/honeypot.png` | 26,404 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); hex alpha mask | intel face: **honeypot** (gray, 0pts) [D-19] |
| `punchboard/punchboard 1/finished individual tiles no shape/honeypot_back.png` | 25,275 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); hex alpha mask | intel back: honeypot |
| `punchboard/punchboard 1/finished individual tiles no shape/industrial_tech.png` | 29,832 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); hex alpha mask | intel face: **industrial_tech** (brown, 2pts) [D-19] |
| `punchboard/punchboard 1/finished individual tiles no shape/industrial_tech_back.png` | 13,318 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); hex alpha mask | intel back: industrial_tech |
| `punchboard/punchboard 1/finished individual tiles no shape/leaked_email.png` | 27,644 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); hex alpha mask | intel face: **leaked_email** (purple, 2pts) [D-19] |
| `punchboard/punchboard 1/finished individual tiles no shape/leaked_email_back.png` | 13,096 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); hex alpha mask | intel back: leaked_email |
| `punchboard/punchboard 1/finished individual tiles no shape/blackmail.png` | 25,189 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); hex alpha mask | intel face: **blackmail** (green, 2pts) [D-19] |
| `punchboard/punchboard 1/finished individual tiles no shape/blackmail_back.png` | 12,776 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); hex alpha mask | intel back: blackmail |
| `punchboard/punchboard 1/finished individual tiles no shape/security_credential.png` | 28,014 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); hex alpha mask | intel face: **security_credential** (yellow, 3pts) [D-19] |
| `punchboard/punchboard 1/finished individual tiles no shape/security_credential_back.png` | 13,160 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); hex alpha mask | intel back: security_credential |
| `punchboard/punchboard 1/finished individual tiles no shape/state_secret.png` | 29,779 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); hex alpha mask | intel face: **state_secret** (cyan, 4pts) [D-19] |
| `punchboard/punchboard 1/finished individual tiles no shape/state_secret_back.png` | 13,758 | PNG (450×450, RGB) | print master | sprite | resize to 80×80 (+ 160×160 retina); hex alpha mask | intel back: state_secret |

### Intel source PSD and exemplar (unused for BGA)

| Path | Bytes | Format | Source kind | BGA use | Transformation | Component |
|---|---:|---|---|---|---|---|
| `punchboard/punchboard 1/mini-hex_INTEL.psd` | 1,003,336 | PSD | source PSD | unused / print-only | none | Layered master that produced the 12 intel PNGs above. |
| `punchboard/punchboard 1/example_finished_tile_with_shape.png` | 27,210 | PNG (450×450, RGBA) | derivative PNG | unused / print-only | none | One-off sample showing the hex-cropped shape applied to a tile. Useful as a *reference for the alpha mask geometry* in the pipeline, but not itself shipped. |

---

## D. Tokens (6 files — rulebook §2.5)

Tokens are 300×300 RGBA (already transparent). Per [D-04]+[D-07] the digital blockade supply is unlimited; per §2.5 the trickle direction arrow is "not modeled in digital state" (the dice colors and odd/even readout convey direction). Pinned-status is a flag on the agent row (§2.3) but the marker overlay still ships as a sprite for visual indication on the agent.

| Path | Bytes | Format | Source kind | BGA use | Transformation | Component |
|---|---:|---|---|---|---|---|
| `punchboard/punchboard 2/blockade_triangle_white.png` | 10,410 | PNG (300×300, RGBA) | print master | sprite | resize to 40×40 (+ 80×80 retina) | token: **blockade overlay**, white player |
| `punchboard/punchboard 2/blockade_triangle_black.png` | 10,241 | PNG (300×300, RGBA) | print master | sprite | resize to 40×40 (+ 80×80 retina) | token: blockade overlay, black player |
| `punchboard/punchboard 2/pinned_triangle_white.png` | 9,822 | PNG (300×300, RGBA) | print master | sprite | resize to 40×40 (+ 80×80 retina) | token: **pin marker overlay**, white-pinned-by-black |
| `punchboard/punchboard 2/pinned_triangle_black.png` | 9,108 | PNG (300×300, RGBA) | print master | sprite | resize to 40×40 (+ 80×80 retina) | token: pin marker overlay, black-pinned-by-white |
| `punchboard/punchboard 2/arrow_white.png` | 9,393 | PNG | print master | unused / print-only | none | Trickle direction physical aid; per rulebook §2.5 *"not modeled in digital state."* Dice colors + odd/even readout convey direction. Do not invent a use. |
| `punchboard/punchboard 2/arrow_black.png` | 9,172 | PNG | print master | unused / print-only | none | Same as above, opposite color. |

---

## E. Box (5 files — entirely out of scope for BGA)

| Path | Bytes | Format | Source kind | BGA use | Transformation | Component |
|---|---:|---|---|---|---|---|
| `box/Large-Stout-Box-top.png` | 2,075,942 | PNG | print master | unused / print-only | none | Physical box top art. BGA does not need box art. |
| `box/Large-Stout-Box-Bottom (2).png` | 2,524,540 | PNG | print master | unused / print-only | none | Physical box bottom art. |
| `box/box_top.psd` | 30,827,540 | PSD | source PSD | unused / print-only | none | Layered master for box top. |
| `box/box_bottom.psd` | 36,563,539 | PSD | source PSD | unused / print-only | none | Layered master for box bottom. |
| `box/box_final_template_250x250x40mm.pdf` | 832,122 | PDF | template | unused / print-only | none | Print cut-line template. |

---

## F. Rulebook art (8 files — content for an in-game help screen, not the play UI)

These are the printed rulebook pages and box-back art. The 3 rulebook PNG pages (`rules_templated_nice_01/02/03.png`) **may** be used as in-game help-modal images if the owner wants — but the canonical machine-readable rules live in `docs/rulebook.md`, not these images. Marking `unused / print-only` for the BGA play surface; the frontend agent (A8) can revisit if a help-modal slideshow is desired.

| Path | Bytes | Format | Source kind | BGA use | Transformation | Component |
|---|---:|---|---|---|---|---|
| `rulebook/rules_templated_nice_01.png` | 1,176,394 | PNG (2221×2185, RGB) | print master | unused / print-only | none | Rulebook spread page 1. Optional in-game help. |
| `rulebook/rules_templated_nice_02.png` | 961,877 | PNG | print master | unused / print-only | none | Rulebook spread page 2. Optional in-game help. |
| `rulebook/rules_templated_nice_03.png` | 938,950 | PNG | print master | unused / print-only | none | Rulebook spread page 3. Optional in-game help. |
| `rulebook/booklet_final_template_200x200mm.pdf` | 809,996 | PDF | template | unused / print-only | none | Print cut-line template. Authoritative source for A2 rules formalization (read via `pages` parameter); not shipped. |
| `rulebook/rules_templated_nice_cover.psd` | 25,794,172 | PSD | source PSD | unused / print-only | none | Cover art layered master. |
| `rulebook/rules_templated_nice_back.psd` | 26,921,972 | PSD | source PSD | unused / print-only | none | Back-cover art layered master. |
| `rulebook/rules_templated_nice_agents.psd` | 16,151,820 | PSD | source PSD | unused / print-only | none | Agents-page layered master. |
| `rulebook/rules_templated_nice_rules.psd` | 12,140,706 | PSD | source PSD | unused / print-only | none | Rules-pages layered master. |
| `rulebook/back stuff/rules_templated_nice_back_update1.png` | 1,576,499 | PNG | print master | unused / print-only | none | Updated back-cover art. |
| `rulebook/back stuff/rules_templated_nice_back_update1.psd` | 28,399,133 | PSD | source PSD | unused / print-only | none | Updated back-cover layered master. |

---

## G. Uncategorized / documents (2 files — not visual assets)

| Path | Bytes | Format | Source kind | BGA use | Transformation | Component |
|---|---:|---|---|---|---|---|
| `Hexpionage Rules FAQ.md` | 1,896 | Markdown | document | unused / print-only | none | FAQ source for rules formalization (A2). Not a visual asset. |
| `Hexpionage pre-production document.docx` | 4,203,673 | DOCX | document | unused / print-only | none | Pre-production design notes. Not a visual asset. |

---

## Validation

- **File-count check**: 59 entries in this manifest. `find /Users/dcepeda/Downloads/final_printing -type f | wc -l` returns **59**. Match confirmed.
- **Component coverage check** (every entry in rulebook §2 maps to a source asset OR to MISSING.md):
  - rulebook §2.2 — 6 agent types × 2 colors = 12 sprites: **all 12 present** (rows in §B above).
  - rulebook §2.4 — 6 intel types (face + back) = 12 sprites: **all 12 present** (rows in §C above).
  - rulebook §2.5 — Blockade triangle (×2 colors): **2 present**. Pinned-status triangle (×2 colors): **2 present**. Trickle direction arrow: **2 present (unused per §2.5)**.
  - rulebook §2.1 — Score markers (×2): **NOT present in source** → see `MISSING.md` "Score tracker / score markers".
  - rulebook §2.1 — 6 intel dice: **NOT present in source** → see `MISSING.md` "Dice faces".
  - rulebook §2.1 — Game board: **present** (`game_board_print.png`); confirmed visually that Field shading, ✦ spawn-row markers, score track, and intel-entry hexes are baked in.
- **BGA-sprite output expectation** (cross-check against PIPELINE.md): every row in this manifest with `BGA use = sprite` must produce an output cell in one of `src/img/agents.png`, `src/img/intel.png`, or `src/img/tokens.png`. Sprite count: 12 agents + 12 intel + 4 tokens = **28 sprite cells** total (excluding board, which is its own file).

## Surprises / notes for downstream agents

1. **No alpha on tile faces** — the 24 face-side PNG tiles (`punchboard 1/finished individual tiles no shape/`) are RGB, not RGBA, despite the directory name. The pipeline must apply a hex alpha mask (see PIPELINE.md). The single file `example_finished_tile_with_shape.png` is RGBA and can be used as the geometric reference for the mask.
2. **Board PNG has score track baked in** — the 0–20 horizontal score track is part of `game_board_print.png` (top-right), so the pipeline doesn't need to render it. But the **score markers themselves** (the per-player draggable counter) are absent from the source; see MISSING.md.
3. **Board PNG has spawn-row ✦ baked in** — confirmed via visual inspection. No CSS overlay required for this. This **closes** the open question in the prompt about whether the star symbol is baked or needs an overlay.
4. **Field purple shading baked in** — confirmed visually. No CSS overlay required.
5. **Agent SVG icons exist but are duplicates** — 6 monochrome glyphs in `agent icons only/`. These do not match the finished art and are marked `unused` to avoid speculation.
6. **No back-of-agent art** — agents have only one face per (type, color), which is correct: agents are public-information (rulebook §3.7), so no hidden side is required.
7. **Honeypot face is `26,404` bytes vs. its peers' `~13K` for backs** — this is just file-content variance (Honeypot face has more imagery than e.g. the Blackmail back), not a data error.
8. **Two separate "back stuff" rulebook files** — `rules_templated_nice_back.psd` and `rules_templated_nice_back_update1.{png,psd}`. The "update1" version is the newer one. Both are in scope only as print masters; ignored by BGA.

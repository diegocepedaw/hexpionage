#!/usr/bin/env python3
"""Generate placeholder sprite sheets for Hexpionage BGA frontend.

Produces 4 PNGs into src/img/ that match the layout locked by
design/PIPELINE.md and src/hexpionage.css. Cells are simple colored
rectangles with text labels — visually crude, but the layout/dimensions
are correct so the BGA frontend renders without missing-image errors.

Replace any of the 4 outputs with real art (same dimensions + layout)
and the CSS will pick it up with no code changes.

Run: python3 design/build_placeholders.py
"""

from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

REPO = Path(__file__).resolve().parent.parent
# The print masters live in the repo (design/masters/, Git LFS) — see ONBOARDING §6.
# This previously pointed at ~/Downloads/final_printing/, which meant that on any
# machine without that folder build_board() silently fell through to its solid-colour
# fallback and overwrote the real board art. Verified byte-identical to the old path.
SRC_BOARD = REPO / "design" / "masters" / "board" / "game_board_print.png"
OUT = REPO / "src" / "img"
OUT.mkdir(parents=True, exist_ok=True)


def get_font(size: int):
    """Best-effort: try common system fonts; fall back to default bitmap."""
    candidates = [
        "/System/Library/Fonts/Helvetica.ttc",
        "/System/Library/Fonts/Supplemental/Arial.ttf",
        "/Library/Fonts/Arial.ttf",
    ]
    for path in candidates:
        try:
            return ImageFont.truetype(path, size)
        except (OSError, IOError):
            continue
    return ImageFont.load_default()


def draw_cell(img, x, y, w, h, fill, label, text_color=(0, 0, 0), font=None):
    draw = ImageDraw.Draw(img)
    draw.rectangle([x, y, x + w - 1, y + h - 1], fill=fill, outline=(0, 0, 0))
    if font is None:
        font = get_font(max(8, h // 8))
    # Text-block centering
    bbox = draw.textbbox((0, 0), label, font=font, align="center")
    tw = bbox[2] - bbox[0]
    th = bbox[3] - bbox[1]
    draw.multiline_text(
        (x + (w - tw) / 2, y + (h - th) / 2 - bbox[1]),
        label,
        fill=text_color,
        font=font,
        align="center",
    )


# Canonical colors per D-19
INTEL_COLORS = {
    "honeypot": (160, 160, 160),               # gray
    "industrial_tech": (139, 90, 43),          # brown
    "leaked_email": (148, 0, 211),             # purple
    "blackmail": (34, 139, 34),                # green
    "security_credential": (255, 215, 0),      # yellow
    "state_secret": (0, 191, 255),             # cyan
}

# Approximate accent per agent type (just for readability — not canonical)
AGENT_ACCENT = {
    "comms_specialist": (90, 160, 90),
    "analyst": (240, 140, 50),
    "smuggler": (140, 80, 180),
    "engineer": (60, 130, 90),
    "hacker": (200, 60, 60),
    "double_agent": (80, 80, 80),
}

AGENT_ROWS = ["comms_specialist", "analyst", "smuggler", "engineer", "hacker", "double_agent"]
INTEL_ROWS = ["honeypot", "industrial_tech", "leaked_email", "blackmail",
              "security_credential", "state_secret"]
INTEL_VALUES = {
    "honeypot": 0, "industrial_tech": 2, "leaked_email": 2,
    "blackmail": 2, "security_credential": 3, "state_secret": 4,
}


def save_pair(img, name: str):
    """Write both the 1x sheet and its _2x retina twin.

    hexpionage.css serves the _2x sheets from a (min-resolution: 192dpi) media
    query with an explicit CSS-pixel background-size, so the retina file must be
    exactly double the pixel dimensions of the 1x sheet. The name avoids "@",
    which Subversion parses as a peg-revision separator, making any such file
    impossible to commit to BGA's SVN. Emitting only the 1x
    file makes every sprite 404 on a retina display, which renders agents, intel
    and tokens invisible. See design/PIPELINE.md, which specifies both sizes.
    """
    out = OUT / f"{name}.png"
    img.save(out)
    retina = img.resize((img.width * 2, img.height * 2), Image.NEAREST)
    retina.save(OUT / f"{name}_2x.png")
    return out


def build_agents():
    """160×480, 6 rows × 2 cols of 80×80. Col 0 = white, col 1 = black."""
    img = Image.new("RGBA", (160, 480), (255, 255, 255, 0))
    font = get_font(11)
    for row, agent in enumerate(AGENT_ROWS):
        accent = AGENT_ACCENT[agent]
        short = agent.replace("_", " ").upper().replace(" ", "\n", 1)[:24]
        # Col 0 — "white" — light fill
        light = tuple(min(255, c + 90) for c in accent)
        draw_cell(img, 0, row * 80, 80, 80, light, short, (0, 0, 0), font)
        # Col 1 — "black" — dark fill
        dark = tuple(max(0, c - 60) for c in accent)
        draw_cell(img, 80, row * 80, 80, 80, dark, short, (255, 255, 255), font)
    return save_pair(img, "agents")


def build_intel():
    """160×480, 6 rows × 2 cols of 80×80. Col 0 = face, col 1 = back."""
    img = Image.new("RGBA", (160, 480), (255, 255, 255, 0))
    font_face = get_font(10)
    font_back = get_font(14)
    for row, intel in enumerate(INTEL_ROWS):
        color = INTEL_COLORS[intel]
        # Face: full color, label + value
        face_label = f"{intel.replace('_', ' ').upper()}\n[{INTEL_VALUES[intel]}]"
        text_color = (0, 0, 0) if sum(color) > 380 else (255, 255, 255)
        draw_cell(img, 0, row * 80, 80, 80, color, face_label, text_color, font_face)
        # Back: lighter / desaturated, "BACK" label
        back = tuple(min(255, c + 60) for c in color)
        draw_cell(img, 80, row * 80, 80, 80, back, "BACK", (0, 0, 0), font_back)
    return save_pair(img, "intel")


def build_tokens():
    """80×80, 2 rows × 2 cols of 40×40. Row 0 blockade, row 1 pin. Col 0 white, col 1 black."""
    img = Image.new("RGBA", (80, 80), (255, 255, 255, 0))
    font = get_font(8)
    layout = [
        ("BLK\nW", (220, 220, 220)),
        ("BLK\nB", (50, 50, 50)),
        ("PIN\nW", (220, 220, 220)),
        ("PIN\nB", (50, 50, 50)),
    ]
    for i, (label, fill) in enumerate(layout):
        col = i % 2
        row = i // 2
        text = (0, 0, 0) if sum(fill) > 380 else (255, 255, 255)
        draw_cell(img, col * 40, row * 40, 40, 40, fill, label, text, font)
    return save_pair(img, "tokens")


def build_board():
    """Downscale the real board PNG to 1200×608. (This is genuinely the user's art —
    placeholder only in the sense that the asset audit pass may want a separate
    optimized version later.)"""
    if not SRC_BOARD.exists():
        # Fallback: solid placeholder rectangle.
        img = Image.new("RGB", (1200, 608), (180, 200, 180))
        draw = ImageDraw.Draw(img)
        draw.text((600, 300), "BOARD PLACEHOLDER", fill=(0, 0, 0),
                  font=get_font(40), anchor="mm")
        out = OUT / "board.png"
        img.save(out)
        return out
    src = Image.open(SRC_BOARD)
    src.thumbnail((1200, 1200), Image.LANCZOS)
    # Pad/crop to exact 1200×608 if needed (preserve aspect, center)
    target = Image.new("RGB", (1200, 608), (255, 255, 255))
    paste_x = (1200 - src.width) // 2
    paste_y = (608 - src.height) // 2
    target.paste(src, (paste_x, paste_y))
    out = OUT / "board.png"
    target.save(out, "PNG", optimize=True)
    return out


def main():
    print(f"Writing into {OUT}")
    for f in (build_agents(), build_intel(), build_tokens(), build_board()):
        size = f.stat().st_size
        print(f"  {f.relative_to(REPO)}  {size:>8} bytes")


if __name__ == "__main__":
    main()

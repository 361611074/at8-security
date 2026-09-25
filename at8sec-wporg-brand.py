#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Draw icon and banner proposals for the WordPress.org listing.

Three directions, each in the sizes the directory asks for:

    icon-128x128.png / icon-256x256.png
    banner-772x250.png / banner-1544x500.png

Everything is drawn from the plugin's own visual identity rather than invented:
the accent is WordPress admin blue #2271b1, which is what the settings screens
already use, and the mark is a shield, which is what the plugin's menu entry
already is (dashicons-shield-alt). The typeface is Segoe UI, the interface font
on the platform the plugin is developed on.

These are proposals. WordPress.org assets are brand decisions, so nothing here
is written into the plugin; the chosen set gets copied up to SVN /assets.

Usage:
    python at8sec-wporg-brand.py            # write every proposal
"""
import io
import math
import os
import sys

from PIL import Image, ImageDraw, ImageFont

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")

OUT = (r"E:\工作文件夹\常用插件及日常学习源码\Wordpress插件\0-自定义插件"
       r"\安全防御插件\wordpress-org-assets\proposals")

FONT_DIR = r"C:\Windows\Fonts"
SEGOE = os.path.join(FONT_DIR, "segoeui.ttf")
SEGOE_BOLD = os.path.join(FONT_DIR, "segoeuib.ttf")
SEGOE_LIGHT = os.path.join(FONT_DIR, "segoeuil.ttf")

BLUE = (34, 113, 177)          # #2271b1, the plugin's own accent
BLUE_DARK = (19, 80, 128)      # a shade for depth
INK = (29, 35, 39)             # #1d2327, WordPress admin ink
PAPER = (246, 247, 247)        # #f6f7f7, WordPress admin background
WHITE = (255, 255, 255)
MUTED = (100, 105, 112)        # #646970


def shield_points(x, y, w, h, steps=28):
    """Outline of a shield inside the given box, as a point list.

    A shield is a rounded-top rectangle whose sides sweep to a point, which is
    what the directory icon and the plugin's own menu glyph both read as. The
    sweep uses t**2.2 so the taper is gentle at first and sharp at the tip --
    a straight taper looks like a kite.
    """
    r = 0.13 * w
    pts = []

    # Top-left corner, 180 degrees round to 270.
    for i in range(steps + 1):
        a = math.pi + (math.pi / 2) * (i / steps)
        pts.append((x + r + r * math.cos(a), y + r + r * math.sin(a)))

    # Top-right corner, -90 degrees round to 0.
    for i in range(steps + 1):
        a = -math.pi / 2 + (math.pi / 2) * (i / steps)
        pts.append((x + w - r + r * math.cos(a), y + r + r * math.sin(a)))

    # Right side sweeping in to the tip.
    for i in range(steps + 1):
        t = i / steps
        pts.append((x + w - (w / 2) * (t ** 2.2), y + r + (h - r) * t))

    # Left side sweeping back out to the shoulder.
    for i in range(steps, -1, -1):
        t = i / steps
        pts.append((x + (w / 2) * (t ** 2.2), y + r + (h - r) * t))

    return pts


def rounded_square(size, radius_frac, fill):
    im = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    r = int(size * radius_frac)
    d.rounded_rectangle([0, 0, size - 1, size - 1], radius=r, fill=fill)
    return im


def draw_shield(draw, x, y, w, h, fill=None, outline=None, width=0):
    pts = shield_points(x, y, w, h)
    if fill:
        draw.polygon(pts, fill=fill)
    if outline:
        draw.line(pts + [pts[0]], fill=outline, width=width, joint="curve")


def font(path, size):
    return ImageFont.truetype(path, size)


def centred(draw, xy, text, fnt, fill):
    """Draw text centred on xy, returning its bounding box."""
    box = draw.textbbox((0, 0), text, font=fnt)
    w = box[2] - box[0]
    h = box[3] - box[1]
    draw.text((xy[0] - w / 2 - box[0], xy[1] - h / 2 - box[1]),
              text, font=fnt, fill=fill)
    return box


# ---------------------------------------------------------------- icon

def icon_a(size):
    """Solid blue tile, white shield."""
    im = rounded_square(size, 0.22, BLUE)
    d = ImageDraw.Draw(im)
    s = size * 0.54
    draw_shield(d, (size - s) / 2, (size - s) / 2, s, s, fill=WHITE)
    return im


def icon_b(size):
    """Dark tile, blue shield with a white check -- reads as 'protected'."""
    im = rounded_square(size, 0.22, INK)
    d = ImageDraw.Draw(im)
    s = size * 0.56
    x = (size - s) / 2
    y = (size - s) / 2
    draw_shield(d, x, y, s, s, fill=BLUE)
    # A check drawn as two strokes, sized to the shield's upper body.
    d.line([(x + s * 0.30, y + s * 0.42), (x + s * 0.45, y + s * 0.58)],
           fill=WHITE, width=max(2, int(size * 0.055)), joint="curve")
    d.line([(x + s * 0.45, y + s * 0.58), (x + s * 0.72, y + s * 0.26)],
           fill=WHITE, width=max(2, int(size * 0.055)), joint="curve")
    return im


def icon_c(size):
    """Light tile, blue outline shield with AT8 underneath."""
    im = rounded_square(size, 0.22, PAPER)
    d = ImageDraw.Draw(im)
    s = size * 0.46
    draw_shield(d, (size - s) / 2, size * 0.13, s, s,
                outline=BLUE, width=max(2, int(size * 0.055)))
    centred(d, (size / 2, size * 0.76), "AT8",
            font(SEGOE_BOLD, int(size * 0.20)), BLUE)
    return im


# -------------------------------------------------------------- banner

def banner_a(w, h):
    """Blue field, white type, shield on the right."""
    im = Image.new("RGB", (w, h), BLUE)
    d = ImageDraw.Draw(im)
    s = h * 0.62
    draw_shield(d, w - s - h * 0.16, (h - s) / 2, s, s, fill=WHITE)
    d.text((h * 0.14, h * 0.30), "At8 Security",
           font=font(SEGOE_BOLD, int(h * 0.20)), fill=WHITE)
    d.text((h * 0.14, h * 0.56), "Lightweight hardening for WordPress",
           font=font(SEGOE_LIGHT, int(h * 0.105)), fill=(214, 231, 245))
    return im


def banner_b(w, h):
    """Dark field with a blue glow band, blue shield."""
    im = Image.new("RGB", (w, h), INK)
    d = ImageDraw.Draw(im)
    for i in range(int(h * 0.9)):
        t = i / (h * 0.9)
        col = (int(INK[0] + (BLUE_DARK[0] - INK[0]) * (1 - t)),
               int(INK[1] + (BLUE_DARK[1] - INK[1]) * (1 - t)),
               int(INK[2] + (BLUE_DARK[2] - INK[2]) * (1 - t)))
        d.line([(0, h - i), (w, h - i)], fill=col)
    s = h * 0.62
    draw_shield(d, w - s - h * 0.16, (h - s) / 2, s, s, fill=BLUE)
    d.text((h * 0.14, h * 0.30), "At8 Security",
           font=font(SEGOE_BOLD, int(h * 0.20)), fill=WHITE)
    d.text((h * 0.14, h * 0.56), "Hardening, firewall, headers, audit log",
           font=font(SEGOE_LIGHT, int(h * 0.105)), fill=(160, 190, 215))
    return im


def banner_c(w, h):
    """Light field, blue type, outlined shield."""
    im = Image.new("RGB", (w, h), PAPER)
    d = ImageDraw.Draw(im)
    d.rectangle([0, 0, w - 1, int(h * 0.055)], fill=BLUE)
    s = h * 0.60
    draw_shield(d, w - s - h * 0.16, (h - s) / 2, s, s,
                outline=BLUE, width=max(3, int(h * 0.028)))
    d.text((h * 0.14, h * 0.28), "At8 Security",
           font=font(SEGOE_BOLD, int(h * 0.20)), fill=INK)
    d.text((h * 0.14, h * 0.54), "Lightweight hardening for WordPress",
           font=font(SEGOE_LIGHT, int(h * 0.105)), fill=MUTED)
    return im


VARIANTS = {
    "a-blue": (icon_a, banner_a),
    "b-midnight": (icon_b, banner_b),
    "c-light": (icon_c, banner_c),
}


def main():
    os.makedirs(OUT, exist_ok=True)
    written = []

    for name, (icon_fn, banner_fn) in VARIANTS.items():
        d = os.path.join(OUT, name)
        os.makedirs(d, exist_ok=True)

        big = icon_fn(256)
        big.save(os.path.join(d, "icon-256x256.png"))
        big.resize((128, 128), Image.LANCZOS).save(
            os.path.join(d, "icon-128x128.png"))

        wide = banner_fn(1544, 500)
        wide.save(os.path.join(d, "banner-1544x500.png"))
        wide.resize((772, 250), Image.LANCZOS).save(
            os.path.join(d, "banner-772x250.png"))

        written.append(name)
        print("  %-12s icon 256/128, banner 1544/772" % name)

    print("\n%d variant(s) in %s" % (len(written), OUT))
    return 0


if __name__ == "__main__":
    sys.exit(main())

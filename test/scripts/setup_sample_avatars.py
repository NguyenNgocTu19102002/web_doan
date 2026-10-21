#!/usr/bin/env python3
"""Tạo 5 ảnh đại diện mẫu cho môi trường test."""
import os
import math
from PIL import Image, ImageDraw, ImageFont

OUT_DIR = os.path.join(os.path.dirname(__file__), '..', 'uploads', 'avatars')
SIZE = 256

SAMPLES = [
    {'file': 'sample_avatar_1.png', 'bg': '#1565c0', 'accent': '#90caf9', 'label': 'HT 1'},
    {'file': 'sample_avatar_2.png', 'bg': '#2e7d32', 'accent': '#a5d6a7', 'label': 'HT 2'},
    {'file': 'sample_avatar_3.png', 'bg': '#c62828', 'accent': '#ef9a9a', 'label': 'HT 3'},
    {'file': 'sample_avatar_4.png', 'bg': '#f57f17', 'accent': '#ffe082', 'label': 'HT 4'},
    {'file': 'sample_avatar_5.png', 'bg': '#6a1b9a', 'accent': '#ce93d8', 'label': 'HT 5'},
]


def load_font(size):
    candidates = [
        'C:/Windows/Fonts/segoeui.ttf',
        'C:/Windows/Fonts/arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    ]
    for path in candidates:
        if os.path.isfile(path):
            try:
                return ImageFont.truetype(path, size)
            except OSError:
                pass
    return ImageFont.load_default()


def draw_avatar(spec):
    img = Image.new('RGBA', (SIZE, SIZE), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)

    # Nền tròn
    draw.ellipse((8, 8, SIZE - 8, SIZE - 8), fill=spec['bg'])

    # Vòng accent
    draw.ellipse((24, 24, SIZE - 24, SIZE - 24), outline=spec['accent'], width=6)

    # Silhouette đơn giản (đầu + vai)
    cx, cy = SIZE // 2, SIZE // 2 + 10
    draw.ellipse((cx - 42, cy - 78, cx + 42, cy + 6), fill=spec['accent'])
    draw.pieslice((cx - 72, cy - 10, cx + 72, cy + 110), 200, 340, fill=spec['accent'])

    # Chữ nhãn
    font = load_font(44)
    label = spec['label']
    bbox = draw.textbbox((0, 0), label, font=font)
    tw = bbox[2] - bbox[0]
    th = bbox[3] - bbox[1]
    tx = (SIZE - tw) // 2
    ty = SIZE - th - 28
    draw.text((tx + 2, ty + 2), label, fill='#00000055', font=font)
    draw.text((tx, ty), label, fill='#ffffff', font=font)

    return img


def main():
    os.makedirs(OUT_DIR, exist_ok=True)
    created = []
    for spec in SAMPLES:
        path = os.path.join(OUT_DIR, spec['file'])
        draw_avatar(spec).save(path, 'PNG', optimize=True)
        created.append(path)
        print('Created:', path)
    print('Done:', len(created), 'files')


if __name__ == '__main__':
    main()

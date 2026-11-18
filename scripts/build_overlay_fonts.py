#!/usr/bin/env python3
"""Builds the overlay editor's fonts from full font files.

  public/fonts/overlay/*.woff2    browsers; embedded into saved overlays
  resources/fonts/overlay/*.ttf   server; librsvg can't read WOFF2

Each font is subset to Latin + Latin-1 (English and Spanish), common
punctuation, € and ™: roughly 20-40 KB per face instead of 100 KB - 2 MB.
Variable fonts are pinned to static Regular/Bold(/Italic) instances.

Usage:  scripts/build_overlay_fonts.py ~/Downloads/Fonts ~/Downloads/MoreFonts
(each argument is a folder searched recursively by file name; OFL.txt /
LICENSE files next to a font are copied to public/fonts/overlay/licenses).
Needs:  pip install fonttools brotli
Keep CUSTOM_FONTS in resources/js/Composables/overlay/fonts.js in step.
"""
import shutil
import sys
from pathlib import Path

from fontTools import subset
from fontTools.ttLib import TTFont
from fontTools.varLib import instancer

ROOT = Path(__file__).resolve().parent.parent
WEB = ROOT / 'public/fonts/overlay'
SERVER = ROOT / 'resources/fonts/overlay'
UNICODES = '0020-007E,00A0-00FF,2010-2027,2030-203A,20AC,2122'

# output name -> (source file, family, bold, italic, pins) ; pins None = static
STATIC = [
    'AdventSans-Logo.otf', 'Italianno-Regular.ttf', 'LindenHill-Regular.ttf', 'LindenHill-Italic.ttf',
    'PlayfairDisplaySC-Regular.ttf', 'PlayfairDisplaySC-Italic.ttf', 'PlayfairDisplaySC-Bold.ttf',
    'PlayfairDisplaySC-BoldItalic.ttf', 'PlayfairDisplaySC-Black.ttf', 'PlayfairDisplaySC-BlackItalic.ttf',
    'Waterfall-Regular.ttf', 'BeauRivage-Regular.ttf', 'league-gothic.ttf',
]
# family, output prefix, upright source, italic source, extra pins
VARIABLE = [
    ('Inter', 'Inter', 'Inter-VariableFont_opsz,wght.ttf', 'Inter-Italic-VariableFont_opsz,wght.ttf', {'opsz': 14}),
    ('Noto Serif', 'NotoSerif', 'NotoSerif-VariableFont_wdth_wght.ttf', 'NotoSerif-Italic-VariableFont_wdth_wght.ttf', {'wdth': 100}),
    ('Cormorant Garamond', 'CormorantGaramond', 'CormorantGaramond-Variable.ttf', 'CormorantGaramond-Italic-Variable.ttf', {}),
]


def find(folders, name):
    for folder in folders:
        hit = next(Path(folder).expanduser().rglob(name), None)
        if hit:
            return hit
    sys.exit(f'not found: {name}')


def do_subset(font, stem, ext):
    opts = subset.Options()
    opts.layout_features = ['kern', 'liga', 'calt', 'swsh', 'salt']
    opts.notdef_outline = True
    opts.name_IDs = ['*']
    unicodes = subset.parse_unicodes(UNICODES)
    sub = subset.Subsetter(opts)
    sub.populate(unicodes=unicodes)
    sub.subset(font)
    font.flavor = None
    font.save(SERVER / f'{stem}.{ext}')
    font.flavor = 'woff2'
    font.save(WEB / f'{stem}.woff2')


def restyle(font, family, bold, italic):
    """Give an instanced face plain RIBBI naming so fontconfig and browsers agree."""
    style = ' '.join(filter(None, ['Bold' if bold else '', 'Italic' if italic else ''])) or 'Regular'
    name = font['name']
    for rec in list(name.names):
        if rec.nameID in (16, 17, 21, 22, 25):
            name.names.remove(rec)
    for nid, value in ((1, family), (2, style), (4, f'{family} {style}'.replace(' Regular', '')),
                       (6, f"{family.replace(' ', '')}-{style.replace(' ', '')}")):
        name.setName(value, nid, 3, 1, 0x409)
        name.setName(value, nid, 1, 0, 0)
    os2 = font['OS/2']
    os2.usWeightClass = 700 if bold else 400
    sel = os2.fsSelection & ~(1 << 0 | 1 << 5 | 1 << 6)
    sel |= (1 << 0 if italic else 0) | (1 << 5 if bold else 0) | (1 << 6 if not bold and not italic else 0)
    os2.fsSelection = sel
    font['head'].macStyle = (1 if bold else 0) | (2 if italic else 0)


def main(folders):
    WEB.mkdir(parents=True, exist_ok=True)
    SERVER.mkdir(parents=True, exist_ok=True)
    (WEB / 'licenses').mkdir(exist_ok=True)
    licenses = set()

    for name in STATIC:
        src = find(folders, name)
        do_subset(TTFont(src), src.stem if name != 'league-gothic.ttf' else 'LeagueGothic-Regular', src.suffix[1:])
        licenses.add(src.parent)

    for family, prefix, upright, italic_src, pins in VARIABLE:
        for italic, fname in ((False, upright), (True, italic_src)):
            src = find(folders, fname)
            licenses.add(src.parent)
            for bold in (False, True):
                font = instancer.instantiateVariableFont(TTFont(src), {'wght': 700 if bold else 400, **pins})
                restyle(font, family, bold, italic)
                stem = f"{prefix}-{'Bold' if bold else ''}{'Italic' if italic else ''}" or f'{prefix}-Regular'
                if stem.endswith('-'):
                    stem += 'Regular'
                do_subset(font, stem, 'ttf')

    for folder in licenses:
        for lic in list(folder.glob('OFL.txt')) + list(folder.glob('LICENSE')):
            shutil.copy(lic, WEB / 'licenses' / f'{lic.stem}-{folder.name}.txt')


if __name__ == '__main__':
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    main(sys.argv[1:])

#!/usr/bin/env python3
"""Build the glyf-based CJK font used by Dompdf and validate its coverage."""

from pathlib import Path
import sys

from fontTools.merge import Merger
from fontTools.ttLib import TTFont
from fontTools.ttLib.scaleUpem import scale_upem


SOURCE_FONTS = (
    Path("/usr/share/fonts/truetype/arphic-gbsn00lp/gbsn00lp.ttf"),
    Path("/usr/share/fonts/truetype/unfonts-core/UnBatang.ttf"),
)
REQUIRED_CHARACTERS = "简体中文业务组客户代理商结算提成한글견적서정산수수료₩¥$%123,456.78GN-System"


def main() -> None:
    output = Path(sys.argv[1]) if len(sys.argv) > 1 else Path("/tmp/GNSystemCJK.ttf")
    fonts = [TTFont(path) for path in SOURCE_FONTS]
    target_upem = fonts[0]["head"].unitsPerEm

    for font in fonts[1:]:
        scale_upem(font, target_upem)

    normalized_paths = [Path("/tmp/gn-cjk-chinese.ttf"), Path("/tmp/gn-cjk-korean.ttf")]
    for font, path in zip(fonts, normalized_paths):
        font.save(path)

    font = Merger().merge([str(path) for path in normalized_paths])
    cmap = font.getBestCmap()
    if not all(ord(char) in cmap for char in REQUIRED_CHARACTERS):
        raise RuntimeError("CJK font is missing a required glyph")
    if "glyf" not in font or "CFF " in font:
        raise RuntimeError("CJK font must contain TrueType glyf outlines")

    output.parent.mkdir(parents=True, exist_ok=True)
    font.save(output)


if __name__ == "__main__":
    main()

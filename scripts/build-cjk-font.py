#!/usr/bin/env python3
"""Build regular and bold glyf-based CJK fonts used by Dompdf."""

from pathlib import Path
import sys

from fontTools.merge import Merger
from fontTools.pens.recordingPen import RecordingPen
from fontTools.ttLib import TTFont
from fontTools.ttLib.scaleUpem import scale_upem


REGULAR_SOURCE_FONTS = (
    Path("/usr/share/fonts/truetype/arphic-gbsn00lp/gbsn00lp.ttf"),
    Path("/usr/share/fonts/truetype/unfonts-core/UnBatang.ttf"),
)
BOLD_SOURCE_FONTS = (
    Path("/usr/share/fonts/truetype/unfonts-core/UnBatangBold.ttf"),
    Path("/usr/share/fonts/truetype/arphic-gbsn00lp/gbsn00lp.ttf"),
)
REQUIRED_CHARACTERS = "简体中文业务组客户代理商结算提成한글견적서정산수수료₩¥$%123,456.78GN-System"


def build(source_paths: tuple[Path, ...], output: Path, weight: int) -> TTFont:
    missing = [str(path) for path in source_paths if not path.is_file()]
    if missing:
        raise FileNotFoundError("Missing source font(s): "+", ".join(missing))

    fonts = [TTFont(path) for path in source_paths]
    target_upem = fonts[0]["head"].unitsPerEm

    for font in fonts[1:]:
        scale_upem(font, target_upem)

    normalized_paths = [Path("/tmp/gn-cjk-source-"+str(index)+".ttf") for index in range(len(fonts))]
    for font, path in zip(fonts, normalized_paths):
        font.save(path)

    font = Merger().merge([str(path) for path in normalized_paths])
    font["OS/2"].usWeightClass = weight
    if weight == 400:
        font["OS/2"].fsSelection = (font["OS/2"].fsSelection | (1 << 6)) & ~(1 << 5)
    else:
        font["OS/2"].fsSelection = (font["OS/2"].fsSelection | (1 << 5)) & ~(1 << 6)
    font["head"].macStyle = 0 if weight == 400 else 1
    cmap = font.getBestCmap()
    if not all(ord(char) in cmap for char in REQUIRED_CHARACTERS):
        raise RuntimeError("CJK font is missing a required glyph")
    if "glyf" not in font or "CFF " in font:
        raise RuntimeError("CJK font must contain TrueType glyf outlines")

    output.parent.mkdir(parents=True, exist_ok=True)
    font.save(output)

    return font


def glyph_signature(font: TTFont, character: str):
    glyph_name = font.getBestCmap().get(ord(character))
    if glyph_name is None:
        return None
    pen = RecordingPen()
    font.getGlyphSet()[glyph_name].draw(pen)

    return (font["hmtx"].metrics[glyph_name], tuple(pen.value))


def main() -> None:
    regular_output = Path(sys.argv[1]) if len(sys.argv) > 1 else Path("/tmp/GNSystemCJK-Regular.ttf")
    bold_output = Path(sys.argv[2]) if len(sys.argv) > 2 else regular_output.with_name("GNSystemCJK-Bold.ttf")
    regular = build(REGULAR_SOURCE_FONTS, regular_output, 400)
    bold = build(BOLD_SOURCE_FONTS, bold_output, 700)
    if regular_output.read_bytes() == bold_output.read_bytes():
        raise RuntimeError("Regular and bold CJK fonts must be different files")
    if not any(
        glyph_signature(regular, character) != glyph_signature(bold, character)
        for character in REQUIRED_CHARACTERS
    ):
        raise RuntimeError("Regular and bold CJK fonts must contain different glyph outlines")


if __name__ == "__main__":
    main()

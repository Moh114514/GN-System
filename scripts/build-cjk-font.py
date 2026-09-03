#!/usr/bin/env python3
"""Build regular and bold glyf-based fonts used by Dompdf."""

from pathlib import Path
from copy import deepcopy
import sys

from fontTools.pens.cu2quPen import Cu2QuPen
from fontTools.pens.recordingPen import RecordingPen
from fontTools.pens.ttGlyphPen import TTGlyphPen
from fontTools.ttLib import TTCollection, TTFont, newTable
from fontTools.ttLib.scaleUpem import scale_upem


SourceFont = tuple[Path, str]

REGULAR_SOURCE_FONTS: tuple[SourceFont, ...] = (
    (Path("/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"), "Noto Sans CJK SC"),
    (Path("/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"), "Noto Sans CJK KR"),
)
BOLD_SOURCE_FONTS: tuple[SourceFont, ...] = (
    (Path("/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"), "Noto Sans CJK SC"),
    (Path("/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"), "Noto Sans CJK KR"),
)
REQUIRED_CHARACTERS = "简体中文业务组客户代理商结算提成한글견적서정산수수료ABC₩¥$%123,456.78KRWGN-System"


def load_source_font(source: SourceFont) -> TTFont:
    path, family_name = source
    if not path.is_file():
        raise FileNotFoundError(f"Missing source font: {path}")

    if path.suffix.lower() != ".ttc":
        return TTFont(path)

    collection = TTCollection(path)
    for font in collection.fonts:
        if font["name"].getDebugName(1) == family_name:
            return font

    available = ", ".join(font["name"].getDebugName(1) or "<unnamed>" for font in collection.fonts)
    raise RuntimeError(f"Missing font face {family_name!r} in {path}; available faces: {available}")


def set_name(font: TTFont, name_id: int, value: str) -> None:
    for record in list(font["name"].names):
        if record.nameID == name_id:
            font["name"].setName(value, name_id, record.platformID, record.platEncID, record.langID)
    font["name"].setName(value, name_id, 3, 1, 0x409)
    font["name"].setName(value, name_id, 1, 0, 0)


def normalize_names(font: TTFont, weight: int) -> None:
    style = "Regular" if weight == 400 else "Bold"
    set_name(font, 1, "GN System Sans")
    set_name(font, 2, style)
    set_name(font, 4, f"GN System Sans {style}")
    set_name(font, 6, f"GNSystemSans-{style}")


def convert_to_true_type(font: TTFont) -> None:
    if "glyf" in font and "CFF " not in font and "CFF2" not in font:
        return

    glyph_set = font.getGlyphSet()
    glyphs = {}
    for glyph_name in font.getGlyphOrder():
        tt_pen = TTGlyphPen(glyph_set)
        pen = Cu2QuPen(tt_pen, max_err=1.0)
        glyph_set[glyph_name].draw(pen)
        glyphs[glyph_name] = tt_pen.glyph()

    glyf = newTable("glyf")
    glyf.glyphs = glyphs
    font["glyf"] = glyf
    font["loca"] = newTable("loca")
    font["loca"].locations = []
    for table in ("CFF ", "CFF2", "VORG"):
        if table in font:
            del font[table]
    font["head"].indexToLocFormat = 1
    font["maxp"].tableVersion = 0x00010000
    font["maxp"].numGlyphs = len(glyphs)
    for field in (
        "maxPoints",
        "maxContours",
        "maxCompositePoints",
        "maxCompositeContours",
        "maxZones",
        "maxTwilightPoints",
        "maxStorage",
        "maxFunctionDefs",
        "maxInstructionDefs",
        "maxStackElements",
        "maxSizeOfInstructions",
        "maxComponentElements",
        "maxComponentDepth",
    ):
        setattr(font["maxp"], field, 0)
    font["maxp"].maxZones = 1


def merge_missing_glyphs(fonts: list[TTFont]) -> TTFont:
    base = fonts[0]
    glyph_order = list(base.getGlyphOrder())
    glyph_names = set(glyph_order)
    cmap = base.getBestCmap()

    for source in fonts[1:]:
        source_cmap = source.getBestCmap()
        for codepoint, source_glyph_name in source_cmap.items():
            if codepoint in cmap:
                continue

            target_glyph_name = source_glyph_name
            if target_glyph_name in glyph_names:
                target_glyph_name = f"{source_glyph_name}.kr"
            if target_glyph_name in glyph_names:
                raise RuntimeError(f"Cannot merge glyph {source_glyph_name!r} without a unique name")

            source_glyph = source["glyf"].glyphs[source_glyph_name]
            for component in getattr(source_glyph, "components", []):
                if component.glyphName not in glyph_names:
                    raise RuntimeError(
                        f"Cannot merge composite glyph {source_glyph_name!r}: "
                        f"missing component {component.glyphName!r}"
                    )

            base["glyf"].glyphs[target_glyph_name] = deepcopy(source_glyph)
            base["hmtx"].metrics[target_glyph_name] = source["hmtx"].metrics[source_glyph_name]
            glyph_order.append(target_glyph_name)
            glyph_names.add(target_glyph_name)
            cmap[codepoint] = target_glyph_name
            for subtable in base["cmap"].tables:
                if subtable.isUnicode():
                    subtable.cmap[codepoint] = target_glyph_name

    base.setGlyphOrder(glyph_order)
    base["maxp"].numGlyphs = len(glyph_order)
    base["hhea"].numberOfHMetrics = len(glyph_order)
    return base


def build(sources: tuple[SourceFont, ...], output: Path, weight: int) -> TTFont:
    fonts = [load_source_font(source) for source in sources]
    target_upem = fonts[0]["head"].unitsPerEm

    for font in fonts[1:]:
        scale_upem(font, target_upem)

    for font in fonts:
        convert_to_true_type(font)
    font = merge_missing_glyphs(fonts)
    font["OS/2"].usWeightClass = weight
    if weight == 400:
        font["OS/2"].fsSelection = (font["OS/2"].fsSelection | (1 << 6)) & ~(1 << 5)
    else:
        font["OS/2"].fsSelection = (font["OS/2"].fsSelection | (1 << 5)) & ~(1 << 6)
    font["head"].macStyle = 0 if weight == 400 else 1
    normalize_names(font, weight)
    cmap = font.getBestCmap()
    if not all(ord(char) in cmap for char in REQUIRED_CHARACTERS):
        raise RuntimeError("CJK font is missing a required glyph")
    if "glyf" not in font or "CFF " in font or "CFF2" in font:
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
    regular_output = Path(sys.argv[1]) if len(sys.argv) > 1 else Path("/tmp/GNSystemSans-Regular.ttf")
    bold_output = Path(sys.argv[2]) if len(sys.argv) > 2 else regular_output.with_name("GNSystemSans-Bold.ttf")
    regular = build(REGULAR_SOURCE_FONTS, regular_output, 400)
    bold = build(BOLD_SOURCE_FONTS, bold_output, 700)
    if regular_output.read_bytes() == bold_output.read_bytes():
        raise RuntimeError("Regular and bold fonts must be different files")
    if not any(
        glyph_signature(regular, character) != glyph_signature(bold, character)
        for character in REQUIRED_CHARACTERS
    ):
        raise RuntimeError("Regular and bold fonts must contain different glyph outlines")


if __name__ == "__main__":
    main()

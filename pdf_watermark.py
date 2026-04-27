#!/usr/bin/env python3
"""
pdf_watermark.py — Наложение изображения-водяного знака на PDF-файл.

Координаты задаются от правого нижнего угла страницы.

Использование:
    python3 pdf_watermark.py -i input.pdf -o output.pdf -w logo.png
    python3 pdf_watermark.py -i input.pdf -o output.pdf -w logo.png \
        --scale 0.15 --offset-x 30 --offset-y 20 --opacity 0.6 \
        --pages 1,3,5-7 --rotate 45

Параметры:
    -i / --input        Входной PDF (обязательный)
    -o / --output       Выходной PDF (обязательный)
    -w / --watermark    Изображение-водяной знак PNG/JPG (обязательный)
    --scale             Масштаб относительно ширины страницы (0.0–1.0, по умолчанию 0.2)
    --offset-x          Отступ от правого края в пунктах pt (по умолчанию 20)
    --offset-y          Отступ от нижнего края в пунктах pt (по умолчанию 20)
    --opacity           Прозрачность: 0.0 (невидимый) – 1.0 (непрозрачный) (по умолчанию 0.5)
    --rotate            Угол поворота изображения в градусах (по умолчанию 0)
    --pages             Страницы для обработки: "all", "1", "1,3,5", "2-5", "1,3-6,8"
                        (по умолчанию all)
    --no-overwrite      Не перезаписывать выходной файл если он существует
"""

import argparse
import io
import os
import sys
import tempfile

from PIL import Image
from pypdf import PdfReader, PdfWriter
from reportlab.lib.pagesizes import letter
from reportlab.pdfgen import canvas as rl_canvas


# ──────────────────────────────────────────────
# Вспомогательные функции
# ──────────────────────────────────────────────

def parse_pages(pages_str: str, total_pages: int) -> set[int]:
    """
    Парсинг строки страниц вида "all", "1", "1,3,5", "2-5", "1,3-6,8".
    Возвращает set с 0-индексированными номерами страниц.
    """
    if pages_str.strip().lower() == "all":
        return set(range(total_pages))

    result = set()
    for part in pages_str.split(","):
        part = part.strip()
        if "-" in part:
            start_s, end_s = part.split("-", 1)
            start, end = int(start_s.strip()), int(end_s.strip())
            for p in range(start, end + 1):
                if 1 <= p <= total_pages:
                    result.add(p - 1)   # перевод в 0-индекс
        else:
            p = int(part)
            if 1 <= p <= total_pages:
                result.add(p - 1)

    return result


def prepare_watermark_image(
    image_path: str,
    opacity: float,
    rotate_deg: float,
) -> Image.Image:
    """Открывает, применяет прозрачность и поворот к изображению."""
    img = Image.open(image_path).convert("RGBA")

    # Применяем прозрачность через альфа-канал
    if opacity < 1.0:
        r, g, b, a = img.split()
        a = a.point(lambda v: int(v * opacity))
        img = Image.merge("RGBA", (r, g, b, a))

    # Поворот с сохранением прозрачности
    if rotate_deg != 0:
        img = img.rotate(rotate_deg, expand=True)

    return img


def build_watermark_pdf(
    wm_image: Image.Image,
    page_width_pt: float,
    page_height_pt: float,
    scale: float,
    offset_x_pt: float,
    offset_y_pt: float,
) -> bytes:
    """
    Создаёт однострочный PDF-слой с водяным знаком.

    Масштаб задаётся относительно ширины страницы.
    Позиция считается от правого нижнего угла:
        x = page_width  - offset_x - wm_width
        y = offset_y                            (нижний край картинки)
    """
    # Целевой размер изображения
    wm_w_pt = page_width_pt * scale
    aspect = wm_image.height / wm_image.width
    wm_h_pt = wm_w_pt * aspect

    # Координаты нижнего левого угла картинки
    x = page_width_pt - offset_x_pt - wm_w_pt
    y = offset_y_pt   # нижняя граница

    # ReportLab требует путь к файлу, сохраняем во временный файл
    with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as tmp:
        tmp_path = tmp.name
        wm_image.save(tmp_path, format="PNG")

    try:
        pdf_buf = io.BytesIO()
        c = rl_canvas.Canvas(pdf_buf, pagesize=(page_width_pt, page_height_pt))
        c.drawImage(
            tmp_path,
            x=x,
            y=y,
            width=wm_w_pt,
            height=wm_h_pt,
            mask="auto",   # учитывает alpha-канал PNG
        )
        c.save()
        pdf_buf.seek(0)
        return pdf_buf.read()
    finally:
        os.unlink(tmp_path)


# ──────────────────────────────────────────────
# Основная логика
# ──────────────────────────────────────────────

def watermark_pdf(
    input_path: str,
    output_path: str,
    watermark_path: str,
    scale: float = 0.2,
    offset_x: float = 20.0,
    offset_y: float = 20.0,
    opacity: float = 0.5,
    rotate: float = 0.0,
    pages_str: str = "all",
    no_overwrite: bool = False,
) -> None:
    # Проверки входных данных
    if not os.path.isfile(input_path):
        sys.exit(f"[ОШИБКА] Входной файл не найден: {input_path}")
    if not os.path.isfile(watermark_path):
        sys.exit(f"[ОШИБКА] Файл водяного знака не найден: {watermark_path}")
    if no_overwrite and os.path.exists(output_path):
        sys.exit(f"[ОШИБКА] Выходной файл уже существует: {output_path}")
    if not (0.0 < scale <= 1.0):
        sys.exit("[ОШИБКА] --scale должен быть в диапазоне (0.0, 1.0]")
    if not (0.0 <= opacity <= 1.0):
        sys.exit("[ОШИБКА] --opacity должен быть в диапазоне [0.0, 1.0]")

    reader = PdfReader(input_path)
    total = len(reader.pages)
    target_pages = parse_pages(pages_str, total)

    if not target_pages:
        sys.exit("[ОШИБКА] Указанные страницы вне диапазона документа.")

    print(f"[INFO] Документ: {total} стр. | Обрабатываем: {len(target_pages)} стр.")
    print(f"[INFO] Масштаб: {scale:.2%} | Отступ X: {offset_x} pt | Отступ Y: {offset_y} pt")
    print(f"[INFO] Прозрачность: {opacity:.0%} | Поворот: {rotate}°")

    # Подготовка изображения (один раз)
    wm_image = prepare_watermark_image(watermark_path, opacity, rotate)

    writer = PdfWriter()

    for i, page in enumerate(reader.pages):
        if i in target_pages:
            pw = float(page.mediabox.width)
            ph = float(page.mediabox.height)

            # Строим PDF-слой с водяным знаком под нужный размер страницы
            wm_pdf_bytes = build_watermark_pdf(
                wm_image, pw, ph, scale, offset_x, offset_y
            )
            wm_page = PdfReader(io.BytesIO(wm_pdf_bytes)).pages[0]

            # Накладываем поверх содержимого страницы
            page.merge_page(wm_page)

        writer.add_page(page)

    # Гарантируем существование директории
    out_dir = os.path.dirname(os.path.abspath(output_path))
    os.makedirs(out_dir, exist_ok=True)

    with open(output_path, "wb") as f:
        writer.write(f)

    print(f"[OK] Готово: {output_path}")


# ──────────────────────────────────────────────
# CLI
# ──────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        description="Наложение изображения-водяного знака на PDF. "
                    "Координаты отсчитываются от правого нижнего угла страницы.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Примеры:
  # Логотип в правом нижнем углу, масштаб 20%
  python3 pdf_watermark.py -i doc.pdf -o out.pdf -w logo.png

  # Масштаб 10%, отступ 40 pt от правого и 30 pt от нижнего края
  python3 pdf_watermark.py -i doc.pdf -o out.pdf -w logo.png \\
      --scale 0.1 --offset-x 40 --offset-y 30

  # Диагональный водяной знак, 30% ширины, полупрозрачный, повёрнут на 45°
  python3 pdf_watermark.py -i doc.pdf -o out.pdf -w stamp.png \\
      --scale 0.3 --opacity 0.4 --rotate 45

  # Только страницы 1, 3 и 5–8
  python3 pdf_watermark.py -i doc.pdf -o out.pdf -w logo.png --pages "1,3,5-8"
        """,
    )

    parser.add_argument("-i", "--input",     required=True, help="Входной PDF-файл")
    parser.add_argument("-o", "--output",    required=True, help="Выходной PDF-файл")
    parser.add_argument("-w", "--watermark", required=True, help="Изображение водяного знака (PNG/JPG)")

    parser.add_argument(
        "--scale", type=float, default=0.2,
        metavar="FLOAT",
        help="Масштаб: доля ширины страницы [0.01–1.0] (по умолчанию: 0.2 = 20%%)"
    )
    parser.add_argument(
        "--offset-x", type=float, default=20.0,
        metavar="PT",
        help="Отступ от правого края в pt (по умолчанию: 20)"
    )
    parser.add_argument(
        "--offset-y", type=float, default=20.0,
        metavar="PT",
        help="Отступ от нижнего края в pt (по умолчанию: 20)"
    )
    parser.add_argument(
        "--opacity", type=float, default=0.5,
        metavar="FLOAT",
        help="Прозрачность 0.0–1.0 (по умолчанию: 0.5)"
    )
    parser.add_argument(
        "--rotate", type=float, default=0.0,
        metavar="DEG",
        help="Угол поворота изображения в градусах (по умолчанию: 0)"
    )
    parser.add_argument(
        "--pages", type=str, default="all",
        metavar="PAGES",
        help='Страницы: "all", "1", "1,3,5", "2-5", "1,3-6,8" (по умолчанию: all)'
    )
    parser.add_argument(
        "--no-overwrite", action="store_true",
        help="Не перезаписывать выходной файл если он уже существует"
    )

    args = parser.parse_args()

    watermark_pdf(
        input_path=args.input,
        output_path=args.output,
        watermark_path=args.watermark,
        scale=args.scale,
        offset_x=args.offset_x,
        offset_y=args.offset_y,
        opacity=args.opacity,
        rotate=args.rotate,
        pages_str=args.pages,
        no_overwrite=args.no_overwrite,
    )


if __name__ == "__main__":
    main()

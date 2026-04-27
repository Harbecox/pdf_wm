#!/usr/bin/env python3
"""
pdf_watermark_api.py — Flask API для наложения водяного знака на PDF.

Запуск:
    python3 pdf_watermark_api.py
    # или через gunicorn:
    gunicorn -w 4 -b 127.0.0.1:5000 pdf_watermark_api:app

Эндпоинты:
    GET  /health          → {"status": "ok"}
    POST /watermark       → PDF-файл в теле ответа
"""

import io
import os
import tempfile

from flask import Flask, request, jsonify, send_file
from werkzeug.exceptions import BadRequest

# Импортируем логику из основного скрипта
from pdf_watermark import prepare_watermark_image, build_watermark_pdf

from pypdf import PdfReader, PdfWriter

# ─── Конфигурация ────────────────────────────────────────────────
MAX_PDF_SIZE = 50 * 1024 * 1024   # 50 МБ
MAX_IMG_SIZE = 10 * 1024 * 1024   # 10 МБ
ALLOWED_IMG_MIME = {'image/png', 'image/jpeg', 'image/gif', 'image/webp'}

app = Flask(__name__)
app.config['MAX_CONTENT_LENGTH'] = MAX_PDF_SIZE + MAX_IMG_SIZE + 1024


# ─── Хелперы ─────────────────────────────────────────────────────

def err(msg: str, code: int = 400):
    return jsonify({"error": msg}), code


def get_float(key: str, default: float, min_val: float, max_val: float) -> float:
    try:
        val = float(request.form.get(key, default))
    except (TypeError, ValueError):
        raise BadRequest(f"Параметр '{key}' должен быть числом.")
    if not (min_val <= val <= max_val):
        raise BadRequest(f"Параметр '{key}' должен быть от {min_val} до {max_val}.")
    return val


# ─── Эндпоинты ───────────────────────────────────────────────────

@app.route('/health', methods=['GET'])
def health():
    return jsonify({"status": "ok"})


@app.route('/watermark', methods=['POST'])
def watermark():
    # 1. Проверяем наличие файлов
    if 'pdf_file' not in request.files:
        return err("Поле 'pdf_file' отсутствует.")
    if 'wm_file' not in request.files:
        return err("Поле 'wm_file' отсутствует.")

    pdf_file = request.files['pdf_file']
    wm_file  = request.files['wm_file']

    # 2. Проверяем MIME PDF
    pdf_bytes = pdf_file.read()
    if len(pdf_bytes) > MAX_PDF_SIZE:
        return err("PDF слишком большой. Максимум 50 МБ.")
    if not pdf_bytes.startswith(b'%PDF'):
        return err("Файл не является PDF.")

    # 3. Проверяем MIME изображения
    wm_bytes = wm_file.read()
    if len(wm_bytes) > MAX_IMG_SIZE:
        return err("Изображение слишком большое. Максимум 10 МБ.")
    mime = wm_file.mimetype or ''
    if mime not in ALLOWED_IMG_MIME:
        # Пробуем определить по сигнатуре
        sigs = {b'\x89PNG': 'image/png', b'\xff\xd8\xff': 'image/jpeg',
                b'GIF8': 'image/gif', b'RIFF': 'image/webp'}
        mime = next((m for s, m in sigs.items() if wm_bytes.startswith(s)), '')
        if not mime:
            return err("Допустимые форматы изображения: PNG, JPG, GIF, WEBP.")

    # 4. Параметры
    try:
        scale    = get_float('scale',    0.2,  0.01, 1.0)
        offset_x = get_float('offset_x', 20.0, -5000.0, 5000.0)
        offset_y = get_float('offset_y', 20.0, -5000.0, 5000.0)
        opacity  = get_float('opacity',  0.5,  0.0,  1.0)
        rotate   = get_float('rotate',   0.0,  0.0,  360.0)
    except BadRequest as e:
        return err(str(e.description))

    pages_str = request.form.get('pages', 'all').strip()
    import re
    if not re.match(r'^(all|[\d,\- ]+)$', pages_str, re.IGNORECASE):
        return err("Неверный формат страниц. Примеры: all, 1, 1,3, 2-5")

    # 5. Обработка во временных файлах
    try:
        with tempfile.TemporaryDirectory() as tmpdir:
            # Сохраняем входные файлы
            pdf_path = os.path.join(tmpdir, 'input.pdf')
            ext = wm_file.filename.rsplit('.', 1)[-1].lower() if wm_file.filename else 'png'
            wm_path  = os.path.join(tmpdir, f'watermark.{ext}')
            out_path = os.path.join(tmpdir, 'output.pdf')

            with open(pdf_path, 'wb') as f: f.write(pdf_bytes)
            with open(wm_path,  'wb') as f: f.write(wm_bytes)

            # Парсим страницы
            from pdf_watermark import parse_pages
            reader = PdfReader(pdf_path)
            total  = len(reader.pages)
            target = parse_pages(pages_str, total)

            if not target:
                return err("Указанные страницы вне диапазона документа.")

            # Подготавливаем изображение
            wm_image = prepare_watermark_image(wm_path, opacity, rotate)
            writer   = PdfWriter()

            for i, page in enumerate(reader.pages):
                if i in target:
                    pw = float(page.mediabox.width)
                    ph = float(page.mediabox.height)
                    wm_pdf_bytes = build_watermark_pdf(wm_image, pw, ph, scale, offset_x, offset_y)
                    wm_page = PdfReader(io.BytesIO(wm_pdf_bytes)).pages[0]
                    page.merge_page(wm_page)
                writer.add_page(page)

            with open(out_path, 'wb') as f:
                writer.write(f)

            # Читаем результат в память до закрытия tmpdir
            with open(out_path, 'rb') as f:
                result_bytes = f.read()

    except Exception as e:
        return err(f"Ошибка обработки: {str(e)}", 500)

    # 6. Возвращаем PDF
    orig_name = os.path.splitext(pdf_file.filename or 'document')[0]
    dl_name   = f"{orig_name}_watermarked.pdf"

    return send_file(
        io.BytesIO(result_bytes),
        mimetype='application/pdf',
        as_attachment=True,
        download_name=dl_name,
    )


# ─── Запуск ──────────────────────────────────────────────────────

if __name__ == '__main__':
    # Только localhost — снаружи не доступен
    app.run(host='127.0.0.1', port=5000, debug=False)

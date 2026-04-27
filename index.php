<?php
ob_start(); // буферизуем весь вывод — защита от мусора перед бинарным PDF
/**
 * PDF Watermark Tool — PHP фронтенд
 * Отправляет файлы и параметры на локальный Python/Flask API.
 *
 * Структура проекта:
 *   index.php              ← этот файл
 *   api_health_proxy.php   ← прокси для JS health-check
 *   pdf_watermark.py       ← основная логика
 *   pdf_watermark_api.py   ← Flask API
 *
 * Запуск:
 *   # Терминал 1 — API
 *   python3 pdf_watermark_api.py
 *
 *   # Терминал 2 — PHP
 *   php -S localhost:8080
 */

define('API_URL',      'http://127.0.0.1:5000/watermark');
define('API_HEALTH',   'http://127.0.0.1:5000/health');
define('API_TIMEOUT',  120);
define('MAX_PDF_SIZE', 50 * 1024 * 1024);
define('MAX_IMG_SIZE', 10 * 1024 * 1024);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // ── Валидация файлов ──────────────────────────────────────
        if (empty($_FILES['pdf_file']['tmp_name']))
            throw new RuntimeException('PDF-файл не загружен.');
        if ($_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK)
            throw new RuntimeException('Ошибка загрузки PDF: код ' . $_FILES['pdf_file']['error']);
        if ($_FILES['pdf_file']['size'] > MAX_PDF_SIZE)
            throw new RuntimeException('PDF слишком большой. Максимум 50 МБ.');

        // Если файл не загружен — используем b.png по умолчанию
        $defaultWm  = __DIR__ . '/b.png';
        $useDefault = empty($_FILES['wm_file']['tmp_name'])
                   || ($_FILES['wm_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE;

        if ($useDefault) {
            if (!file_exists($defaultWm))
                throw new RuntimeException('Файл водяного знака не загружен и b.png не найден рядом с index.php.');
            $wmPath = $defaultWm;
            $wmMime = 'image/png';
            $wmName = 'b.png';
        } else {
            if ($_FILES['wm_file']['error'] !== UPLOAD_ERR_OK)
                throw new RuntimeException('Ошибка загрузки изображения: код ' . $_FILES['wm_file']['error']);
            if ($_FILES['wm_file']['size'] > MAX_IMG_SIZE)
                throw new RuntimeException('Изображение слишком большое. Максимум 10 МБ.');
            $wmPath = $_FILES['wm_file']['tmp_name'];
            $wmMime = $_FILES['wm_file']['type'] ?: 'image/png';
            $wmName = $_FILES['wm_file']['name'];
        }

        // ── Валидация параметров ──────────────────────────────────
        $scale   = (float)($_POST['scale']    ?? 0.2);
        $offsetX = (float)($_POST['offset_x'] ?? 20);
        $offsetY = (float)($_POST['offset_y'] ?? 20);
        $opacity = (float)($_POST['opacity']  ?? 0.5);
        $rotate  = (float)($_POST['rotate']   ?? 0);
        $pages   = trim($_POST['pages']        ?? 'all');

        if ($scale  <= 0 || $scale  > 1)     throw new RuntimeException('Масштаб: от 0.01 до 1.0');
        if ($offsetX < -5000 || $offsetX > 5000) throw new RuntimeException('Отступ X: от -5000 до 5000');
        if ($offsetY < -5000 || $offsetY > 5000) throw new RuntimeException('Отступ Y: от -5000 до 5000');
        if ($opacity < 0 || $opacity > 1)    throw new RuntimeException('Прозрачность: от 0.0 до 1.0');
        if (!preg_match('/^(all|[\d,\- ]+)$/i', $pages))
            throw new RuntimeException('Неверный формат страниц. Примеры: all, 1, 1,3, 2-5');

        if (!function_exists('curl_init'))
            throw new RuntimeException('cURL не установлен. Выполните: apt install php-curl');

        // ── Проверка доступности API ──────────────────────────────
        $hCh = curl_init(API_HEALTH);
        curl_setopt_array($hCh, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        curl_exec($hCh);
        $hCode = curl_getinfo($hCh, CURLINFO_HTTP_CODE);
        curl_close($hCh);

        if ($hCode !== 200)
            throw new RuntimeException(
                "API недоступен (127.0.0.1:5000).\nЗапустите: python3 pdf_watermark_api.py"
            );

        // ── Запрос к API ──────────────────────────────────────────
        $ch = curl_init(API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => API_TIMEOUT,
            CURLOPT_POSTFIELDS     => [
                'pdf_file' => new CURLFile(
                    $_FILES['pdf_file']['tmp_name'],
                    'application/pdf',
                    $_FILES['pdf_file']['name']
                ),
                'wm_file' => new CURLFile($wmPath, $wmMime, $wmName),
                'scale'    => (string)$scale,
                'offset_x' => (string)$offsetX,
                'offset_y' => (string)$offsetY,
                'opacity'  => (string)$opacity,
                'rotate'   => (string)$rotate,
                'pages'    => $pages,
            ],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mimeType  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) throw new RuntimeException("cURL ошибка: $curlError");

        if ($httpCode !== 200 || strpos($mimeType, 'application/pdf') === false) {
            $decoded = json_decode($response, true);
            throw new RuntimeException("Ошибка API: " . ($decoded['error'] ?? "код $httpCode"));
        }

        // ── Отдаём PDF ────────────────────────────────────────────
        $name = pathinfo($_FILES['pdf_file']['name'], PATHINFO_FILENAME) . '_watermarked.pdf';
        ob_end_clean(); // сбрасываем буфер — никакого мусора перед бинарным PDF
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . addslashes($name) . '"');
        header('Content-Length: ' . strlen($response));
        header('Cache-Control: no-cache, no-store');
        echo $response;
        exit;

    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PDF Watermark Tool</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;600&display=swap');
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{
    --bg:#0f0f0f;--surface:#181818;--border:#2a2a2a;
    --accent:#e8ff00;--accent2:#ff6b35;--text:#e8e8e8;
    --muted:#666;--error:#ff4444;
    --mono:'IBM Plex Mono',monospace;--sans:'IBM Plex Sans',sans-serif;
  }
  body{background:var(--bg);color:var(--text);font-family:var(--sans);
       min-height:100vh;display:flex;align-items:flex-start;
       justify-content:center;padding:40px 16px 60px}
  .shell{width:100%;max-width:780px}

  /* Header */
  header{border-bottom:1px solid var(--border);padding-bottom:24px;
         margin-bottom:32px;display:flex;align-items:flex-end;gap:16px}
  .logo-mark{width:40px;height:40px;background:var(--accent);
             display:flex;align-items:center;justify-content:center;flex-shrink:0}
  .logo-mark svg{width:22px;height:22px}
  h1{font-family:var(--mono);font-size:1.1rem;font-weight:600;
     letter-spacing:.08em;text-transform:uppercase}
  h1 span{color:var(--accent)}
  .tagline{font-size:.75rem;color:var(--muted);font-family:var(--mono);margin-top:2px}

  /* API badge */
  .api-badge{margin-left:auto;font-family:var(--mono);font-size:.68rem;
             padding:4px 10px;border:1px solid var(--border);color:var(--muted);
             display:flex;align-items:center;gap:6px;flex-shrink:0;align-self:center}
  .api-badge .dot{width:6px;height:6px;border-radius:50%;background:var(--muted);
                  animation:pulse 2s ease-in-out infinite}
  .api-badge.online{border-color:#00ff88;color:#00ff88}
  .api-badge.online .dot{background:#00ff88}
  .api-badge.offline{border-color:var(--error);color:var(--error)}
  .api-badge.offline .dot{background:var(--error);animation:none}
  @keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}

  /* Info */
  .info-box{background:#0a1200;border:1px solid #2a3a00;border-left:3px solid var(--accent);
            padding:12px 16px;margin-bottom:28px;font-family:var(--mono);
            font-size:.72rem;color:#8a9a00;line-height:1.8}
  .info-box code{color:var(--accent)}

  /* Error */
  .error-box{background:#1a0000;border:1px solid var(--error);
             border-left:3px solid var(--error);padding:14px 18px;
             margin-bottom:28px;font-family:var(--mono);font-size:.8rem;
             color:#ff8888;white-space:pre-wrap;line-height:1.6}
  .error-box::before{content:'✖ ОШИБКА  ';color:var(--error);font-weight:600}

  /* Sections */
  .section{margin-bottom:36px}
  .section-label{font-family:var(--mono);font-size:.68rem;letter-spacing:.12em;
                 text-transform:uppercase;color:var(--muted);margin-bottom:14px;
                 display:flex;align-items:center;gap:10px}
  .section-label::after{content:'';flex:1;height:1px;background:var(--border)}

  /* Upload grid */
  .upload-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  @media(max-width:560px){.upload-grid{grid-template-columns:1fr}}
  .drop-zone{border:1px dashed var(--border);background:var(--surface);
             padding:32px 24px;text-align:center;cursor:pointer;
             transition:border-color .2s,background .2s;position:relative}
  .drop-zone:hover,.drop-zone.drag-over{border-color:var(--accent);background:#1a1a00}
  .drop-zone input[type="file"]{position:absolute;inset:0;opacity:0;
                                cursor:pointer;width:100%;height:100%}
  .drop-icon{font-size:2rem;margin-bottom:10px;display:block}
  .drop-label{font-family:var(--mono);font-size:.78rem;color:var(--muted);line-height:1.5}
  .drop-label strong{color:var(--accent);font-weight:600}
  .drop-name{margin-top:10px;font-family:var(--mono);font-size:.75rem;
             color:var(--accent2);display:none}

  /* Param grid */
  .param-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
  @media(max-width:560px){.param-grid{grid-template-columns:1fr 1fr}}
  .field{display:flex;flex-direction:column;gap:6px}
  .field label{font-family:var(--mono);font-size:.7rem;color:var(--muted);
               letter-spacing:.06em;text-transform:uppercase}
  .field label .hint{display:block;font-size:.62rem;color:#444;
                     text-transform:none;letter-spacing:0;margin-top:1px}
  .field input[type="text"],.field input[type="number"]{
    background:var(--surface);border:1px solid var(--border);color:var(--text);
    font-family:var(--mono);font-size:.85rem;padding:9px 12px;width:100%;
    transition:border-color .15s;outline:none;-moz-appearance:textfield}
  .field input::-webkit-outer-spin-button,.field input::-webkit-inner-spin-button{-webkit-appearance:none}
  .field input:focus{border-color:var(--accent)}
  .slider-wrap{display:flex;align-items:center;gap:10px}
  input[type="range"]{flex:1;-webkit-appearance:none;height:2px;
                      background:var(--border);outline:none;cursor:pointer}
  input[type="range"]::-webkit-slider-thumb{-webkit-appearance:none;width:14px;height:14px;
                                            background:var(--accent);cursor:pointer;border-radius:0}
  input[type="range"]::-moz-range-thumb{width:14px;height:14px;background:var(--accent);
                                        border:none;border-radius:0;cursor:pointer}
  .slider-val{font-family:var(--mono);font-size:.8rem;color:var(--accent);
              min-width:36px;text-align:right}

  /* Submit */
  .submit-row{margin-top:40px;display:flex;align-items:center;gap:20px}
  button[type="submit"]{background:var(--accent);color:#000;border:none;
    font-family:var(--mono);font-size:.85rem;font-weight:600;
    letter-spacing:.1em;text-transform:uppercase;padding:14px 36px;
    cursor:pointer;transition:background .15s,transform .1s}
  button[type="submit"]:hover{background:#d4eb00}
  button[type="submit"]:active{transform:scale(.98)}
  button[type="submit"].loading{pointer-events:none;background:#555;color:#888}
  .submit-note{font-family:var(--mono);font-size:.7rem;color:var(--muted);line-height:1.5}
  .progress{display:none;height:2px;background:var(--border);
            margin-top:16px;position:relative;overflow:hidden}
  .progress.active{display:block}
  .progress::after{content:'';position:absolute;left:-40%;top:0;height:100%;
                   width:40%;background:var(--accent);animation:bar 1.2s ease-in-out infinite}
  @keyframes bar{0%{left:-40%}100%{left:100%}}

  footer{margin-top:60px;border-top:1px solid var(--border);padding-top:20px;
         font-family:var(--mono);font-size:.68rem;color:#333;
         display:flex;justify-content:space-between}
</style>
</head>
<body>
<div class="shell">

  <header>
    <div class="logo-mark">
      <svg viewBox="0 0 24 24" fill="none">
        <rect x="3" y="3" width="13" height="17" rx="1" fill="#000"/>
        <path d="M8 9h7M8 13h5" stroke="#e8ff00" stroke-width="1.5" stroke-linecap="square"/>
        <circle cx="17" cy="17" r="5" fill="#e8ff00"/>
        <path d="M15 17l1.5 1.5L19 15" stroke="#000" stroke-width="1.5" stroke-linecap="square"/>
      </svg>
    </div>
    <div>
      <h1>PDF <span>Watermark</span> Tool</h1>
      <div class="tagline">Flask API · координаты от правого нижнего угла</div>
    </div>
    <div class="api-badge" id="api-badge">
      <span class="dot"></span>
      <span id="api-status">проверка…</span>
    </div>
  </header>

  <div class="info-box">
    API: <code>http://127.0.0.1:5000</code> &nbsp;·&nbsp;
    Запуск: <code>python3 pdf_watermark_api.py</code> &nbsp;·&nbsp;
    Endpoint: <code>POST /watermark</code>
  </div>

  <?php if ($error): ?>
  <div class="error-box"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" id="wm-form">

    <div class="section">
      <div class="section-label">01 / Файлы</div>
      <div class="upload-grid">
        <div>
          <div class="drop-zone" id="zone-pdf">
            <input type="file" name="pdf_file" id="inp-pdf" accept=".pdf" required>
            <span class="drop-icon">📄</span>
            <div class="drop-label">
              <strong>PDF-документ</strong><br>перетащите или нажмите<br>
              <span style="font-size:.65rem;color:#444">макс. 50 МБ</span>
            </div>
            <div class="drop-name" id="name-pdf"></div>
          </div>
        </div>
        <div>
          <div class="drop-zone" id="zone-wm">
            <input type="file" name="wm_file" id="inp-wm" accept="image/png,image/jpeg,image/gif,image/webp">
            <span class="drop-icon">🖼</span>
            <div class="drop-label">
              <strong>Водяной знак</strong><br>PNG / JPG / WEBP<br>
              <span style="font-size:.65rem;color:#444">макс. 10 МБ · PNG с прозрачностью лучше</span>
            </div>
            <div class="drop-name" id="name-wm">✔ b.png (по умолчанию)</div>
          </div>
        </div>
      </div>
    </div>

    <div class="section">
      <div class="section-label">02 / Параметры наложения</div>
      <div class="param-grid">

        <div class="field">
          <label>Масштаб <span class="hint">доля ширины страницы</span></label>
          <div class="slider-wrap">
            <input type="range" name="scale" min="0.02" max="1" step="0.01" value="0.20"
              oninput="document.getElementById('sv-scale').textContent=(+this.value*100).toFixed(0)+'%'">
            <span class="slider-val" id="sv-scale">20%</span>
          </div>
        </div>

        <div class="field">
          <label>Прозрачность <span class="hint">0 = невидим · 1 = полный</span></label>
          <div class="slider-wrap">
            <input type="range" name="opacity" min="0" max="1" step="0.05" value="0.40"
              oninput="document.getElementById('sv-opacity').textContent=(+this.value*100).toFixed(0)+'%'">
            <span class="slider-val" id="sv-opacity">40%</span>
          </div>
        </div>

        <div class="field">
          <label>Поворот <span class="hint">градусы 0–360</span></label>
          <div class="slider-wrap">
            <input type="range" name="rotate" min="0" max="360" step="1" value="0"
              oninput="document.getElementById('sv-rotate').textContent=this.value+'°'">
            <span class="slider-val" id="sv-rotate">0°</span>
          </div>
        </div>

        <div class="field">
          <label>Отступ X (pt) <span class="hint">от правого края</span></label>
          <input type="text" name="offset_x" value="20" min="-5000" max="5000" step="1">
        </div>

        <div class="field">
          <label>Отступ Y (pt) <span class="hint">от нижнего края</span></label>
          <input type="text" name="offset_y" value="20" min="-5000" max="5000" step="1">
        </div>

        <div class="field">
          <label>Страницы <span class="hint">all · 1,3 · 2-5 · 1,3-6,8</span></label>
          <input type="text" name="pages" value="all" placeholder="all">
        </div>

      </div>
    </div>

    <div class="submit-row">
      <button type="submit" id="btn-submit">▶ Применить и скачать</button>
      <div class="submit-note">
        Файлы передаются на локальный Flask API.<br>
        Ничего не сохраняется на диск.
      </div>
    </div>
    <div class="progress" id="progress-bar"></div>

  </form>

  <footer>
    <span>Flask API · python3 · pypdf · reportlab</span>
    <span>1 pt ≈ 0.35 мм · 72 pt = 1 дюйм</span>
  </footer>
</div>

<script>
// API health check через вспомогательный PHP-прокси
async function checkApi() {
  const badge  = document.getElementById('api-badge');
  const status = document.getElementById('api-status');
  try {
    const r = await fetch('api_health_proxy.php', { signal: AbortSignal.timeout(3000) });
    const d = await r.json();
    badge.className   = d.ok ? 'api-badge online' : 'api-badge offline';
    status.textContent = d.ok ? 'API online' : 'API offline';
  } catch {
    badge.className   = 'api-badge offline';
    status.textContent = 'API offline';
  }
}
checkApi();
setInterval(checkApi, 10000);

// Drag & drop
['zone-pdf','zone-wm'].forEach(id => {
  const z = document.getElementById(id);
  z.addEventListener('dragover',  e => { e.preventDefault(); z.classList.add('drag-over'); });
  z.addEventListener('dragleave', ()  => z.classList.remove('drag-over'));
  z.addEventListener('drop',      ()  => z.classList.remove('drag-over'));
});
function bindFile(iId, nId, zId) {
  const inp = document.getElementById(iId);
  inp.addEventListener('change', () => {
    if (!inp.files[0]) return;
    const n = document.getElementById(nId);
    n.textContent = '✔ ' + inp.files[0].name;
    n.style.display = 'block';
    document.getElementById(zId).style.borderColor = 'var(--accent)';
  });
}
bindFile('inp-pdf','name-pdf','zone-pdf');
bindFile('inp-wm', 'name-wm', 'zone-wm');

// Показываем дефолтный водяной знак сразу
document.getElementById('name-wm').style.display = 'block';
document.getElementById('zone-wm').style.borderColor = 'var(--border)';

// Loading state
document.getElementById('wm-form').addEventListener('submit', () => {
  const btn = document.getElementById('btn-submit');
  btn.textContent = '⏳ Обработка…';
  btn.classList.add('loading');
  document.getElementById('progress-bar').classList.add('active');
});
</script>
</body>
</html>

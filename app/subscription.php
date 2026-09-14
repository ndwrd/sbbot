<?php
// $data приходит из Bot::sub() (см. BotSingboxTrait.php) — эта заготовка
// только рендерит то, что уже посчитано там. Список приложений — в
// apps.json рядом с этим файлом (см. user_guide.md); локальные правки —
// в apps.override.json (гитигнорится, полностью подменяет платформу с
// тем же ключом — тот же принцип, что у i18n.override.php).
$apps = json_decode(file_get_contents(__DIR__ . '/apps.json'), true) ?: [];
if (file_exists(__DIR__ . '/apps.override.json')) {
    $apps = array_merge($apps, json_decode(file_get_contents(__DIR__ . '/apps.override.json'), true) ?: []);
}

function subscriptionHint($hint, $lang)
{
    return $hint[$lang] ?? '';
}

// Кэш версионных GitHub-ссылок — читаем один раз на страницу, а не внутри
// resolveDownloadLink() на каждое приложение с downloadKey (сейчас их два, но
// открывать и блокировать один и тот же файл по разу на карточку незачем).
//
// Читаем здесь напрямую, а не через $this->readJsonLocked(): этот файл —
// шаблон, который сознательно не знает про класс Bot и работает с одними
// только $data и $hash. Иначе его нельзя было бы отрендерить отдельно, а
// subscription.override.php стал бы завязан на внутренности бота.
//
// Режим 'r', а не 'c+': это путь только на чтение, создавать пустой файл при
// его отсутствии тут не нужно (за создание отвечает checkAppDownloadLinks()).
$appsCache = [];
if ($fp = @fopen('/config/apps_cache.json', 'r')) {
    // LOCK_SH — синхронизируется с writeJsonLocked() в checkAppDownloadLinks(),
    // чтобы не прочитать файл на середине записи.
    flock($fp, LOCK_SH);
    $appsCache = json_decode(stream_get_contents($fp), true) ?: [];
    flock($fp, LOCK_UN);
    fclose($fp);
}

// Для версионных GitHub-ссылок (downloadKey в apps.json) — подменяем на то,
// что раз в сутки резолвит checkAppDownloadLinks() (см. BotCoreTrait.php).
// Кэша ещё нет/резолв не удался — остаёмся на статическом 'download' из
// apps.json (обычно releases/latest страница).
function resolveDownloadLink($app, $cache)
{
    if (empty($app['downloadKey'])) {
        return $app['download'];
    }
    return $cache[$app['downloadKey']] ?? $app['download'];
}

// Поле 'add' в apps.json — символическое имя схемы, а не готовая ссылка:
// сам urlencode/набор параметров и его синхронизация со схемой приложения
// живут только тут, одним местом, а не размазаны по JSON на каждом сервере.
function resolveAppAddLink($key, $data)
{
    switch ($key) {
        case 'singboxImport':
            return 'sing-box://import-remote-profile?url=' . rawurlencode($data['links']['singbox']) . '#' . rawurlencode($data['username']);
        case 'incyAdd':
            return $data['happIncy']['addIncy'];
        case 'rabbitholeAdd':
            // Тот же приём, что и в userXr(): rabbithole://add/{url подписки}.
            return 'rabbithole://add/' . $data['links']['clash'];
        case 'mihomoImport':
            return 'clash://install-config?url=' . rawurlencode($data['links']['clash']) . '&overwrite=no&name=' . rawurlencode($data['username']);
        case 'onexraySubAdd':
            return 'onexray://onexray.com/sub/add?url=' . rawurlencode($data['links']['xray']) . '#' . rawurlencode($data['username']);
        default:
            return null;
    }
}

// Поле 'routing' в apps.json — тот же приём, что и 'add': символическое имя,
// а не готовая ссылка. Пока только INCY (routingIncy строится в sub() из
// buildHappRouting()); Happ убран из подборки приложений, поэтому его
// routing-ссылку сюда добавлять не нужно.
function resolveRoutingLink($key, $data)
{
    switch ($key) {
        case 'incyRouting':
            return $data['happIncy']['routingIncy'] ?? null;
        default:
            return null;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>sbbot — subscription</title>
<!-- Пустой data-uri фавикон — без него браузер сам шлёт GET /favicon.ico,
     который попадает в общий `location /` (decoy-заглушка) с auth_basic и
     на каждой загрузке страницы всплывает окно ввода логина/пароля. -->
<link rel="icon" href="data:,">
<!-- Шрифты — системные, без обращения к fonts.googleapis.com. Причин две:
     во-первых, это страница, которая сама по себе говорит "у этого человека
     есть VPN-подписка", и тянуть с неё ресурс на сторону незачем; во-вторых,
     открывают её ровно из тех сетей, где googleapis может не резолвиться, а
     <link rel=stylesheet> блокирует отрисовку — то есть страница висела бы
     белой до таймаута, чтобы в итоге всё равно показать fallback. -->
<style>
  :root{
    --bg:#F6F5F2; --surface:#FFFFFF; --surface-2:#EFEDE8; --border:#E1DED6;
    --text:#1B1D1F; --text-muted:#6B6A66; --text-faint:#948F86;
    --accent:#0E8A82; --accent-ink:#FFFFFF;
    --good:#1E9E5A; --good-soft:#E1F5E9; --bad:#D64545; --bad-soft:#FBE4E4;
    --shadow: 0 1px 2px rgba(20,20,18,0.04), 0 8px 24px -12px rgba(20,20,18,0.12);
    --radius: 14px;
    --font-display:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;
    --font-body:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;
    --font-mono:ui-monospace,SFMono-Regular,'SF Mono',Menlo,Consolas,'Liberation Mono',monospace;
  }
  @media (prefers-color-scheme: dark){
    :root:not([data-theme="light"]){
      --bg:#14171A; --surface:#1C2023; --surface-2:#22262A; --border:#2C3135;
      --text:#EDEBE6; --text-muted:#9B9D9E; --text-faint:#6E7274;
      --accent:#4BD6C7; --accent-ink:#0A1413;
      --good:#4ADE80; --good-soft:#173523; --bad:#F87171; --bad-soft:#3A1E1E;
      --shadow: 0 1px 2px rgba(0,0,0,0.3), 0 12px 28px -14px rgba(0,0,0,0.55);
    }
  }
  :root[data-theme="dark"]{
    --bg:#14171A; --surface:#1C2023; --surface-2:#22262A; --border:#2C3135;
    --text:#EDEBE6; --text-muted:#9B9D9E; --text-faint:#6E7274;
    --accent:#4BD6C7; --accent-ink:#0A1413;
    --good:#4ADE80; --good-soft:#173523; --bad:#F87171; --bad-soft:#3A1E1E;
    --shadow: 0 1px 2px rgba(0,0,0,0.3), 0 12px 28px -14px rgba(0,0,0,0.55);
  }
  *{box-sizing:border-box;}
  body{background:var(--bg);color:var(--text);font-family:var(--font-body);margin:0;}
  .wrap{max-width:640px;margin:0 auto;padding:28px 20px 64px;}
  .topbar{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:22px;gap:12px;}
  .brand{font-family:var(--font-display);font-weight:800;font-size:19px;letter-spacing:-.01em;margin-top:2px;}
  .langs{display:flex;gap:2px;background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:2px;}
  .langs button{font-family:var(--font-mono);font-size:11px;font-weight:500;padding:5px 10px;border-radius:8px;border:0;background:transparent;color:var(--text-muted);cursor:pointer;}
  .langs button.active{background:var(--surface);color:var(--text);box-shadow:var(--shadow);}
  section{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:20px 20px 22px;margin-bottom:16px;}
  h2{font-family:var(--font-display);font-weight:700;font-size:14px;letter-spacing:.02em;text-transform:uppercase;color:var(--text-muted);margin:0 0 14px;text-wrap:balance;}
  .status-row{display:flex;align-items:center;gap:10px;margin-bottom:18px;}
  .dot{width:9px;height:9px;border-radius:50%;background:var(--good);box-shadow:0 0 0 4px var(--good-soft);flex:none;}
  .dot.off{background:var(--bad);box-shadow:0 0 0 4px var(--bad-soft);}
  .status-text{font-family:var(--font-display);font-weight:800;font-size:20px;letter-spacing:-.01em;}
  .status-sub{font-size:13px;color:var(--text-muted);margin-left:auto;text-align:right;}
  .stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--border);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
  .stat{background:var(--surface);padding:12px 14px;}
  .stat .k{font-size:11px;color:var(--text-faint);text-transform:uppercase;letter-spacing:.06em;font-family:var(--font-mono);}
  .stat .v{font-family:var(--font-mono);font-variant-numeric:tabular-nums;font-size:16px;font-weight:500;margin-top:3px;}
  .stat .v.down{color:var(--accent);}
  .servers{display:flex;flex-direction:column;gap:8px;}
  .server{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);}
  /* Явный emoji-стек, чтобы флаг не зависел от основной гарнитуры. На Windows
     флаги всё равно покажутся буквами кода страны — Segoe UI Emoji их просто не
     содержит; на iOS/Android/macOS, откуда страницу и открывают, всё нормально. */
  .server .flag{font-size:18px;line-height:1;font-family:'Apple Color Emoji','Segoe UI Emoji','Noto Color Emoji',sans-serif;}
  .server .tag{font-family:var(--font-mono);font-weight:500;font-size:13px;}
  .server .protos{margin-left:auto;font-size:12px;color:var(--text-muted);text-align:right;}
  .tabs{display:flex;gap:6px;overflow-x:auto;margin-bottom:14px;padding-bottom:2px;}
  .tab{font-family:var(--font-body);font-size:13px;font-weight:500;padding:7px 14px;border-radius:999px;border:1px solid var(--border);background:var(--surface-2);color:var(--text-muted);white-space:nowrap;cursor:pointer;}
  .tab.active{background:var(--accent);border-color:var(--accent);color:var(--accent-ink);}
  .apps{display:flex;flex-direction:column;gap:8px;}
  .app-platform{display:none;}
  .app-platform.active{display:flex;flex-direction:column;gap:8px;}
  .app{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--border);border-radius:10px;flex-wrap:wrap;}
  .app .icon{width:36px;height:36px;border-radius:9px;background:var(--surface-2);object-fit:cover;flex:none;}
  .app .name{font-weight:600;font-size:14px;}
  .app .hint{font-size:12px;color:var(--text-faint);}
  .app .go{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;}
  .btn{font-family:var(--font-body);font-size:12.5px;font-weight:600;padding:7px 12px;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;}
  .btn.primary{background:var(--accent);border-color:var(--accent);color:var(--accent-ink);}
  .step-num{display:inline-block;width:14px;height:14px;line-height:14px;border-radius:50%;background:var(--surface-2);color:var(--text-faint);font-size:9px;font-weight:700;text-align:center;margin-right:5px;}
  .btn.primary .step-num{background:rgba(255,255,255,.35);color:var(--accent-ink);}
  .linklist{display:flex;flex-direction:column;gap:8px;}
  .linkrow{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);}
  .linkrow .lname{font-size:12px;font-weight:600;color:var(--text-muted);width:76px;flex:none;}
  .linkrow code{flex:1;font-family:var(--font-mono);font-size:12px;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .linkrow .btn{padding:7px 9px;}
  footer{text-align:center;font-family:var(--font-mono);font-size:11px;color:var(--text-faint);margin-top:24px;}
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div>
      <div class="brand" data-i18n="brand">Информация</div>
    </div>
    <div class="langs">
      <button class="active" data-lang-btn="ru">RU</button>
      <button data-lang-btn="en">EN</button>
    </div>
  </div>

  <section>
    <div class="status-row">
      <span class="dot<?= $data['status']['active'] ? '' : ' off' ?>"></span>
      <span class="status-text" data-i18n="<?= $data['status']['active'] ? 'active' : 'inactive' ?>"><?= $data['status']['active'] ? 'Активна' : 'Отключена' ?></span>
      <?php if ($data['status']['expire']): ?>
      <span class="status-sub"><span data-i18n="until">до</span> <?= htmlspecialchars($data['status']['expire']) ?></span>
      <?php endif; ?>
    </div>
    <div class="stat-grid">
      <div class="stat"><div class="k" data-i18n="down">Скачано</div><div class="v down"><?= htmlspecialchars($data['status']['download']) ?></div></div>
      <div class="stat"><div class="k" data-i18n="up">Загружено</div><div class="v"><?= htmlspecialchars($data['status']['upload']) ?></div></div>
      <div class="stat"><div class="k" data-i18n="limit">Лимит</div><div class="v"><?= htmlspecialchars($data['status']['limit'] ?: '—') ?></div></div>
    </div>
  </section>

  <section>
    <h2 data-i18n="servers-title">Серверы в подписке</h2>
    <div class="servers">
      <?php foreach ($data['servers'] as $s): ?>
      <div class="server">
        <span class="flag"><?= htmlspecialchars($s['flag']) ?></span>
        <span class="tag"><?= htmlspecialchars($s['tag']) ?></span>
        <span class="protos"><?= htmlspecialchars(implode(' · ', $s['protocols']) ?: '—') ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section>
    <h2 data-i18n="setup-title">Настройка приложения</h2>
    <?php $platformLabels = ['ios' => 'iOS', 'android' => 'Android', 'windows' => 'Windows', 'macos' => 'macOS']; ?>
    <div class="tabs">
      <?php $first = true; foreach (array_keys($apps) as $platform): ?>
      <div class="tab<?= $first ? ' active' : '' ?>" data-platform="<?= $platform ?>"><?= htmlspecialchars($platformLabels[$platform] ?? $platform) ?></div>
      <?php $first = false; endforeach; ?>
    </div>
    <?php $first = true; foreach ($apps as $platform => $list): ?>
    <div class="app-platform<?= $first ? ' active' : '' ?>" data-platform-panel="<?= $platform ?>">
      <?php foreach ($list as $app): $addLink = !empty($app['add']) ? resolveAppAddLink($app['add'], $data) : null; $downloadLink = resolveDownloadLink($app, $appsCache); $routingLink = !empty($app['routing']) ? resolveRoutingLink($app['routing'], $data) : null; ?>
      <div class="app">
        <img class="icon" src="/webapp<?= $hash ?>/icons/<?= rawurlencode($app['icon']) ?>.png" alt="">
        <div>
          <div class="name"><?= htmlspecialchars($app['name']) ?></div>
          <?php if (!empty($app['hint'])): ?>
          <div class="hint" data-hint-ru="<?= htmlspecialchars(subscriptionHint($app['hint'], 'ru')) ?>" data-hint-en="<?= htmlspecialchars(subscriptionHint($app['hint'], 'en')) ?>"><?= htmlspecialchars(subscriptionHint($app['hint'], 'ru')) ?></div>
          <?php endif; ?>
        </div>
        <div class="go">
          <a class="btn<?= $addLink ? '' : ' primary' ?>" href="<?= htmlspecialchars($downloadLink) ?>" target="_blank" rel="noopener"><span class="step-num">1</span><span data-i18n="download">Скачать</span></a>
          <?php if ($addLink): ?>
          <a class="btn primary" href="<?= htmlspecialchars($addLink) ?>"><span class="step-num">2</span><span data-i18n="add">Добавить</span></a>
          <?php endif; ?>
          <?php if ($routingLink): ?>
          <a class="btn" href="<?= htmlspecialchars($routingLink) ?>"><span class="step-num">3</span><span data-i18n="activate-routing">Активировать роутинг</span></a>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php $first = false; endforeach; ?>
  </section>

  <section>
    <h2 data-i18n="links-title">Ссылки для ручного добавления</h2>
    <div class="linklist">
      <div class="linkrow"><span class="lname">sing-box</span><code id="l-singbox"><?= htmlspecialchars($data['links']['singbox']) ?></code><button class="btn" data-copy="l-singbox" title="copy">⧉</button></div>
      <div class="linkrow"><span class="lname">xray</span><code id="l-xray"><?= htmlspecialchars($data['links']['xray']) ?></code><button class="btn" data-copy="l-xray" title="copy">⧉</button></div>
      <div class="linkrow"><span class="lname">mihomo</span><code id="l-clash"><?= htmlspecialchars($data['links']['clash']) ?></code><button class="btn" data-copy="l-clash" title="copy">⧉</button></div>
      <div class="linkrow"><span class="lname">Happ/INCY</span><code id="l-happ"><?= htmlspecialchars($data['links']['happ']) ?></code><button class="btn" data-copy="l-happ" title="copy">⧉</button></div>
      <div class="linkrow"><span class="lname">vless</span><code id="l-vless"><?= htmlspecialchars($data['links']['vless']) ?></code><button class="btn" data-copy="l-vless" title="copy">⧉</button></div>
    </div>
  </section>

  <footer>sbbot · <?= htmlspecialchars($data['username']) ?></footer>
</div>

<script>
  const dict = {
    ru: {
      brand:'Информация', active:'Активна', inactive:'Отключена', until:'до', down:'Скачано', up:'Загружено', limit:'Лимит',
      'servers-title':'Серверы в подписке', 'setup-title':'Настройка приложения',
      download:'Скачать', add:'Добавить',
      'links-title':'Ссылки для ручного добавления',
      'activate-routing':'Активировать роутинг'
    },
    en: {
      brand:'Information', active:'Active', inactive:'Disabled', until:'until', down:'Downloaded', up:'Uploaded', limit:'Limit',
      'servers-title':'Servers in this subscription', 'setup-title':'App setup',
      download:'Download', add:'Add',
      'links-title':'Manual import links',
      'activate-routing':'Activate routing'
    }
  };
  function setLang(lang){
    document.querySelectorAll('[data-i18n]').forEach(el=>{
      const key = el.getAttribute('data-i18n');
      if (dict[lang][key]) el.textContent = dict[lang][key];
    });
    document.querySelectorAll('.hint').forEach(el=>{
      const v = el.getAttribute('data-hint-' + lang);
      if (v) el.textContent = v;
    });
    document.querySelectorAll('[data-lang-btn]').forEach(b=>{
      b.classList.toggle('active', b.getAttribute('data-lang-btn')===lang);
    });
    document.documentElement.setAttribute('lang', lang);
    try { localStorage.setItem('sub_lang', lang); } catch(e){}
  }
  document.querySelectorAll('[data-lang-btn]').forEach(b=>{
    b.addEventListener('click', ()=> setLang(b.getAttribute('data-lang-btn')));
  });
  document.querySelectorAll('.tab').forEach(t=>{
    t.addEventListener('click', ()=>{
      const platform = t.getAttribute('data-platform');
      document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));
      t.classList.add('active');
      document.querySelectorAll('.app-platform').forEach(p=>{
        p.classList.toggle('active', p.getAttribute('data-platform-panel')===platform);
      });
    });
  });
  document.querySelectorAll('[data-copy]').forEach(b=>{
    b.addEventListener('click', ()=>{
      const text = document.getElementById(b.getAttribute('data-copy')).textContent;
      navigator.clipboard && navigator.clipboard.writeText(text);
      const old = b.textContent;
      b.textContent = '✓';
      setTimeout(()=> b.textContent = old, 1000);
    });
  });
  let initialLang = 'ru';
  try { initialLang = (localStorage.getItem('sub_lang')) || ((navigator.language||'').startsWith('ru') ? 'ru' : 'en'); } catch(e){}
  setLang(initialLang);
</script>
</body>
</html>

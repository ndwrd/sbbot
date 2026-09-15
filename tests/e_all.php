<?php
// Стенд: гоняет НАСТОЯЩИЙ код бота под E_ALL и собирает реальные warning'и —
// ровно то, что попало бы в /logs/php_error после переключения error_reporting.
// Статикой неопределённые ключи массивов надёжно не найти.
//
// Каждый сценарий — отдельный процесс: в коде полно exit (subscription(),
// createSrs()), который иначе обрывал бы весь прогон. Вывод печатается из
// register_shutdown_function(), поэтому exit ничего не теряет.
//
// Запуск: php tests/e_all.php              — прогнать всё
//         php tests/e_all.php <n> <профиль> — один сценарий (внутренний режим)
//
// Ничего не трогает в системе: работает в своей папке во временном каталоге.
// См. tests/readme.md.

$REPO = dirname(__DIR__);
$TMP  = sys_get_temp_dir() . '/sbbot_eall';
$PHP  = PHP_BINARY;
$uid  = 'ed5f12c0-6f0a-46ad-9a4b-10eb08e3cb6b';

$names = [
    'sub() страница подписки', 'subscription t=si', 'subscription t=s xray',
    'subscription t=cl mihomo', 'subscription t=hp happ', 'userXr(0) меню юзера',
    'singbox() экран Sing-box', 'listXr(0) список юзеров', 'statsMenu()',
    'templates(sing)', 'templates(clash)', 'linkVless варианты',
    'xtlsproxy/xtlsblock', 'xtlsapp/xtlsprocess', 'xtlssubnet/xtlsrulesset',
    'exportList(есть данные)', 'exportList(пусто)', 'export() бэкап',
    'cron checkBackup/checkLogs', 'cron checkResetSingboxStats', 'cron singboxStatsUser',
    'cron checkNodeProvisioning', 'cron checkNodeAutoCleanLogs', 'getSubscriptionServers',
    'ensureMainGeoTag', 'buildSingboxConfig', 'menu(config)', 'happSubUrl/buildHappRouting',
    'menu(nodes)/nodeMenu', 'warp()', 'dnstt()', 'mtproto()/linkMtproto',
    'configMenu()', 'xtlswarp/backXtlsList', 'userXr(1) второй юзер',
    'timerXr/limitXr/switchXr', 'getSingboxTotalTraffic', 'saveTemplate валидный',
    'importFile(бэкап)', 'addxrus + delxr', 'deleteAll(includelist)',
    'getHostStats/getSingboxSysStats', 'users() экран Users', 'menu() главное меню',
    'cron checkMenuStatus', 'nodeMenu офлайн + nodeRebind', 'перепривязка: пароль/ошибка/нет ноды',
    'importFile(бэкап vpnbot)',
    // Ниже — точки входа. Они грузят bot.php сами, поэтому обрабатываются
    // отдельной веткой и обязаны идти последними: $ENTRY_FROM смотрит на индекс.
    'index.php запрос подписки', 'index.php мусорный URL',
];
$ENTRY_FROM = count($names) - 2;

// ================= режим драйвера =================
// Два профиля конфига: 'full' — обжитый сервер, 'fresh' — только что
// установленный (в pac.json почти ничего нет). Именно на втором и вылезает
// большинство отсутствующих ключей, поэтому гоняем оба.
if (!isset($argv[1])) {
    $all = []; $rows = [];
    foreach (['full', 'fresh'] as $profile) {
        foreach ($names as $n => $label) {
            $out = shell_exec(escapeshellarg($PHP) . ' ' . escapeshellarg(__FILE__) . " $n $profile 2>&1");
            $j = json_decode(substr($out, (int) strpos($out, '@@JSON@@') + 8) ?: '', true);
            if (!is_array($j)) { $rows[] = [$profile, $label, '?', 'стенд не отработал: ' . substr(trim($out), 0, 80)]; continue; }
            $rows[] = [$profile, $label, count($j['warn']), $j['fatal'] ?? null];
            foreach ($j['warn'] as $w) $all[$w] = ($all[$w] ?? 0) + 1;
        }
    }
    echo "=== СЦЕНАРИИ (профиль / сценарий) ===\n";
    foreach ($rows as [$p, $l, $c, $err]) {
        if ($c === 0 && !$err) continue;                      // чистые не печатаем
        printf("  %-6s %-32s %-14s %s\n", $p, $l, $c === '?' ? '' : "$c предупр.", $err ? "[" . substr($err, 0, 100) . "]" : '');
    }
    $clean = count(array_filter($rows, fn($r) => $r[2] === 0 && !$r[3]));
    echo "  (чистых сценариев: $clean из " . count($rows) . ")\n";
    echo "\n=== УНИКАЛЬНЫЕ ПРЕДУПРЕЖДЕНИЯ (" . count($all) . ") ===\n";
    $k = array_keys($all); sort($k);
    foreach ($k as $w) printf("  %-4s %s\n", $all[$w] . 'x', $w);
    exit;
}
$PROFILE = $argv[2] ?? 'full';

// ================= режим сценария =================
error_reporting(E_ALL);
ini_set('display_errors', '0');
$WARN = [];
register_shutdown_function(function () use (&$WARN) {
    $f = error_get_last();
    $fatal = ($f && in_array($f['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR])) ? basename($f['file']) . ':' . $f['line'] . ' ' . $f['message'] : null;
    echo "@@JSON@@" . json_encode(['warn' => array_keys($WARN), 'fatal' => $fatal]);
});
set_error_handler(function ($no, $str, $file, $line) use (&$WARN) {
    // В PHP 8 оператор @ всё равно зовёт пользовательский обработчик, просто
    // временно обнуляет error_reporting(). Штатный обработчик PHP это
    // учитывает и в лог ничего не пишет — повторяем то же поведение, иначе
    // стенд считает подавленным вызовам то, чего в /logs/php_error не будет.
    if (!(error_reporting() & $no)) return true;
    if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED) return true;   // их исключаем из E_ALL
    $WARN[basename($file) . ':' . $line . '  ' . $str] = 1;
    return true;
});

// ---------- песочница ----------
// Профиль 'fresh' обязан быть ЧЕСТНО пустым. Первый заход на боевой сервер
// показал, чего стоит слишком щедрая фикстура: стенд создавал config.php с
// ключом 'debug', mtproto-секреты и файл статистики — то есть проверял более
// обжитую установку, чем бывает сразу после init.sh. В итоге `if ($c['debug'])`
// давал по warning'у НА КАЖДЫЙ HTTP-запрос в бою и ни одного на стенде.
// Всё, что создаётся по ходу работы бота, в 'fresh' не создаём.
$virgin = $PROFILE === 'fresh';

foreach (['', '/config', '/config/dnstt', '/logs', '/certs', '/ssh', '/update', '/qr'] as $d) @mkdir($TMP . $d, 0777, true);
foreach (glob("$TMP/config/*") as $f) if (is_file($f)) @unlink($f);   // не тащим хвосты прошлого прогона
foreach (glob("$REPO/config/*.json") as $f) copy($f, "$TMP/config/" . basename($f));
if (!$virgin) {
    file_put_contents("$TMP/config/mtprotosecret", str_repeat('a', 32));
    file_put_contents("$TMP/config/mtprotodomain", 'yandex.ru');
    file_put_contents("$TMP/update/reload_message", '1:1');
    file_put_contents("$TMP/update/message", '');
} else {
    foreach (['mtprotosecret', 'mtprotodomain', 'mtprotoadtag', 'singbox.stats'] as $f) @unlink("$TMP/config/$f");
    foreach (['reload_message', 'message'] as $f) @unlink("$TMP/update/$f");
}
file_put_contents("$TMP/certs/cert_public", '');
file_put_contents("$TMP/version", '1.0');
foreach (['nginx.conf', 'nginx_default.conf', 'upstream.conf', 'include.conf'] as $f) {
    if (file_exists("$REPO/config/$f")) copy("$REPO/config/$f", "$TMP/config/$f");
}
if (!$virgin) {
    file_put_contents("$TMP/config/dnstt/server.pub", str_repeat('b', 32));
    file_put_contents("$TMP/config/dnstt/server.key", str_repeat('c', 32));
} else {
    foreach (['server.pub', 'server.key'] as $f) @unlink("$TMP/config/dnstt/$f");
}
file_put_contents("$TMP/config/location.conf", '');
file_put_contents("$TMP/config/override.conf", '');

$pac = [
    'hashbot' => '53924739', 'domain' => '31-57-241-154.nip.io', 'transport' => 'ws',
    'geoTag' => 'DE', 'letsencrypt' => 'letsencrypt', 'language' => 'ru', 'limitpage' => 5,
    'reality' => ['domain' => 'www.microsoft.com', 'destination' => 'www.microsoft.com:443',
                  'shortId' => 'abcd', 'privateKey' => 'k', 'publicKey' => 'p'],
    'naiveSubdomain' => 'n1', 'anytlsSubdomain' => 'a1',
    'singboxClients' => [
        ['id' => $uid, 'username' => 'testuser', 'password' => 'pw', 'time' => time() + 86400, 'trafficlimit' => 107374182400],
        ['id' => 'aaaa1111-0000-4000-8000-aaaabbbbcccc', 'username' => 'second', 'password' => 'pw2'],
    ],
    'includelist' => ['example.com' => true], 'blocklist' => ['ads.example' => true],
    // Адрес из TEST-NET-3: ssh() на стенде заглушен, так что нода выглядит
    // недоступной — ровно состояние после восстановления из бэкапа.
    'nodes' => ['n1a2b3c4' => ['label' => 'Helsinki', 'ip' => '203.0.113.10', 'login' => 'root', 'geoTag' => 'FI']],
];
// Профиль 'fresh' — только что поставленный бот: один пользователь, никаких
// списков, шаблонов, нод, домена и статистики. Самое частое состояние ключей
// в pac.json — «их нет».
if ($PROFILE === 'fresh') {
    $pac = [
        'hashbot' => '53924739',
        'singboxClients' => [['id' => $uid, 'username' => 'testuser', 'password' => 'pw']],
    ];
}
file_put_contents("$TMP/config/pac.json", json_encode($pac, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
// В fresh файла нет ВООБЩЕ (его создаёт первый setSingboxStats()), а не '{}'.
if (!$virgin) {
    file_put_contents("$TMP/config/singbox.stats", json_encode(['users' => [0 => ['global' => ['download' => 100, 'upload' => 50]]]]));
}

// ---------- код с подменой абсолютных путей ----------
$map = [
    "'/config/" => "'$TMP/config/", '"/config/' => "\"$TMP/config/",
    "'/logs/"   => "'$TMP/logs/",   '"/logs/'   => "\"$TMP/logs/",
    "'/certs/"  => "'$TMP/certs/",  '"/certs/'  => "\"$TMP/certs/",
    "'/ssh/"    => "'$TMP/ssh/",    '"/ssh/'    => "\"$TMP/ssh/",
    "'/update/" => "'$TMP/update/", '"/update/' => "\"$TMP/update/",
    "'/version'" => "'$TMP/version'", "'/start'" => "'$TMP/start'",
];
// Заглушки расширений, которых нет в локальной минимальной сборке PHP, но
// которые есть в контейнере (см. dockerfile/php.dockerfile: mbstring, intl,
// pecl-yaml, opcache, curl). Без них сценарии падают на первом же вызове и
// код за ними остаётся непроверенным.
if (!function_exists('mb_chr')) {
    function mb_chr($cp, $enc = null) { return html_entity_decode('&#' . (int) $cp . ';', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('idn_to_utf8'))  { function idn_to_utf8($d, $f = 0, $v = 1, &$i = null)  { return $d; } }
if (!function_exists('idn_to_ascii')) { function idn_to_ascii($d, $f = 0, $v = 1, &$i = null) { return $d; } }
if (!function_exists('yaml_emit'))    { function yaml_emit($d, ...$a) { return json_encode($d); } }
if (!function_exists('opcache_invalidate')) { function opcache_invalidate($f, $force = false) { return true; } }
if (!function_exists('yaml_parse_file')) { function yaml_parse_file($f, ...$a) { return []; } }
if (!function_exists('yaml_parse'))      { function yaml_parse($s, ...$a) { return []; } }
if (!class_exists('CURLStringFile')) { class CURLStringFile { public function __construct(public $data = '', public $postname = '', public $mime = '') {} } }
if (!class_exists('CURLFile'))       { class CURLFile       { public function __construct(public $name = '', public $mime = '', public $postname = '') {} } }
if (!function_exists('mb_strlen'))   { function mb_strlen($s, $e = null) { return preg_match_all('/./us', (string) $s); } }
if (!function_exists('mb_str_pad'))  { function mb_str_pad($s, $l, $p = ' ', $t = STR_PAD_RIGHT, $e = null) { return str_pad($s, $l + strlen($s) - mb_strlen($s), $p, $t); } }
// Главное меню разбирает сертификат (expireCert()/domainsCert()).
if (!function_exists('openssl_x509_read'))  { function openssl_x509_read($c) { return $c; } }
if (!function_exists('openssl_x509_parse')) { function openssl_x509_parse($c, $s = true) { return ['validTo_time_t' => time() + 86400 * 60, 'extensions' => ['subjectAltName' => 'DNS:31-57-241-154.nip.io']]; } }

// Пишем подменённые исходники во временные файлы и require'им, а не eval'им:
// в сообщениях об ошибках тогда видно настоящее имя файла и номер строки
// (подмена путей идёт внутри строковых литералов и строк не добавляет).
// Структура $TMP/app/traits/ — ровно как в репозитории: код внутри трейтов
// ходит по dirname(__DIR__) за subscription.php и config.php, и при плоской
// раскладке эти require падали, обрывая сценарии на середине.
@mkdir("$TMP/app/traits", 0777, true);
@mkdir("$TMP/app/qr", 0777, true);
foreach (glob("$REPO/app/*.php") as $f) copy($f, "$TMP/app/" . basename($f));
foreach (glob("$REPO/app/*.json") as $f) copy($f, "$TMP/app/" . basename($f));
// config.php в профиле fresh — ровно то, что пишет scripts/init.sh: ОДИН ключ
// key, без admin и без debug. Отсутствие debug давало по warning'у на каждый
// HTTP-запрос в бою, а стенд его не видел, потому что фикстура была полнее.
file_put_contents("$TMP/app/config.php", $virgin
    ? "<?php\n\n\$c = ['key' => '123456:TESTTOKEN'];\n"
    : "<?php\n\n\$c = ['key' => '123456:TESTTOKEN', 'admin' => [1], 'debug' => false];\n");
// Трейты кладём с подменёнными путями, а bot.php — КАК ЕСТЬ: его require_once
// сам подтянет их из $TMP/app/traits/. Раньше эти строки вырезались, а трейты
// грузились вручную — из-за чего index.php нельзя было прогнать вообще
// (он грузит bot.php сам, и получалось повторное объявление class Bot).
foreach (glob("$REPO/app/traits/*.php") as $f) {
    file_put_contents("$TMP/app/traits/" . basename($f), strtr(file_get_contents($f), $map));
}
file_put_contents("$TMP/app/bot.php", strtr(file_get_contents("$REPO/app/bot.php"), $map));

// Точки входа прогоняются ДО загрузки bot.php: index.php грузит его сам.
// Это единственный способ проверить сам роутер и его шапку — а именно оттуда
// и шёл весь шум в /logs/php_error.
if (($argv[1] ?? '') !== '' && (int) $argv[1] >= $ENTRY_FROM) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SERVER_NAME']    = '31-57-241-154.nip.io';
    $_SERVER['REQUEST_URI']    = (int) $argv[1] === $ENTRY_FROM
        ? '/pac53924739/' . base64_encode(serialize(['h' => '53924739', 't' => 'si', 's' => $uid]))
        : '/' . bin2hex(random_bytes(6));
    $_GET = $_POST = [];
    ob_start();
    require "$TMP/app/index.php";
    ob_end_clean();
    return;
}

require "$TMP/app/bot.php";
require "$REPO/app/i18n.php";

class TestBot extends Bot
{
    // Сигнатуры должны совпадать с настоящими до последнего аргумента, иначе
    // PHP роняет фатал прямо на объявлении класса и ни один сценарий не стартует.
    public function request($method, $data, $json_header = 0) {
        return match ($method) {
            'getMyName' => ['result' => ['name' => 'TestBot']],
            'getFile'   => ['result' => ['file_path' => 'x']],
            default     => ['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => 1]]],
        };
    }
    public function ssh($cmd, $service = 'service', $wait = true, $log = '/dev/null', $host = null) { return ''; }
    public function send($chat, $text, ?int $to = 0, $button = false, $reply = false, $mode = 'HTML', $disable_notification = false) { return ['result' => ['message_id' => 1]]; }
    public function update($chat, $message_id, $text, $button = false, $reply = false, $mode = 'HTML') { return ['result' => ['message_id' => 1]]; }
    public function answer($callback_id, $textNotify = false, $notify = false) { return true; }
    public function sendPhoto($chat, $id_url_cFile, $caption = false, $to = false) { return ['result' => ['message_id' => 1]]; }
    public function sendFile($chat, $id_url_cFile, $caption = false, $to = false) { return ['result' => ['message_id' => 1]]; }
    public function sendDraft($chat, $draft_id, $text = '', $mode = 'HTML') { return true; }
    public function upload($name, $code, $chat = false) { return ['result' => ['message_id' => 1]]; }
    public function pin($chat, $message_id, $notnotify = true) { return true; }
    public function unpin($chat, $message_id) { return true; }
    public function restartSingbox($c, $norestart = false) { return true; }
    public function queryV2raySingboxStats($host = null) { return ['users' => [], 'inbounds' => []]; }
    public function cleanDocker() { return true; }
    // curl-расширения локально нет; гео и так кэшируется в pac при создании
    public function geoCountryCode($ip) { return 'DE'; }
}

$GLOBALS['debug'] = false;
$b = new TestBot('123456:TESTTOKEN', $i);
$b->pac       = "$TMP/config/pac.json";
$b->appsCache = "$TMP/config/apps_cache.json";
$b->resetPacCache();          // конструктор успел закэшировать пустой конфиг со старого пути
$b->ip    = '31.57.241.154';
$b->limit = 5;
$b->input = ['chat' => 1, 'message_id' => 1, 'from' => 1, 'username' => 'admin',
             'callback_id' => false, 'file_id' => false, 'callback' => '', 'caption' => 'cap'];
$_SESSION = [];
$_SERVER['REQUEST_URI'] = '/pac53924739/x';
$_SERVER['SERVER_NAME'] = '31-57-241-154.nip.io';

$s = [
    fn() => (function () use ($b, $uid) { $_GET = ['id' => $uid]; ob_start(); $b->sub(); ob_end_clean(); })(),
    fn() => (function () use ($b, $uid) { $_GET = ['t' => 'si', 's' => $uid]; ob_start(); $b->subscription(); ob_end_clean(); })(),
    fn() => (function () use ($b, $uid) { $_GET = ['t' => 's',  's' => $uid]; ob_start(); $b->subscription(); ob_end_clean(); })(),
    fn() => (function () use ($b, $uid) { $_GET = ['t' => 'cl', 's' => $uid]; ob_start(); $b->subscription(); ob_end_clean(); })(),
    fn() => (function () use ($b, $uid) { $_GET = ['t' => 'hp', 's' => $uid]; ob_start(); $b->subscription(); ob_end_clean(); })(),
    fn() => $b->userXr(0),
    fn() => $b->singbox(),
    fn() => $b->listXr(0),
    fn() => $b->statsMenu(),
    fn() => $b->templates('sing'),
    fn() => $b->templates('clash'),
    fn() => [$b->linkVless(0), $b->linkVless(0, 1), $b->linkVless(0, 2), $b->linkVless(0, 3)],
    fn() => [$b->xtlsproxy(), $b->xtlsblock()],
    fn() => [$b->xtlsapp(), $b->xtlsprocess()],
    fn() => [$b->xtlssubnet(), $b->xtlsrulesset()],
    fn() => $b->exportList('includelist'),
    fn() => $b->exportList('warplist'),
    fn() => $b->export(),
    fn() => [$b->checkBackup(), $b->checkLogs()],
    fn() => $b->checkResetSingboxStats(),
    fn() => $b->singboxStatsUser(),
    fn() => $b->checkNodeProvisioning(),
    fn() => $b->checkNodeAutoCleanLogs(),
    fn() => $b->getSubscriptionServers(),
    fn() => $b->ensureMainGeoTag(),
    fn() => $b->buildSingboxConfig($b->getPacConf()),
    fn() => $b->menu('config'),
    fn() => [$b->happSubUrl($uid), $b->buildHappRouting($b->getPacConf())],
    fn() => $b->menu('nodes'),
    fn() => $b->warp(),
    fn() => $b->dnstt(),
    fn() => [$b->mtproto(), $b->linkMtproto()],
    fn() => $b->configMenu(),
    fn() => [$b->xtlswarp(), $b->backXtlsList('includelist', 0)],
    fn() => $b->userXr(1),
    fn() => [$b->timerXr(0), $b->limitXr(0), $b->switchXr(0)],
    fn() => $b->getSingboxTotalTraffic($b->getSingboxStats()),
    fn() => $b->saveTemplate('мой', 'sing', json_encode(['outbounds' => []])),
    fn() => (function () use ($b, $TMP) {
        file_put_contents("$TMP/backup.json", $b->export());
        $b->importFile("$TMP/backup.json");
    })(),
    fn() => [$b->addxrus('newuser'), $b->delxr(1)],
    fn() => $b->deleteAll('includelist'),
    fn() => [$b->getHostStats(), $b->getSingboxSysStats()],
    fn() => $b->users(),
    fn() => $b->menu(),
    fn() => [$b->checkMenuStatus(), $b->menu()],
    fn() => [$b->nodeMenu('n1a2b3c4'), $b->nodeRebind('n1a2b3c4')],
    // Локально нет ssh2 — nodeBootstrap() вернёт ошибку, это ветка ERROR.
    fn() => [$b->nodeAuthPassword('n1a2b3c4', 'rebindNodePassword'), $b->nodeAuthKey('n1a2b3c4', 'rebindNodeKey'),
             $b->rebindNodePassword(' secret ', 'n1a2b3c4'), $b->rebindNodeKey('not a key', 'n1a2b3c4'),
             $b->finishRebindNode('gone0000', 'password', 'x'), $b->nodeRebind('gone0000')],
    // Бэкап в формате vpnbot: разделы, которых у sbbot нет, uuid-дубль
    // существующего пользователя, клиент без email, свой домен (не nip.io).
    fn() => (function () use ($b, $TMP, $uid) {
        file_put_contents("$TMP/vpnbot.json", json_encode([
            'wg' => ['server' => [], 'clients' => []], 'wg1' => [], 'ad' => [], 'hwid' => [], 'hy' => [],
            'oc' => '', 'ocu' => '', 'ss' => [], 'sl' => [], 'xraystats' => ['users' => []],
            'pac' => [
                'domain' => 'vpn.example.com', 'hashbot' => '11112222', 'language' => 'fa', 'limitpage' => 7,
                'transport' => 'Reality', 'amnezia' => 1, 'includelist' => ['old.example' => true],
                'dnsttDomain' => 't.example.com', 'dnsttPassword' => 'pw',
            ],
            'xray' => ['inbounds' => [['settings' => ['clients' => [
                ['id' => $uid, 'email' => 'dup', 'flow' => 'xtls-rprx-vision'],
                ['id' => '11111111-2222-4333-8444-555555555555', 'email' => 'mama', 'time' => time() + 86400, 'off' => '11111111-2222-4333-8444-555555555555'],
                ['id' => '99999999-2222-4333-8444-555555555555'],
            ]]]]],
            'mtproto' => str_repeat('d', 32), 'mtprotodomain' => 'ya.ru', 'ssl' => false, 'dnstt' => false,
        ]));
        $b->importFile("$TMP/vpnbot.json");
    })(),
];
($s[(int) $argv[1]])();

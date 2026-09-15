<?php
// Снимок ПОВЕДЕНИЯ: гоняет набор функций на указанной копии репозитория и
// печатает @@OUT@@<json> с результатами — возвращаемые значения, напечатанный
// вывод и все сообщения, которые бот отправил бы.
//
// Смысл — сравнить снимки до и после правки и убедиться, что поведение не
// изменилось. Как это делается, см. tests/readme.md.
//
// Запуск: php tests/compare_behaviour.php <путь-к-репо> <full|fresh>
error_reporting(E_ALL);
ini_set('display_errors', '0');
set_error_handler(fn() => true);          // предупреждения тут не интересны, только результат

$REPO    = $argv[1];
$PROFILE = $argv[2] ?? 'full';
$TMP     = sys_get_temp_dir() . '/sbbot_cmp_' . md5($REPO . $PROFILE);
$uid     = 'ed5f12c0-6f0a-46ad-9a4b-10eb08e3cb6b';

foreach (['', '/config', '/config/dnstt', '/logs', '/certs', '/ssh', '/update', '/qr'] as $d) @mkdir($TMP . $d, 0777, true);
foreach (glob("$REPO/config/*.json") as $f) copy($f, "$TMP/config/" . basename($f));
foreach (['nginx.conf', 'nginx_default.conf', 'upstream.conf'] as $f) if (file_exists("$REPO/config/$f")) copy("$REPO/config/$f", "$TMP/config/$f");
file_put_contents("$TMP/config/mtprotosecret", str_repeat('a', 32));
file_put_contents("$TMP/config/mtprotodomain", 'yandex.ru');
file_put_contents("$TMP/config/dnstt/server.pub", str_repeat('b', 32));
file_put_contents("$TMP/config/dnstt/server.key", str_repeat('c', 32));
file_put_contents("$TMP/certs/cert_public", '');
file_put_contents("$TMP/version", '1.0');

$pac = [
    'hashbot' => '53924739', 'domain' => '31-57-241-154.nip.io', 'transport' => 'ws',
    'geoTag' => 'DE', 'letsencrypt' => 'letsencrypt', 'language' => 'ru', 'limitpage' => 5,
    'reality' => ['domain' => 'www.microsoft.com', 'destination' => 'www.microsoft.com:443',
                  'shortId' => 'abcd', 'privateKey' => 'k', 'publicKey' => 'p'],
    'naiveSubdomain' => 'n1', 'anytlsSubdomain' => 'a1',
    'singboxClients' => [
        ['id' => $uid, 'username' => 'testuser', 'password' => 'pw', 'time' => 1800000000, 'trafficlimit' => 107374182400],
        ['id' => 'aaaa1111-0000-4000-8000-aaaabbbbcccc', 'username' => 'second', 'password' => 'pw2'],
    ],
    'includelist' => ['example.com' => true], 'blocklist' => ['ads.example' => true],
];
if ($PROFILE === 'fresh') {
    $pac = ['hashbot' => '53924739', 'singboxClients' => [['id' => $uid, 'username' => 'testuser', 'password' => 'pw']]];
}
file_put_contents("$TMP/config/pac.json", json_encode($pac));
file_put_contents("$TMP/config/singbox.stats", $PROFILE === 'fresh' ? '{}'
    : json_encode(['users' => [0 => ['global' => ['download' => 100, 'upload' => 50]]]]));

$map = [
    "'/config/" => "'$TMP/config/", '"/config/' => "\"$TMP/config/",
    "'/logs/" => "'$TMP/logs/", '"/logs/' => "\"$TMP/logs/",
    "'/certs/" => "'$TMP/certs/", '"/certs/' => "\"$TMP/certs/",
    "'/ssh/" => "'$TMP/ssh/", '"/ssh/' => "\"$TMP/ssh/",
    "'/update/" => "'$TMP/update/", '"/update/' => "\"$TMP/update/",
    "'/version'" => "'$TMP/version'", "'/start'" => "'$TMP/start'",
];
if (!function_exists('mb_chr')) { function mb_chr($cp, $e = null) { return html_entity_decode('&#' . (int) $cp . ';', ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('idn_to_utf8'))  { function idn_to_utf8($d, $f = 0, $v = 1, &$i = null) { return $d; } }
if (!function_exists('idn_to_ascii')) { function idn_to_ascii($d, $f = 0, $v = 1, &$i = null) { return $d; } }
if (!function_exists('yaml_emit'))    { function yaml_emit($d, ...$a) { return json_encode($d, JSON_PRETTY_PRINT); } }
if (!function_exists('yaml_parse_file')) { function yaml_parse_file($f, ...$a) { return []; } }
if (!function_exists('opcache_invalidate')) { function opcache_invalidate($f, $x = false) { return true; } }
if (!class_exists('CURLStringFile')) { class CURLStringFile { public function __construct(public $data = '', public $postname = '', public $mime = '') {} } }
if (!class_exists('CURLFile')) { class CURLFile { public function __construct(public $name = '', public $mime = '', public $postname = '') {} } }
if (!function_exists('mb_strlen'))   { function mb_strlen($s, $e = null) { return preg_match_all('/./us', (string) $s); } }
if (!function_exists('mb_str_pad'))  { function mb_str_pad($s, $l, $p = ' ', $t = STR_PAD_RIGHT, $e = null) { return str_pad($s, $l + strlen($s) - mb_strlen($s), $p, $t); } }
if (!function_exists('openssl_x509_read'))  { function openssl_x509_read($c) { return $c; } }
if (!function_exists('openssl_x509_parse')) { function openssl_x509_parse($c, $s = true) { return ['validTo_time_t' => 1900000000, 'extensions' => ['subjectAltName' => 'DNS:31-57-241-154.nip.io']]; } }

@mkdir("$TMP/app/traits", 0777, true);
@mkdir("$TMP/app/qr", 0777, true);
foreach (glob("$REPO/app/*.php") as $f) copy($f, "$TMP/app/" . basename($f));
foreach (glob("$REPO/app/*.json") as $f) copy($f, "$TMP/app/" . basename($f));
file_put_contents("$TMP/app/config.php", "<?php\n\n\$c = ['key' => '123456:TESTTOKEN', 'admin' => [1], 'debug' => false];\n");
foreach (glob("$REPO/app/traits/*.php") as $f) {
    file_put_contents("$TMP/app/traits/" . basename($f), strtr(file_get_contents($f), $map));
    require "$TMP/app/traits/" . basename($f);
}
file_put_contents("$TMP/app/bot.php", preg_replace('~require_once[^;]+;~', '', strtr(file_get_contents("$REPO/app/bot.php"), $map)));
require "$TMP/app/bot.php";
require "$REPO/app/i18n.php";

class CmpBot extends Bot
{
    public array $sent = [];
    public function request($method, $data, $json_header = 0) {
        return match ($method) {
            'getMyName' => ['result' => ['name' => 'TestBot']],
            'getFile'   => ['result' => ['file_path' => 'x']],
            default     => ['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => 1]]],
        };
    }
    public function ssh($cmd, $service = 'service', $wait = true, $log = '/dev/null', $host = null) { return ''; }
    // Тексты и клавиатуры меню — это и есть наблюдаемый результат, копим их.
    public function send($chat, $text, ?int $to = 0, $button = false, $reply = false, $mode = 'HTML', $dn = false) { $this->sent[] = ['send', $text, $button]; return ['result' => ['message_id' => 1]]; }
    public function update($chat, $message_id, $text, $button = false, $reply = false, $mode = 'HTML') { $this->sent[] = ['update', $text, $button]; return ['result' => ['message_id' => 1]]; }
    public function answer($cb, $t = false, $n = false) { return true; }
    public function sendPhoto($c, $f, $cap = false, $to = false) { $this->sent[] = ['photo', $cap]; return ['result' => ['message_id' => 1]]; }
    public function sendFile($c, $f, $cap = false, $to = false) { $this->sent[] = ['file', $cap]; return ['result' => ['message_id' => 1]]; }
    public function sendDraft($c, $d, $t = '', $m = 'HTML') { return true; }
    public function upload($name, $code, $chat = false) { return ['result' => ['message_id' => 1]]; }
    public function pin($c, $m, $n = true) { return true; }
    public function unpin($c, $m) { return true; }
    public function restartSingbox($c, $norestart = false) { return true; }
    public function queryV2raySingboxStats($host = null) { return ['users' => [], 'inbounds' => []]; }
    public function geoCountryCode($ip) { return 'DE'; }
    public function cleanDocker() { return true; }
}

$GLOBALS['debug'] = false;
$b = new CmpBot('123456:TESTTOKEN', $i);
$b->pac = "$TMP/config/pac.json"; $b->appsCache = "$TMP/config/apps_cache.json";
$b->resetPacCache();
$b->ip = '31.57.241.154'; $b->limit = 5;
$b->input = ['chat' => 1, 'message_id' => 1, 'from' => 1, 'username' => 'admin',
             'callback_id' => false, 'file_id' => false, 'callback' => '', 'caption' => 'cap'];
$_SESSION = [];
$_SERVER['REQUEST_URI'] = '/pac53924739/x'; $_SERVER['SERVER_NAME'] = '31-57-241-154.nip.io';

$grab = function (callable $f) use ($b) {
    $b->sent = [];
    ob_start();
    try { $r = $f(); } catch (Throwable $e) { $r = 'THROW ' . get_class($e); }
    $out = ob_get_clean();
    return ['out' => $out, 'ret' => is_string($r) ? $r : json_encode($r), 'sent' => $b->sent];
};

$R = [];
foreach ([
    'linkVless0'   => fn() => $b->linkVless(0),
    'linkVless0_1' => fn() => $b->linkVless(0, 1),
    'linkVless0_2' => fn() => $b->linkVless(0, 2),
    'linkVless0_3' => fn() => $b->linkVless(0, 3),
    'happSubUrl'   => fn() => $b->happSubUrl($uid),
    'happRouting'  => fn() => $b->buildHappRouting($b->getPacConf()),
    'getDomain'    => fn() => $b->getDomain() . '|' . $b->getDomain(true),
    'export'       => fn() => $b->export(),
    'buildSingbox' => fn() => $b->buildSingboxConfig($b->getPacConf()),
    'subServers'   => fn() => $b->getSubscriptionServers(),
    'userXr0'      => fn() => $b->userXr(0),
    'userXr99'     => fn() => $b->userXr(99),
    'singbox'      => fn() => $b->singbox(),
    'users'        => fn() => $b->users(),
    'menuMain'     => fn() => $b->menu(),
    'listXr'       => fn() => $b->listXr(0),
    'templatesSing'=> fn() => $b->templates('sing'),
    'xtlsproxy'    => fn() => $b->xtlsproxy(),
    'statsMenu'    => fn() => $b->statsMenu(),
    'getTime'      => fn() => $b->getTime(time() + 3600 * 50) . '|' . $b->getTime(time() + 90) . '|' . $b->getTime(0),
    'exportList'   => fn() => $b->exportList('includelist'),
    'exportListEmpty' => fn() => $b->exportList('warplist'),
    'menuConfig'   => fn() => $b->menu('config'),
    'warp'         => fn() => $b->warp(),
    // Нода кладётся в конфиг прямо здесь, а не в фикстуру: иначе поменялись бы
    // снимки подписок и экспорта выше. Поэтому эти два сценария — последние.
    // ssh() заглушен, значит нода «недоступна», как после восстановления.
    'nodeMenuOffline' => fn() => [$b->setNode('n1a2b3c4', ['label' => 'Helsinki', 'ip' => '203.0.113.10', 'login' => 'root', 'geoTag' => 'FI']), $b->nodeMenu('n1a2b3c4')],
    'nodeRebind'   => fn() => $b->nodeRebind('n1a2b3c4'),
    // Исключение упомянутой плейсхолдером ноды из общих групп. Протоколы с
    // "~domain~" стоят ДО групп нарочно: так был устроен баг, когда поиск
    // упомянутых нод цеплялся за первую тильду в JSON и нода RU попадала в Proxy.
    'geoExclusion' => fn() => (function () use ($b, $uid) {
        $servers = [
            ['tag' => '🇩🇪DE', 'geoTag' => 'DE', 'domain' => 'de.example.com', 'hash' => 'h', 'naiveSubdomain' => 'n', 'anytlsSubdomain' => 'a', 'outboundsOff' => [], 'isMain' => true],
            ['tag' => '🇷🇺RU', 'geoTag' => 'RU', 'domain' => 'ru.example.com', 'hash' => 'h', 'naiveSubdomain' => 'n', 'anytlsSubdomain' => 'a', 'outboundsOff' => [], 'isMain' => false],
            ['tag' => '🇫🇮FI', 'geoTag' => 'FI', 'domain' => 'fi.example.com', 'hash' => 'h', 'naiveSubdomain' => 'n', 'anytlsSubdomain' => 'a', 'outboundsOff' => [], 'isMain' => false],
        ];
        $clash = [
            'proxies' => [
                ['name' => '🇩🇪DE|Vless', 'type' => 'vless', 'server' => '~domain~'],
                ['name' => '🇩🇪DE|Hy2', 'type' => 'hysteria2', 'server' => '~domain~'],
            ],
            'proxy-groups' => [
                ['name' => 'Proxy', 'type' => 'fallback', 'proxies' => ['🇩🇪DE|Vless', '🇩🇪DE|Hy2']],
                ['name' => 'YT', 'type' => 'url-test', 'proxies' => ['~🇷🇺RU:outbounds~']],
            ],
        ];
        $sing = ['outbounds' => [
            ['tag' => '🇩🇪DE|Vless', 'type' => 'vless', 'server' => '~domain~'],
            ['tag' => '🇩🇪DE|Hy2', 'type' => 'hysteria2', 'server' => '~domain~'],
            ['tag' => 'Proxy', 'type' => 'selector', 'outbounds' => ['🇩🇪DE|Vless', '🇩🇪DE|Hy2']],
            ['tag' => '⚡️Auto', 'type' => 'urltest', 'outbounds' => ['🇩🇪DE|Vless', '🇩🇪DE|Hy2']],
            ['tag' => 'YT', 'type' => 'urltest', 'outbounds' => ['~🇷🇺RU:outbounds~']],
            ['tag' => 'Gone', 'type' => 'selector', 'outbounds' => ['~🇺🇸US:outbounds~'], 'default' => '🇺🇸US|Vless'],
        ]];
        $groups = fn ($list, $key, $items) => array_column(array_map(fn ($g) => [$g[$key], $g[$items] ?? null, $g['default'] ?? null], array_filter($list, fn ($g) => isset($g[$items]))), null);
        return [
            'clash' => $groups($b->buildClashMultiOutbounds($clash, $clash['proxies'], $servers, 'proxy', $uid, 'pw')['proxy-groups'], 'name', 'proxies'),
            'sing'  => $groups($b->buildSingMultiOutbounds($sing, $sing['outbounds'], $servers, 'proxy', $uid, 'u', 'pw')['outbounds'], 'tag', 'outbounds'),
        ];
    })(),
    // Только конвертация, без записи: username/password случайные, в снимок
    // идёт их длина.
    'vpnbotConvert' => fn() => (function () use ($b, $uid) {
        $r = $b->convertVpnbotBackup([
            'wg' => [], 'ad' => [], 'hwid' => [],
            'pac' => ['domain' => 'vpn.example.com', 'hashbot' => '11112222', 'language' => 'fa', 'limitpage' => 7,
                      'transport' => 'Reality', 'amnezia' => 1, 'includelist' => ['old.example' => true],
                      'reality' => ['privateKey' => 'vpnbot-key'], 'dnsttDomain' => 't.example.com'],
            'xray' => ['inbounds' => [['settings' => ['clients' => [
                ['id' => $uid, 'email' => 'dup'],
                ['id' => '11111111-2222-4333-8444-555555555555', 'email' => 'mama', 'time' => 1900000000, 'off' => 'x', 'flow' => 'xtls-rprx-vision', 'hwid_limit' => 3],
                ['id' => '99999999-2222-4333-8444-555555555555'],
            ]]]]],
            'mtproto' => 'm', 'ssl' => ['private' => 'p', 'public' => 'c'],
        ]);
        foreach ($r['singbox']['inbounds'][0]['settings']['clients'] as &$c) {
            $c['username'] = strlen($c['username']);
            $c['password'] = strlen($c['password']);
            if (isset($c['description']) && preg_match('~^[0-9a-f]{8}$~', $c['description'])) {
                $c['description'] = 'USERNAME';
            }
        }
        unset($r['pac']['singboxClients']);
        return $r;
    })(),
] as $name => $fn) {
    $R[$name] = $grab($fn);
}
echo '@@OUT@@' . json_encode($R, JSON_UNESCAPED_UNICODE);

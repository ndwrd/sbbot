<?php

trait BotPacTrait
{
public function getPacConf()
    {
        // Пустой/битый/ещё не созданный pac.json даёт json_decode() null — а
        // setPacConf() принимает только array (строгая типизация), так что любой
        // caller вида "$c = getPacConf(); ...; setPacConf($c)" падает TypeError'ом
        // на всём service.php (см. selfUpdate()). Гарантируем массив на выходе,
        // а не только у отдельных вызовов.
        // Кэш на время одной единицы работы. getPacConf() зовётся 10-20 раз на
        // один запрос подписки (getSingbox(), getHashBot(), getDomain(),
        // getSubscriptionServers(), ensureMainGeoTag()...), и каждый раз это
        // open+flock+read+json_decode целого pac.json. На конфиге с большими
        // списками доменов одно чтение стоит миллисекунды: 200 юзеров + 10k
        // доменов (~1 МБ) — 7 мс за чтение, то есть больше 100 мс на запрос
        // впустую.
        //
        // Область жизни кэша — ровно одна единица работы, и это не случайность:
        //   Unit   — Bot создаётся заново на каждый запрос (index.php);
        //   polling() и cron() — долгоживущие циклы, поэтому там кэш явно
        //                        сбрасывается в начале каждой итерации.
        // Внутри одной единицы работы читать согласованный снимок правильнее,
        // чем подхватывать чужие записи в середине.
        //
        // Записи кэш не ломают: setPacConf()/updatePacConf() кладут в него то,
        // что реально записали, а updatePacConf() читает конфиг заново под
        // блокировкой, кэш для этого не используется.
        if ($this->pacCache !== null) {
            return $this->pacCache;
        }
        return $this->pacCache = ($this->readJsonLocked($this->pac) ?: []);
    }

public function resetPacCache()
    {
        $this->pacCache = null;
    }

public function setPacConf(array $conf)
    {
        $r = $this->writeJsonLocked($this->pac, $conf);
        // Держим кэш в согласии с диском. Если запись не удалась — сбрасываем,
        // чтобы следующий getPacConf() не отдавал то, чего в файле нет.
        $this->pacCache = $r === false ? null : $conf;
        return $r;
    }

public function updatePacConf(callable $fn)
    {
        // Атомарный read-modify-write. getPacConf() + setPacConf() по
        // отдельности так не умеют: блокировка держится только внутри каждого
        // из них, а между ними другой процесс успевает записать своё — и
        // следующий setPacConf() затирает его целиком, потому что пишет весь
        // конфиг из снимка, снятого до чужой записи.
        //
        // Параллельные писатели тут есть всегда, это не редкий случай: cron()
        // крутится в контейнере service каждые 10 секунд, polling() — в php,
        // плюс Unit-процессы на бутстрапе. Особенно опасны места, где между
        // чтением и записью идёт что-то медленное (grpcurl по SSH, запрос к
        // Telegram, SSH на ноду) — там окно на секунды, и админская правка из
        // меню, попавшая в это окно, просто исчезает.
        //
        // $fn получает свежий конфиг, прочитанный уже ПОД блокировкой, и
        // возвращает изменённый — либо null, если писать не нужно. Внутри $fn
        // ничего медленного делать нельзя: на это время конфиг не могут
        // прочитать все остальные, включая запросы подписки.
        [$result, $conf] = $this->updateJsonLocked($this->pac, $fn);
        // $conf прочитан под блокировкой, то есть заведомо свежее кэша — кладём
        // его даже когда $fn ничего не вернул и записи не было. null — файл не
        // открылся, кэш тогда не трогаем.
        if ($conf !== null) {
            $this->pacCache = $conf;
        }
        return $result;
    }

// Атомарный read-modify-write любого JSON-файла — механика updatePacConf(), но
// без кэша pac. Возвращает [результат fwrite() или false, итоговый массив]:
// записанный, а если записи не было — прочитанный под блокировкой; null, если
// файл не открылся.
public function updateJsonLocked($path, callable $fn)
    {
        $fp = @fopen($path, 'c+');
        if (!$fp) {
            error_log("updateJsonLocked: cannot open $path");
            return [false, null];
        }
        flock($fp, LOCK_EX);
        $conf   = json_decode(stream_get_contents($fp), true) ?: [];
        $new    = $fn($conf);
        $result = false;
        if (is_array($new)) {
            $json = json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                error_log("updateJsonLocked: json_encode failed for $path: " . json_last_error_msg());
            } else {
                ftruncate($fp, 0);
                rewind($fp);
                $result = fwrite($fp, $json);
                fflush($fp);
            }
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return [$result, is_array($new) && $result !== false ? $new : $conf];
    }

public function readJsonLocked($path)
    {
        // LOCK_SH синхронизируется с эксклюзивной блокировкой в
        // writeJsonLocked() — при нескольких PHP-процессах (см.
        // config/unit.json "processes") не даёт прочитать файл, пока другой
        // процесс его ещё дописывает, и тем самым не даёт словить "рваное"
        // чтение и битый JSON.
        $fp = @fopen($path, 'c+');
        if (!$fp) {
            return null;
        }
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return json_decode($content, true);
    }

public function writeJsonLocked($path, $data)
    {
        // LOCK_EX на всё время записи — при нескольких PHP-процессах две
        // одновременные записи иначе могут наложиться друг на друга и
        // испортить файл (а следующее чтение тогда вернёт null/[], и
        // следующая же запись реально сотрёт весь предыдущий конфиг).
        // ftruncate() перед fwrite(), а не сразу file_put_contents(), —
        // чтобы старое содержимое не "просвечивало" за пределами нового,
        // если оно короче.
        //
        // Кодируем ДО открытия файла: json_encode() умеет вернуть false
        // (невалидный UTF-8 в чьём-нибудь username, слишком глубокая
        // вложенность), а раньше ftruncate($fp, 0) к этому моменту уже
        // отрабатывал — и в файл писалась пустая строка. То есть одно битое
        // поле стирало весь конфиг целиком, ровно тот сценарий, от которого
        // тут и защищаемся.
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            error_log("writeJsonLocked: json_encode failed for $path: " . json_last_error_msg());
            return false;
        }
        // Без проверки fopen() неудачное открытие даёт flock(false, LOCK_EX) —
        // а это на PHP 8 TypeError, то есть фатал вместо обычного отказа записи.
        $fp = @fopen($path, 'c+');
        if (!$fp) {
            error_log("writeJsonLocked: cannot open $path for writing");
            return false;
        }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        $result = fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $result;
    }

public function include($type)
    {
        switch ($type) {
            case 'rulessetlist':
                $r = $this->send(
                    $this->input['chat'],
                    "@{$this->input['username']} outbound[:behavior]:time:URL",
                    $this->input['message_id'],
                    reply: 'outbound[:behavior]:time:URL',
                );
                break;

            default:
                $r = $this->send(
                    $this->input['chat'],
                    "@{$this->input['username']} list separated by commas",
                    $this->input['message_id'],
                    reply: 'list separated by commas',
                );
                break;
        }
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'addInclude',
            'args'          => [$type],
        ];
    }

public function addInclude(string $domains, $type)
    {
        if ($type == 'rulessetlist' && !preg_match('~^.+:.+:https?://.+~', $domains)) {
            $this->send($this->input['from'], 'wrong pattern, enter [direct|block|proxy|custom outbound]:time:URL');
            return;
        }
        $domains = explode(',', $domains);
        $domains = array_filter($domains, fn($x) => !empty(trim($x)));
        if (!empty($domains)) {
            $conf = $this->getPacConf();
            foreach ($domains as $k => $v) {
                if (in_array($type, ['white', 'deny'])) {
                    $conf[$type][] = $v;
                } else {
                    $conf[$type][in_array($type, ['rulessetlist', 'packagelist', 'processlist']) ? trim($v) : idn_to_ascii(trim($v))] = true;
                }
            }
            ksort($conf[$type]);
            $this->setPacConf($conf);
            $page = (int) floor(array_search($v, array_keys($conf[$type])) / $this->limit);
        }
        $page = $page ?: -2;
        $this->backXtlsList($type, $page);
    }

public function deleteYes($type)
    {
        $c = $this->getPacConf();
        unset($c[$type]);
        $this->setPacConf($c);
        switch ($type) {
            case 'includelist':
                $this->xtlsproxy();
                break;
            case 'blocklist':
                $this->xtlsblock();
                break;
            case 'warplist':
                $this->xtlswarp();
                break;
            case 'packagelist':
                $this->xtlsapp();
                break;
            case 'processlist':
                $this->xtlsprocess();
                break;
            case 'rulessetlist':
                $this->xtlsrulesset();
                break;
        }
    }

public function deleteAll($type)
    {
        switch ($type) {
            case 'includelist':
                $dir = 'domains';
                break;
            case 'warplist':
                $dir = 'WARP';
                break;
            case 'blocklist':
                $dir = 'BLOCK';
                break;
            case 'packagelist':
                $dir = 'PACKAGE';
                break;
            case 'processlist':
                $dir = 'PROCESS';
                break;
            case 'subnetlist':
                $dir = 'SUBNET';
                break;
            case 'rulessetlist':
                $dir = 'rulesset';
                break;
        }
        $text   = <<<text
                Menu -> $dir -> delete all
                text;
        $data[] = [
            [
                'text'          => $this->i18n('yes'),
                'callback_data' => "/deleteYes $type",
            ],
        ];
        switch ($type) {
            case 'includelist':
                $data[] = [
                    [
                        'text'          => $this->i18n('back'),
                        'callback_data' => "/xtlsproxy",
                    ],
                ];
                break;
            case 'warplist':
                $data[] = [
                    [
                        'text'          => $this->i18n('back'),
                        'callback_data' => "/xtlswarp",
                    ],
                ];
                break;
            case 'blocklist':
                $data[] = [
                    [
                        'text'          => $this->i18n('back'),
                        'callback_data' => "/xtlsblock",
                    ],
                ];
                break;
            case 'packagelist':
                $data[] = [
                    [
                        'text'          => $this->i18n('back'),
                        'callback_data' => "/xtlsapp",
                    ],
                ];
                break;
            case 'processlist':
                $data[] = [
                    [
                        'text'          => $this->i18n('back'),
                        'callback_data' => "/xtlsprocess",
                    ],
                ];
                break;
            case 'subnetlist':
                $data[] = [
                    [
                        'text'          => $this->i18n('back'),
                        'callback_data' => "/xtlssubnet",
                    ],
                ];
                break;
            case 'rulessetlist':
                $data[] = [
                    [
                        'text'          => $this->i18n('back'),
                        'callback_data' => "/xtlsrulesset",
                    ],
                ];
                break;
        }
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            $text,
            $data ?: false,
        );
    }

public function exportList($type)
    {
        // ?? [] — списка может не быть в pac вообще (ни одного домена не
        // добавляли); $text = '' — иначе первый .= идёт по неопределённой.
        $domains = $this->getPacConf()[$type] ?? [];
        $text    = '';
        if (!empty($domains)) {
            foreach ($domains as $k => $v) {
                $text .= "$k;$v\n";
            }
            $this->sendFile(
                $this->input['chat'],
                new CURLStringFile($text, "$type.csv", 'application/csv'),
                to: $this->input['message_id'],
            );
        }
    }

public function listPac($type, $page, $menu, $basename = false)
    {
        $data[] = [
            [
                'text'          => $this->i18n('add'),
                'callback_data' => "/include $type",
            ],
        ];
        // ?? [] — списка может не быть в pac; $text = [] — функция возвращает
        // его всегда, даже когда ветка с <blockquote> ниже не отработала.
        $domains = $this->getPacConf()[$type] ?? [];
        $text    = [];
        if (!empty($domains)) {
            $all     = (int) ceil(count($domains) / $this->limit);
            $page    = min($page, $all - 1);
            $page    = $page < 0 ? $all - 1 : $page;
            $domains = array_slice($domains, $page * $this->limit, $this->limit, true);
            $i = 0;
            foreach ($domains as $k => $v) {
                if ($type == 'rulessetlist') {
                    $text[] = "<blockquote><code>$k</code></blockquote>";
                }
                $data[] = [
                    [
                        'text'          => $this->i18n($v ? 'on' : 'off') . ' ' . ($basename ? basename($k) . ' ' : '') . (in_array($type, ['rulessetlist', 'packagelist', 'processlist', 'subnetlist']) ? $k : idn_to_utf8($k)),
                        'callback_data' => "/change$type " . ($i + $page * $this->limit) . " $page",
                    ],
                    [
                        'text'          => 'Delete',
                        'callback_data' => "/delete$type " . ($i + $page * $this->limit) . " $page",
                    ],
                ];
                $i++;
            }
            if ($all > 1) {
                $data[] = [
                    [
                        'text'          => '<<',
                        'callback_data' => "/$menu " . ($page - 1 >= 0 ? $page - 1 : $all - 1),
                    ],
                    [
                        'text'          => $page + 1,
                        'callback_data' => "/$menu $page",
                    ],
                    [
                        'text'          => '>>',
                        'callback_data' => "/$menu " . ($page < $all - 1 ? $page + 1 : 0),
                    ],
                ];
            }
            $data[] = [
                [
                    'text'          => $this->i18n('delete all'),
                    'callback_data' => "/deleteAll $type",
                ],
                [
                    'text'          => $this->i18n('export'),
                    'callback_data' => "/exportList $type",
                ],
                [
                    'text'          => $this->i18n('import'),
                    'callback_data' => "/importList $type",
                ],
            ];
        } else {
            $data[] = [
                [
                    'text'          => $this->i18n('import'),
                    'callback_data' => "/importList $type",
                ],
            ];
        }
        return [$data, $text];
    }

public function listPacChange($type, $action, $key, $page = 0)
    {
        $conf = $this->getPacConf();
        $i = 0;
        foreach ($conf[$type] as $k => $v) {
            if ($key == $i) {
                switch ($action) {
                    case 'change':
                        $conf[$type][$k] = !$v;
                        break;
                    case 'delete':
                        unset($conf[$type][$k]);
                        break;
                }
                break;
            }
            $i++;
        }
        $this->setPacConf($conf);
        $this->backXtlsList($type, $page);
    }
}

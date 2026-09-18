<?php

trait BotNodeTrait
{
public function getNodes()
    {
        return $this->getPacConf()['nodes'] ?? [];
    }

public function getNode($id)
    {
        return $this->getNodes()[$id] ?? null;
    }

public function setNode($id, array $node)
    {
        $conf = $this->getPacConf();
        $conf['nodes'][$id] = $node;
        $this->setPacConf($conf);
    }

public function nodeIsOnline($ip)
    {
        return trim((string) $this->ssh('echo ok', null, true, '/dev/null', $ip)) === 'ok';
    }

// Статусы нод для экрана «Ноды». Раньше список делал SSH-подключение на каждую
// ноду, а до недоступной ждал до 5 с (fsockopen-проба в ssh()) — и всё это в
// однопоточном polling(). Теперь их опрашивает cron(), а экран читает готовое.
//
// Отдельный таймер, а не каждый проход cron(): проверка недоступной ноды стоит
// до 5 с, и проход удлинялся бы на столько за каждую такую ноду.
public function checkNodesStatus()
    {
        if (!empty($this->time_nodes_status) && time() - $this->time_nodes_status < 30) {
            return;
        }
        $this->time_nodes_status = time();
        $status = [];
        foreach ($this->getNodes() as $id => $node) {
            if (empty($node['off']) && !empty($node['ip'])) {
                $online      = $this->nodeIsOnline($node['ip']);
                $status[$id] = ['online' => $online, 'time' => time()];
                // Сервисы и порты для карточки ноды — тот же блок, что в
                // главном меню Бота. Нода на старой версии statusReport() не
                // знает и ответит не массивом — тогда блока просто нет.
                if ($online) {
                    $services = $this->nodeConsole($node['ip'], 'statusReport');
                    if (is_array($services)) {
                        $status[$id]['services']     = $services;
                        $status[$id]['servicesTime'] = time();
                    }
                }
                // Пуш пользователей не прошёл (нода не ответила, когда меняли
                // список или стартовал бот) — без повтора нода молча выпадала из
                // подписок до ручного «Sync users». Повторяем только для
                // ответивших нод и не во время установки.
                if ($online && empty($node['usersSynced']) && empty($node['provisioning'])) {
                    $this->nodeSyncUsersSilent($id);
                }
            }
        }
        // Кэш заменяется целиком — удалённые и выключенные ноды из него уходят.
        // Но опрос длится секунды, и карточка ноды могла за это время записать
        // более свежий живой результат — его не затираем.
        $this->updateJsonLocked('/config/nodes_status.json', function ($c) use ($status) {
            foreach ($status as $id => $v) {
                if (($c[$id]['time'] ?? 0) > $v['time']) {
                    // Онлайн — из карточки (свежее), сервисы — из опроса:
                    // карточка их не собирает.
                    $status[$id] = ['online' => !empty($c[$id]['online']), 'time' => $c[$id]['time']] + $v;
                }
            }
            return $status;
        });
    }

// Отчёт ноды для её карточки на главном: то же, что показывает главное меню
// самой ноды — статусы сервисов (из её кэша menu_status.json, его пишет её
// собственный cron) и опубликованные порты. Зовётся через console.php.
public function statusReport()
    {
        $st = $this->menuStatus();
        return [
            // Та же строка версии, что в главном меню Бота: VER и ветка git.
            'version'   => $this->botVersion(),
            'branch'    => trim((string) exec('git -C / rev-parse --abbrev-ref HEAD 2>/dev/null')),
            'singbox'   => !empty($st['singbox']),
            'mtproto'   => !empty($st['mtproto']),
            'warp'      => !empty($st['warp']),
            'dnstt'     => !empty($st['dnstt']),
            'dnsttUsed' => !empty($this->getPacConf()['dnsttUsed']),
            'ports'     => array_intersect_key($this->getPorts(), ['tg' => 1, 'dnstt' => 1]),
        ];
    }

// Живой результат из карточки ноды — чтобы список сразу показывал то же самое
// (например, 🟢 сразу после перепривязки), не дожидаясь следующего опроса.
public function nodeStatusRemember($id, $online)
    {
        $this->updateJsonLocked('/config/nodes_status.json', function ($c) use ($id, $online) {
            // Сервисы не трогаем — их собирает только checkNodesStatus().
            $c[$id] = ['online' => (bool) $online, 'time' => time()] + ($c[$id] ?? []);
            return $c;
        });
    }

public function nodeTagPrefix($node)
    {
        if (empty($node['geoTag'])) {
            return '';
        }
        return $this->countryFlag(preg_replace('~\d+$~', '', $node['geoTag'])) . $node['geoTag'];
    }

public function nodesMenu()
    {
        $text[] = "Menu -> " . $this->i18n('nodes');
        $nodes  = $this->getNodes();
        if (empty($nodes)) {
            $text[] = $this->i18n('no nodes yet');
        }
        $data = [];
        // Статус — из кэша (checkNodesStatus() в cron, nodeStatusRemember() из
        // карточки). Нет записи (нода только что добавлена) или она старше двух
        // минут (cron не работает) — проверяем вживую, как раньше.
        $cache = $this->readJsonLocked('/config/nodes_status.json') ?: [];
        foreach ($nodes as $id => $node) {
            if (!empty($node['off'])) {
                $dot = '⚪';
            } else {
                $st = $cache[$id] ?? null;
                if (!is_array($st) || time() - ($st['time'] ?? 0) > 120) {
                    $online = $this->nodeIsOnline($node['ip']);
                    $this->nodeStatusRemember($id, $online);
                } else {
                    $online = !empty($st['online']);
                }
                $dot = $online ? '🟢' : '🔴';
            }
            $label = $node['label'] . (!empty($node['geoTag']) ? " | {$this->nodeTagPrefix($node)}" : '');
            $data[] = [
                [
                    'text'          => "$dot $label",
                    'callback_data' => "/nodeMenu $id",
                ],
            ];
        }
        if (!empty($nodes)) {
            $data[] = [
                [
                    'text'          => '─────────────',
                    'callback_data' => "/menu nodes",
                ],
            ];
        }
        $data[] = [
            [
                'text'          => $this->i18n('add node'),
                'callback_data' => "/addNode",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/menu",
            ],
        ];
        return [
            'text' => implode("\n", $text),
            'data' => $data,
        ];
    }

public function nodeMenu($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            $r = $this->nodesMenu();
            $this->update($this->input['chat'], $this->input['message_id'], $r['text'], $r['data']);
            return;
        }
        $off    = !empty($node['off']);
        $online = $this->nodeIsOnline($node['ip']);
        $this->nodeStatusRemember($id, $online);
        $dot    = $off ? '⚪' : ($online ? '🟢' : '🔴');
        $status = $off ? $this->i18n('node off') : ($online ? $this->i18n('node online') : $this->i18n('node offline'));
        // Версия, сервисы и порты — как в главном меню Бота. Из кэша
        // checkNodesStatus() (опрос раз в 30 с), не вживую: это SSH-вызов с
        // запуском PHP на ноде на каждое открытие карточки. Старше двух минут
        // (cron стоит) или нода сейчас недоступна — не показываем: во время
        // обновления там была бы уже неверная версия.
        $cached = ($this->readJsonLocked('/config/nodes_status.json') ?: [])[$id] ?? [];
        $svc    = !$off && $online && is_array($cached['services'] ?? null) && time() - ($cached['servicesTime'] ?? 0) <= 120
            ? $cached['services']
            : null;
        $text[] = "Menu -> " . $this->i18n('nodes') . " -> {$node['label']}";
        if (!empty($svc['version'])) {
            $text[] = trim("v{$svc['version']} " . ($svc['branch'] ?? ''));
        }
        $text[] = "IP: {$node['ip']}";
        $text[] = "$dot $status";
        if ($svc !== null) {
            $text[] = '<code>';
            $text[] = $this->statusColumns($svc, (array) ($svc['ports'] ?? []), !empty($svc['dnsttUsed']));
            $text[] = '</code>';
        }
        if (!empty($node['geoTag'])) {
            $tagPrefix = $this->nodeTagPrefix($node);
            $text[]    = '';
            $text[]    = 'Node outbound tag:';
            $text[]    = "<code>~{$tagPrefix}:outbounds~</code>";
            // Ровно те же теги, что попадают в selector/⚡️Auto реальной
            // подписки (buildSingMultiOutbounds()) — чтобы можно было
            // скопировать готовую строку прямо в свой шаблон. Выключенный
            // (toggleOutbound()) протокол пропадает и отсюда, и из реальной
            // подписки — списки не расходятся.
            $nodeOff = $node['outboundsOff'] ?? [];
            $labels  = array_filter(
                ['vless' => 'Vless', 'naive' => 'Naive', 'hysteria2' => 'Hy2', 'anytls' => 'Anytls'],
                fn ($k) => empty($nodeOff[$k]),
                ARRAY_FILTER_USE_KEY
            );
            if (!empty($labels)) {
                $text[] = '<blockquote>Outbounds:';
                foreach ($labels as $label) {
                    $text[] = "<code>{$tagPrefix}|{$label}</code>";
                }
                $text[] = '</blockquote>';
            }
        }
        $data   = [
            [
                [
                    'text'          => $this->i18n('outbounds'),
                    'callback_data' => "/nodeOutbounds $id",
                ],
                [
                    'text'          => "{$this->i18n('Domains')} & {$this->i18n('Ports')}",
                    'callback_data' => "/nodeDomains $id",
                ],
            ],
            [
                [
                    'text'          => $this->i18n('telegram proxy'),
                    'callback_data' => "/nodeMtproto $id",
                ],
                [
                    'text'          => 'Stats',
                    'callback_data' => "/nodeStats $id",
                ],
            ],
            [
                [
                    'text'          => $this->i18n('logs'),
                    'callback_data' => "/nodeLogs $id",
                ],
                [
                    'text'          => $this->i18n('sync users'),
                    'callback_data' => "/nodeSyncUsers $id",
                ],
            ],
            [
                [
                    'text'          => $this->i18n('update'),
                    'callback_data' => "/nodeUpdate $id",
                ],
                [
                    'text'          => $off ? $this->i18n('turn on') : $this->i18n('turn off'),
                    'callback_data' => "/nodeToggleOff $id",
                ],
                [
                    'text'          => $this->i18n('restart'),
                    'callback_data' => "/nodeRestart $id",
                ],
            ],
            [
                [
                    'text'          => "{$this->i18n('delete')} {$node['label']}",
                    'callback_data' => "/delNode $id",
                ],
            ],
            [
                [
                    'text'          => $this->i18n('back'),
                    'callback_data' => "/menu nodes",
                ],
            ],
        ];
        if (!$online) {
            // Нода есть в конфиге, но не отвечает. Частая причина —
            // восстановление бота из бэкапа на другом сервере: /ssh/key в
            // бэкап не входит, а нода доверяет ключу старого сервера. Кнопка
            // ставит на ноду текущий ключ, не трогая её запись в конфиге.
            array_splice($data, count($data) - 2, 0, [[
                [
                    'text'          => $this->i18n('rebind node'),
                    'callback_data' => "/nodeRebind $id",
                ],
            ]]);
        }
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $data);
    }

public function nodeStopSingbox($ip)
    {
        $this->ssh('pkill sing-box', 'sbx', true, '/dev/null', $ip);
    }

public function nodeStartSingbox($ip)
    {
        // -HUP, а не простой pkill: если sing-box на ноде уже работает (ноду
        // выключали, пока она была недоступна, и остановка до неё не дошла),
        // pkill без сигнала убивал его, а "||" не давал запуститься новому —
        // нода оставалась без sing-box. Второй путь — нода, ещё не обновлённая
        // до конфига в каталоге /sing-box: run по новому пути сразу падает, и
        // срабатывает старый. Переменных тут нет намеренно: ssh() оборачивает
        // команду в двойные кавычки, и $var раскрыла бы внешняя оболочка.
        $this->ssh('pkill -HUP sing-box || sing-box run -c /sing-box/config.json || sing-box run -c /sing.json', 'sbx', false, '/logs/singbox', $ip);
    }

public function nodeToggleOff($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $off  = empty($node['off']);
        $conf = $this->getPacConf();
        $conf['nodes'][$id]['off'] = $off;
        $this->setPacConf($conf);
        // off: новые подписки перестают включать ноду (getSubscriptionServers()),
        // и дополнительно гасим sing-box+mtproto на самой ноде, чтобы уже
        // выданные клиентам конфиги тоже перестали через неё подключаться.
        // on: обратное — поднимаем оба сервиса заново.
        if ($off) {
            $this->nodeStopSingbox($node['ip']);
            $this->nodeConsole($node['ip'], 'tgStop');
        } else {
            $this->nodeStartSingbox($node['ip']);
            $this->nodeRestartTG($id);
        }
        $this->nodeMenu($id);
    }

public function nodeConsole($ip, $method, ...$args)
    {
        // Мост «SSH-команда -> вызов PHP-метода на ноде»: php-контейнер ноды
        // держим поднятым по остаточному принципу — ресурсов почти не ест, а
        // так весь код BotDomainSslTrait/BotAdminSettingsTrait переиспользуется
        // как есть (console.php просто создаёт Bot и зовёт нужный метод), без
        // повторной реализации той же логики через sed/echo по SSH.
        $cmd = 'php /app/console.php ' . escapeshellarg($method);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg((string) $a);
        }
        $out = trim($this->ssh($cmd, 'php', true, '/dev/null', $ip));
        $decoded = json_decode($out, true);
        return $out !== '' && ($decoded !== null || $out === 'null') ? $decoded : $out;
    }

// $restart — показать кнопку перезапуска (порт MTProto изменён, но ещё не
// опубликован, см. nodeSetPort()). Отдельно не проверяем при каждом открытии
// экрана: это два SSH-вызова на каждое нажатие.
public function nodeDomains($id, $restart = false)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        // Рефреш при каждом открытии — не только по событию смены домена/
        // сертификата. Раньше кэш обновлялся только внутри nodeSetDomain()/
        // nodeIssueSSL()/nodeDelDomain(); если те выполнялись, пока ещё был
        // жив баг docker exec (см. предыдущий фикс), в кэш уходила пустота
        // навсегда — нода на самом деле готова, а getSubscriptionServers()
        // её не видел. Так самостоятельно не расходится.
        $this->nodeCacheDomain($id);
        $node = $this->getNode($id);

        $text[] = "Menu -> " . $this->i18n('nodes') . " -> {$node['label']} -> " . $this->i18n('Domains') . '/' . $this->i18n('Ports');
        if (!empty($node['domain'])) {
            $text[] = "Domain: {$node['domain']}";
            if (!empty($node['naiveSubdomain'])) {
                $text[] = "Naive: {$node['naiveSubdomain']}.{$node['domain']}";
            }
            if (!empty($node['anytlsSubdomain'])) {
                $text[] = "Anytls: {$node['anytlsSubdomain']}.{$node['domain']}";
            }
            if ($this->nodeWebHost($node) !== '') {
                $text[] = "Telegram Proxy: " . $this->nodeWebHost($node);
            }
            // Дата — только у настроенного сертификата: после смены домена на
            // ноде остаётся файл старого (например, от nip.io) со своей датой,
            // но для нового домена он не годится, и нода не идёт в подписку.
            $text[] = "SSL: " . (!empty($node['cert']) && !empty($node['certExpiry']) ? date('Y-m-d H:i:s', $node['certExpiry']) : $this->i18n('not configured'));
        }

        $data = [
            [
                [
                    'text'          => !empty($node['domain']) ? "{$this->i18n('delete')} {$node['domain']}" : $this->i18n('install domain'),
                    'callback_data' => !empty($node['domain']) ? "/nodeDelDomain $id" : "/nodeSetDomainDialog $id",
                ],
            ],
        ];
        if (empty($node['domain'])) {
            $data[0][] = [
                'text'          => $this->i18n('nip.io'),
                'callback_data' => "/nodeAddNip $id",
            ];
        }
        // Как у Бота: нет сертификата — выпуск, есть — перевыпуск (та же
        // команда). Раньше кнопка пряталась по одной дате сертификата, и нода
        // со старым файлом после смены домена оставалась совсем без кнопки.
        if (!empty($node['domain'])) {
            $data[] = [
                [
                    'text'          => $this->i18n(!empty($node['cert']) ? 'renew SSL' : 'Letsencrypt SSL'),
                    'callback_data' => "/nodeIssueSSL $id",
                ],
            ];
        }
        $data[] = [
            [
                'text'          => "MTProto Port",
                'callback_data' => "/nodePortsDialog $id",
            ],
        ];
        if ($restart) {
            $data[] = [
                [
                    'text'          => $this->i18n('restart'),
                    'callback_data' => "/nodeRestart $id",
                ],
            ];
        }
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/nodeMenu $id",
            ],
        ];
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $data);
    }

public function nodeSetDomainDialog($id)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter domain",
            $this->input['message_id'],
            reply: 'enter domain',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'nodeSetDomain',
            'args'          => [$id],
        ];
    }

public function nodeAddNip($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        // addNipdomain() зовётся без аргументов и внутри сама берёт $this->ip —
        // а это уже IP ноды (console.php создаёт Bot в её собственном
        // контексте), поэтому домен получится "{ip-через-дефисы}.nip.io" ноды,
        // не главного. DNS-уведомление не нужно — nip.io резолвится сам.
        $this->nodeConsole($node['ip'], 'addNipdomain');
        $this->nodeCacheDomain($id);
        $this->nodeDomains($id);
    }

public function nodeCacheDomain($id)
    {
        // subscription() генерируется на каждый запрос клиента — ходить по
        // SSH на каждую ноду на каждый такой запрос нельзя, поэтому всё
        // нужное для outbound'а (domain/поддомены/hash/тип сертификата)
        // кэшируем тут же на главном, в момент, когда это реально меняется.
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $pac    = $this->nodeConsole($node['ip'], 'getPacConf') ?: [];
        $cert   = $this->nodeConsole($node['ip'], 'nginxGetTypeCert');
        $expiry = $this->nodeConsole($node['ip'], 'expireCert');
        $hash   = $this->nodeConsole($node['ip'], 'getHashBot');
        $conf = $this->getPacConf();
        $conf['nodes'][$id]['domain']          = $pac['domain'] ?? null;
        $conf['nodes'][$id]['naiveSubdomain']  = $pac['naiveSubdomain'] ?? null;
        $conf['nodes'][$id]['anytlsSubdomain'] = $pac['anytlsSubdomain'] ?? null;
        // WEB-прокси — чтобы ссылку можно было строить без SSH (nodeLinkWebProxy()).
        $conf['nodes'][$id]['webSubdomain']    = $pac['webSubdomain'] ?? null;
        $conf['nodes'][$id]['tgWeb']           = !empty($pac['tgWeb']) && !empty($pac['domain']);
        $conf['nodes'][$id]['tgWebSecret']     = $pac['tgWebSecret'] ?? '';
        $conf['nodes'][$id]['hash']            = $hash ?: null;
        $conf['nodes'][$id]['cert']            = $cert ?: null;
        $conf['nodes'][$id]['certExpiry']      = !empty($expiry) ? $expiry : null;
        $this->setPacConf($conf);
    }

public function nodeSetDomain($domain, $id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        // idn_to_ascii(): домен Бота хранится в punycode (addDomain()), и без
        // приведения кириллический ввод не совпал бы со своим же punycode.
        $domain = idn_to_ascii(trim($domain)) ?: trim($domain);
        // Домен ноды не может совпадать с доменом Бота или другой ноды: имя
        // резолвится в один IP, поэтому либо нода не получит трафик (клиенты
        // придут на Бота), либо на ней не выпустится сертификат — проверка
        // Let's Encrypt уйдёт не на тот сервер. Рабочая схема — Бот на домене,
        // ноды на поддоменах (ru.example.com).
        $taken = array_filter(array_merge(
            [$this->getPacConf()['domain'] ?? null],
            array_map(fn ($n) => $n['domain'] ?? null, array_diff_key($this->getNodes(), [$id => 1]))
        ));
        if (in_array(strtolower($domain), array_map('strtolower', $taken), true)) {
            $this->send($this->input['chat'], $this->i18n('domain already used'));
            $this->nodeDomains($id);
            return;
        }
        // addDomain() на ноде сам пытается прислать DNS-уведомление, но там
        // нет чата (console.php — не telegram-контекст) — шлём его сами, от
        // main, у которого чат есть.
        $this->nodeConsole($node['ip'], 'addDomain', $domain, '1');
        $this->nodeCacheDomain($id);
        $node = $this->getNode($id);
        if (!empty($node['domain']) && !preg_match('~^\d{1,3}-\d{1,3}-\d{1,3}-\d{1,3}\.nip\.io$~', $node['domain'])) {
            $hosts = "{$node['domain']}, {$node['naiveSubdomain']}.{$node['domain']}, {$node['anytlsSubdomain']}.{$node['domain']}"
                . ($this->nodeWebHost($node) !== '' ? ", " . $this->nodeWebHost($node) . " (Telegram Web Proxy)" : '');
            $notice = str_replace(['%ip%', '%hosts%'], [$node['ip'], $hosts], $this->i18n('node dns notice'));
            $this->send($this->input['chat'], $notice);
        }
        $this->nodeDomains($id);
    }

public function nodeDelDomain($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $this->nodeConsole($node['ip'], 'delDomain');
        $this->nodeCacheDomain($id);
        $this->nodeDomains($id);
    }

public function nodeIssueSSL($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $this->send($this->input['chat'], $this->i18n('installing certificate'));
        $this->nodeConsole($node['ip'], 'setSSL', 'letsencrypt');
        $this->nodeCacheDomain($id);
        $node = $this->getNode($id);
        $this->send($this->input['chat'], !empty($node['cert']) ? 'OK' : 'ERROR');
        $this->nodeDomains($id);
    }

public function nodePortsDialog($id)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} number port",
            $this->input['message_id'],
            reply: 'number port',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'nodeSetPort',
            'args'          => [$id],
        ];
    }

public function nodeSetPort($port, $id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $this->nodeConsole($node['ip'], 'setPort', trim($port), 'tg');
        // Запоминаем порт в записи ноды — по нему nodeLinkMtproto() собирает
        // ссылку. Правило то же, что в setPort(): 443, 80 или не число значит
        // «порт закрыт».
        $p = (int) trim($port);
        $this->updatePacConf(function ($c) use ($id, $p) {
            if (!isset($c['nodes'][$id])) {
                return null;
            }
            if ($p > 0 && $p != 443 && $p != 80) {
                $c['nodes'][$id]['mtprotoPort'] = $p;
            } else {
                unset($c['nodes'][$id]['mtprotoPort']);
            }
            return $c;
        });
        // Порт публикует docker при создании контейнера, так что новая запись
        // в docker-compose.override.yml заработает только после перезапуска
        // ноды. Предлагаем его кнопкой на экране портов — как на главном в
        // ports() — и только когда он правда нужен: ввели тот же порт, что уже
        // открыт, — перезапускать нечего.
        $this->nodeDomains($id, $this->nodeTgPortPending($node['ip']));
    }

// Расходится ли порт MTProto, записанный в docker-compose.override.yml ноды, с
// тем, что сейчас публикует её работающий контейнер tg. Оба читаем по SSH с
// хоста ноды, а не через console.php — так проверка не зависит от того, какая
// версия кода стоит на ноде.
public function nodeTgPortPending($ip)
    {
        $yaml    = (string) $this->ssh('cat ~/sbbot/docker-compose.override.yml 2>/dev/null', null, true, '/dev/null', $ip);
        $conf    = trim($yaml) === '' ? [] : (yaml_parse($yaml) ?: []);
        $wanted  = explode(':', (string) ($conf['services']['tg']['ports'][0] ?? ''))[0];
        // "0.0.0.0:4443" или пусто, если порт не опубликован.
        $current = trim((string) $this->ssh('cd ~/sbbot && docker compose port tg 443 2>/dev/null | head -1', null, true, '/dev/null', $ip));
        $current = $current === '' ? '' : substr($current, strrpos($current, ':') + 1);
        return $wanted !== $current;
    }

public function nodeStats($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $st   = $this->queryV2raySingboxStats($node['ip']);
        $sys  = $this->getSingboxSysStats($node['ip']);
        // getHostStats() читает /proc/stat, /proc/meminfo и df локально в том
        // же процессе, что её вызывает — через console.php это уже процесс
        // на самой ноде, поэтому дополнительных параметров не нужно.
        $host = $this->nodeConsole($node['ip'], 'getHostStats') ?: [];

        $text[] = "Menu -> " . $this->i18n('nodes') . " -> {$node['label']} -> Stats";
        $text[] = '<blockquote>';
        $text[] = '<b>' . $this->i18n('system') . '</b>';
        $text[] = "CPU {$host['cpu']}% · MEM {$host['mem']}% · DISK {$host['disk']}%";
        $text[] = 'Sing-box uptime: ' . $this->formatUptime($sys['uptime'] ?? 0);
        $text[] = '</blockquote>';
        // queryV2raySingboxStats() отдаёт плоскую структуру (download/upload
        // прямо в inbounds[tag]), в отличие от getSingboxStats() с её global/
        // session — тут это разовый живой снимок, не накопленный кэш, поэтому
        // getSingboxTotalTraffic() (ждёт вложенность global/session) не подходит.
        $download = $upload = 0;
        foreach (['vless-in', 'naive-in', 'anytls-in', 'hysteria2-in'] as $tag) {
            $download += $st['inbounds'][$tag]['download'] ?? 0;
            $upload   += $st['inbounds'][$tag]['upload']   ?? 0;
        }
        $text[] = "Total: ↓{$this->getBytes($download)} ↑{$this->getBytes($upload)}";
        foreach ([
            'vless-in'     => 'Vless',
            'naive-in'     => 'Naive',
            'anytls-in'    => 'Anytls',
            'hysteria2-in' => 'Hysteria2',
        ] as $tag => $label) {
            $d      = $st['inbounds'][$tag]['download'] ?? 0;
            $u      = $st['inbounds'][$tag]['upload']   ?? 0;
            $text[] = "$label: ↓{$this->getBytes($d)} ↑{$this->getBytes($u)}";
        }
        $data = [
            [
                [
                    'text'          => $this->i18n('back'),
                    'callback_data' => "/nodeMenu $id",
                ],
            ],
        ];
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $data);
    }

public function nodeRestart($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        // Метку запуска спрашиваем ДО перезапуска, пока нода ещё отвечает: по
        // её смене checkNodeRestarting() и поймёт, что всё закончилось.
        $from = trim((string) $this->nodeConsole($node['ip'], 'bootId'));
        // Тот же механизм, что и make r/restart() на главном: пишем в
        // /update/pipe, а уже поднятый на ноде update.sh (стартует автоматом
        // с make u при провижининге) сам делает down+up на своём хосте.
        $this->ssh('echo 2 > ~/sbbot/update/pipe', null, true, '/dev/null', $node['ip']);
        // Эту строку дальше правит checkNodeRestarting() из cron.
        $this->startNodeOperation($id, 'restarting', $from, "{$node['label']}: ⏳ {$this->i18n('restarting')}");
        $this->nodeMenu($id);
    }

public function nodeUpdate($id)
    {
        // Тот же /update/pipe, что и restart(), только cmd=1 — update.sh на
        // ноде (уже поднят с make u при провижининге) сам делает git reset
        // --hard/clean/fetch/pull + docker compose pull + make start. Код
        // ноды — тот же репозиторий, что и у main, так что любое будущее
        // дополнение (например, мониторинг) доезжает этим же путём.
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        // Версию спрашиваем ДО запуска обновления, пока нода ещё отвечает:
        // по смене версии checkNodeUpdating() и поймёт, что всё закончилось.
        $from = trim((string) $this->nodeConsole($node['ip'], 'botVersion'));
        $this->ssh('echo 1 > ~/sbbot/update/pipe', null, true, '/dev/null', $node['ip']);
        // Эту строку дальше правит checkNodeUpdating() из cron.
        $this->startNodeOperation($id, 'updating', $from, "{$node['label']}: ⏳ {$this->i18n('node updating')}");
        $this->nodeMenu($id);
    }

public function delNode($id)
    {
        $node = $this->getNode($id);
        $data = [
            [
                [
                    'text'          => "{$this->i18n('delete')} " . ($node['label'] ?? $id),
                    'callback_data' => "/delNodeYes $id",
                ],
                [
                    'text'          => $this->i18n('back'),
                    'callback_data' => "/nodeMenu $id",
                ],
            ],
        ];
        $this->update($this->input['chat'], $this->input['message_id'], $this->i18n('confirm delete node') . '?', $data);
    }

public function nodeTeardown($ip)
    {
        // make delete само по себе не гарантированно чистит именованные
        // volume'ы (sbbot_warp/sbbot_adguard — по опыту, system/volume prune
        // не всегда их подбирают) и не трогает authorized_keys, куда
        // nodeBootstrap() дописал ключ main — убираем явно, чтобы не
        // оставлять ни ключ, ни данные warp/adguard после "удаления".
        $pubkey = trim(file_get_contents('/ssh/key.pub'));
        $cmd = 'cd ~/sbbot && make d 2>/dev/null; '
             . 'docker volume rm -f sbbot_warp sbbot_adguard 2>/dev/null; '
             . 'docker system prune -f -a 2>/dev/null; '
             . 'docker volume prune -f -a 2>/dev/null; '
             . 'grep -vxF ' . escapeshellarg($pubkey) . ' ~/.ssh/authorized_keys > ~/.ssh/authorized_keys.tmp 2>/dev/null '
             . '&& mv ~/.ssh/authorized_keys.tmp ~/.ssh/authorized_keys; '
             . 'rm -rf ~/sbbot';
        $this->ssh($cmd, null, false, '/dev/null', $ip);
    }

public function delNodeYes($id)
    {
        $node = $this->getNode($id);
        if (!empty($node['ip'])) {
            // Неблокирующе — prune/rm -rf может занять время, а сам факт
            // "удаления" на стороне main не должен его ждать.
            $this->nodeTeardown($node['ip']);
        }
        $conf = $this->getPacConf();
        unset($conf['nodes'][$id]);
        $this->setPacConf($conf);
        $r = $this->nodesMenu();
        $this->update($this->input['chat'], $this->input['message_id'], $r['text'], $r['data']);
    }

public function addNode()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} " . $this->i18n('enter node label'),
            $this->input['message_id'],
            reply: $this->i18n('enter node label'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'addNodeLabel',
            'args'          => [],
        ];
    }

public function addNodeLabel($label)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} " . $this->i18n('enter node ip'),
            $this->input['message_id'],
            reply: $this->i18n('enter node ip'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'addNodeIp',
            'args'          => [trim($label)],
        ];
    }

public function addNodeIp($ip, $label)
    {
        $ip = trim($ip);
        // Одна и та же нода, заведённая дважды, ломает синхронизацию: две
        // записи пушат пользователей на один сервер, обе считают себя его
        // хозяином, а в подписке он появляется двумя серверами с разными
        // гео-тегами.
        foreach ($this->getNodes() as $exists) {
            if (($exists['ip'] ?? null) === $ip) {
                $this->send($this->input['chat'], str_replace('%label%', $exists['label'] ?? '', $this->i18n('node ip exists')));
                $r = $this->nodesMenu();
                $this->update($this->input['chat'], $this->input['message_id'], $r['text'], $r['data']);
                return;
            }
        }
        // Данные ноды на этом шаге ещё не подтверждены (доступ не проверен) —
        // держим их во временной записи, а не в conf['nodes'], пока bootstrap
        // по одному из двух способов ниже не пройдёт успешно.
        $tmpId = bin2hex(random_bytes(4));
        $conf  = $this->getPacConf();
        $conf['nodesPending'][$tmpId] = ['label' => $label, 'ip' => $ip];
        $this->setPacConf($conf);

        $data = [
            [
                [
                    'text'          => $this->i18n('password'),
                    'callback_data' => "/nodeAuthPassword $tmpId",
                ],
                [
                    'text'          => $this->i18n('ssh key'),
                    'callback_data' => "/nodeAuthKey $tmpId",
                ],
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            "$label ({$conf['nodesPending'][$tmpId]['ip']})\n" . $this->i18n('choose auth method'),
            $data,
        );
    }

// $target — tmpId заявки при добавлении ноды или id ноды при перепривязке,
// $callback — кто получит введённый секрет.
public function nodeAuthPassword($target, $callback = 'addNodePassword')
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} " . $this->i18n('key is safer warning') . "\n" . $this->i18n('enter node password'),
            $this->input['message_id'],
            reply: $this->i18n('enter node password'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => $callback,
            'args'          => [$target],
        ];
    }

public function nodeAuthKey($target, $callback = 'addNodeKey')
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} " . $this->i18n('send ssh key file or text'),
            $this->input['message_id'],
            reply: $this->i18n('send ssh key file or text'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => $callback,
            'args'          => [$target],
        ];
    }

public function addNodePassword($password, $tmpId)
    {
        $this->finishAddNode($tmpId, 'password', trim($password));
    }

public function addNodeKey($text, $tmpId)
    {
        $this->finishAddNode($tmpId, 'key', $this->nodeKeyFromInput($text));
    }

public function nodeKeyFromInput($text)
    {
        // Некоторые генераторы ключей отдают их как текст для копипаста, а не
        // файл — поддерживаем оба варианта одним обработчиком.
        if (!empty($this->input['file_id'])) {
            $r = $this->request('getFile', ['file_id' => $this->input['file_id']]);
            return file_get_contents($this->file . $r['result']['file_path']);
        }
        return $text;
    }

public function finishAddNode($tmpId, $authType, $secret)
    {
        $conf    = $this->getPacConf();
        $pending = $conf['nodesPending'][$tmpId] ?? null;
        unset($conf['nodesPending'][$tmpId]);
        $this->setPacConf($conf);
        if (empty($pending)) {
            $this->send($this->input['chat'], $this->i18n('node request expired'));
            return;
        }

        $login  = 'root';
        $result = $this->nodeBootstrap($pending['ip'], $login, $authType, $secret);
        if (empty($result['ok'])) {
            $this->send($this->input['chat'], "ERROR\n{$result['error']}");
            return;
        }

        // Гео определяется один раз, сейчас — и хранится постоянно (не
        // пересчитывается при каждой подписке), чтобы обозначение ноды в
        // клиентских конфигах не "плавало" со временем.
        $this->ensureMainGeoTag();
        $geoTag = $this->assignGeoTag($this->geoCountryCode($pending['ip']) ?: 'XX');

        $id   = bin2hex(random_bytes(4));
        $conf = $this->getPacConf();
        $conf['nodes'][$id] = [
            'label'        => $pending['label'],
            'ip'           => $pending['ip'],
            'login'        => $login,
            'geoTag'       => $geoTag,
            'outboundsOff' => $this->defaultOutboundsOff(),
        ];
        $this->setPacConf($conf);

        // Доступ есть, но sbbot на ноде ещё не установлен — гоняем init.sh
        // (тот же скрипт, что и вручную на голом сервере) удалённо. Не ждём
        // синхронно: apt/docker/git clone/make u — это минуты, а не секунды,
        // $wait=false фонит команду на хосте ноды через nohup (см. ssh()).
        // Ключ — случайный, не настоящий токен бота (нода не должна его знать).
        //
        // curl есть не на каждом образе (на минимальном Debian его нет), а без
        // него установка молча не начиналась: ошибку curl никто не видел, bash
        // получал пустой скрипт, лог оставался пустым. Гарантирован только apt —
        // им curl и ставим, если его нет. Группа { } целиком, чтобы в
        // /root/node_init.log попадал и вывод apt, и ошибки загрузки, а не
        // только вывод самого скрипта. Внутри — ни $, ни двойных кавычек:
        // ssh() подставляет команду в sh -c "...".
        $nodeKey = bin2hex(random_bytes(16));
        $this->ssh(
            '{ command -v curl >/dev/null || { apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y curl; }; '
            . "curl -fsSL https://raw.githubusercontent.com/ndwrd/sbbot/main/scripts/init.sh | bash -s $nodeKey main node; }",
            null,
            false,
            '/root/node_init.log',
            $pending['ip'],
        );
        $r = $this->send($this->input['chat'], "{$this->i18n('node added')}: {$pending['label']} — {$this->i18n('node install started')}");
        // checkNodeProvisioning() (дёргается из cron()) редактирует ЭТО ЖЕ
        // сообщение по мере установки — вместо того чтобы слать новое и
        // удалять старое, как при обновлении самого Бота через update.sh.
        $conf = $this->getPacConf();
        $conf['nodes'][$id]['provisioning'] = [
            'chat'      => $this->input['chat'],
            'messageId' => $r['result']['message_id'] ?? null,
            'startedAt' => time(),
            'lastPing'  => time(),
        ];
        $this->setPacConf($conf);
        $this->nodeMenu($id);
    }

// Перепривязка: нода уже установлена и записана в конфиг, но не принимает
// ключ бота (после восстановления из бэкапа на другом сервере ключ новый).
// Тот же nodeBootstrap(), что при добавлении, но без init.sh и без новой
// записи — label, geoTag, outbounds и прочее остаются как были.
public function nodeRebind($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            $this->nodeMenu($id);
            return;
        }
        $text[] = "Menu -> " . $this->i18n('nodes') . " -> {$node['label']} -> " . $this->i18n('rebind node');
        $text[] = "IP: {$node['ip']}";
        $text[] = '';
        $text[] = $this->i18n('rebind node hint');
        $text[] = '';
        $text[] = $this->i18n('choose auth method');
        $data   = [
            [
                [
                    'text'          => $this->i18n('password'),
                    'callback_data' => "/nodeRebindPassword $id",
                ],
                [
                    'text'          => $this->i18n('ssh key'),
                    'callback_data' => "/nodeRebindKey $id",
                ],
            ],
            [
                [
                    'text'          => $this->i18n('back'),
                    'callback_data' => "/nodeMenu $id",
                ],
            ],
        ];
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $data);
    }

public function rebindNodePassword($password, $id)
    {
        $this->finishRebindNode($id, 'password', trim($password));
    }

public function rebindNodeKey($text, $id)
    {
        $this->finishRebindNode($id, 'key', $this->nodeKeyFromInput($text));
    }

public function finishRebindNode($id, $authType, $secret)
    {
        $node = $this->getNode($id);
        if (empty($node['ip'])) {
            // Ноду успели удалить, пока вводили пароль.
            $r = $this->nodesMenu();
            $this->update($this->input['chat'], $this->input['message_id'], $r['text'], $r['data']);
            return;
        }
        $result = $this->nodeBootstrap($node['ip'], ($node['login'] ?? null) ?: 'root', $authType, $secret);
        if (empty($result['ok'])) {
            $this->send($this->input['chat'], "ERROR\n{$result['error']}");
            return;
        }
        // Ключ записан, но ssh() ходит своим путём (root, /ssh/key) — проверяем
        // именно его, а не верим nodeBootstrap() на слово.
        if (!$this->nodeIsOnline($node['ip'])) {
            $this->send($this->input['chat'], "{$node['label']}: " . $this->i18n('node rebind no access'));
        }
        // Карточка сама покажет 🟢 — это и есть подтверждение.
        $this->nodeMenu($id);
    }

// Версия, на которой сейчас работает бот. VER приходит из compose (git
// describe --tags на момент запуска контейнеров), поэтому меняется ровно
// тогда, когда контейнеры пересозданы новой версией — это и есть признак
// завершённого обновления. Спрашивается у ноды через console.php.
public function botVersion()
    {
        return trim((string) getenv('VER'));
    }

// Метка запуска контейнера, в котором работает console.php (php ноды): btime
// хоста + время старта его PID 1. make start пересоздаёт контейнеры
// (--force-recreate), так что после перезапуска ноды метка другая — по ней
// checkNodeRestarting() и понимает, что перезапуск прошёл. Версия тут не
// годится: при перезапуске она та же.
public function bootId()
    {
        $stat = @file_get_contents('/proc/1/stat') ?: '';
        // Имя процесса (поле 2) в скобках может содержать пробелы, поэтому
        // считаем от последней ')': дальше идут поля с третьего, starttime — 22-е.
        $start = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2))[19] ?? '';
        preg_match('~^btime (\d+)~m', @file_get_contents('/proc/stat') ?: '', $m);
        return $start === '' ? '' : ($m[1] ?? '') . ':' . $start;
    }

// Запомнить операцию над нодой, которую дальше ведёт trackNodeOperation():
// в чат уходит строка с ⏳, и её id сохраняется в записи ноды.
public function startNodeOperation($id, $field, $from, $text)
    {
        $r   = $this->send($this->input['chat'], $text);
        $msg = $r['result']['message_id'] ?? null;
        $this->updatePacConf(function ($c) use ($id, $field, $msg, $from) {
            if (!isset($c['nodes'][$id]) || empty($msg)) {
                return null;
            }
            $c['nodes'][$id][$field] = [
                'chat'      => $this->input['chat'],
                'messageId' => $msg,
                'from'      => $from,
                'startedAt' => time(),
                'lastPing'  => time(),
            ];
            return $c;
        });
    }

// Нода не может написать в чат сама: при установке ей выдаётся случайный ключ,
// а не настоящий токен бота. Поэтому за долгими операциями, запущенными
// кнопкой (обновление, перезапуск), следит главный — так же, как за установкой
// (checkNodeProvisioning()): опрашивает ноду и правит то же самое сообщение,
// которое отправил при нажатии: ⏳ с минутами, ✅ по готовности, ⚠️ по таймауту.
//
// $done($node, $state, $elapsed) возвращает хвост ✅-строки, когда операция
// закончилась, или null, пока нет. Во время операции контейнеры ноды
// остановлены и console.php не отвечает — это штатно, просто ждём дальше.
public function trackNodeOperation($field, callable $done, $timeout, $waitKey, $timeoutKey)
    {
        $conf = $this->getPacConf();
        $ops  = [];
        foreach ($conf['nodes'] ?? [] as $id => $node) {
            $u = $node[$field] ?? null;
            if (empty($u) || empty($u['messageId']) || empty($node['ip'])) {
                continue;
            }
            $label   = $node['label'] ?? $id;
            $elapsed = time() - ($u['startedAt'] ?? time());
            $ok      = $done($node, $u, $elapsed);
            if ($ok !== null) {
                $this->update($u['chat'], $u['messageId'], "$label: ✅ $ok");
                $ops[$id] = null;
            } elseif ($elapsed > $timeout) {
                $this->update($u['chat'], $u['messageId'], "$label: ⚠️ " . $this->i18n($timeoutKey));
                $ops[$id] = null;
            } elseif (time() - ($u['lastPing'] ?? 0) >= 30) {
                $min = (int) floor($elapsed / 60);
                $this->update($u['chat'], $u['messageId'], "$label: ⏳ " . $this->i18n($waitKey) . " {$min} " . $this->i18n('min'));
                $ops[$id] = time();
            }
        }
        if (!empty($ops)) {
            // Тем же способом, что и checkNodeProvisioning(): опрос ноды идёт
            // секунды, и за это время админ мог что-то поменять в меню —
            // пишем только своё поле, а не весь снимок конфига.
            $this->updatePacConf(function ($c) use ($ops, $field) {
                foreach ($ops as $id => $lastPing) {
                    if (!isset($c['nodes'][$id])) {
                        continue;
                    }
                    if ($lastPing === null) {
                        unset($c['nodes'][$id][$field]);
                    } elseif (isset($c['nodes'][$id][$field])) {
                        $c['nodes'][$id][$field]['lastPing'] = $lastPing;
                    }
                }
                return $c;
            });
        }
    }

public function checkNodeUpdating()
    {
        $this->trackNodeOperation('updating', function ($node, $u, $elapsed) {
            $ver = trim((string) $this->nodeConsole($node['ip'], 'botVersion'));
            // Версия сменилась — обновление закончено. Если нода была на той же
            // версии (повторное обновление), считаем законченным по факту
            // ответа, но не раньше пяти минут: сразу после нажатия она ещё
            // отвечает старым, ещё не остановленным контейнером.
            if ($ver !== '' && ($ver !== ($u['from'] ?? '') || $elapsed > 300)) {
                return $this->i18n('node updated') . " $ver";
            }
            return null;
        }, 900, 'node updating', 'node update timeout');
    }

public function checkNodeRestarting()
    {
        $this->trackNodeOperation('restarting', function ($node, $u, $elapsed) {
            $boot = trim((string) $this->nodeConsole($node['ip'], 'bootId'));
            $from = $u['from'] ?? '';
            // Метка запуска сменилась — контейнеры пересозданы. Нода на версии
            // без bootId() метку не отдаёт вовсе: тогда считаем готовой, когда
            // она снова отвечает, но не раньше минуты — сразу после нажатия
            // она ещё работает старыми контейнерами.
            if ($from !== '' ? ($boot !== '' && $boot !== $from)
                : ($elapsed >= 60 && trim((string) $this->nodeConsole($node['ip'], 'botVersion')) !== '')) {
                return $this->i18n('node restarted');
            }
            return null;
        }, 600, 'restarting', 'node restart timeout');
    }

public function checkNodeCerts()
    {
        // Тот же принцип, что и checkCert() для главного — раз в сутки, в
        // 12 часов, шлём админам предупреждение за 14 дней до истечения. Но
        // expireCert() тут читает сертификат НЕ локально, а по SSH на каждую
        // ноду — свой троттлинг-таймер (time_node_cert), не общий с checkCert().
        try {
            require dirname(__DIR__) . '/config.php';
            if (empty($c['admin']) || date('H') != 12) {
                return;
            }
            if (!empty($this->time_node_cert) && (time() - $this->time_node_cert) < 4600) {
                return;
            }
            $this->time_node_cert = time();
            foreach ($this->getNodes() as $node) {
                if (empty($node['ip']) || !empty($node['off'])) {
                    continue;
                }
                $expiry = $this->nodeConsole($node['ip'], 'expireCert');
                if (!empty($expiry) && is_numeric($expiry) && $expiry - 60 * 60 * 24 * 14 < time()) {
                    foreach ($c['admin'] as $admin) {
                        $this->send($admin, "{$node['label']}: certificate expire: " . date('Y-m-d H:i:s', $expiry));
                    }
                }
            }
        } catch (Exception $e) {
        }
    }

public function checkNodeProvisioning()
    {
        // $conf тут — только снимок для принятия решений; сами изменения
        // копим в $ops и применяем одной атомарной транзакцией в конце.
        // Раньше весь снимок писался обратно целиком, а между его чтением и
        // записью успевали отработать nodeConsole() (SSH на ноду, до секунд) и
        // update() (запрос к Telegram) на каждую ноду — всё, что админ менял
        // через меню в это окно, молча откатывалось.
        $conf = $this->getPacConf();
        $ops  = [];
        foreach ($conf['nodes'] ?? [] as $id => $node) {
            $p = $node['provisioning'] ?? null;
            if (empty($p) || empty($p['messageId'])) {
                continue;
            }
            $elapsed = time() - $p['startedAt'];
            // ssh()/nodeConsole() отдают '' (не null) при недоступности хоста —
            // getPacConf() на реально поднятой ноде всегда возвращает массив
            // (даже пустой), так что сравниваем именно с '', а не с null.
            if ($this->nodeConsole($node['ip'], 'getPacConf') !== '') {
                $this->update($p['chat'], $p['messageId'], "{$this->i18n('node added')}: {$node['label']} — ✅ {$this->i18n('node install done')}");
                $ops[$id] = null;
            } elseif ($elapsed > 900) {
                $this->update($p['chat'], $p['messageId'], "{$this->i18n('node added')}: {$node['label']} — ⚠️ {$this->i18n('node install timeout')}");
                $ops[$id] = null;
            } elseif (time() - $p['lastPing'] >= 30) {
                $min = (int) floor($elapsed / 60);
                $this->update($p['chat'], $p['messageId'], "{$this->i18n('node added')}: {$node['label']} — ⏳ {$this->i18n('node install in progress')} {$min} " . $this->i18n('min'));
                $ops[$id] = time();
            }
        }
        if (!empty($ops)) {
            $this->updatePacConf(function ($c) use ($ops) {
                foreach ($ops as $id => $lastPing) {
                    // Ноду могли удалить, пока мы её опрашивали — тогда
                    // возвращать ей provisioning-запись нельзя.
                    if (!isset($c['nodes'][$id])) {
                        continue;
                    }
                    if ($lastPing === null) {
                        unset($c['nodes'][$id]['provisioning']);
                    } elseif (isset($c['nodes'][$id]['provisioning'])) {
                        $c['nodes'][$id]['provisioning']['lastPing'] = $lastPing;
                    }
                }
                return $c;
            });
        }
    }

public function geoCountryCode($ip)
    {
        // Разовый вызов (при создании ноды/первый раз для главного), не на
        // каждый запрос подписки — своей GeoIP-базы в образах нет.
        $ch = curl_init("http://ip-api.com/json/$ip?fields=countryCode");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $r = json_decode(curl_exec($ch) ?: '', true);
        curl_close($ch);
        return $r['countryCode'] ?? null;
    }

public function countryFlag($code)
    {
        // Regional indicator symbols: A..Z -> U+1F1E6..U+1F1FF, флаг —
        // просто два таких символа подряд, справочник стран не нужен.
        $flag = '';
        foreach (str_split(strtoupper($code)) as $ch) {
            $flag .= mb_chr(0x1F1E6 + (ord($ch) - 65), 'UTF-8');
        }
        return $flag;
    }

public function assignGeoTag($countryCode)
    {
        // Номер при совпадении страны — тоже присваивается один раз, в момент
        // создания сервера, а не пересчитывается на лету при каждой подписке.
        $pac      = $this->getPacConf();
        $existing = [];
        if (!empty($pac['geoTag'])) {
            $existing[] = $pac['geoTag'];
        }
        foreach ($pac['nodes'] ?? [] as $node) {
            if (!empty($node['geoTag'])) {
                $existing[] = $node['geoTag'];
            }
        }
        $count = 0;
        foreach ($existing as $tag) {
            if (preg_replace('~\d+$~', '', $tag) === $countryCode) {
                $count++;
            }
        }
        return $countryCode . ($count > 0 ? (string) ($count + 1) : '');
    }

public function ensureMainGeoTag()
    {
        $pac = $this->getPacConf();
        if (empty($pac['geoTag'])) {
            $pac['geoTag'] = $this->assignGeoTag($this->geoCountryCode($this->ip) ?: 'XX');
            $this->setPacConf($pac);
        }
        // correctSingOriginTags()/correctClashOriginTags() идемпотентны —
        // сопоставляют по type, а не по текущему имени/тегу, так что звать их
        // повторно безопасно и без изменений, если уже поправлено. Раньше
        // это гейтилось флагом в pac.json ("сделано один раз"), но
        // застрявший true (например, от раннего прогона до того, как сама
        // коррекция начала реально что-то менять) навсегда блокировал
        // перезапуск — clash.json у части инсталляций так и остался с
        // "Vless"/"HY2" вместо "{флаг}{тег}|Vless" на главном сервере, хотя
        // sing.json скорректировался нормально. Зовём безусловно каждый раз,
        // но сами correct*OriginTags() пишут файл только при реальном
        // изменении: путь сюда лежит через getSubscriptionServers(), то есть
        // это КАЖДЫЙ запрос подписки, а не редкий вызов из меню, и
        // безусловный LOCK_EX + перезапись sing.json/clash.json на каждом
        // таком запросе сериализовала их между собой.
        $flag = $this->countryFlag(preg_replace('~\d+$~', '', $pac['geoTag']));
        $this->correctSingOriginTags($flag . $pac['geoTag']);
        $this->correctClashOriginTags($flag . $pac['geoTag']);
        return $pac['geoTag'];
    }

public function correctSingOriginTags($tagPrefix)
    {
        $path = '/config/sing.json';
        $c    = $this->readJsonLocked($path);
        if (empty($c['outbounds'])) {
            return;
        }
        $before    = $c;
        $protocols = [
            'vless'     => 'Vless',
            'hysteria2' => 'Hy2',
            'naive'     => 'Naive',
            'anytls'    => 'Anytls',
        ];
        $renames = [];
        foreach ($c['outbounds'] as &$o) {
            $label = $protocols[$o['type'] ?? ''] ?? null;
            if ($label === null || empty($o['tag'])) {
                continue;
            }
            $newTag           = "{$tagPrefix}|{$label}";
            $renames[$o['tag']] = $newTag;
            $o['tag']         = $newTag;
        }
        unset($o);
        foreach ($c['outbounds'] as &$o) {
            if (!empty($o['outbounds'])) {
                $o['outbounds'] = array_map(fn ($t) => $renames[$t] ?? $t, $o['outbounds']);
            }
            if (!empty($o['default']) && isset($renames[$o['default']])) {
                $o['default'] = $renames[$o['default']];
            }
        }
        unset($o);
        // На диск идём только если коррекция реально что-то поменяла. Саму
        // коррекцию это не гейтит (см. ensureMainGeoTag() — прогоняем её
        // по-прежнему каждый раз), просто no-op больше не доходит до файла:
        // ensureMainGeoTag() висит на getSubscriptionServers(), то есть
        // вызывается на КАЖДЫЙ запрос подписки каждым клиентом, а
        // writeJsonLocked() — это LOCK_EX + перезапись файла целиком, из-за
        // чего параллельные запросы подписки выстраивались в очередь друг за
        // другом (и несколько PHP-процессов в unit.json ничего не давали).
        if ($c !== $before) {
            $this->writeJsonLocked($path, $c);
        }
    }

public function correctClashOriginTags($tagPrefix)
    {
        $path = '/config/clash.json';
        $c    = $this->readJsonLocked($path);
        if (empty($c['proxies'])) {
            return;
        }
        $before = $c;
        // mihomo не поддерживает naive — тем же составом, что и в текущих
        // proxies шаблона.
        $protocols = [
            'vless'     => 'Vless',
            'hysteria2' => 'Hy2',
            'anytls'    => 'Anytls',
        ];
        $renames = [];
        foreach ($c['proxies'] as &$p) {
            $label = $protocols[$p['type'] ?? ''] ?? null;
            if ($label === null || empty($p['name'])) {
                continue;
            }
            $newName            = "{$tagPrefix}|{$label}";
            $renames[$p['name']] = $newName;
            $p['name']          = $newName;
        }
        unset($p);
        // `foreach ($c['proxy-groups'] ?? [] as &$g)` выглядело бы безопаснее,
        // но `??` возвращает временную копию, а не ссылку на элемент массива
        // — `&$g` мутировал бы эту копию, и группа "Proxy" никогда не
        // обновлялась бы (реальный баг: proxies переименовывались, а группа
        // молча оставалась со старыми именами). Проверяем существование ДО
        // foreach, а не внутри условия итерации, чтобы не терять ссылку.
        if (!empty($c['proxy-groups'])) {
            foreach ($c['proxy-groups'] as &$g) {
                if (!empty($g['proxies'])) {
                    $g['proxies'] = array_map(fn ($n) => $renames[$n] ?? $n, $g['proxies']);
                }
            }
            unset($g);
        }
        // Пишем только при реальном изменении — см. correctSingOriginTags().
        if ($c !== $before) {
            $this->writeJsonLocked($path, $c);
        }
    }

public function nodeBootstrap($ip, $login, $authType, $secret)
    {
        // Пароль/ключ, введённые тут, используются только один раз — чтобы
        // поставить на ноду тот же публичный ключ, которым main уже управляет
        // соседними контейнерами (/ssh/key.pub). Дальше вся связь с нодой идёт
        // этим общим ключом, введённый секрет никуда не сохраняется.
        $tmpPriv = null;
        try {
            $c = @ssh2_connect($ip, 22);
            if (empty($c)) {
                return ['ok' => false, 'error' => "no connection to $ip"];
            }
            if ($authType === 'password') {
                $a = @ssh2_auth_password($c, $login, $secret);
            } else {
                $tmpPriv = tempnam(sys_get_temp_dir(), 'ndk');
                file_put_contents($tmpPriv, rtrim($secret) . "\n");
                chmod($tmpPriv, 0600);
                $tmpPub = "$tmpPriv.pub";
                exec('ssh-keygen -y -f ' . escapeshellarg($tmpPriv) . ' > ' . escapeshellarg($tmpPub) . ' 2>/dev/null', $out, $code);
                if ($code !== 0) {
                    return ['ok' => false, 'error' => 'invalid private key'];
                }
                $a = @ssh2_auth_pubkey_file($c, $login, $tmpPub, $tmpPriv);
            }
            if (empty($a)) {
                return ['ok' => false, 'error' => 'auth failed'];
            }

            $pubkey = trim(file_get_contents('/ssh/key.pub'));
            $cmd    = 'mkdir -p ~/.ssh && chmod 700 ~/.ssh && grep -qxF ' . escapeshellarg($pubkey)
                . ' ~/.ssh/authorized_keys 2>/dev/null || echo ' . escapeshellarg($pubkey) . ' >> ~/.ssh/authorized_keys'
                . ' && chmod 600 ~/.ssh/authorized_keys';
            $s = ssh2_exec($c, $cmd);
            stream_set_blocking($s, true);
            $out = stream_get_contents($s);
            fclose($s);
            ssh2_disconnect($c);
            return ['ok' => true, 'out' => $out];
        } catch (Exception | Error $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            if ($tmpPriv) {
                @unlink($tmpPriv);
                @unlink("$tmpPriv.pub");
            }
        }
    }

// Menu -> Nodes -> <нода> -> Telegram Proxy: то же, что у Бота, — блоки
// MTProto и Web Proxy. Состояние — одним SSH-вызовом (nodeTgReport()), он же
// обновляет WEB-часть в записи ноды.
public function nodeMtprotoMenu($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            $this->nodeMenu($id);
            return;
        }
        $report     = $this->nodeTgReport($id);
        $node       = $this->getNode($id);
        $secret     = $node['mtprotosecret'] ?? '';
        $fakedomain = $node['mtprotodomain'] ?? 'yandex.ru';
        $st         = ($report['status'] ?? '') === 'on' ? 'on' : 'off';
        $web        = $st == 'on' && !empty($node['tgWeb']);
        $host       = $this->nodeWebHost($node);

        $text[] = "Menu -> " . $this->i18n('nodes') . " -> {$node['label']} -> " . $this->i18n('telegram proxy');
        $text[] = '';
        $text[] = '<b>MTProto</b>';
        $text[] = "Status: $st";
        $text[] = "Fake domain: <code>$fakedomain</code>";
        if ($st == 'on' && !empty($secret)) {
            $text[] = 'Link: ' . $this->nodeLinkMtproto($id);
        }
        $text[] = '';
        $text[] = '<b>Web Proxy</b>';
        $text[] = 'Status: ' . ($web ? 'on' : 'off');
        if ($host !== '') {
            $text[] = "Domain: <code>$host</code>";
        }
        if ($web) {
            $text[] = 'Link: ' . $this->nodeLinkWebProxy($id);
        }
        $data = [
            [['text' => '· MTProto ·', 'callback_data' => "/nodeMtproto $id"]],
            [
                ['text' => $this->i18n('generateSecret'), 'callback_data' => "/nodeGenerateSecret $id"],
                ['text' => $this->i18n('setSecret'), 'callback_data' => "/nodeSetSecret $id"],
            ],
            [
                ['text' => $this->i18n('changeFakeDomain'), 'callback_data' => "/nodeChangeTGDomain $id"],
                ['text' => $this->i18n('show QR'), 'callback_data' => "/nodeQrMtproto $id"],
            ],
            [['text' => '· ' . $this->i18n('web proxy') . ' ·', 'callback_data' => "/nodeMtproto $id"]],
            [
                ['text' => $this->i18n('generateSecret'), 'callback_data' => "/nodeWebGenerate $id"],
                ['text' => $this->i18n('setSecret'), 'callback_data' => "/nodeWebSetSecret $id"],
            ],
            [['text' => $this->i18n('show QR'), 'callback_data' => "/nodeQrWeb $id"]],
            [['text' => $this->i18n('back'), 'callback_data' => "/nodeMenu $id"]],
        ];
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $data);
    }

// Состояние прокси ноды (tgReport() на ней). WEB-часть сохраняем в записи
// ноды: по ней меню Бота и QR строят ссылки без SSH. null — нода не ответила
// (или ещё не обновлена и такого метода у неё нет).
public function nodeTgReport($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return null;
        }
        $r = $this->nodeConsole($node['ip'], 'tgReport');
        if (!is_array($r)) {
            return null;
        }
        $this->updatePacConf(function ($c) use ($id, $r) {
            if (!empty($c['nodes'][$id])) {
                $c['nodes'][$id]['tgWeb']        = !empty($r['tgWeb']);
                $c['nodes'][$id]['tgWebSecret']  = $r['tgWebSecret'] ?? '';
                $c['nodes'][$id]['webSubdomain'] = $r['webSubdomain'] ?? null;
            }
            return $c;
        });
        return $r;
    }

public function nodeWebHost(array $node)
    {
        return !empty($node['domain']) && !empty($node['webSubdomain'])
            ? strtolower("{$node['webSubdomain']}.{$node['domain']}")
            : '';
    }

// Порт в WEB-ссылке не указывается: клиент требует 443 — как в linkWebProxy().
public function nodeLinkWebProxy($id)
    {
        $node = $this->getNode($id);
        $host = !empty($node) ? $this->nodeWebHost($node) : '';
        $s    = $node['tgWebSecret'] ?? '';
        return !empty($node['tgWeb']) && $host !== '' && preg_match('~^[0-9a-f]{32}$~', $s)
            ? "https://t.me/webproxy?server=$host&secret=dd$s"
            : '';
    }

public function nodeWebGenerate($id)
    {
        $this->nodeWebApply($id, bin2hex(random_bytes(16)));
    }

public function nodeWebSetSecret($id)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter key or 0 for stop web proxy",
            $this->input['message_id'],
            reply: 'enter key or 0 for stop web proxy',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'nodeWebSecretSet',
            'args'          => [$id],
        ];
    }

public function nodeWebSecretSet($secret, $id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $secret = trim($secret);
        if ($secret === '0') {
            $this->nodeConsole($node['ip'], 'tgWebOff');
            $this->nodeMtprotoMenu($id);
            return;
        }
        if (!preg_match('~^(?:dd|ee)?([0-9a-f]{32})$~i', $secret, $m)) {
            $this->update($this->input['chat'], $this->input['message_id'], 'wrong secret');
            sleep(2);
            $this->nodeMtprotoMenu($id);
            return;
        }
        $this->nodeWebApply($id, strtolower($m[1]));
    }

// Включение WEB на ноде и смена его ключа: сама работа — tgWebApply() на ноде
// (через console.php), отсюда — только сообщения: у ноды своего чата нет.
public function nodeWebApply($id, $secret)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $report = $this->nodeTgReport($id);
        $node   = $this->getNode($id);
        $host   = $this->nodeWebHost($node);
        if (!is_array($report)) {
            $this->send($this->input['chat'], str_replace('%label%', $node['label'], $this->i18n('node web failed')));
            $this->nodeMtprotoMenu($id);
            return;
        }
        if (empty($report['tgWeb'])) {
            // Отказ по условиям — сразу, без похода к certbot.
            if (($report['webCheck'] ?? 'ok') !== 'ok') {
                $this->send($this->input['chat'], $this->i18n($report['webCheck']));
                $this->nodeMtprotoMenu($id);
                return;
            }
            // О выпуске сертификата — только если он действительно будет: WEB-
            // имени нет в сертификате ноды. Нет и A-записи — отказ сразу, без
            // certbot (tgWebApply() на ноде отказал бы так же). Нода старше 1.4.0
            // этих полей не присылает — тогда сообщаем, как раньше.
            if (empty($report['webCert'])) {
                if (isset($report['webDns']) && empty($report['webDns'])) {
                    $this->send($this->input['chat'], str_replace('%host%', $host, $this->i18n('web dns missing')));
                    $this->nodeMtprotoMenu($id);
                    return;
                }
                $this->send($this->input['chat'], str_replace('%host%', $host, $this->i18n('web cert issuing')));
            }
        }
        $r = $this->nodeConsole($node['ip'], 'tgWebApply', $secret);
        $this->nodeTgReport($id);
        $host = $this->nodeWebHost($this->getNode($id)) ?: $host;
        if ($r !== 'ok') {
            $this->send(
                $this->input['chat'],
                is_string($r) && $r !== ''
                    ? str_replace('%host%', $host, $this->i18n($r))
                    : str_replace('%label%', $node['label'], $this->i18n('node web failed'))
            );
        }
        $this->nodeMtprotoMenu($id);
    }

// Работает ли прокси на ноде — по кэшу опроса нод (checkNodesStatus(), раз в
// 30 с): для QR по SSH не ходим. Нет данных (нода не отвечала) — считаем
// выключенным: QR прокси, до которого не достучаться, бесполезен.
public function nodeTgOn($id)
    {
        $svc = ($this->readJsonLocked('/config/nodes_status.json') ?: [])[$id]['services'] ?? null;
        return is_array($svc) && !empty($svc['mtproto']);
    }

// QR — отдельным сообщением, как у Бота (qrMtproto()/qrWebProxy()).
public function nodeQrMtproto($id)
    {
        $node = $this->getNode($id);
        $link = $this->nodeLinkMtproto($id);
        if ($link === '' || !$this->nodeTgOn($id)) {
            $this->answer($this->input['callback_id'], 'MTProto: off', true);
            return;
        }
        $this->sendQr("mtproto {$node['label']}", $link, "{$node['label']}: <code>$link</code>");
    }

public function nodeQrWeb($id)
    {
        $node = $this->getNode($id);
        $link = $this->nodeLinkWebProxy($id);
        if ($link === '' || !$this->nodeTgOn($id)) {
            $this->answer($this->input['callback_id'], $this->i18n('web proxy') . ': off', true);
            return;
        }
        $this->sendQr("webproxy {$node['label']}", $link, "{$node['label']}: <code>$link</code>");
    }

public function nodeGenerateSecret($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $node['mtprotosecret'] = bin2hex(random_bytes(16));
        $this->setNode($id, $node);
        $this->nodeRestartTG($id);
        $this->nodeMtprotoMenu($id);
    }

public function nodeSetSecret($id)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter key or 0 for stop mtproto",
            $this->input['message_id'],
            reply: 'enter key or 0 for stop mtproto',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'nodeSecretSet',
            'args'          => [$id],
        ];
    }

public function nodeSecretSet($secret, $id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $secret = trim($secret);
        if (preg_match('~^(?:dd|ee)?([0-9a-f]{32})~i', $secret, $m)) {
            $secret = strtolower($m[1]);
        }
        $node['mtprotosecret'] = $secret;
        $this->setNode($id, $node);
        $this->nodeRestartTG($id);
        $this->nodeMtprotoMenu($id);
    }

public function nodeChangeTGDomain($id)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter domain",
            $this->input['message_id'],
            reply: 'enter domain',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'nodeSetTelegramDomain',
            'args'          => [$id],
        ];
    }

public function nodeSetTelegramDomain($domain, $id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $node['mtprotodomain'] = trim($domain);
        $this->setNode($id, $node);
        $this->nodeRestartTG($id);
        $this->nodeMtprotoMenu($id);
    }

public function nodeRestartTG($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $secret     = $node['mtprotosecret'] ?? '';
        $fakedomain = $node['mtprotodomain'] ?? 'yandex.ru';
        // Ноду больше не запускаем по SSH: в контейнере с готовым образом
        // Telemt нет sshd. Отдаём секрет и домен её же боту через
        // console.php, дальше нода применяет их своим applyMtproto().
        if (preg_match('~^[0-9a-f]{32}$~i', $secret)) {
            $this->nodeConsole($node['ip'], 'applyMtproto', $secret, $fakedomain);
        }
    }

public function nodeLogFiles($ip)
    {
        // basename везде вместо ls/find -printf: то и другое зависит от того,
        // GNU-утилиты в образе или busybox — basename+wc есть в обоих.
        $raw = trim($this->ssh('for f in /logs/*; do [ -f "$f" ] && basename "$f"; done', 'php', true, '/dev/null', $ip));
        return array_values(array_filter(explode("\n", $raw)));
    }

public function nodeLogs($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $raw = trim($this->ssh('for f in /logs/*; do [ -f "$f" ] && echo "$(wc -c < "$f") $(basename "$f")"; done', 'php', true, '/dev/null', $node['ip']));

        $text[] = "Menu -> " . $this->i18n('nodes') . " -> {$node['label']} -> " . $this->i18n('logs');
        $data   = [];
        foreach (array_filter(explode("\n", $raw)) as $k => $line) {
            [$size, $name] = array_pad(explode(' ', $line, 2), 2, '');
            if ($name === '') {
                continue;
            }
            $data[] = [
                [
                    'text'          => "$size $name",
                    'callback_data' => "/nodeGetLog $id $k",
                ],
                [
                    'text'          => $this->i18n('clean'),
                    'callback_data' => "/nodeClearLog $id $k",
                ],
            ];
        }
        // Как и у Бота (см. logs()): логи прокси Telegram лежат в stdout
        // контейнера, а не файлом в /logs, поэтому в списке выше их нет —
        // отдельная строка без размера и без очистки.
        $data[] = [
            [
                'text'          => $this->i18n('mtproto'),
                'callback_data' => "/nodeTgLogs $id",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('clean all'),
                'callback_data' => "/nodeCleanLog $id",
            ],
        ];
        // Тот же формат ("start / period"), что и autocleanlogs у Бота
        // (см. logs()/checkLogs()) — просто в записи ноды, не в общем pac.
        $autoclean = array_filter(explode('/', $node['autocleanlogs'] ?? ''));
        if (!empty($autoclean)) {
            if (!empty(strtotime($autoclean[0])) && !empty(strtotime($autoclean[1]))) {
                $autoclean = "{$autoclean[0]} start / {$autoclean[1]} period";
            } else {
                $autoclean = $this->i18n('off') . " {$node['autocleanlogs']} - wrong format";
            }
        }
        $data[] = [
            [
                'text'          => $this->i18n('autoclean') . ': ' . ($autoclean ?: $this->i18n('off')),
                'callback_data' => "/nodeAutoCleanLogsDialog $id",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/nodeMenu $id",
            ],
        ];
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text ?: ['...']), $data);
    }

// Логи прокси Telegram на ноде — тот же экран, что у Бота, только текст
// приезжает через console-мост: до docker.sock ноды дотягивается лишь её
// собственный php-контейнер.
public function nodeTgLogs($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $raw = $this->nodeConsole($node['ip'], 'tgLogsText');
        // nodeConsole() отдаёт разобранный JSON, если вывод им оказался —
        // для логов это нормальная строка, но подстраховываемся.
        $log = trim(is_scalar($raw) ? (string) $raw : '');
        $log = strlen($log) > 3000 ? '...' . substr($log, -3000) : $log;
        $text = "Menu -> " . $this->i18n('nodes') . " -> {$node['label']} -> " . $this->i18n('logs') . " -> " . $this->i18n('mtproto')
            . "\n\n<pre>" . htmlspecialchars($log ?: '-') . "</pre>";
        $data = [
            [
                [
                    'text'          => $this->i18n('back'),
                    'callback_data' => "/nodeLogs $id",
                ],
            ],
        ];
        $this->update($this->input['chat'], $this->input['message_id'], $text, $data);
    }

public function nodeAutoCleanLogsDialog($id)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter like: start / period",
            $this->input['message_id'],
            reply: 'enter like: now / 12 hours',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'nodeSetAutoCleanLogs',
            'args'          => [$id],
        ];
    }

public function nodeSetAutoCleanLogs($text, $id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $text = trim($text);
        $conf = $this->getPacConf();
        if (empty($text)) {
            $conf['nodes'][$id]['autocleanlogs'] = '';
        } else {
            [$start, $period] = array_pad(explode('/', $text), 2, '');
            if (!empty(strtotime($start)) && !empty(strtotime($period))) {
                $conf['nodes'][$id]['autocleanlogs'] = implode(' / ', [date('Y-m-d H:i', strtotime($start)), trim($period)]);
            } else {
                $this->send($this->input['chat'], $text . ' - wrong format');
            }
        }
        $this->setPacConf($conf);
        $this->nodeLogs($id);
    }

public function checkNodeAutoCleanLogs()
    {
        // То же вычисление расписания, что и checkLogs() у Бота, только по
        // каждой ноде отдельно (own lastCleanLogsTime, не общий с Ботом) и
        // сама очистка — прямой SSH, без рендера меню (это фон, не клик).
        // $conf — снимок для решений; отметки времени применяем транзакцией в
        // конце, а не переписыванием всего снимка: в цикле идёт SSH на каждую
        // ноду, и за это время конфиг успевает измениться.
        $conf  = $this->getPacConf();
        $stamp = [];
        $now   = time();
        foreach ($conf['nodes'] ?? [] as $id => $node) {
            if (empty($node['autocleanlogs']) || empty($node['ip'])) {
                continue;
            }
            [$start, $period] = array_pad(explode('/', $node['autocleanlogs']), 2, '');
            $start  = strtotime(trim($start));
            $period = strtotime(trim($period), 0);
            if (empty($start) || empty($period) || $now < $start) {
                continue;
            }
            $elapsed             = $now - $start;
            $periodsElapsed      = floor($elapsed / $period);
            $lastScheduledClean  = $start + ($periodsElapsed * $period);
            $lastCleanTime       = $node['lastCleanLogsTime'] ?? 0;
            if ($lastCleanTime < $lastScheduledClean) {
                $this->ssh('for f in /logs/*; do [ -f "$f" ] && > "$f"; done', 'php', true, '/dev/null', $node['ip']);
                $stamp[$id] = $now;
            }
        }
        if (!empty($stamp)) {
            $this->updatePacConf(function ($c) use ($stamp) {
                foreach ($stamp as $id => $t) {
                    if (isset($c['nodes'][$id])) {
                        $c['nodes'][$id]['lastCleanLogsTime'] = $t;
                    }
                }
                return $c;
            });
        }
    }

public function nodeGetLog($id, $k)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $name = $this->nodeLogFiles($node['ip'])[$k] ?? null;
        if (empty($name)) {
            return;
        }
        $content = $this->ssh('cat ' . escapeshellarg("/logs/$name"), 'php', true, '/dev/null', $node['ip']);
        $tmp     = tempnam(sys_get_temp_dir(), 'ndl');
        file_put_contents($tmp, $content);
        $this->sendFile($this->input['chat'], curl_file_create($tmp, 'text/plain', $name));
        unlink($tmp);
    }

public function nodeClearLog($id, $k)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $name = $this->nodeLogFiles($node['ip'])[$k] ?? null;
        if (!empty($name)) {
            $this->ssh('> ' . escapeshellarg("/logs/$name"), 'php', true, '/dev/null', $node['ip']);
        }
        $this->nodeLogs($id);
    }

public function nodeCleanLog($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $this->ssh('for f in /logs/*; do [ -f "$f" ] && > "$f"; done', 'php', true, '/dev/null', $node['ip']);
        $this->nodeLogs($id);
    }

public function nodeSyncAllUsers()
    {
        // Единая точка входа из restartSingbox() (13 мест правки списка) —
        // сразу гасим usersSynced у всех нод (пока не подтверждён пуш,
        // subscription() не должен предлагать клиенту фоллбэк на них), потом
        // пытаемся пушить. Без чата/меню — это фон, а не ответ на нажатие кнопки.
        $this->updatePacConf(function ($c) {
            foreach ($c['nodes'] ?? [] as $id => $node) {
                $c['nodes'][$id]['usersSynced'] = false;
            }
            return $c;
        });
        foreach (array_keys($this->getNodes()) as $id) {
            $this->nodeSyncUsersSilent($id);
        }
    }

// То, что уходит на ноду при синхронизации, — из этого нода строит свой
// серверный конфиг. Отдельной функцией, чтобы сравнивать отправленное с
// актуальным (см. nodeSyncUsersSilent()).
public function nodeUsersPayload($pac)
    {
        return [
            'singboxClients' => $pac['singboxClients'] ?? [],
            'transport'      => $pac['transport'] ?? 'Websocket',
            'reality'        => $pac['reality'] ?? [],
            'blocklist'      => $pac['blocklist'] ?? [],
            'warplist'       => $pac['warplist'] ?? [],
        ];
    }

public function nodeSyncUsersSilent($id)
    {
        // main остаётся источником правды по списку пользователей — просто
        // пушим то же, из чего сам buildSingboxConfig() строит инбаунды, и
        // просим ноду прогнать её же собственным console.php: код тот же,
        // что и при обычном restartSingbox() на главном, просто по данным,
        // присланным сюда, а не введённым локально админом.
        $node = $this->getNode($id);
        if (empty($node)) {
            return false;
        }
        $sent = $this->nodeUsersPayload($this->getPacConf());
        $ok   = $this->nodeConsole($node['ip'], 'applyUsers', json_encode($sent)) === 'ok';
        if ($ok) {
            // Под блокировкой и только если список не поменялся, пока шёл пуш
            // (секунды SSH). Иначе, например, повтор из cron со старым списком
            // мог бы отметить ноду синхронизированной поверх более свежего
            // изменения — и она числилась бы синхронизированной со старыми
            // пользователями. Поменялся — отметит тот, кто его поменял, или
            // следующий повтор из cron.
            $this->updatePacConf(function ($c) use ($id, $sent) {
                if (!isset($c['nodes'][$id]) || $this->nodeUsersPayload($c) !== $sent) {
                    return null;
                }
                $c['nodes'][$id]['usersSynced'] = true;
                return $c;
            });
        }
        return $ok;
    }

public function nodeSyncUsers($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $ok = $this->nodeSyncUsersSilent($id);
        $this->send($this->input['chat'], "{$node['label']}: " . $this->i18n($ok ? 'users synced' : 'sync failed'));
        $this->nodeMenu($id);
    }

public function applyUsers($json)
    {
        $data = json_decode($json, true) ?: [];
        $pac  = $this->getPacConf();
        $pac['singboxClients'] = $data['singboxClients'] ?? [];
        $pac['transport']      = $data['transport'] ?? 'Websocket';
        $pac['reality']        = $data['reality'] ?? [];
        $pac['blocklist']      = $data['blocklist'] ?? [];
        $pac['warplist']       = $data['warplist'] ?? [];
        // Та же сборка block/warp outbound'а и правил, что и на главном
        // (singboxUpdateRules()) — без этого нода получила бы списки, но
        // buildSingboxConfig() ниже подхватывает их только из
        // pac['singboxOutbounds']/['singboxRoutingRules'], которые тут
        // больше никто не считает.
        $built = $this->buildWarpBlockOutboundsRules($pac);
        $pac['singboxOutbounds']    = $built['outbounds'];
        $pac['singboxRoutingRules'] = $built['rules'];
        $this->setPacConf($pac);
        $sing = $this->buildSingboxConfig($pac);
        $this->writeSingboxRuntime($sing);
        $this->ssh('pkill -HUP sing-box || sing-box run -c /sing-box/config.json', 'sbx', false);
        return 'ok';
    }

public function nodeDnstt($id)
    {
        // dnsttStart()/dnstt() на ноде переиспользуются как есть через
        // console-мост (как addDomain()/setSSL()) — они уже сами берут
        // правильные $c['domain']/$this->ip из контекста ноды, поэтому
        // инструкция по NS/A-записям получается для ноды автоматически.
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $pac    = $this->nodeConsole($node['ip'], 'getPacConf') ?: [];
        $pubkey = trim((string) $this->ssh('cat /config/dnstt/server.pub 2>/dev/null', 'php', true, '/dev/null', $node['ip']));

        $text[] = "Menu -> " . $this->i18n('nodes') . " -> {$node['label']} -> dnstt";
        if (!empty($pac['dnsttDomain']) && !empty($pac['dnsttPassword'])) {
            $text[] = "<pre>set the NS record for {$pac['dnsttDomain']}: tns.{$pac['domain']}\nset A record for tns.{$pac['domain']}: {$node['ip']}</pre>";
            $text[] = "account: <code>vpnbot:{$pac['dnsttPassword']}</code>";
            $text[] = "server name: <code>{$pac['dnsttDomain']}</code>";
            if (!empty($pubkey)) {
                $text[] = "public key: <code>$pubkey</code>";
            }
        } else {
            $text[] = "set subdomain and password";
        }

        $data = [];
        if (!empty($pubkey)) {
            $data[] = [
                [
                    'text'          => $this->i18n('download pubkey'),
                    'callback_data' => "/nodeDnsttDownload $id",
                ],
            ];
        }
        $data[] = [
            [
                'text'          => $this->i18n('set subdomain'),
                'callback_data' => "/nodeDnsttDomainDialog $id",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('set password'),
                'callback_data' => "/nodeDnsttPasswordDialog $id",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/nodeMenu $id",
            ],
        ];
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $data);
    }

public function nodeDnsttDomainDialog($id)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter domain",
            $this->input['message_id'],
            reply: 'enter domain',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'nodeSetDnsttDomain',
            'args'          => [$id],
        ];
    }

public function nodeSetDnsttDomain($domain, $id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $this->nodeConsole($node['ip'], 'setdnsttDomain', trim($domain));
        $this->nodeDnstt($id);
    }

public function nodeDnsttPasswordDialog($id)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter password",
            $this->input['message_id'],
            reply: 'enter password',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'nodeSetDnsttPassword',
            'args'          => [$id],
        ];
    }

public function nodeSetDnsttPassword($password, $id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $this->nodeConsole($node['ip'], 'setdnsttPassword', trim($password));
        $this->nodeDnstt($id);
    }

public function nodeDnsttDownload($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $content = $this->ssh('cat /config/dnstt/server.pub', 'php', true, '/dev/null', $node['ip']);
        $tmp     = tempnam(sys_get_temp_dir(), 'ndk');
        file_put_contents($tmp, $content);
        $this->sendFile($this->input['chat'], curl_file_create($tmp, 'text/plain', "{$node['label']}_dnstt.pub"));
        unlink($tmp);
    }

public function nodeLinkMtproto($id)
    {
        $node = $this->getNode($id);
        if (empty($node) || empty($node['mtprotosecret'])) {
            return '';
        }
        $s  = $node['mtprotosecret'];
        $d  = trim($node['mtprotodomain'] ?? 'yandex.ru');
        // bin2hex() вместо вызова шелла — см. linkMtproto() в BotMtprotoTrait.
        $d  = bin2hex($d);
        // Порт, который нода публикует наружу. Записывается при смене через
        // бота (nodeSetPort()); если его меняли раньше, чем появилась запись, —
        // берём из отчёта ноды в кэше статусов (statusReport(), опрос раз в
        // 30 с). По SSH не ходим: ссылки всех нод строятся на каждое открытие
        // меню MTProto главного.
        $p = $node['mtprotoPort'] ?? null;
        if (empty($p)) {
            $tg = ($this->readJsonLocked('/config/nodes_status.json') ?: [])[$id]['services']['ports']['tg'] ?? [];
            $p  = !empty($tg['enable']) && !empty($tg['port']) ? $tg['port'] : 443;
        }
        return "https://t.me/proxy?server={$node['ip']}&port=$p&secret=ee$s$d";
    }
}

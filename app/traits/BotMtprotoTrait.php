<?php

trait BotMtprotoTrait
{
// Контейнер tg — Telemt (github.com/telemt/telemt): обычный MTProto (ee) и,
// следующим шагом, WEB-прокси в одном процессе. sshd в образе нет (distroless),
// поэтому управление — через Docker API, а конфиг целиком пишет бот:
// tgWriteConfig() -> /config/telemt/config.toml, в контейнере это
// /data/config.toml. Смонтирован каталог, а не файл: атомарную замену
// одиночного bind-mount docker внутри не показывает.
//
// Применение. Telemt сам следит за каталогом конфига (inotify плюс опрос раз
// в 3 с) и на лету подхватывает секрет пользователя и ad_tag — без разрыва
// соединений. Остальное (fake-домен, порт, middle-proxy) читается только при
// старте, поэтому такая правка перезапускает контейнер — короткий обрыв.
//
// Сами секрет и домен по-прежнему лежат в /config/mtprotosecret и
// /config/mtprotodomain: на них завязаны меню, бэкап и восстановление.
public function tgConfigFile()
    {
        return '/config/telemt/config.toml';
    }

public function generateSecret()
    {
        $this->secretSet(bin2hex(random_bytes(16)));
    }

public function setSecret()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter key or 0 for stop mtproto",
            $this->input['message_id'],
            reply: 'enter key or 0 for stop mtproto',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'secretSet',
            'args'           => [],
        ];
    }

public function secretSet($secret)
    {
        // linkMtproto() выдаёт клиенту secret с префиксом ee и хвостом-доменом
        // (fake-tls) — если пользователь копирует именно эту строку обратно
        // сюда, а не сырой 32-символьный ключ, он не пройдёт проверку и
        // mtproto молча не поднимется. Достаём чистый ключ сами.
        $secret = trim($secret);
        // «0» по подсказке в setSecret() — остановить прокси; секрет не трогаем,
        // чтобы следующий «Сгенерировать»/«Установить» поднял его заново.
        if ($secret === '0') {
            $this->tgStop();
            $this->mtproto();
            return;
        }
        if (!preg_match('~^(?:dd|ee)?([0-9a-f]{32})~i', $secret, $m)) {
            $this->update($this->input['chat'], $this->input['message_id'], 'wrong secret');
            sleep(2);
            $this->mtproto();
            return;
        }
        file_put_contents('/config/mtprotosecret', strtolower($m[1]));
        $this->restartTG();
        $this->tgStart();
        $this->mtproto();
    }

public function setTelegramDomain($domain)
    {
        file_put_contents('/config/mtprotodomain', trim($domain));
        $this->restartTG();
        $this->mtproto();
    }

public function setTelegramAdtag($adtag)
    {
        $adtag = trim($adtag);
        if ($adtag === '0') {
            $adtag = '';
        } elseif (!preg_match('~^[a-f0-9]{32}$~i', $adtag)) {
            $this->update($this->input['chat'], $this->input['message_id'], 'wrong adtag');
            sleep(2);
            $this->mtproto();
            return;
        }
        file_put_contents('/config/mtprotoadtag', strtolower($adtag));
        $this->restartTG();
        $this->mtproto();
    }

// Секрет прокси. Обычно это наш файл. Если его нет — берём тот, с которым
// работал прежний прокси: teleproxy на свежей установке генерировал секрет сам,
// и ссылки клиентов выданы именно с ним. Нет и его — создаём новый: Telemt, в
// отличие от teleproxy, без пользователя в конфиге не стартует.
public function tgSecret()
    {
        $secret = trim(@file_get_contents('/config/mtprotosecret') ?: '');
        if (preg_match('~^[0-9a-f]{32}$~i', $secret)) {
            return strtolower($secret);
        }
        $old    = @file_get_contents('/config/teleproxy/config.toml') ?: '';
        $secret = preg_match('~^\s*key\s*=\s*"([0-9a-f]{32})"~mi', $old, $m)
            ? strtolower($m[1])
            : bin2hex(random_bytes(16));
        file_put_contents('/config/mtprotosecret', $secret);
        return $secret;
    }

public function tgDomain()
    {
        return trim(@file_get_contents('/config/mtprotodomain') ?: '') ?: 'yandex.ru';
    }

// Статус берём из состояния контейнера, а не из pgrep: в образе нет ни sshd,
// ни shell, а healthcheck (см. docker-compose.yml) спрашивает сам Telemt через
// его API — это честнее, чем наличие процесса.
public function tgStatus()
    {
        $state = $this->containerState('tg');
        return !empty($state['running']) && ($state['health'] ?? 'healthy') !== 'unhealthy' ? 'on' : 'off';
    }

// Собрать конфиг Telemt из текущих настроек и записать, если он изменился.
// Возвращает null — ничего не поменялось, 'hot' — поменялись только секрет или
// ad_tag (Telemt подхватит их сам, без разрыва соединений), 'restart' — всё
// остальное, нужен перезапуск контейнера.
//
// Зовётся и из init.php — ДО того, как php станет healthy: контейнер tg ждёт
// именно этого (depends_on), а без готового файла Telemt не стартует. teleproxy
// собирал конфиг сам из переменных окружения, у Telemt такого нет.
public function tgWriteConfig()
    {
        $path = $this->tgConfigFile();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        // Образ Telemt работает от nonroot и пишет в этот же каталог своё
        // состояние (кэш TLS-эмуляции, proxy-secret, cache/), а создаёт каталог
        // php от root — открываем на запись. Снаружи он недоступен: лежит внутри
        // каталога бота.
        chmod($dir, 0777);
        // Внешний адрес для middle-proxy — только если он публичный. IP в env
        // берётся из `hostname -I` (makefile), а на многих VPS первым там идёт
        // внутренний адрес: с ним middle-proxy согласовывал бы ключи с неверным
        // IP, и медиа без Premium не грузилось бы. Без явного адреса Telemt
        // определяет его сам (middle_proxy_nat_probe, STUN).
        $ip    = filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) ? $this->ip : '';
        $adtag = strtolower(trim((string) @file_get_contents('/config/mtprotoadtag')));
        $q     = fn ($s) => '"' . addcslashes((string) $s, "\\\"") . '"';
        // WEB-прокси — только когда он включён и собран целиком: Telemt с
        // web.enabled требует listener, vhost и профиль, а vhost — публичный
        // IP (public_addr участвует во внутреннем маршруте relay).
        $pac     = $this->getPacConf();
        $webHost = $this->tgWebHost($pac);
        $webKey  = $this->tgWebSecret();
        $web     = !empty($pac['tgWeb']) && $webHost !== '' && $ip !== '' && $webKey !== '';
        $webLines = !$web ? [] : [
            // Не опубликован наружу: сюда ходит только ng, он же снимает TLS.
            '[[server.listeners]]',
            'ip = "0.0.0.0"',
            'port = 18080',
            'transport = "web"',
            'proxy_protocol = false',
            'reuse_allow = false',
            'web_client_ip_source = "x_forwarded_for"',
            'web_trusted_proxy_cidrs = ["10.10.0.2/32"]',
            '',
            // Автовыбор транспорта: WebSocket-варианты и HTTPS-lanes по очереди,
            // обычный HTTPS (long polling) — последний откат. Если провайдер
            // режет WebSocket, клиент останется на HTTPS.
            '[web]',
            'enabled = true',
            'carrier = "https"',
            'carriers = ["websocket-lanes", "websocket", "https-lanes"]',
            '',
            '[[web.vhosts]]',
            'host = ' . $q($webHost),
            'public_addr = ' . $q("$ip:443"),
            '',
            // Всё неопознанное — на тот же сайт-обманку, что и основной домен
            // (внутренний server на 8088 в nginx_default.conf).
            '[web.vhosts.decoy]',
            'mode = "http_upstream"',
            'upstream = "http://10.10.0.2:8088"',
            '',
            // ee WEB-клиенты не принимают, поэтому свой пользователь в режиме dd.
            '[[web.vhosts.profiles]]',
            'user = "web"',
            'secret_mode = "dd"',
            '',
        ];
        $lines = [
            '# Генерирует бот (BotMtprotoTrait::tgWriteConfig()) при старте и при',
            '# смене настроек MTProto — правки руками будут перезаписаны.',
            '[general]',
            // Middle-proxy обязателен: без него у аккаунтов без Premium не грузятся
            // фото, видео и истории. За docker-NAT ему нужен внешний адрес.
            'use_middle_proxy = true',
            $ip !== '' ? 'middle_proxy_nat_ip = ' . $q($ip) : null,
            'log_level = "normal"',
            preg_match('~^[0-9a-f]{32}$~', $adtag) ? 'ad_tag = ' . $q($adtag) : null,
            '',
            '[general.modes]',
            'classic = false',
            'secure = false',
            'tls = true',
            '',
            // Ссылки строит бот; печатать секреты в логи незачем.
            '[general.links]',
            'show = []',
            '',
            '[server]',
            'port = 443',
            '',
            // healthcheck образа ходит в этот API — выключать нельзя, иначе
            // контейнер навсегда unhealthy, и меню покажет MTProto выключенным.
            '[server.api]',
            'enabled = true',
            'listen = "127.0.0.1:9091"',
            'whitelist = ["127.0.0.1/32"]',
            '',
            '[[server.listeners]]',
            'ip = "0.0.0.0"',
            '',
            ...$webLines,
            '[censorship]',
            'tls_domain = ' . $q($this->tgDomain()),
            'mask = true',
            'tls_emulation = true',
            '',
            '[access.users]',
            'sbbot = ' . $q($this->tgSecret()),
            $web ? 'web = ' . $q($webKey) : null,
        ];
        $toml = implode("\n", array_filter($lines, fn ($l) => $l !== null)) . "\n";
        $old  = @file_get_contents($path);
        if ($old === $toml) {
            return null;
        }
        $tmp = "$path.tmp";
        file_put_contents($tmp, $toml);
        chmod($tmp, 0644);
        rename($tmp, $path);
        return $old !== false && $this->tgColdPart($old) === $this->tgColdPart($toml) ? 'hot' : 'restart';
    }

// Часть конфига, которую Telemt читает только при старте: всё, кроме ad_tag и
// секции [access.users] (она последняя — см. tgWriteConfig()).
public function tgColdPart($toml)
    {
        $toml = preg_replace('~^ad_tag\s*=.*\R?~m', '', $toml);
        return preg_replace('~^\[access\.users\].*~ms', '', $toml);
    }

// Применить текущие настройки. Остановленный контейнер не поднимаем: его
// остановили намеренно («0» вместо секрета) — он прочитает конфиг, когда его
// запустят (tgStart()).
public function restartTG()
    {
        $change = $this->tgWriteConfig();
        if ($change === null || empty($this->containerState('tg')['running'])) {
            return;
        }
        if ($change === 'restart') {
            $this->restartContainer('tg');
        } else {
            // Наблюдатель Telemt и сам увидит новый файл за пару секунд; SIGHUP —
            // тот же триггер перечитывания, только сразу.
            $this->signalContainer('tg', 'HUP');
        }
    }

public function tgStop()
    {
        $id = $this->containerId('tg');
        if ($id) {
            $this->dockerApi("/containers/$id/stop", 'POST');
        }
        return 'ok';
    }

public function tgStart()
    {
        $id = $this->containerId('tg');
        if ($id) {
            $this->dockerApi("/containers/$id/start", 'POST');
        }
        return 'ok';
    }

// Вызывается на НОДЕ через console.php: главный присылает секрет и домен из
// своей записи о ноде, нода кладёт их к себе и применяет тем же кодом, что и
// главный у себя. Раньше главный сам запускал процесс на ноде по SSH — в
// контейнере с готовым образом sshd нет.
public function applyMtproto($secret, $domain = '')
    {
        $secret = trim((string) $secret);
        if (!preg_match('~^[0-9a-f]{32}$~i', $secret)) {
            return 'error';
        }
        file_put_contents('/config/mtprotosecret', strtolower($secret));
        if (trim((string) $domain) !== '') {
            file_put_contents('/config/mtprotodomain', trim((string) $domain));
        }
        // Сначала конфиг, потом старт: остановленный контейнер поднимется сразу
        // с новыми настройками, работающий — применит их в restartTG().
        $this->restartTG();
        $this->tgStart();
        return 'ok';
    }

// WEB-прокси: тот же Telemt, отдельный пользователь "web" с секретом в режиме
// dd (ee WEB-клиенты не принимают). Отдельный — чтобы его можно было сменить,
// не трогая обычный MTProto. Клиенты: Telegram Desktop 7.1+.
public function tgWebSecret()
    {
        $s = strtolower(trim((string) ($this->getPacConf()['tgWebSecret'] ?? '')));
        return preg_match('~^[0-9a-f]{32}$~', $s) ? $s : '';
    }

// Порт в WEB-ссылке не указывается: клиент требует 443.
public function linkWebProxy()
    {
        $host = $this->tgWebHost();
        $s    = $this->tgWebSecret();
        return $host !== '' && $s !== '' ? "tg://webproxy?server=$host&secret=dd$s" : '';
    }

public function tgWebToggle()
    {
        $pac = $this->getPacConf();
        if (!empty($pac['tgWeb'])) {
            $this->updatePacConf(function ($c) {
                unset($c['tgWeb']);
                return $c;
            });
            $this->cloakNginx();
            $this->restartTG();
            $this->mtproto();
            return;
        }
        // WebView клиента примет только сертификат публичного CA —
        // самоподписанный мост не загрузит.
        if (empty($pac['domain']) || ($pac['letsencrypt'] ?? '') !== 'letsencrypt') {
            $this->answer($this->input['callback_id'], $this->i18n('web needs letsencrypt'), true);
            return;
        }
        if (!filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)) {
            $this->answer($this->input['callback_id'], $this->i18n('web needs public ip'), true);
            return;
        }
        $this->updatePacConf(function ($c) {
            $c          = $this->ensureProtocolSubdomains($c);
            $c['tgWeb'] = true;
            if (!preg_match('~^[0-9a-f]{32}$~', $c['tgWebSecret'] ?? '')) {
                $c['tgWebSecret'] = bin2hex(random_bytes(16));
            }
            return $c;
        });
        $host = $this->tgWebHost();
        // Сначала nginx: проверка Host на 80-м порту должна пропускать WEB-имя
        // до того, как certbot пойдёт его подтверждать (HTTP-01).
        $this->cloakNginx();
        if (!in_array($host, $this->domainsCert() ?: [], true)) {
            if (!preg_match('~^(\d{1,3})-(\d{1,3})-(\d{1,3})-(\d{1,3})\.nip\.io$~', $pac['domain'])) {
                $this->send($this->input['chat'], str_replace('%host%', $host, $this->i18n('web dns')));
            }
            $this->setSSL('letsencrypt');
            if (!in_array($host, $this->domainsCert() ?: [], true)) {
                // Сертификат не выпустился (чаще всего нет A-записи для имени) —
                // откатываемся, чтобы не держать включённым то, что не работает.
                $this->updatePacConf(function ($c) {
                    unset($c['tgWeb']);
                    return $c;
                });
                $this->cloakNginx();
                $this->send($this->input['chat'], str_replace('%host%', $host, $this->i18n('web cert failed')));
                $this->mtproto();
                return;
            }
        }
        $this->restartTG();
        $this->mtproto();
    }

public function tgWebNewSecret()
    {
        $this->updatePacConf(function ($c) {
            $c['tgWebSecret'] = bin2hex(random_bytes(16));
            return $c;
        });
        // Секрет пользователя Telemt подхватывает на лету — соединения обычного
        // MTProto это не рвёт.
        $this->restartTG();
        $this->mtproto();
    }

public function qrWebProxy()
    {
        $link = $this->linkWebProxy();
        if ($link !== '') {
            $this->sendQr('webproxy', $link, "<code>$link</code>");
        }
    }

public function linkMtproto()
    {
        $s  = $this->tgSecret();
        $p  = $this->getPorts()['tg']['port'];
        $d  = $this->tgDomain();
        // bin2hex() вместо exec("echo $d | tr -d '\n' | xxd -ps -c 200"):
        // результат тот же самый, но без вызова шелла — а там домен
        // подставлялся без кавычек, хотя приходит из админского ввода.
        $d  = bin2hex($d);
        $ip = $this->getDomain();
        return "https://t.me/proxy?server=$ip&port=$p&secret=ee$s$d";
    }

public function mtproto()
    {
        $d      = $this->tgDomain();
        $st     = $this->tgStatus();
        $text[] = "Menu -> MTProto";
        $text[] = "status: $st";
        $text[] = "fake domain: <code>$d</code>";
        if ($st == 'on') {
            $text[] = $this->linkMtproto();
        }
        $web    = !empty($this->getPacConf()['tgWeb']);
        $text[] = '';
        $text[] = '<b>WEB</b> (Telegram Desktop 7.1+): ' . $this->i18n($web && $st == 'on' ? 'on' : 'off');
        if ($web && $st == 'on') {
            $text[] = '<code>' . $this->linkWebProxy() . '</code>';
        }
        foreach ($this->getNodes() as $id => $node) {
            $text[] = '';
            $text[] = "<b>MTProto {$node['label']}</b>";
            $link   = $this->nodeLinkMtproto($id);
            $text[] = $link ?: $this->i18n('not configured');
        }
        $data[] = [
            [
                'text'          => $this->i18n('generateSecret'),
                'callback_data' => "/generateSecret",
            ],
            [
                'text'          => $this->i18n('setSecret'),
                'callback_data' => "/setSecret",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('changeFakeDomain'),
                'callback_data' => "/changeTGDomain",
            ],
            [
                'text'          => $this->i18n('show QR'),
                'callback_data' => "/qrMtproto",
            ],
        ];
        $webRow = [
            [
                'text'          => $this->i18n($web ? 'on' : 'off') . ' WEB',
                'callback_data' => "/tgWebToggle",
            ],
        ];
        if ($web) {
            $webRow[] = [
                'text'          => 'QR WEB',
                'callback_data' => "/qrWebProxy",
            ];
            $webRow[] = [
                'text'          => $this->i18n('web secret'),
                'callback_data' => "/tgWebNewSecret",
            ];
        }
        $data[] = $webRow;
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/menu",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

// Хвост логов прокси одним текстом. Отдельно от tgLogs() потому, что ноде
// нужен именно возврат: main зовёт этот метод на ноде через console.php
// (nodeTgLogs()), а docker.sock ноды виден только её собственному php.
public function tgLogsText($tail = 100)
    {
        $log = trim($this->containerLogs('tg', (int) $tail));
        // Телеграм не принимает сообщение длиннее 4096 символов — оставляем хвост.
        return strlen($log) > 3000 ? '...' . substr($log, -3000) : $log;
    }

// Логи прокси. Готовый образ пишет в stdout контейнера, а не в файл в /logs,
// поэтому в общий список логов они не попадают — показываем отдельной кнопкой.
public function tgLogs()
    {
        $log  = $this->tgLogsText();
        $text = "Menu -> " . $this->i18n('config') . " -> " . $this->i18n('logs') . " -> " . $this->i18n('mtproto') . "\n\n<pre>" . htmlspecialchars($log ?: '-') . "</pre>";
        $data = [
            [
                [
                    'text'          => $this->i18n('back'),
                    'callback_data' => "/logs",
                ],
            ],
        ];
        $this->update($this->input['chat'], $this->input['message_id'], $text, $data);
    }

public function changeTGDomain()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter domain",
            $this->input['message_id'],
            reply: 'enter domain',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'setTelegramDomain',
            'args'           => [],
        ];
    }

public function changeTGAdtag()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter adtag or 0 for disable",
            $this->input['message_id'],
            reply: 'enter adtag or 0 for disable',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'setTelegramAdtag',
            'args'           => [],
        ];
    }
}

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
        $ip    = filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $this->ip : '';
        $adtag = strtolower(trim((string) @file_get_contents('/config/mtprotoadtag')));
        $q     = fn ($s) => '"' . addcslashes((string) $s, "\\\"") . '"';
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
            '[censorship]',
            'tls_domain = ' . $q($this->tgDomain()),
            'mask = true',
            'tls_emulation = true',
            '',
            '[access.users]',
            'sbbot = ' . $q($this->tgSecret()),
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

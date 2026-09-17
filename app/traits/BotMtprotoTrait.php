<?php

trait BotMtprotoTrait
{
// Контейнер tg — готовый образ teleproxy (наш прежний форк MTProxy от
// GetPageSpeed заархивирован, разработка переехала туда). Управление им
// отличается от остальных контейнеров: sshd внутри нет, поэтому вместо
// ssh+pkill используется Docker API, а настройки живут в двух местах:
//
//   override.env (/docker/env) — источник правды. start.sh образа при КАЖДОМ
//                                старте заново генерирует config.toml из
//                                переменных окружения, так что запись только
//                                в config.toml не пережила бы пересоздание
//                                контейнера;
//   data/config.toml + SIGHUP  — мгновенное применение без разрыва соединений
//                                (образ это прямо поддерживает).
//
// Сами секрет и домен по-прежнему лежат в /config/mtprotosecret и
// /config/mtprotodomain: на них завязаны меню, бэкап и восстановление.
public function tgConfigFile()
    {
        return '/config/teleproxy/config.toml';
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
        if (preg_match('~^(?:dd|ee)?([0-9a-f]{32})~i', $secret, $m)) {
            $secret = strtolower($m[1]);
        }
        file_put_contents('/config/mtprotosecret', $secret);
        $this->restartTG();
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

// Секрет, с которым реально работает прокси. Обычно это наш файл, но на свежей
// установке его ещё нет, а контейнер уже поднялся и сгенерировал себе секрет
// сам — тогда забираем его из config.toml и сохраняем у себя, иначе ссылка из
// меню не совпала бы с тем, что принимает прокси.
public function tgSecret()
    {
        $secret = trim(@file_get_contents('/config/mtprotosecret') ?: '');
        if (preg_match('~^[0-9a-f]{32}$~i', $secret)) {
            return strtolower($secret);
        }
        $toml = @file_get_contents($this->tgConfigFile()) ?: '';
        if (preg_match('~^\s*key\s*=\s*"([0-9a-f]{32})"~mi', $toml, $m)) {
            file_put_contents('/config/mtprotosecret', strtolower($m[1]));
            return strtolower($m[1]);
        }
        return '';
    }

public function tgDomain()
    {
        return trim(@file_get_contents('/config/mtprotodomain') ?: '') ?: 'yandex.ru';
    }

// Статус берём из состояния контейнера, а не из pgrep: в образе нет sshd, а
// healthcheck там дёргает /stats самого прокси — это честнее, чем наличие
// процесса.
public function tgStatus()
    {
        $state = $this->containerState('tg');
        return !empty($state['running']) && ($state['health'] ?? 'healthy') !== 'unhealthy' ? 'on' : 'off';
    }

// Ключи в override.env. Файл читают все контейнеры как env_file, поэтому имена
// с префиксом TG_, а в compose они отображаются в SECRET/EE_DOMAIN/PROXY_TAG.
public function tgEnvSet(array $vars)
    {
        $path  = '/docker/env';
        $lines = preg_split('~\R~', (string) @file_get_contents($path)) ?: [];
        foreach ($vars as $k => $v) {
            $found = false;
            foreach ($lines as $i => $line) {
                if (preg_match('~^\s*' . preg_quote($k, '~') . '\s*=~', $line)) {
                    $lines[$i] = "$k=$v";
                    $found     = true;
                }
            }
            if (!$found) {
                $lines[] = "$k=$v";
            }
        }
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
        return file_put_contents($path, implode("\n", $lines) . "\n") !== false;
    }

public function restartTG()
    {
        $secret = $this->tgSecret();
        $domain = $this->tgDomain();
        $adtag  = trim(file_exists('/config/mtprotoadtag') ? file_get_contents('/config/mtprotoadtag') : '');
        if (!preg_match('~^[0-9a-f]{32}$~i', $secret)) {
            // Секрета нет вообще (свежая установка до первого «сгенерировать») —
            // пусть контейнер поднимается со своим, tgSecret() заберёт его при
            // первом открытии меню.
            return;
        }
        $this->tgEnvSet([
            'TG_SECRET' => $secret,
            'TG_DOMAIN' => $domain,
            'TG_TAG'    => $adtag,
        ]);

        // Быстрый путь: правим уже сгенерированный config.toml и просим
        // перечитать его сигналом. Если файла ещё нет (контейнер ни разу не
        // стартовал), делать нечего — start.sh создаст его из env.
        $path = $this->tgConfigFile();
        $toml = @file_get_contents($path);
        if ($toml === false) {
            return;
        }
        $toml = preg_replace('~^\s*domain\s*=.*$~m', 'domain = "' . $domain . '"', $toml, -1, $n);
        if (empty($n)) {
            $toml = preg_replace('~^(\s*http_stats\s*=.*)$~m', "$1\ndomain = \"$domain\"", $toml, 1);
        }
        // Секрет у нас один: заменяем ключ во всех блоках [[secret]].
        $toml = preg_replace('~^(\s*key\s*=\s*)"[^"]*"~m', '$1"' . $secret . '"', $toml);
        if ($adtag) {
            $toml = preg_replace('~^\s*proxy_tag\s*=.*$~m', 'proxy_tag = "' . $adtag . '"', $toml, -1, $n);
            if (empty($n)) {
                $toml = preg_replace('~^(\s*http_stats\s*=.*)$~m', "$1\nproxy_tag = \"$adtag\"", $toml, 1);
            }
        } else {
            $toml = preg_replace('~^\s*proxy_tag\s*=.*$\R?~m', '', $toml);
        }
        file_put_contents($path, $toml);
        $this->signalContainer('tg', 'HUP');
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
        $this->tgStart();
        $this->restartTG();
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
                'text'          => $this->i18n('logs'),
                'callback_data' => "/tgLogs",
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

// Логи прокси. Готовый образ пишет в stdout контейнера, а не в файл в /logs,
// поэтому в общий список логов они не попадают — показываем отдельной кнопкой.
public function tgLogs()
    {
        $log  = trim($this->containerLogs('tg', 100));
        // Телеграм не принимает сообщение длиннее 4096 символов — оставляем хвост.
        $log  = strlen($log) > 3000 ? '...' . substr($log, -3000) : $log;
        $text = "Menu -> MTProto -> " . $this->i18n('logs') . "\n\n<pre>" . htmlspecialchars($log ?: '-') . "</pre>";
        $data = [
            [
                [
                    'text'          => $this->i18n('back'),
                    'callback_data' => "/mtproto",
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

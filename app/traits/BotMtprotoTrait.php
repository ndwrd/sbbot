<?php

trait BotMtprotoTrait
{
// Контейнер tg — Telemt (github.com/telemt/telemt): обычный MTProto (ee) и
// WEB-прокси в одном процессе. sshd в образе нет (distroless),
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
        // «0» по подсказке в setSecret() — отключить пользователя MTProto
        // (см. tgWriteConfig()), а сам Telemt не останавливать: в нём же
        // работает WEB-прокси. Секрет не трогаем — «Установить» с тем же ключом
        // вернёт прежние ссылки. tgStart() — если контейнер остановил прежний
        // «0» (раньше он гасил Telemt целиком): WEB должен вернуться.
        if ($secret === '0') {
            $this->tgSetUserOff(true);
            $this->restartTG();
            $this->tgStart();
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
        $this->tgSetUserOff(false);
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

// Работает ли Telemt — по состоянию контейнера, а не по pgrep: в образе нет ни
// sshd, ни shell, а healthcheck (см. docker-compose.yml) спрашивает сам Telemt
// через его API — это честнее, чем наличие процесса. От этого зависит и
// WEB-прокси.
public function tgRunning()
    {
        $state = $this->containerState('tg');
        return !empty($state['running']) && ($state['health'] ?? 'healthy') !== 'unhealthy';
    }

// Статус обычного MTProto: 'on'; 'user off' — Telemt работает, но пользователь
// MTProto отключён «0» вместо ключа; 'off' — Telemt не работает.
public function tgStatus()
    {
        if (!$this->tgRunning()) {
            return 'off';
        }
        return !empty($this->getPacConf()['tgUserOff']) ? 'user off' : 'on';
    }

public function tgSetUserOff($off)
    {
        $this->updatePacConf(function ($c) use ($off) {
            if ($off) {
                $c['tgUserOff'] = true;
            } else {
                unset($c['tgUserOff']);
            }
            return $c;
        });
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
        // WEB-часть — когда её можно собрать целиком: Telemt с web.enabled
        // требует listener, vhost и профиль, а vhost — публичный IP
        // (public_addr участвует во внутреннем маршруте relay).
        //
        // Держим её и при выключенном WEB, если он хоть раз включался (ключ
        // есть): выключение — это отключённый пользователь web (ниже), его
        // Telemt применяет на лету. Иначе каждое вкл/выкл меняло бы холодную
        // часть конфига и перезапускало контейнер — с обрывом обычного
        // MTProto. Перезапуск остаётся только у первого включения. Чужой трафик
        // при выключенном WEB к Telemt и так не доходит: nginx отдаёт WEB-имя
        // обычному блоку домена, тому же сайту-обманке (cloakNginx()).
        $pac     = $this->getPacConf();
        $webHost = $this->tgWebHost($pac);
        $webKey  = $this->tgWebSecret();
        $web     = $webHost !== '' && $ip !== '' && $webKey !== '';
        $webOff  = $web && empty($pac['tgWeb']);
        $userOff = !empty($pac['tgUserOff']);
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
            // «0» вместо ключа (secretSet() у MTProto, tgWebOff() у WEB):
            // пользователь остаётся в конфиге, но отключён. Telemt применяет это
            // на лету и сам рвёт его сессии, второй пользователь продолжает
            // работать. Секция идёт после [access.users], то есть попадает в
            // горячую часть (tgColdPart()).
            ...($userOff || $webOff ? ['', '[access.user_enabled]'] : []),
            $userOff ? 'sbbot = false' : null,
            $webOff ? 'web = false' : null,
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
// остановили намеренно (нода выключена кнопкой в боте) — он прочитает конфиг,
// когда его запустят (tgStart()).
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
//
// $userOff — отключён ли пользователь MTProto («0» в меню ноды): '1' или '0'.
// Флаг хранится в записи ноды на главном и приходит с каждым вызовом, поэтому
// смена домена или включение ноды его не сбрасывают. Главный старее этого
// аргумента его не шлёт — тогда флаг не трогаем.
public function applyMtproto($secret, $domain = '', $userOff = '')
    {
        $secret = trim((string) $secret);
        if (!preg_match('~^[0-9a-f]{32}$~i', $secret)) {
            return 'error';
        }
        file_put_contents('/config/mtprotosecret', strtolower($secret));
        if (trim((string) $domain) !== '') {
            file_put_contents('/config/mtprotodomain', trim((string) $domain));
        }
        if ($userOff !== '') {
            $this->tgSetUserOff($userOff === '1');
        }
        // Сначала конфиг, потом старт: остановленный контейнер поднимется сразу
        // с новыми настройками, работающий — применит их в restartTG().
        $this->restartTG();
        $this->tgStart();
        return 'ok';
    }

// WEB-прокси: тот же Telemt, отдельный пользователь "web" с секретом в режиме
// dd (ee WEB-клиенты не принимают). Отдельный — чтобы его можно было сменить,
// не трогая обычный MTProto.
//
// Управление — как у MTProto: «Сгенерировать ключ» и «Установить свой ключ»
// включают WEB (если он выключен) с новым секретом, «0» вместо ключа —
// выключает; сам секрет при этом остаётся.
public function tgWebSecret()
    {
        $s = strtolower(trim((string) ($this->getPacConf()['tgWebSecret'] ?? '')));
        return preg_match('~^[0-9a-f]{32}$~', $s) ? $s : '';
    }

public function tgWebOn()
    {
        return !empty($this->getPacConf()['tgWeb']) && $this->tgWebHost() !== '' && $this->tgRunning();
    }

// Порт в WEB-ссылке не указывается: клиент требует 443.
public function linkWebProxy()
    {
        $host = $this->tgWebHost();
        $s    = $this->tgWebSecret();
        return $host !== '' && $s !== '' ? "https://t.me/webproxy?server=$host&secret=dd$s" : '';
    }

public function tgWebGenerate()
    {
        $this->tgWebEnable(bin2hex(random_bytes(16)));
    }

public function tgWebSetSecret()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter key or 0 for stop web proxy",
            $this->input['message_id'],
            reply: 'enter key or 0 for stop web proxy',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'tgWebSecretSet',
            'args'           => [],
        ];
    }

public function tgWebSecretSet($secret)
    {
        $secret = trim($secret);
        if ($secret === '0') {
            $this->tgWebOff();
            $this->mtproto();
            return;
        }
        // Как в secretSet(): принимаем и ключ из ссылки — с префиксом dd/ee.
        if (!preg_match('~^(?:dd|ee)?([0-9a-f]{32})$~i', $secret, $m)) {
            $this->update($this->input['chat'], $this->input['message_id'], 'wrong secret');
            sleep(2);
            $this->mtproto();
            return;
        }
        $this->tgWebEnable(strtolower($m[1]));
    }

public function tgWebEnable($secret)
    {
        $r = $this->tgWebApply($secret);
        $text = str_replace('%host%', $this->tgWebHost(), $this->i18n($r));
        // После certbot (десятки секунд) всплывающее окно уже не показать —
        // Telegram к этому времени отбрасывает ответ на нажатие.
        if ($r === 'web cert failed') {
            $this->send($this->input['chat'], $text);
        } elseif ($r !== 'ok') {
            $this->notice($text);
        }
        $this->mtproto();
    }

// Сообщение об отказе: из кнопки — всплывающим окном, из ввода ключа —
// отдельным сообщением (кнопка, с которой начинали, к этому моменту уже
// отвечена).
public function notice($text)
    {
        if (!empty($this->input['reply'])) {
            $this->send($this->input['chat'], $text);
        } else {
            $this->answer($this->input['callback_id'], $text, true);
        }
    }

// Включение WEB и смена его ключа — без интерфейса: зовётся и из меню Бота, и
// главным на ноде через console.php (nodeWebApply()). Возвращает 'ok' или ключ
// i18n с причиной отказа.
public function tgWebApply($secret)
    {
        $secret = strtolower(trim((string) $secret));
        if (!preg_match('~^[0-9a-f]{32}$~', $secret)) {
            return 'wrong secret';
        }
        $pac = $this->getPacConf();
        // Уже включён — меняется только ключ: Telemt подхватит его на лету,
        // соединения обычного MTProto это не рвёт.
        if (!empty($pac['tgWeb'])) {
            $this->updatePacConf(function ($c) use ($secret) {
                $c['tgWebSecret'] = $secret;
                return $c;
            });
            $this->restartTG();
            return 'ok';
        }
        $check = $this->tgWebCheck();
        if ($check !== 'ok') {
            return $check;
        }
        $this->updatePacConf(function ($c) use ($secret) {
            $c                = $this->ensureProtocolSubdomains($c);
            $c['tgWeb']       = true;
            $c['tgWebSecret'] = $secret;
            return $c;
        });
        $host = $this->tgWebHost();
        // Откат, если WEB не удалось довести до рабочего состояния: не держать
        // включённым то, что не работает.
        $rollback = function ($reason) {
            $this->updatePacConf(function ($c) {
                unset($c['tgWeb']);
                return $c;
            });
            $this->cloakNginx();
            return $reason;
        };
        // Сначала nginx: проверка Host на 80-м порту должна пропускать WEB-имя
        // до того, как certbot пойдёт его подтверждать (HTTP-01).
        $this->cloakNginx();
        // Сертификат перевыпускаем, только если WEB-имени в нём нет: setSSL()
        // добавляет его сам, как только DNS имени смотрит на сервер, так что
        // после выпуска SSL с готовым DNS (и всегда у nip.io) включение WEB
        // обходится без certbot.
        if (!in_array($host, $this->domainsCert() ?: [], true)) {
            // Без A-записи certbot провалил бы выпуск целиком — не зовём его.
            if (!$this->tgWebDnsReady()) {
                return $rollback('web dns missing');
            }
            // На ноде чата нет (console.php) — там это сообщение шлёт главный.
            if (!empty($this->input['chat'])) {
                $this->send($this->input['chat'], str_replace('%host%', $host, $this->i18n('web cert issuing')));
            }
            $this->setSSL('letsencrypt');
            if (!in_array($host, $this->domainsCert() ?: [], true)) {
                return $rollback('web cert failed');
            }
        }
        $this->restartTG();
        return 'ok';
    }

// Можно ли включить WEB: 'ok' или ключ i18n с причиной.
public function tgWebCheck()
    {
        $pac = $this->getPacConf();
        // WebView клиента примет только сертификат публичного CA —
        // самоподписанный мост не загрузит.
        if (empty($pac['domain']) || ($pac['letsencrypt'] ?? '') !== 'letsencrypt') {
            return 'web needs letsencrypt';
        }
        if (!filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)) {
            return 'web needs public ip';
        }
        return 'ok';
    }

// Выключение — на лету: пользователь web отключается (tgWriteConfig()),
// контейнер не перезапускается, обычный MTProto не рвётся.
public function tgWebOff()
    {
        $this->updatePacConf(function ($c) {
            unset($c['tgWeb']);
            return $c;
        });
        $this->cloakNginx();
        $this->restartTG();
        return 'ok';
    }

// Состояние прокси одним вызовом — для главного: он зовёт это на ноде через
// console.php (nodeTgReport()) и кэширует WEB-часть в записи ноды, чтобы
// строить ссылки без SSH.
public function tgReport()
    {
        $pac = $this->getPacConf();
        return [
            'status'       => $this->tgStatus(),
            'tgWeb'        => !empty($pac['tgWeb']) && $this->tgWebHost($pac) !== '',
            'tgWebSecret'  => $this->tgWebSecret(),
            'webSubdomain' => $pac['webSubdomain'] ?? null,
            'domain'       => $pac['domain'] ?? null,
            'webCheck'     => $this->tgWebCheck(),
            // Нужен ли перевыпуск при включении и готов ли для него DNS — чтобы
            // главный сообщал о выпуске сертификата, только когда он будет.
            'webCert'      => $this->tgWebHost($pac) !== '' && in_array($this->tgWebHost($pac), $this->domainsCert() ?: [], true),
            'webDns'       => $this->tgWebDnsReady($pac),
        ];
    }

// QR WEB-прокси: Бота и каждой ноды, где он включён, — отдельными
// сообщениями, как в qrMtproto().
public function qrWebProxy()
    {
        $links = [];
        if ($this->tgWebOn()) {
            $links['webproxy'] = $this->linkWebProxy();
        }
        foreach ($this->getNodes() as $id => $node) {
            $link = $this->nodeLinkWebProxy($id);
            if ($link !== '' && $this->nodeTgOn($id)) {
                $links["webproxy {$node['label']}"] = $link;
            }
        }
        if (empty($links)) {
            $this->answer($this->input['callback_id'], $this->i18n('web proxy') . ': off', true);
            return;
        }
        foreach ($links as $name => $link) {
            $label = $name === 'webproxy' ? '' : substr($name, 9) . ': ';
            $this->sendQr($name, $link, "$label<code>$link</code>");
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

// Menu -> Telegram Proxy: блоки MTProto и Web Proxy Бота, под ними — такие же
// по каждой ноде. Состояние нод — из кэша (статус MTProto — из опроса нод
// checkNodesStatus() раз в 30 с, WEB — из записи ноды): по SSH на каждое
// открытие меню не ходим.
public function mtproto()
    {
        $st     = $this->tgStatus();
        $web    = $this->tgWebOn();
        $host   = $this->tgWebHost();
        $text[] = "Menu -> " . $this->i18n('telegram proxy');
        $text[] = '';
        $text[] = '<b>MTProto</b>';
        $text[] = "Status: $st";
        $text[] = 'Fake domain: <code>' . $this->tgDomain() . '</code>';
        if ($st == 'on') {
            $text[] = 'Link: ' . $this->linkMtproto();
        }
        $text[] = '';
        $text[] = '<b>Web Proxy</b>';
        $text[] = 'Status: ' . ($web ? 'on' : 'off');
        if ($host !== '') {
            $text[] = "Domain: <code>$host</code>";
        }
        if ($web) {
            $text[] = 'Link: ' . $this->linkWebProxy();
        }
        $cache = $this->readJsonLocked('/config/nodes_status.json') ?: [];
        foreach ($this->getNodes() as $id => $node) {
            $link   = $this->nodeLinkMtproto($id);
            $svc    = $cache[$id]['services'] ?? null;
            $text[] = '';
            $text[] = '<b>' . $this->i18n('telegram proxy') . " {$node['label']}</b>";
            if ($link === '') {
                $text[] = 'MTProto: ' . (!empty($node['mtprotoUserOff']) ? 'user off' : $this->i18n('not configured'));
            } else {
                $text[] = 'MTProto: ' . (is_array($svc) ? (!empty($svc['mtproto']) ? 'on' : 'off') . ' · ' : '')
                    . '<code>' . trim($node['mtprotodomain'] ?? 'yandex.ru') . '</code>';
                $text[] = "Link: $link";
            }
            $webLink = $this->nodeLinkWebProxy($id);
            if ($webLink === '') {
                $text[] = 'Web Proxy: off';
            } else {
                $text[] = 'Web Proxy: ' . (is_array($svc) && empty($svc['mtproto']) ? 'off' : 'on') . ' · <code>' . $this->nodeWebHost($node) . '</code>';
                $text[] = "Link: $webLink";
            }
        }
        $data = [
            [['text' => '· MTProto ·', 'callback_data' => "/mtproto"]],
            [
                ['text' => $this->i18n('generateSecret'), 'callback_data' => "/generateSecret"],
                ['text' => $this->i18n('setSecret'), 'callback_data' => "/setSecret"],
            ],
            [
                ['text' => $this->i18n('changeFakeDomain'), 'callback_data' => "/changeTGDomain"],
                ['text' => $this->i18n('show QR'), 'callback_data' => "/qrMtproto"],
            ],
            [['text' => '· ' . $this->i18n('web proxy') . ' ·', 'callback_data' => "/mtproto"]],
            [
                ['text' => $this->i18n('generateSecret'), 'callback_data' => "/tgWebGenerate"],
                ['text' => $this->i18n('setSecret'), 'callback_data' => "/tgWebSetSecret"],
            ],
            [['text' => $this->i18n('show QR'), 'callback_data' => "/qrWebProxy"]],
            [['text' => $this->i18n('back'), 'callback_data' => "/menu"]],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text),
            $data,
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

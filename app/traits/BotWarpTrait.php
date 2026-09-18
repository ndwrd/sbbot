<?php

trait BotWarpTrait
{
public function warpPlus()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter key",
            $this->input['message_id'],
            reply: 'enter key',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'addWarpPlus',
            'args'           => [],
        ];
    }

public function addWarpPlus($key)
    {
        $c    = $this->getPacConf();
        $chat = $this->input['chat'];
        $log  = '';

        $step = function($label, $cmd, $container) use ($chat, &$log) {
            $log .= "$label...";
            $this->sendDraft($chat, 1, $log);
            $out = trim($this->ssh($cmd, $container));
            $log .= $out ? "\n$out\n" : " ok\n";
            $this->sendDraft($chat, 1, $log);
            return empty($out); // true = success (no output = exit 0)
        };

        if (!$step('stopping warp', 'wg-quick down /etc/warp/wgcf-profile.conf 2>/dev/null || true', 'wp')) {
            $this->send($chat, $log);
            return;
        }

        if (!$step('removing old profile', 'rm -f /etc/warp/wgcf-profile.conf /etc/warp/wgcf-account.toml', 'wp')) {
            $this->send($chat, $log);
            return;
        }

        if (!$step('[warp] Registering WARP account...', 'cd /etc/warp && wgcf register --accept-tos', 'wp')) {
            $this->send($chat, $log);
            return;
        }

        if (!empty($key)) {
            $c['warp'] = $key;
            if (!$step('setting license key', "sed -i 's/^license_key.*/license_key = \"$key\"/' /etc/warp/wgcf-account.toml", 'wp')) {
                $this->send($chat, $log);
                return;
            }
            if (!$step('applying license key', 'cd /etc/warp && wgcf update', 'wp')) {
                $this->send($chat, $log);
                return;
            }
        } else {
            unset($c['warp']);
        }
        $this->setPacConf($c);

        if (!$step('generating profile', 'cd /etc/warp && wgcf generate', 'wp')) {
            $this->send($chat, $log);
            return;
        }
        $this->ssh("sed -i '/^Address.*:/d' /etc/warp/wgcf-profile.conf", 'wp');
        $this->ssh("sed -i '/^AllowedIPs.*::/d' /etc/warp/wgcf-profile.conf", 'wp');

        $started = $step('starting warp', 'out=$(wg-quick up /etc/warp/wgcf-profile.conf 2>&1 | grep -v "skip sysctl"); ec=${PIPESTATUS[0]}; [ "$ec" -ne 0 ] && printf "%s" "$out"; exit $ec', 'wp');

        if (!$started) {
            $this->send($chat, $log);
            return;
        }

        sleep(1);
        // sendMessageDraft closes only when sendMessage is called — send a dummy and delete it
        $r = $this->send($chat, '.');
        if (!empty($r['result']['message_id'])) {
            $this->delete($chat, $r['result']['message_id']);
        }

        $this->warp();
    }

public function warpStatus()
    {
        // Запрос идёт через сам туннель WARP до Cloudflare, и на ноде с потерями
        // по пути TLS через туннель занимает от долей секунды до 9 с (замер на
        // BG: TCP переотправляет потерянные пакеты с растущей паузой). Отсюда
        // 10 с. Если wp не запущен, отказ приходит сразу — ждать приходится,
        // только когда туннель медленный. --connect-timeout сюда не годится: в
        // curl он ограничивает всю фазу соединения, включая SOCKS и TLS через
        // туннель, — то есть ту же медленную часть.
        $st = $this->ssh('curl -m 10 -x socks5://wp:1080 https://cloudflare.com/cdn-cgi/trace', 'sbx');
        // Ответа может не быть вовсе (контейнер wp не поднят, нет сети) — тогда
        // preg_match не заполнит $m, и это штатное "выключено", а не ошибка.
        preg_match('~warp=(\w+)~', $st, $m);
        return trim($m[1] ?? '') ?: 'off';
    }

// Warp для блока статуса. Проверка дорогая (см. warpStatus()), поэтому раз в
// минуту, а не на каждом проходе cron (раз в 10 с), и красный — только после
// двух неудач подряд: одна медленная попытка статус не меняет. Состояние между
// проходами — в том же menu_status.json, так что переживает и перезапуск cron.
// Рабочими считаются и on, и plus (WARP+).
public function warpMenuStatus()
    {
        $prev = json_decode(@file_get_contents('/config/menu_status.json') ?: '', true) ?: [];
        if (isset($prev['warp']) && time() - (int) ($prev['warpTime'] ?? 0) < 60) {
            return array_intersect_key($prev, ['warp' => 1, 'warpFails' => 1, 'warpTime' => 1]);
        }
        $ok    = in_array($this->warpStatus(), ['on', 'plus'], true);
        $fails = $ok ? 0 : (int) ($prev['warpFails'] ?? 0) + 1;
        return [
            'warp'      => $ok || (!empty($prev['warp']) && $fails < 2),
            'warpFails' => $fails,
            'warpTime'  => time(),
        ];
    }

public function offWarp()
    {
        $p    = $this->getPacConf();
        if (!empty($this->selfupdate)) {
            if (!empty($p['warpoff'])) {
                $this->ssh('warp-cli --accept-tos registration delete 2>&1', 'wp');
                $this->ssh('pkill warp-svc', 'wp');
            }
        } elseif (!empty($p['warpoff'])) {
            $this->ssh('warp-svc > /dev/null 2>&1 &', 'wp');
            sleep(3);
            if (empty($this->ssh('[ -f "/var/lib/cloudflare-warp/conf.json" ] && echo 1', 'wp'))) {
                $this->send($this->input['chat'], 'Registration: ' . $this->ssh('warp-cli --accept-tos registration new 2>&1', 'wp'));
                if (!empty($p['warp'])) {
                    $this->send($this->input['chat'], 'License: ' . $this->ssh("warp-cli --accept-tos registration license {$p['warp']} 2>&1", 'wp'));
                }
            }
            $this->send($this->input['chat'], 'Proxy mode: ' . $this->ssh('warp-cli --accept-tos mode proxy 2>&1', 'wp'));
            $this->send($this->input['chat'], 'Connect: ' . $this->ssh('warp-cli --accept-tos connect 2>&1', 'wp'));
            unset($p['warpoff']);
        } else {
            $this->send($this->input['chat'], 'Registration delete: ' . $this->ssh('warp-cli --accept-tos registration delete 2>&1', 'wp'));
            $this->ssh('pkill warp-svc', 'wp');
            $p['warpoff'] = 1;
        }
        $this->setPacConf($p);
        if (empty($this->selfupdate)) {
            $this->warp();
        }
    }

public function warp()
    {
        $p      = $this->getPacConf();
        // Файлы появляются только после регистрации WARP (том warp: в
        // docker-compose), а меню открывается и до неё — тогда тут было по два
        // "Failed to open stream" на каждый заход.
        $c      = @file_get_contents('/etc/warp/wgcf-profile.conf') ?: '';
        $a      = @file_get_contents('/etc/warp/wgcf-account.toml') ?: '';
        $text[] = "Menu -> " . $this->i18n('warp');
        $text[] = "status: <pre>" . $this->ssh('wgcf trace', 'wp') . '</pre>';
        $text[] = "key: <code>" . ($p['warp'] ?? '') . "</code>";
        $text[] = "<pre>$a</pre>";
        $text[] = "<pre>$c</pre>";
        $data[] = [
            [
                'text'          => $this->i18n('set key'),
                'callback_data' => "/warpPlus",
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
}

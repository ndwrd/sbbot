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

        // Удаляем старые профиль и аккаунт
        if (!$step('removing old profile', 'rm -f /etc/warp/wgcf-profile.conf /etc/warp/wgcf-account.toml', 'wp')) {
            $this->send($chat, $log);
            return;
        }

        // Регистрируем новый аккаунт
        if (!$step('[warp] Registering WARP account...', 'cd /etc/warp && wgcf register --accept-tos', 'wp')) {
            $this->send($chat, $log);
            return;
        }

        if (!empty($key)) {
            $c['warp'] = $key;
            // Заменяем ключ в wgcf-account.toml
            if (!$step('setting license key', "sed -i 's/^license_key.*/license_key = \"$key\"/' /etc/warp/wgcf-account.toml", 'wp')) {
                $this->send($chat, $log);
                return;
            }
            // Обновляем аккаунт с ключом
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
        $st = $this->ssh('curl -m 1 -x socks5://wp:1080 https://cloudflare.com/cdn-cgi/trace', 'sbx');
        preg_match('~warp=(\w+)~', $st, $m);
        return trim($m[1]) ?: 'off';
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
        $c      = file_get_contents('/etc/warp/wgcf-profile.conf');
        $a      = file_get_contents('/etc/warp/wgcf-account.toml');
        $text[] = "Menu -> " . $this->i18n('warp');
        $text[] = "status: <pre>" . $this->ssh('wgcf trace', 'wp') . '</pre>';
        $text[] = "key: <code>{$p['warp']}</code>";
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

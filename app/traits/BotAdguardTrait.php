<?php

trait BotAdguardTrait
{
public function readAdguardConfig()
    {
        // AdGuardHome НЕ пишет /config/AdGuardHome.yaml на диск сама, пока её
        // install-wizard не пройден через веб-интерфейс — а мы его никогда не
        // проходим, конфиг с нуля собирает бот. Пара попыток с паузой — только
        // на случай, если файл в этот момент читает/пишет параллельный вызов
        // (adguardSync() и adguardSingboxClients() работают с одним файлом);
        // если файла всё равно нет, отдаём валидный шаблон сами, а не false —
        // раньше запись поверх false PHP молча превращала в пустой массив, и
        // дальнейший yaml_emit_file() писал огрызок без dns/schema_version.
        // schema_version обязателен: без него AdGuardHome решает, что схема
        // нулевая, и пытается мигрировать её поверх структур (clients и т.п.),
        // которые бот и так пишет в текущем формате — несовместимость валит
        // AdGuardHome на старте ("unexpected type of clients").
        for ($i = 0; $i < 6; $i++) {
            $c = yaml_parse_file($this->adguard);
            if (is_array($c)) {
                return $c;
            }
            usleep(500000);
        }
        return [
            'schema_version' => 34,
            'dns' => [
                'bind_hosts'   => ['0.0.0.0'],
                'upstream_dns' => ['1.1.1.1', '8.8.8.8'],
            ],
            'users' => [],
        ];
    }

public function adguardSync()
    {
        $pac = $this->getPacConf();
        $pac['adpswd'] = ($pac['adpswd'] ?? null) ?: substr(hash('md5', time()), 0, 10);
        $this->setPacConf($pac);
        $ssl = $this->nginxGetTypeCert();
        $c   = $this->readAdguardConfig();
        $this->stopAd();
        // adguardBasicAuth() и текст меню везде подразумевают логин "admin" —
        // на чистой установке users стартует пустым, и без явного имени
        // получившаяся запись пользователя ни под каким логином не подходит.
        $c['users'][0]['name']     = 'admin';
        $c['users'][0]['password'] = password_hash($pac['adpswd'], PASSWORD_DEFAULT);
        // AdGuardHome по умолчанию поднимает веб-интерфейс на заводском :3000 (порт
        // выбирается только через install-wizard, который мы никогда не проходим) —
        // а nginx проксирует /adguard/ на 80-й порт (см. cloakNginx()), поэтому без
        // этой правки прокси всегда получает 502.
        $c['http']['address'] = '0.0.0.0:80';
        if (!empty($ssl) && !empty($pac['domain'])) {
            if (empty($c['tls']['enabled'])) {
                $c['tls']['enabled']     = true;
                $c['tls']['server_name'] = $pac['domain'];
            }
            // Независимо от того, включали TLS сейчас или раньше — пути к сертификату
            // могли остаться пустыми (как раз наш случай на живом сервере: enabled уже
            // true, но certificate_path/private_key_path так и не были прописаны).
            if (empty($c['tls']['certificate_path'])) {
                $c['tls']['certificate_path'] = '/certs/cert_public';
                $c['tls']['private_key_path'] = '/certs/cert_private';
            }
        }
        yaml_emit_file($this->adguard, $c);
        $this->startAd();
    }

public function adguardpsswd()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter password",
            $this->input['message_id'],
            reply: 'enter password',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'chpsswd',
            'args'          => [],
        ];
    }

public function setAdguardKey()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter key",
            $this->input['message_id'],
            reply: 'enter key',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'setAdKey',
            'args'          => [],
        ];
    }

public function setAdKey($key)
    {
        $c = $this->getPacConf();
        $c['adguardkey'] = $key;
        $this->setPacConf($c);
        $this->menu('adguard');
    }

public function adguardreset()
    {
        $out[] = 'Restart Adguard Home';
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
        exec('git -C / checkout config/AdGuardHome.yaml');
        $this->adguardSync();
        $this->cloakNginx();
        sleep(3);
        $this->menu('adguard');
    }

public function adguardSingboxClients()
    {
        $xr = $this->getSingbox();
        $ad = $this->readAdguardConfig();
        foreach ($xr['inbounds'][0]['settings']['clients'] as $k => $v) {
            $tmp[] = [
                'safe_search' => [
                    'enabled'    => true,
                    'bing'       => true,
                    'duckduckgo' => true,
                    'google'     => true,
                    'pixabay'    => true,
                    'yandex'     => true,
                    'youtube'    => true,
                ],
                'blocked_services' => [
                    'schedule' => ['time_zone' => date_default_timezone_get()],
                    'ids'      => [],
                ],
                'name'                        => $v['username'],
                'ids'                         => [$v['id']],
                'tags'                        => [],
                'upstreams'                   => [],
                'uid'                         => $v['id'],
                'upstreams_cache_size'        => 0,
                'upstreams_cache_enabled'     => false,
                'use_global_settings'         => true,
                'filtering_enabled'           => false,
                'parental_enabled'            => false,
                'safebrowsing_enabled'        => false,
                'use_global_blocked_services' => true,
                'ignore_querylog'             => false,
                'ignore_statistics'           => false,
            ];
        }
        $ad['clients']['persistent'] = $tmp;
        yaml_emit_file($this->adguard, $ad);
        $this->stopAd();
        $this->startAd();
    }

public function checkdns()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter dns address
Plain DNS:
<code>example.org 94.140.14.14</code>
DNS-over-TLS:
<code>example.org tls://dns.adguard.com</code>
DNS-over-TLS with IP:
<code>example.org tls://dns.adguard.com 94.140.14.14</code>
DNS-over-HTTPS with HTTP/2:
<code>example.org https://dns.adguard.com/dns-query</code>
DNS-over-HTTPS forcing HTTP/3 only:
<code>example.org h3://dns.google/dns-query</code>
DNS-over-HTTPS with IP:
<code>example.org https://dns.adguard.com/dns-query 94.140.14.14</code>",
            $this->input['message_id'],
            reply: 'enter command',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'dnscheck',
            'args'          => [],
        ];
    }

public function dnscheck($dns)
    {
        exec("JSON=1 dnslookup $dns", $out, $code);
        if ($code) {
            $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out), mode: false);
        } else {
            $this->send($this->input['chat'], "JSON=1 dnslookup $dns\n" . implode("\n", $out), mode: false);
        }
        sleep(3);
        $this->menu('adguard');
    }

public function addupstream()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter address upstream",
            $this->input['message_id'],
            reply: 'enter address upstream',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'upstream',
            'args'          => [],
        ];
    }

public function upstream($url)
    {
        $out[] = 'Restart Adguard Home';
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
        $out[] = $this->stopAd();
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
        $c = yaml_parse_file($this->adguard);
        $c['dns']['upstream_dns'][] = $url;
        yaml_emit_file($this->adguard, $c);
        $out[] = $this->startAd();
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
        sleep(3);
        $this->menu('adguard');
    }

public function delupstream($k)
    {
        $out[] = 'Restart Adguard Home';
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
        $this->stopAd();
        $c = yaml_parse_file($this->adguard);
        unset($c['dns']['upstream_dns'][$k]);
        $c['dns']['upstream_dns'] = array_values($c['dns']['upstream_dns']);
        yaml_emit_file($this->adguard, $c);
        $this->startAd();
        $this->menu('adguard');
    }

public function startAd()
    {
        return $this->ssh('/opt/adguardhome/AdGuardHome --no-check-update --pidfile /opt/adguardhome/pid -c /config/AdGuardHome.yaml -h 0.0.0.0 -w /opt/adguardhome/work', 'ad', false);
    }

public function stopAd()
    {
        return $this->ssh('kill -15 $(cat /opt/adguardhome/pid)', 'ad');
    }

public function adguardBasicAuth()
    {
        return base64_encode('admin:' . $this->getPacConf()['adpswd']);
    }

public function adguardChBr()
    {
        $c = $this->getPacConf();
        $c['adgbrowser'] = $c['adgbrowser'] ? 0 : 1;
        $this->setPacConf($c);
        $this->cloakNginx();
        $this->answer($this->input['callback_id'], $this->i18n($c['adgbrowser'] ? 'browser_notify_on' : 'browser_notify_off'), true);
        $this->menu('adguard');
    }

public function adguardMenu()
    {
        $conf   = $this->getPacConf();
        $ip     = $this->ip;
        $domain = $this->getDomain();
        $hash   = $this->getHashBot();
        $scheme = empty($ssl = $this->nginxGetTypeCert()) ? 'http' : 'https';
        $text   = "$scheme://$domain/adguard$hash\nLogin: admin\nPass: <span class='tg-spoiler'>{$conf['adpswd']}</span>\n\n";
        if ($ssl) {
            $text .= "DNS over HTTPS:\n<code>$ip</code>\n<code>$scheme://$domain/dns-query$hash" . (!empty($conf['adguardkey']) ? "/{$conf['adguardkey']}" : '') . "</code>\n\n";
            $text .= "DNS over TLS:\n<code>tls://" . (!empty($conf['adguardkey'] )? "{$conf['adguardkey']}." : '') . "$domain</code>";
        }
        $status = $this->i18n(exec("JSON=1 timeout 2 dnslookup google.com ad") ? 'on' : 'off');
        $safesearch = yaml_parse_file($this->adguard)['filtering']['safe_search']['enabled'];
        $text .= "\n\nstatus: $status\t\tsafesearch: " . $this->i18n($safesearch ? 'on' : 'off');
        $allowedClients = yaml_parse_file($this->adguard)['dns']['allowed_clients'];
        $text .= $allowedClients ? "\n\nallowed clients: \n - " . implode("\n - ", $allowedClients) : '';

        $data = [
            [
                [
                    'text'          => 'web panel',
                    'web_app' => [
                        "url" => "https://$domain/adguard$hash"
                    ],
                ],
                [
                    'text'          => $this->i18n('third party browser') . ': ' . $this->i18n(!empty($conf['adgbrowser']) ? 'on' : 'off'),
                    'callback_data' => '/adguardChBr'
                ],
            ],
            [
                [
                    'text'          => $this->i18n('change password'),
                    'callback_data' => "/adguardpsswd",
                ],
                [
                    'text'          => 'ClientID' . ($conf['adguardkey'] ? ": {$conf['adguardkey']}" : ''),
                    'callback_data' => "/setAdguardKey",
                ],
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('fill allowed clients'),
                'callback_data' => "/adgFillAllowedClients 0",
            ],
            [
                'text'          => $this->i18n('delete allowed clients'),
                'callback_data' => "/adgFillAllowedClients 1",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('check DNS'),
                'callback_data' => "/checkdns",
            ],
            [
                'text'          => $this->i18n('reset settings'),
                'callback_data' => "/adguardreset",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('add upstream'),
                'callback_data' => "/addupstream",
            ],
        ];
        $upstreams = yaml_parse_file($this->adguard)['dns']['upstream_dns'];
        if (!empty($upstreams)) {
            foreach ($upstreams as $k => $v) {
                $data[] = [
                    [
                        'text'          => $v,
                        'callback_data' => "/menu adguard",
                    ],
                    [
                        'text'          => $this->i18n('delete'),
                        'callback_data' => "/delupstream $k",
                    ],
                ];
            }
        }
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/menu",
            ],
        ];
        return [
            'text' => $text,
            'data' => $data,
        ];
    }

public function adgFillAllowedClients($delete = false)
    {
        $pac = $this->getPacConf();
        $out[] = 'Restart Adguard Home';
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
        $this->stopAd();
        $c = yaml_parse_file($this->adguard);
        if (!empty($delete)) {
            unset($c['dns']['allowed_clients']);
        } else {
            $c['dns']['allowed_clients'] = [];
            $c['dns']['allowed_clients'][] = '10.10.0.0/24';
            if (!empty($pac['adguardkey'])) {
                $c['dns']['allowed_clients'][] = $pac['adguardkey'];
            }
            if (!empty($xr = $this->getSingbox())) {
                foreach ($xr['inbounds'][0]['settings']['clients'] as $v) {
                    $c['dns']['allowed_clients'][] = $v['id'];
                }
            }
        }
        yaml_emit_file($this->adguard, $c);
        $this->startAd();
        $this->menu('adguard');
    }
}

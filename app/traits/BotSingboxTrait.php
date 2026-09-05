<?php

trait BotSingboxTrait
{
public function restartSingbox($c, $norestart = false)
    {
        // Виртуальный вид (та же форма, что был у xray.json) сохраняется в pac.json —
        // sing-box падает на неизвестных полях (off/time/template), поэтому книга
        // клиентов и sing-box-конфиг теперь разные файлы. См. план перехода на sing-box.
        $clients = array_values($c['inbounds'][0]['settings']['clients'] ?? []);
        $pac     = $this->getPacConf();
        $pac['singboxClients'] = $clients;

        $reality = $c['inbounds'][0]['streamSettings']['realitySettings'] ?? [];
        if (array_key_exists('serverNames', $reality)) {
            $pac['reality']['domain'] = $reality['serverNames'][0] ?? null;
        }
        if (array_key_exists('dest', $reality)) {
            $pac['reality']['destination'] = $reality['dest'];
        }
        if (array_key_exists('shortIds', $reality)) {
            $pac['reality']['shortId'] = $reality['shortIds'][0] ?? null;
        }
        if (array_key_exists('privateKey', $reality)) {
            $pac['reality']['privateKey'] = $reality['privateKey'];
        }
        if (array_key_exists('outbounds', $c)) {
            $pac['singboxOutbounds'] = $c['outbounds'];
        }
        if (isset($c['routing']['rules'])) {
            $pac['singboxRoutingRules'] = $c['routing']['rules'];
        }
        $this->setPacConf($pac);

        $sing = $this->buildSingboxConfig($pac);
        if (empty($norestart)) {
            $this->collectSession();
            file_put_contents('/config/sing-server.json', json_encode($sing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            // SIGHUP = sing-box's own graceful reload (validates the new config, then
            // swaps instances with a shutdown grace period for existing connections)
            // instead of a hard kill; falls back to a cold start if nothing is running yet.
            $this->ssh('pkill -HUP sing-box || (sing-box run -c /sing.json > /dev/null 2>&1 &)', 'sbx');
        } else {
            file_put_contents('/config/sing-server.json', json_encode($sing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

public function buildSingboxConfig($pac)
    {
        $hash      = $this->getHashBot();
        $transport = $pac['transport'] ?? 'Websocket';
        $users     = [];
        foreach ($pac['singboxClients'] ?? [] as $v) {
            if (empty($v['id']) || !empty($v['off'])) {
                continue;
            }
            $u = [
                'uuid' => $v['id'],
                'name' => $v['email'] ?? $v['id'],
            ];
            if ($transport == 'Reality') {
                $u['flow'] = 'xtls-rprx-vision';
            }
            $users[] = $u;
        }

        $inbound = [
            'type'        => 'vless',
            'tag'         => 'vless-in',
            'listen'      => '0.0.0.0',
            'listen_port' => 443,
            'users'       => $users,
            // multiplex поддерживает только vless среди наших протоколов (naive/hysteria2/
            // anytls мультиплексируются на своём транспортном уровне и такого поля не имеют).
            'multiplex'   => [
                'enabled' => true,
                'padding' => true,
            ],
        ];

        if ($transport == 'Reality') {
            $dest   = $pac['reality']['destination'] ?? ($pac['reality']['domain'] ?? '') . ':443';
            $server = explode(':', $dest)[0];
            $port   = (int) (explode(':', $dest)[1] ?? 443);
            $inbound['tls'] = [
                'enabled'     => true,
                'server_name' => $pac['reality']['domain'] ?? '',
                'reality'     => [
                    'enabled'   => true,
                    'handshake' => [
                        'server'      => $server,
                        'server_port' => $port ?: 443,
                    ],
                    'private_key' => $pac['reality']['privateKey'] ?? '',
                    'short_id'    => [$pac['reality']['shortId'] ?? ''],
                ],
            ];
        } else {
            $inbound['tls'] = ['enabled' => false];
            $inbound['transport'] = [
                'type' => 'ws',
                'path' => "/ws$hash",
            ];
        }

        // naive/anytls: TLS уже снят на ng (тот же паттерн, что и у vless-in выше — они
        // приходят по внутренней docker-сети уже расшифрованными, ng слушает их поддомены
        // сам). hysteria2 — единственный протокол на своём отдельном UDP-порту напрямую
        // на sbx, TLS здесь остаётся на стороне sing-box (реальный сертификат сервера).
        $protocolUsers = [];
        foreach ($pac['singboxClients'] ?? [] as $v) {
            if (empty($v['id']) || !empty($v['off']) || empty($v['password'])) {
                continue;
            }
            $protocolUsers[] = ['name' => $v['email'] ?? $v['id'], 'password' => $v['password']];
        }

        $inbounds = [$inbound];

        $inbounds[] = [
            'type'        => 'naive',
            'tag'         => 'naive-in',
            'listen'      => '0.0.0.0',
            'listen_port' => 8444,
            'users'       => array_map(fn ($u) => ['username' => $u['name'], 'password' => $u['password']], $protocolUsers),
            'tls'         => ['enabled' => false],
        ];

        $inbounds[] = [
            'type'           => 'anytls',
            'tag'            => 'anytls-in',
            'listen'         => '0.0.0.0',
            'listen_port'    => 8445,
            'users'          => $protocolUsers,
            'padding_scheme' => [
                'stop=8',
                '0=30-30',
                '1=100-400',
                '2=400-500,c,500-1000,c,500-1000,c,500-1000,c,500-1000',
                '3=9-9,500-1000',
                '4=500-1000',
                '5=500-1000',
                '6=500-1000',
                '7=500-1000',
            ],
            'tls'            => ['enabled' => false],
        ];

        $inbounds[] = [
            'type'        => 'hysteria2',
            'tag'         => 'hysteria2-in',
            'listen'      => '0.0.0.0',
            'listen_port' => 443,
            'users'       => $protocolUsers,
            'tls'         => [
                'enabled'          => true,
                'certificate_path' => '/certs/cert_public',
                'key_path'         => '/certs/cert_private',
            ],
        ];

        $outbounds = [
            ['type' => 'direct', 'tag' => 'direct'],
            ['type' => 'block', 'tag' => 'block'],
        ];
        foreach ($pac['singboxOutbounds'] ?? [] as $o) {
            $outbounds[] = $o;
        }

        return [
            'log' => [
                'level'  => 'info',
                'output' => '/logs/singbox.log',
            ],
            'dns' => [
                'servers' => [
                    ['tag' => 'default', 'type' => 'local'],
                ],
            ],
            'inbounds'  => $inbounds,
            'outbounds' => $outbounds,
            'route'     => [
                'rules' => $pac['singboxRoutingRules'] ?? [],
                'final' => 'direct',
            ],
            'experimental' => [
                'v2ray_api' => [
                    'listen' => '127.0.0.1:8080',
                    'stats'  => [
                        'enabled'   => true,
                        'inbounds'  => ['vless-in', 'naive-in', 'anytls-in', 'hysteria2-in'],
                        'outbounds' => ['direct'],
                        'users'     => array_unique(array_merge(array_column($users, 'name'), array_column($protocolUsers, 'name'))),
                    ],
                ],
            ],
        ];
    }

public function addXrUser()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter name",
            $this->input['message_id'],
            reply: 'enter name:description:uuid:password [,name:description:uuid:password]',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'addxrus',
            'args'           => [],
        ];
    }

public function renameXrUser($i)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter name",
            $this->input['message_id'],
            reply: 'enter password',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'renXrUs',
            'args'           => [$i],
        ];
    }

public function singboxStatsUser()
    {
        // Живой сбор статистики через sing-box v2ray_api временно не реализован
        // (нужен отдельный gRPC-клиент внутри sbx) — заглушка по согласованному плану,
        // getSingboxStats()/setSingboxStats() продолжают отдавать последнее сохранённое значение.
        return;
    }

public function checkResetSingboxStats()
    {
        $pac = $this->getPacConf();
        if (!empty($pac['reset_monthly'])) {
            $now    = time();
            $start  = strtotime('first day of previous month midnight');
            $period = strtotime('1 month', 0);

            if (
                !empty($start)
                && !empty($period)
                && $now >= $start
            ) {
                // Вычисляем, сколько полных периодов прошло с момента start
                $elapsed = $now - $start;
                $periodsElapsed = floor($elapsed / $period);

                // Время последнего планового сброса статистики
                $lastScheduledReset = $start + ($periodsElapsed * $period);

                // Проверяем, делали ли уже сброс в этом периоде
                $lastResetTime = $pac['last_reset_singbox_time'] ?? 0;

                // Если последний сброс был сделан до начала текущего периода - делаем сброс
                if ($lastResetTime < $lastScheduledReset) {
                    $pac['last_reset_singbox_time'] = $now;
                    $this->setPacConf($pac);
                    $this->resetXrStats(1);
                    require dirname(__DIR__) . '/config.php';
                    foreach ($c['admin'] as $admin) {
                        $this->send($admin, "vless: reset stats");
                    }
                }
            }
        }
    }

public function shutdownClientXr()
    {
        try {
            $c = $this->getSingbox();
            foreach ($c['inbounds'][0]['settings']['clients'] as $k => $v) {
                if (!empty($v['time']) && ($v['time'] < time())) {
                    $this->switchXr($k, 1);
                }
            }
        } catch (Exception $e) {
        }
    }

public function dw($u, $t)
    {
        $pac                    = $this->getPacConf();
        $c                      = $this->getSingbox()['inbounds'][0]['settings']['clients'][$u];
        $_GET['s']              = $c['id'];
        $_GET['t']              = $t;
        $_SERVER['SERVER_NAME'] = $this->getDomain($pac['transport'] != 'Reality');
        $conf                   = $this->subscription(1);
        $this->sendFile($this->input['from'], new CURLStringFile($conf, $c['email'] . ($t == 'cl' ? '_mihomo.yaml' :($t == 'si' ? '_singbox.json' : '_v2ray.json'))));
    }

public function timerXr($k)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter time like https://www.php.net/manual/ru/function.strtotime.php:",
            $this->input['message_id'],
            reply: 'enter time like https://www.php.net/manual/ru/function.strtotime.php:',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'setTimerXr',
            'args'          => [$k],
        ];
    }

public function backXtlsList($type, $page = 0)
    {
        switch ($type) {
            case 'includelist':
                $this->xtlsproxy($page);
                break;
            case 'blocklist':
                $this->singboxUpdateRules();
                $this->xtlsblock($page);
                break;
            case 'warplist':
                $this->singboxUpdateRules();
                $this->xtlswarp($page);
                break;
            case 'processlist':
                $this->xtlsprocess($page);
                break;
            case 'packagelist':
                $this->xtlsapp($page);
                break;
            case 'subnetlist':
                $this->xtlssubnet($page);
                break;
            case 'rulessetlist':
                $this->xtlsrulesset($page);
                break;
            case 'tunprocess':
                $this->tunprocess($page);
                break;
            case 'tunpackage':
                $this->tunpackage($page);
                break;
            case 'white':
            case 'deny':
                $this->syncDeny();
                $this->denyList($page, $type == 'white' ? 1 : 0);
                break;
        }
    }

public function singboxUpdateRules()
    {
        $c  = $this->getPacConf();
        $xr = $this->getSingbox();
        $xr['outbounds'] = [
            [
                'type'        => 'socks',
                'tag'         => 'warp',
                'server'      => '10.10.0.13',
                'server_port' => 1080,
            ],
        ];

        $toIpCidr = function ($k) {
            if (preg_match('~^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}(/\d{1,2})?$~', $k, $m)) {
                return $k . (empty($m[1]) ? '/32' : '');
            }
            return null;
        };
        $buildRules = function (array $list, string $outbound) use ($toIpCidr) {
            $domains = $ips = [];
            foreach (array_filter($list) as $k => $v) {
                $cidr = $toIpCidr($k);
                if ($cidr !== null) {
                    $ips[] = $cidr;
                } else {
                    $domains[] = $k;
                }
            }
            $rules = [];
            if (!empty($domains)) {
                $rules[] = ['domain_suffix' => $domains, 'outbound' => $outbound];
            }
            if (!empty($ips)) {
                $rules[] = ['ip_cidr' => $ips, 'outbound' => $outbound];
            }
            return $rules;
        };

        $rules = array_merge(
            $buildRules($c['blocklist'] ?? [], 'block'),
            $buildRules($c['warplist'] ?? [], 'warp'),
        );

        $xr['routing']['rules'] = $rules;
        $this->restartSingbox($xr);
    }

public function tunpackagemode()
    {
        $c = $this->getPacConf();
        $c['tunpackagemode'] = $c['tunpackagemode'] ? 0 : 1;
        $this->setPacConf($c);
        $this->tun();
    }

public function tunprocessmode()
    {
        $c = $this->getPacConf();
        $c['tunprocessmode'] = $c['tunprocessmode'] ? 0 : 1;
        $this->setPacConf($c);
        $this->tun();
    }

public function tunpackage($page = 0)
    {
        $text[] = "Menu -> " . $this->i18n('vless') . ' -> ' . $this->i18n('routes') . ' -> ' . $this->i18n('package');

        [$data] = $this->listPac('tunpackage', $page, 'tunpackage');
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/tun",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function tunprocess($page = 0)
    {
        $text[] = "Menu -> " . $this->i18n('vless') . ' -> ' . $this->i18n('routes') . ' -> ' . $this->i18n('process');

        [$data] = $this->listPac('tunprocess', $page, 'tunprocess');
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/tun",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function xtlsblock($page = 0)
    {
        $text[] = "Menu -> " . $this->i18n('vless') . ' -> ' . $this->i18n('routes') . ' -> block list';

        [$data] = $this->listPac('blocklist', $page, 'xtlsblock');
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/routes",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function xtlswarp($page = 0)
    {
        $text[] = "Menu -> " . $this->i18n('vless') . ' -> ' . $this->i18n('routes') . ' -> warp list';

        [$data] = $this->listPac('warplist', $page, 'xtlswarp');
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/routes",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function xtlsproxy($page = 0)
    {
        $_SESSION['proxylistentry'] = 1;
        $p = $this->getPacConf();
        $text[] = "Menu -> " . $this->i18n('vless') . ' -> ' . $this->i18n('routes') . ' -> proxy list';
        [$data] = $this->listPac('includelist', $page, 'xtlsproxy');
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/routes",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function appOutbound()
    {
        $p = $this->getPacConf();
        $p['app_outbound'] = !$p['app_outbound'];
        $p = $this->setPacConf($p);
        $this->xtlsapp();
    }

public function domainsOutbound()
    {
        $p = $this->getPacConf();
        $p['domains_outbound'] = !$p['domains_outbound'];
        $p = $this->setPacConf($p);
        $this->xtlsproxy();
    }

public function finalOutbound()
    {
        $p = $this->getPacConf();
        $p['final_outbound'] = !$p['final_outbound'];
        $p = $this->setPacConf($p);
        $this->routes();
    }

public function processOutbound()
    {
        $p = $this->getPacConf();
        $p['process_outbound'] = !$p['process_outbound'];
        $p = $this->setPacConf($p);
        $this->xtlsprocess();
    }

public function xtlsapp($page = 0)
    {
        $text[] = "Menu -> " . $this->i18n('vless') . ' -> ' . $this->i18n('routes') . ' -> package list';

        [$data] = $this->listPac('packagelist', $page, 'xtlsapp');
        $p      = $this->getPacConf();
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/routes",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function xtlsprocess($page = 0)
    {
        $text[] = "Menu -> " . $this->i18n('vless') . ' -> ' . $this->i18n('routes') . ' -> process list';

        [$data] = $this->listPac('processlist', $page, 'xtlsprocess');
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/routes",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function xtlssubnet($page = 0)
    {
        $text[] = "Menu -> " . $this->i18n('vless') . ' -> ' . $this->i18n('routes') . ' -> subnet';

        [$data] = $this->listPac('subnetlist', $page, 'xtlssubnet');
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/routes",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function xtlsrulesset($page = 0)
    {
        $text[] = "Menu -> " . $this->i18n('vless') . ' -> ' . $this->i18n('routes') . ' -> rulesset list';

        [$data, $tmp] = $this->listPac('rulessetlist', $page, 'xtlsrulesset', 1);
        $text = array_merge($text, $tmp ?: []);
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/routes",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function switchMonthlyStats()
    {
        $c = $this->getPacConf();
        $c['reset_monthly'] = $c['reset_monthly'] ? 0 : 1;
        $this->setPacConf($c);
        $this->singbox();
    }

public function linkVless($i, $s = false)
    {
        $c      = $this->getSingbox();
        $pac    = $this->getPacConf();
        $domain = $this->getDomain($pac['transport'] != 'Reality');
        $scheme = empty($this->nginxGetTypeCert()) ? 'http' : 'https';
        $hash   = $this->getHashBot();
        $si     = "$scheme://{$domain}/pac$hash/" . base64_encode(serialize([
            'h' => $hash,
            't' => 'si',
            's' => $c['inbounds'][0]['settings']['clients'][$i]['id'],
        ]));
        $v2     = "$scheme://{$domain}/pac$hash/" . base64_encode(serialize([
            'h' => $hash,
            't' => 's',
            's' => $c['inbounds'][0]['settings']['clients'][$i]['id'],
        ]));

        switch ($s) {
            case 1:
                return "v2rayng://install-config?url=$v2#{$c['inbounds'][0]['settings']['clients'][$i]['id']}";
            case 2:
                return "sing-box://import-remote-profile/?url={$si}#{$c['inbounds'][0]['settings']['clients'][$i]['email']}";

            default:
                switch ($pac['transport']) {
                    case 'Reality':
                        $link = "vless://{$c['inbounds'][0]['settings']['clients'][$i]['id']}@$domain:443"
                                    . "?security=reality"
                                    . "&sni={$c['inbounds'][0]['streamSettings']['realitySettings']['serverNames'][0]}"
                                    . "&fp=chrome&pbk={$pac['reality']['publicKey']}"
                                    . "&sid={$c['inbounds'][0]['streamSettings']['realitySettings']['shortIds'][0]}"
                                    . "&type=tcp"
                                    . "&flow=xtls-rprx-vision"
                                    . "#{$c['inbounds'][0]['settings']['clients'][$i]['email']}";
                        break;

                    default:
                        $link =  "vless://{$c['inbounds'][0]['settings']['clients'][$i]['id']}@$domain:443"
                                    . "?flow="
                                    . "&path=%2Fws$hash"
                                    . "&security=tls"
                                    . "&sni=$domain"
                                    . "&fp=chrome"
                                    . "&type=ws"
                                    . "#{$c['inbounds'][0]['settings']['clients'][$i]['email']}";
                        break;
                }
                return $link;

        }
    }

public function delxr($i)
    {
        $r  = $this->getSingbox();
        $st = $this->getSingboxStats();
        foreach ($r['inbounds'][0]['settings']['clients'] as $k => $v) {
            if ($i == $k) {
                unset($r['inbounds'][0]['settings']['clients'][$k]);
                unset($st['users'][$k]);
                $this->setSingboxStats($st);
                $this->restartSingbox($r);
                $this->adguardSingboxClients();
                break;
            }
        }
        $this->singbox();
    }

public function addxrus($users)
    {
        $c     = $this->getSingbox();
        $p     = $this->getPacConf();
        $users = array_map(fn ($e) => trim($e), explode(',', $users));
        $users = array_map(fn ($e) => explode(':', $e), $users);
        foreach ($c['inbounds'][0]['settings']['clients'] as $k => $v) {
            $uuids[]  = $v['id'];
            $emails[] = $v['email'];
        }
        foreach ($users as $user) {
            $description = $user[1] ?? '';
            $uuid        = $user[2] ?? '';
            $password    = $user[3] ?? '';
            $uuid        = $uuid ?: trim($this->ssh('sing-box generate uuid', 'sbx'));
            $password    = $password ?: trim($this->ssh('openssl rand -base64 16', 'sbx'));
            if (in_array($uuid, $uuids ?: []) || in_array($user[0], $emails ?: [])) {
                $this->send($this->input['chat'], "user {$user[0]} already exists");
                return $this->singbox();
            }
            $client = [
                'id'       => $uuid,
                'email'    => $user[0],
                'password' => $password,
            ];
            if ($description !== '') {
                $client['description'] = $description;
            }
            if ($p['transport'] == 'Reality') {
                $client['flow'] = 'xtls-rprx-vision';
            }
            $c['inbounds'][0]['settings']['clients'][] = $client;
        }
        $this->restartSingbox($c);
        $this->adguardSingboxClients();
        if (count($users) == 1) {
            $this->userXr(count($c['inbounds'][0]['settings']['clients']) - 1);
        } else {
            $this->singbox();
        }
    }

public function setTimerXr($time, $i)
    {
        $c = $this->getSingbox();
        if (empty($time)) {
            unset($c['inbounds'][0]['settings']['clients'][$i]['time']);
        } else {
            $time = strtotime($time);
            if ($time === false) {
                $this->send($this->input['chat'], 'wrong format');
                return;
            }
            $c['inbounds'][0]['settings']['clients'][$i]['time'] = $time;
        }
        $this->restartSingbox($c, 1);
        if (!empty($c['inbounds'][0]['settings']['clients'][$i]['off'])) {
            $this->switchXr($i, 0, 1);
        } else {
            $this->userXr($i);
        }
    }

public function switchXr($i, $nm = 0, $time = false)
    {
        $c = $this->getSingbox();
        if (empty($time)) {
            unset($c['inbounds'][0]['settings']['clients'][$i]['time']);
        }
        if (empty($c['inbounds'][0]['settings']['clients'][$i]['off'])) {
            $c['inbounds'][0]['settings']['clients'][$i]['off'] = $c['inbounds'][0]['settings']['clients'][$i]['id'];
            $c['inbounds'][0]['settings']['clients'][$i]['id']  = trim($this->ssh('sing-box generate uuid', 'sbx'));
        } else {
            $c['inbounds'][0]['settings']['clients'][$i]['id'] = $c['inbounds'][0]['settings']['clients'][$i]['off'];
            unset($c['inbounds'][0]['settings']['clients'][$i]['off']);
        }
        $this->restartSingbox($c);
        if (empty($nm)) {
            $this->userXr($i);
        }
    }

public function renXrUs($name, $i)
    {
        $c = $this->getSingbox();
        $c['inbounds'][0]['settings']['clients'][$i]['email'] = $name;
        $this->restartSingbox($c);
        $this->adguardSingboxClients();
        $this->userXr($i);
    }

public function getSingboxStats()
    {
        return json_decode(file_get_contents('/config/singbox.stats'), true) ?: [];
    }

public function setSingboxStats($x)
    {
        file_put_contents('/config/singbox.stats', json_encode($x));
    }

public function resetXrUser($i)
    {
        $c = $this->getSingboxStats();
        unset($c['users'][$i]);
        $this->restartSingbox($this->getSingbox());
        $this->userXr($i);
    }

public function resetXrStats($nomenu = false)
    {
        $this->restartSingbox($this->getSingbox());
        $this->setSingboxStats([]);
        if (empty($nomenu)) {
            $this->singbox();
        }
    }

public function listXr($i)
    {
        $c = $this->getPacConf();
        $c['xtlslist'] = $i;
        $this->setPacConf($c);
        $this->singbox();
    }

public function templateAdd($type)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} send the template file:",
            $this->input['message_id'],
            reply: 'send the template file:',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'addTemplate',
            'args'           => [$type],
        ];
    }

public function templateCopy($type)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} send the template name",
            $this->input['message_id'],
            reply: 'send the template name',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'copyTemplate',
            'args'           => [$type],
        ];
    }

public function addTemplate($n, $type)
    {
        if (empty($this->input['caption'])) {
            $this->send($this->input['chat'], 'empty name');
            return;
        }
        $r    = $this->request('getFile', ['file_id' => $this->input['file_id']]);
        $json = json_decode(file_get_contents($this->file . $r['result']['file_path']), true);
        if ($json === false) {
            $this->send($this->input['chat'], 'wrong format');
            return;
        }
        $pac = $this->getPacConf();
        $pac["{$type}templates"][$this->input['caption']] = $json;
        $this->setPacConf($pac);
        $this->templates($type);
    }

public function saveTemplate($name, $type, $json)
    {
        if (json_decode($json, true) === false) {
            return [
                'status'  => false,
                'message' => 'wrong format',
            ];
        }
        $pac = $this->getPacConf();
        switch ($name) {
            case 'origin':
                file_put_contents("/config/$type.json", json_encode(json_decode($json, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                break;

            default:
                $pac["{$type}templates"][$name] = json_decode($json, true);
                break;
        }
        $this->setPacConf($pac);
        return [
            'status' => true,
        ];
    }

public function delTemplate($type, $name)
    {
        $pac = $this->getPacConf();
        unset($pac["{$type}templates"][base64_decode($name)]);
        $this->setPacConf($pac);
        $this->templates($type);
    }

public function copyTemplate($name, $type)
    {
        $pac  = $this->getPacConf();
        $pac["{$type}templates"][$name] = json_decode(file_get_contents("/config/$type.json"), true);
        $this->setPacConf($pac);
        $this->templates($type);
    }

public function downloadOrigin($type)
    {
        $f = new \CURLFile("/config/$type.json", 'application/json', 'origin.json');
        $this->sendFile($this->input['chat'], $f);
    }

public function downloadTemplate($type, $name)
    {
        $pac = $this->getPacConf();
        $f = new \CURLStringFile(json_encode($pac["{$type}templates"][base64_decode($name)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), base64_decode($name) . '.json', 'application/json');
        $this->sendFile($this->input['chat'], $f);
    }

public function defaultTemplate($type, $name)
    {
        $pac = $this->getPacConf();
        if (!empty($name)) {
            $pac["default{$type}template"] = $name;
        } else {
            unset($pac["default{$type}template"]);
        }
        $this->setPacConf($pac);
        $this->templates($type);
    }

public function templates($type)
    {
        $pac    = $this->getPacConf();
        $domain = $this->getDomain();
        $hash   = $this->getHashBot();
        $text[] = "Menu -> " . $this->i18n('vless') . " -> " . $this->i18n($type) . " templates";
        $text[] = <<<TEXT
            <code>~outbound~</code>
            <code>~pac~</code>
            <code>~package~</code>
            <code>~process~</code>
            <code>~subnet~</code>
            <code>~block~</code>
            <code>~warp~</code>
            <code>~dns~</code>
            <code>~dnspath~</code>
            <code>~uid~</code>
            <code>~password~</code>
            <code>~naive_domain~</code>
            <code>~anytls_domain~</code>
            <code>~domain~</code>
            <code>~directdomain~</code>
            <code>~cdndomain~</code>
            <code>~short_id~</code>
            <code>~email~</code>
            <code>~public_key~</code>
            <code>~server_name~</code>
            <code>~ip~</code>
            TEXT;
        $templates = $pac["{$type}templates"];

        $data[] = [
            [
                'text'          => $this->i18n('add'),
                'callback_data' => "/templateAdd $type",
            ],
        ];
        $data[] = [
            [
                'text'          => "origin",
                'web_app' => ['url' => "https://$domain/pac$hash?t=te&ty=$type"],
            ],
            [
                'text'          => $this->i18n('download'),
                'callback_data' => "/downloadOrigin $type",
            ],
            [
                'text'          => $this->i18n('copy'),
                'callback_data' => "/templateCopy $type",
            ],
            [
                'text'          => $this->i18n($pac["default{$type}template"] && !empty($pac["{$type}templates"][base64_decode($pac["default{$type}template"])]) ? 'off' : 'on'),
                'callback_data' => "/defaultTemplate $type",
            ],
        ];
        foreach ($templates as $k => $v) {
            $data[] = [
                [
                    'text'          => $k,
                    'web_app' => ['url' => "https://$domain/pac$hash?t=te&ty=$type&te=" . urlencode($k)],
                ],
                [
                    'text'          => $this->i18n('download'),
                    'callback_data' => "/downloadTemplate $type " . base64_encode($k),
                ],
                [
                    'text'          => $this->i18n('delete'),
                    'callback_data' => "/delTemplate $type " . base64_encode($k),
                ],
                [
                    'text'          => $this->i18n($pac["default{$type}template"] == base64_encode($k) ? 'on' : 'off'),
                    'callback_data' => "/defaultTemplate $type " . base64_encode($k),
                ],
            ];
        }

        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/templatesMenu",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function mainOutbound()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} send name",
            $this->input['message_id'],
            reply: 'send name',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'setMainOutbound',
            'args'           => [],
        ];
    }

public function setMainOutbound($text)
    {
        $pac = $this->getPacConf();
        if (!empty($text)) {
            $pac['outbound'] = $text;
        } else {
            unset($pac['outbound']);
        }
        $this->setPacConf($pac);
        $this->singbox();
    }

public function singbox($page = 0)
    {
        $c      = $this->getSingbox();
        $p      = $this->getPacConf();
        $text[] = "Menu -> " . $this->i18n('vless');
        if (!empty($c['inbounds'][0]['streamSettings']['realitySettings']['serverNames'][0])) {
            $text[] = "fake domain: <code>{$c['inbounds'][0]['streamSettings']['realitySettings']['serverNames'][0]}</code>";
        }
        $text[] = 'transport: ' . ($p['transport'] ?: 'Websocket');
        $data[] = [
            [
                'text'          => $this->i18n('main outbound name: ') . ($p['outbound'] ?? 'proxy'),
                'callback_data' => '/mainOutbound',
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('routes'),
                'callback_data' => "/routes",
            ],
            [
                'text'          => $this->i18n('templates'),
                'callback_data' => "/templatesMenu",
            ],
        ];
        $on = $off = 0;
        foreach ($c['inbounds'][0]['settings']['clients'] as $k => $v) {
            if (!empty($v['off'])) {
                $off++;
            } else {
                $on++;
            }
        }
        $type    = !empty($this->getPacConf()['xtlslist']);
        $clients = array_filter($c['inbounds'][0]['settings']['clients'], fn($e) => !$type ? empty($e['off']) : !empty($e['off']));
        uasort($clients, fn($a, $b) => ($a['time'] ?: PHP_INT_MAX) <=> ($b['time'] ?: PHP_INT_MAX));

        $all     = (int) ceil(count($clients) / $this->limit);
        $page    = min($page, $all - 1);
        $page    = $page == -2 ? $all - 1 : $page;
        $clients = $page != -1 ? array_slice($clients, $page * $this->limit, $this->limit, true) : $clients;
        foreach ($clients as $k => $v) {
            $time     = !empty($v['time']) ? $this->getTime($v['time']) : '';
            $data[]   = [
                [
                    'text'          => "{$v['email']}" . (!empty($v['description']) ? " — {$v['description']}" : '') . ($time ? ": $time" : ''),
                    'callback_data' => "/userXr $k",
                ],
            ];
        }
        if ($page != -1 && $all > 1) {
            $data[] = [
                [
                    'text'          => '<<',
                    'callback_data' => "/singbox " . ($page - 1 >= 0 ? $page - 1 : $all - 1),
                ],
                [
                    'text'          => $page + 1,
                    'callback_data' => "/singbox $page",
                ],
                [
                    'text'          => '>>',
                    'callback_data' => "/singbox " . ($page < $all - 1 ? $page + 1 : 0),
                ],
            ];
        }
        $data[] = [
            [
                'text'          => $this->i18n('add'),
                'callback_data' => "/addXrUser",
            ],
            [
                'text'          => $this->i18n('on') . " $on " . (!$type ? "✅" : ''),
                'callback_data' => "/listXr 0",
            ],
            [
                'text'          => $this->i18n('off') . " $off " . ($type ? "✅" : ''),
                'callback_data' => "/listXr 1",
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

public function templatesMenu()
    {
        $text[] = "Menu -> " . $this->i18n('vless') . ' -> ' . $this->i18n('templates');

        $data = [
            [[
                'text'          => 'Xray',
                'callback_data' => "/templates v2ray",
            ]],
            [[
                'text'          => 'Sing-box',
                'callback_data' => "/templates sing",
            ]],
            [[
                'text'          => 'Mihomo',
                'callback_data' => "/templates clash",
            ]],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/singbox",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function routes()
    {
        $text[] = "Menu -> " . $this->i18n('vless') . ' -> routes';

        $data = [
            [[
                'text'          => $this->i18n('block'),
                'callback_data' => "/xtlsblock",
            ]],
            [[
                'text'          => $this->i18n('warp'),
                'callback_data' => "/xtlswarp",
            ]],
            [[
                'text'          => 'domains',
                'callback_data' => "/xtlsproxy",
            ]],
            [[
                'text'          => "subnet",
                'callback_data' => "/xtlssubnet",
            ]],
            [[
                'text'          => 'process',
                'callback_data' => "/xtlsprocess",
            ]],
            [[
                'text'          => 'package',
                'callback_data' => "/xtlsapp",
            ]],
            [[
                'text'          => $this->i18n('rulesset'),
                'callback_data' => "/xtlsrulesset",
            ]],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/singbox",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function tun()
    {
        $text[] = "Menu -> " . $this->i18n('vless') . ' -> ' . $this->i18n('tun lists');

        $c = $this->getPacConf();

        $data = [
            [
                [
                    'text'          => $this->i18n('package'),
                    'callback_data' => "/tunpackage",
                ],
                [
                    'text'          => 'mode: ' . $this->i18n(!empty($c['tunpackagemode']) ? 'exclude' : 'include'),
                    'callback_data' => "/tunpackagemode",
                ],
            ],
            [
                [
                    'text'          => $this->i18n('process'),
                    'callback_data' => "/tunprocess",
                ],
                [
                    'text'          => 'mode: ' . $this->i18n(!empty($c['tunprocessmode']) ? 'exclude' : 'include'),
                    'callback_data' => "/tunprocessmode",
                ],
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/singbox",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function choiceTemplate($arg)
    {
        $arg = explode('_', $arg);
        $c   = $this->getSingbox();
        if (!empty($arg[2])) {
            $c['inbounds'][0]['settings']['clients'][$arg[1]]["{$arg[0]}template"] = $arg[2];
        } else {
            unset($c['inbounds'][0]['settings']['clients'][$arg[1]]["{$arg[0]}template"]);
        }
        $this->restartSingbox($c, 1);
        $this->userXr($arg[1]);
    }

public function templateUser($type, $i)
    {
        $c         = $this->getSingbox();
        $pac       = $this->getPacConf();
        $text[]    = "Menu -> " . $this->i18n('vless') . " -> {$c['inbounds'][0]['settings']['clients'][$i]['email']}\n";
        $templates = $pac["{$type}templates"];
        $data[]    = [
            [
                'text'          => 'default',
                'callback_data' => "/choiceTemplate {$type}_$i",
            ],
        ];
        $data[] = [
            [
                'text'          => 'origin',
                'callback_data' => "/choiceTemplate {$type}_{$i}_" . base64_encode('origin'),
            ],
        ];
        foreach ($templates as $k => $v) {
            $data[] = [
                [
                    'text'          => $k,
                    'callback_data' => "/choiceTemplate {$type}_{$i}_" . base64_encode($k),
                ],
            ];
        }
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/userXr $i",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function userXr($i)
    {
        $xray   = $this->getSingbox();
        $c      = $xray['inbounds'][0]['settings']['clients'][$i];
        $pac    = $this->getPacConf();
        $domain = $this->getDomain($pac['transport'] != 'Reality');
        $scheme = empty($this->nginxGetTypeCert()) ? 'http' : 'https';
        $hash   = $this->getHashBot();

        $text[] = "Menu -> " . $this->i18n('vless') . " -> {$c['email']}\n";
        if (file_exists(dirname(__DIR__) . '/subscription.php')) {
            $text[] = "<a href='$scheme://{$domain}/pac$hash/sub?id={$c['id']}'>subscription</a>";
        }
        $text[] = "<pre><code>{$this->linkVless($i)}</code></pre>\n";

        $text[] = "<a href='$scheme://{$domain}/pac$hash?t=s&r=v&s={$c['id']}#{$c['email']}'>import://v2rayng</a>";
        $text[] = "<a href='$scheme://{$domain}/pac$hash?t=si&r=si&s={$c['id']}#{$c['email']}'>import://sing-box</a>";
        $text[] = "<a href='$scheme://{$domain}/pac$hash?t=s&r=st&s={$c['id']}#{$c['email']}'>import://streisand</a>";
        $text[] = "<a href='$scheme://{$domain}/pac$hash?t=si&r=h&s={$c['id']}#{$c['email']}'>import://hiddify</a>";
        $text[] = "<a href='$scheme://{$domain}/pac$hash?t=si&r=k&s={$c['id']}#{$c['email']}'>import://karing</a>";
        $text[] = "<a href='$scheme://{$domain}/pac$hash?t=cl&r=c&s={$c['id']}#{$c['email']}'>import://mihomo</a>";
        $text[] = "<a href='$scheme://{$domain}/pac$hash?t=cl&r=rh&s={$c['id']}#{$c['email']}'>import://rabbit-hole</a>";

        $si = "$scheme://{$domain}/pac$hash/" . base64_encode(serialize([
            'h' => $hash,
            't' => 'si',
            's' => $c['id'],
        ]));
        $xr = "$scheme://{$domain}/pac$hash/" . base64_encode(serialize([
            'h' => $hash,
            't' => 's',
            's' => $c['id'],
        ]));
        $cl = "$scheme://{$domain}/pac$hash/" . base64_encode(serialize([
            'h' => $hash,
            't' => 'cl',
            's' => $c['id'],
        ]));

        $text[] = "\nv2ray config: <pre><code>$xr</code></pre>";
        $text[] = "sing-box config: <pre><code>$si</code></pre>";
        $text[] = "mihomo config: <pre><code>$cl</code></pre>";

        $st       = $this->getSingboxStats();
        $download = $this->getBytes($st['users'][$i]['global']['download'] + $st['users'][$i]['session']['download']);
        $upload   = $this->getBytes($st['users'][$i]['global']['upload'] + $st['users'][$i]['session']['upload']);
        $data[]   = [
            [
                'text'          => $this->i18n('reset stats') . ": ↓$download  ↑$upload",
                'callback_data' => "/resetXrUser $i",
            ],
        ];
        $data[] = [
            [
                'text'    => $this->i18n('v2ray'),
                'web_app' => ['url' => "https://{$domain}/pac$hash?t=s&s={$c['id']}"]
            ],
            [
                'text'    => $this->i18n('singbox'),
                'web_app' => ['url' => "https://{$domain}/pac$hash?t=si&s={$c['id']}"]
            ],
            [
                'text'    => $this->i18n('mihomo'),
                'web_app' => ['url' => "https://{$domain}/pac$hash?t=cl&s={$c['id']}"]
            ],
        ];
        $data[] = [
            [
                'text'    => $this->i18n('v2ray ⬇️'),
                'callback_data' => "/dw {$i} s",
            ],
            [
                'text'    => $this->i18n('singbox ⬇️'),
                'callback_data' => "/dw {$i} si",
            ],
            [
                'text'    => $this->i18n('mihomo ⬇️'),
                'callback_data' => "/dw {$i} cl",
            ],
        ];
        $data[] = [
            [
                'text'          => $c['time'] ? "timer: " . $this->getTime($c['time']) : $this->i18n('timer'),
                'callback_data' => "/timerXr $i",
            ],
            [
                'text'          => $this->i18n($c['off'] ? 'off' : 'on'),
                'callback_data' => "/switchXr $i",
            ],
        ];
        $singtemplate  = $c['singtemplate'] ? base64_decode($c['singtemplate']) : 'default(' . ($pac['defaultsingtemplate'] && !empty($pac['singtemplates'][base64_decode($pac['defaultsingtemplate'])]) ? base64_decode($pac['defaultsingtemplate']) : 'origin') . ')';
        $v2raytemplate = $c['v2raytemplate'] ? base64_decode($c['v2raytemplate']) : 'default(' . ($pac['defaultv2raytemplate'] && !empty($pac['v2raytemplates'][base64_decode($pac['defaultv2raytemplate'])]) ? base64_decode($pac['defaultv2raytemplate']) : 'origin') . ')';
        $clashtemplate = $c['clashtemplate'] ? base64_decode($c['clashtemplate']) : 'default(' . ($pac['defaultclashtemplate'] && !empty($pac['clashtemplates'][base64_decode($pac['defaultclashtemplate'])]) ? base64_decode($pac['defaultclashtemplate']) : 'origin') . ')';
        $data[]        = [
            [
                'text'          => $this->i18n('v2ray') . ": $v2raytemplate",
                'callback_data' => "/templateUser v2ray $i",
            ],
            [
                'text'          => $this->i18n('singbox') . ": $singtemplate",
                'callback_data' => "/templateUser sing $i",
            ],
            [
                'text'          => $this->i18n('mihomo') . ": $clashtemplate",
                'callback_data' => "/templateUser clash $i",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('qr short'),
                'callback_data' => "/qrVless $i",
            ],
            [
                'text'          => $this->i18n('qr v2ray'),
                'callback_data' => "/qrVless {$i}_1",
            ],
            [
                'text'          => $this->i18n('qr singbox'),
                'callback_data' => "/qrVless {$i}_2",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('rename'),
                'callback_data' => "/renameXrUser $i",
            ],
            [
                'text'          => $this->i18n('delete'),
                'callback_data' => "/delxr $i",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/singbox",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function sub()
    {
        $xr     = $this->getSingbox();
        $pac    = $this->getPacConf();
        $st     = $this->getSingboxStats();
        $domain = $_GET['cdn'] ?: ($_SERVER['SERVER_NAME'] ?: $this->getDomain($pac['transport'] != 'Reality'));
        $scheme = empty($this->nginxGetTypeCert()) ? 'http' : 'https';
        $hash   = $this->getHashBot();
        $flag   = true;
        foreach ($xr['inbounds'][0]['settings']['clients'] as $k => $v) {
            if ($v['id'] == $_GET['id']) {
                if (empty($v['off'])) {
                    $flag = false;
                }
                $uid    = $v['id'];
                $email  = $v['email'];
                $expire = $v['time'];
                break;
            }
        }
        if (!$flag) {
            exit;
        }
        $suburl   = "<a href='$scheme://{$domain}/pac$hash/sub?id={$uid}'>subscription</a>";
        $download = $this->getBytes($st['users'][$k]['global']['download'] + $st['users'][$k]['session']['download']);
        $upload   = $this->getBytes($st['users'][$k]['global']['upload'] + $st['users'][$k]['session']['upload']);
        $singbox  = "$scheme://{$domain}/pac$hash/" . base64_encode(serialize([
            'h' => $hash,
            't' => 'si',
            's' => $uid,
        ]));
        $xray = "$scheme://{$domain}/pac$hash/" . base64_encode(serialize([
            'h' => $hash,
            't' => 's',
            's' => $uid,
        ]));
        $clash = "$scheme://{$domain}/pac$hash/" . base64_encode(serialize([
            'h' => $hash,
            't' => 'cl',
            's' => $uid,
        ]));
        $vless   = $this->linkVless($k);
        $_GET['s'] = $uid;
        foreach ([
          'xray'    => 's',
          'singbox' => 'si',
          'clash'   => 'cl'
        ] as $k     => $v) {
            $_GET['t'] = $v;
            $configs[$k] = $this->subscription(1);
        }
        require dirname(__DIR__) . '/subscription.php';
    }

public function subscription($return = false)
    {
        switch ($_GET['t']) {
            case 's':
                $type = 'v2ray';
                break;
            case 'si':
                $type = 'sing';
                break;
            case 'cl':
                $type = 'clash';
                break;
        }
        $pac    = $this->getPacConf();
        $domain = $_GET['cdn'] ?: ($_SERVER['SERVER_NAME'] ?: $this->getDomain($pac['transport'] != 'Reality'));
        $xr     = $this->getSingbox();
        $scheme = empty($this->nginxGetTypeCert()) ? 'http' : 'https';
        $hash   = $this->getHashBot();

        $flag = true;
        foreach ($xr['inbounds'][0]['settings']['clients'] as $k => $v) {
            if ($v['id'] == $_GET['s']) {
                if (empty($v['off'])) {
                    $flag = false;
                }
                $template = base64_decode($v["{$type}template"]);
                $uid      = $v['id'];
                $email    = $v['email'];
                $password = $v['password'] ?? '';
                break;
            }
        }
        if ($flag) {
            header('500', true, 500);
            exit;
        }

        if (!empty($_GET['r'])) {
            $si = "$scheme://{$domain}/pac$hash/" . base64_encode(serialize([
                'h' => $hash,
                't' => 'si',
                's' => $uid,
            ]));
            $v2 = "$scheme://{$domain}/pac$hash/" . base64_encode(serialize([
                'h' => $hash,
                't' => 's',
                's' => $uid,
            ]));
            $cl = "$scheme://{$domain}/pac$hash/" . base64_encode(serialize([
                'h' => $hash,
                't' => 'cl',
                's' => $uid,
            ]));
            switch ($_GET['r']) {
                case 'si':
                    header("Location: sing-box://import-remote-profile/?url=$si");
                    exit;
                case 'st':
                    header("Location: streisand://import/$v2");
                    exit;
                case 'v':
                    header("Location: v2rayng://install-config?url=$v2");
                    exit;
                case 'k':
                    header("Location: karing://install-config?url=$si");
                    exit;
                case 'h':
                    header("Location: hiddify://install-config/?url=$si");
                    exit;
                case 'c':
                    header("Location: clash://install-config/?url=$cl&overwrite=no&name=$email");
                    exit;
                case 'rh':
                    header("Location: rabbithole://add/$cl");
                    exit;
            }
        }
        switch (true) {
            case !empty($template) && $template == 'origin':
            case empty($template) && empty($pac["default{$type}template"]):
            case empty($template) && empty($pac["{$type}templates"][base64_decode($pac["default{$type}template"])]):
            case !empty($template) && empty($pac["{$type}templates"][$template]):
                $c = json_decode(file_get_contents("/config/{$type}.json"), true);
                break;
            case !empty($template):
                $c = $pac["{$type}templates"][$template];
                break;

            default:
                $c = $pac["{$type}templates"][base64_decode($pac["default{$type}template"])];
                break;
        }

        $outbound = $pac['outbound'] ?: 'proxy';
        $c = json_decode($this->replaceTags(json_encode($c), [
            '~outbound~' => $outbound,
        ]), true);
        foreach ($c['outbounds'] as $k => $v) {
            if ($v['tag'] == $outbound) {
                $index = $k;
                break;
            }
        }
        if (!isset($index)) {
            foreach ($c['proxies'] as $k => $v) {
                if ($v['name'] == $outbound) {
                    $index = $k;
                    break;
                }
            }
        }

        switch ($_GET['t']) {
            case 's':
                $c['outbounds'][$index]['settings']['vnext'][0]['address']  = '~domain~';
                $c['outbounds'][$index]['settings']['vnext'][0]['users'][0] = [
                    'id'         => '~uid~',
                    'encryption' => 'none',
                ];
                $fingerprint = $c['outbounds'][$index]['streamSettings']['realitySettings']['fingerprint'] ?? $c['outbounds'][$index]['streamSettings']['tlsSettings']['fingerprint'] ?? 'chrome';
                switch ($pac['transport']) {
                    case 'Reality':
                        $c['outbounds'][$index]['settings']['vnext'][0]['users'][0]["flow"] = "xtls-rprx-vision";
                        $c['outbounds'][$index]['streamSettings']                           = [
                            "network"         => "tcp",
                            "security"        => "reality",
                            "realitySettings" => [
                                "serverName"  => '~server_name~',
                                "fingerprint" => $fingerprint,
                                "publicKey"   => '~public_key~',
                                "shortId"     => '~short_id~',
                            ]
                        ];
                        $c['outbounds'][$index]['mux'] = [
                            "enabled"     => false,
                            "concurrency" => -1
                        ];
                        break;
                    case 'xhttp':
                        $c['outbounds'][$index]['streamSettings'] = [
                            "network"  => "xhttp",
                            "security" => "tls",

                            "xhttpSettings" => [
                                "host" => "~domain~",
                                "mode" => "packet-up",
                                "path" => "/ws$hash",

                                "extra" => [
                                    "scMaxEachPostBytes"    => 1000000,
                                    "scMinPostsIntervalMs"  => 30,
                                    "scStreamUpServerSecs"  => "20-80",
                                    "xmux" => [
                                        "cMaxReuseTimes"    => 0,
                                        "hKeepAlivePeriod"  => 0,
                                        "hMaxRequestTimes"  => "600-900",
                                        "hMaxReusableSecs"  => "1800-3000",
                                        "maxConcurrency"    => "16-32",
                                        "maxConnections"    => 0,
                                    ],
                                    "xPaddingBytes" => "100-1000",
                                    "noGRPCHeader"  => false
                                ]
                            ],

                            "tlsSettings" => [
                                "allowInsecure" => false,
                                "alpn"          => ["h2", "http/1.1"],
                                "fingerprint"   => "chrome",
                                "serverName"    => "~domain~",
                                "show"          => false
                            ]
                        ];
                        unset($c['outbounds'][$index]['mux']);
                        break;

                    default:
                        $c['outbounds'][$index]['streamSettings'] = [
                            "network"    => "ws",
                            "security"   => "tls",
                            "wsSettings" => [
                                "path" => "/ws$hash?ed=2560"
                            ],
                            "tlsSettings" => [
                                "allowInsecure" => false,
                                "serverName"    => '~domain~',
                                "fingerprint"   => $fingerprint
                            ]
                        ];
                        unset($c['outbounds'][$index]['mux']);
                        break;
                }

                break;
            case 'si':
                $c['outbounds'][$index]['uuid'] = '~uid~';
                switch ($pac['transport']) {
                    case 'Reality':
                        unset($c['outbounds'][$index]["transport"]);
                        $c['outbounds'][$index]['flow']                         = 'xtls-rprx-vision';
                        $c['outbounds'][$index]['tls']['reality']['public_key'] = '~public_key~';
                        $c['outbounds'][$index]['tls']['server_name']           = '~server_name~';
                        $c['outbounds'][$index]['tls']['reality']['short_id']   = '~short_id~';
                        break;
                    case 'xhttp':
                        unset($c['outbounds'][$index]['flow']);
                        unset($c['outbounds'][$index]['tls']['reality']);

                        $c['outbounds'][$index]["transport"] = [
                            "type" => "xhttp",
                            "host" => "~domain~",
                            "mode" => "packet-up",
                            "path" => "/ws$hash",  // ← путь WS + hash
                            "xmux" => [
                                "max_concurrency"   => "16-32",
                                "max_connections"   => "0-1",
                                "c_max_reuse_times" => "0-1",
                                "h_max_request_times" => "600-900",
                                "h_max_reusable_secs" => "1800-3000",
                                "h_keep_alive_period" => 60
                            ]
                        ];

                        $c['outbounds'][$index]['tls'] = [
                            "enabled"     => true,
                            "insecure"    => false,
                            "server_name" => "~domain~",
                            "alpn"        => ["h2"]
                        ];
                        break;

                    default:
                        unset($c['outbounds'][$index]['tls']['reality']);
                        unset($c['outbounds'][$index]['flow']);
                        $c['outbounds'][$index]["transport"] = [
                            "type" => "ws",
                            "path" => "/ws$hash"
                        ];
                        $c['outbounds'][$index]['tls']['server_name'] = '~domain~';
                        break;
                }
                break;
            case 'cl':
                $c['proxies'][$index]['server'] = '~domain~';
                $c['proxies'][$index]['uuid']   = '~uid~';
                switch (true) {
                    case $pac['transport'] == 'Reality':
                        unset($c['proxies'][$index]["ws-opts"]);
                        unset($c['proxies'][$index]["skip-cert-verify"]);
                        $c['proxies'][$index]["network"]      = "tcp";
                        $c['proxies'][$index]['flow']         = 'xtls-rprx-vision';
                        $c['proxies'][$index]['servername']  = '~server_name~';
                        $c['proxies'][$index]['reality-opts'] = [
                            'public-key' => '~public_key~',
                            'short-id'   => '~short_id~',
                        ];
                        break;
                    case $pac['transport'] == 'xhttp':
                        unset($c['proxies'][$index]['ws-opts']);
                        unset($c['proxies'][$index]['flow']);
                        unset($c['proxies'][$index]['reality-opts']);

                        $c['proxies'][$index]['network']            = 'xhttp';
                        $c['proxies'][$index]['client-fingerprint'] = 'chrome';
                        $c['proxies'][$index]['tls']                = true;
                        $c['proxies'][$index]['alpn']               = ['h2'];
                        $c['proxies'][$index]['servername']         = '~domain~';
                        $c['proxies'][$index]['skip-cert-verify']   = false;

                        $c['proxies'][$index]['xhttp-opts'] = [
                            'host'                   => '~domain~',
                            'path'                   => "/ws$hash",
                            'mode'                   => 'packet-up',
                            'no-grpc-header'         => false,
                            'x-padding-bytes'        => '100-1000',
                            'sc-max-each-post-bytes' => 1000000,
                            'reuse-settings'         => [
                                'max-connections'   => '0',
                                'max-concurrency'   => '8-16',
                                'c-max-reuse-times' => '0',
                                'h-max-request-times' => '100-200',
                                'h-max-reusable-secs' => '1800-3000',
                            ],
                        ];
                        break;

                    default:
                        unset($c['proxies'][$index]['flow']);
                        unset($c['proxies'][$index]['reality-opts']);
                        $c['proxies'][$index]["network"]          = "ws";
                        $c['proxies'][$index]["ws-opts"]['path']  = "/ws$hash";
                        $c['proxies'][$index]["skip-cert-verify"] = false;
                        $c['proxies'][$index]['servername']       = '~domain~';
                        break;
                }

                $tunpackage = array_filter($pac['tunpackage'] ?? []);
                $tunprocess = array_filter($pac['tunprocess'] ?? []);
                if (!empty($tunpackage) || !empty($tunprocess)) {
                    if (!empty($tunpackage)) {
                        if (!empty($pac['tunpackagemode'])) {
                            $c['tun']['exclude-package'] = array_keys($tunpackage);
                        } else {
                            $c['tun']['include-package'] = array_keys($tunpackage);
                        }
                    }
                    if (!empty($tunprocess)) {
                        if (!empty($pac['tunprocessmode'])) {
                            $c['tun']['exclude-process'] = array_keys($tunprocess);
                        } else {
                            $c['tun']['include-process'] = array_keys($tunprocess);
                        }
                    }
                }
                break;
        }
        $c = json_decode($this->replaceTags(json_encode($c), [
            '"~pac~"'        => json_encode(array_keys(array_filter($pac['includelist'] ?: []))),
            '"~block~"'      => json_encode(array_keys(array_filter($pac['blocklist'] ?: []))),
            '"~warp~"'       => json_encode(array_keys(array_filter($pac['warplist'] ?: []))),
            '"~process~"'    => json_encode(array_keys(array_filter($pac['processlist'] ?: []))),
            '"~package~"'    => json_encode(array_keys(array_filter($pac['packagelist'] ?: []))),
            '"~subnet~"'     => json_encode(array_keys(array_filter($pac['subnetlist'] ?: []))),
            '~dns~'          => "https://$domain/dns-query$hash/$uid",
            '~dnspath~'      => "/dns-query$hash/$uid",
            '~uid~'          => $uid,
            '~password~'     => $password,
            '~domain~'       => $domain,
            '~naive_domain~'  => "{$pac['naiveSubdomain']}.{$pac['domain']}",
            '~anytls_domain~' => "{$pac['anytlsSubdomain']}.{$pac['domain']}",
            '~directdomain~' => $pac['domain'],
            '~cdndomain~'    => $pac['linkdomain'],
            '~short_id~'     => $xr['inbounds'][0]['streamSettings']['realitySettings']['shortIds'][0],
            '~email~'        => $email,
            '~public_key~'   => $pac['reality']['publicKey'],
            '~server_name~'  => $xr['inbounds'][0]['streamSettings']['realitySettings']['serverNames'][0],
            '~ip~'           => $this->ip,
            '~outbound~'     => $outbound,
        ]), true);

        switch ($_GET['t']) {
            case 's':
                if (!empty($c['routing']['rules'])) {
                    $ips = $domains = [];
                    foreach ($c['routing']['rules'] as $k => $v) {
                        if (array_key_exists('domain', $v) && !empty($v['domain'])) {
                            foreach ($v['domain'] as $j) {
                                if (!preg_match('~^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}(/\d{1,2})?$~', $j)) {
                                    $domains[$v['outboundTag']][] = $j;
                                } else {
                                    $ips[$v['outboundTag']][] = $j;
                                }
                            }
                        }
                        if (array_key_exists('ip', $v) && !empty($v['ip'])) {
                            foreach ($v['domain'] as $j) {
                                if (!preg_match('~^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}(/\d{1,2})?$~', $j)) {
                                    $domains[$v['outboundTag']][] = $j;
                                } else {
                                    $ips[$v['outboundTag']][] = $j;
                                }
                            }
                        }
                    }
                    $c['routing']['rules'] = [];

                    if (!empty($domains)) {
                        foreach ($domains as $k => $v) {
                            $c['routing']['rules'][] = [
                                "type"        => "field",
                                "outboundTag" => $k,
                                "domain"      => $v
                            ];
                        }
                    }
                    if (!empty($ips)) {
                        foreach ($ips as $k => $v) {
                            $c['routing']['rules'][] = [
                                "type"        => "field",
                                "outboundTag" => $k,
                                "ip"          => $v
                            ];
                        }
                    }
                }
                break;
            case 'si':
                $c['route'] = $this->addRuleSet($c['route']);
                $c['route'] = $this->createRuleSet($c['route'], $uid, $domain);
                if (!empty($c['route']['rules'])) {
                    foreach ($c['route']['rules'] as $k => $v) {
                        if (count($v) == 1 && array_key_exists('outbound', $v)) {
                            unset($c['route']['rules'][$k]);
                        }
                    }
                    $c['route']['rules'] = array_values($c['route']['rules']);
                }
                if (empty($c['route'])) {
                    unset($c['route']);
                }
                break;
            case 'cl':
                $c = $this->addClashRuleSet($c);
                if (!empty($c['rules'])) {
                    $c = $this->clashRules($c, $uid, $domain);
                    if (count($c['rules']) == 1) {
                        unset($c['rules']);
                    }
                }
                break;
        }
        if (!empty($return)) {
            if ($_GET['t'] == 'cl') {
                return yaml_emit($c);
            }
            return json_encode($c);
        }

        if ($_GET['t'] == 'cl') {
            header('Content-type: text/yaml');
            echo yaml_emit($c);
            return;
        }

        header('Content-type: application/json');
        echo json_encode($c);
    }

public function addClashRuleSet($c)
    {
        $p = $this->getPacConf();
        if (!empty($p['rulessetlist']) && $c['add-rule-providers']) {
            foreach ($p['rulessetlist'] as $k => $v) {
                if (!empty($v)) {
                    [$type, $behavior, $time, $url] = explode(':', $k, 4);
                    if (preg_match('~\.(mrs|yaml|yml)$~', $url, $m)) {
                        $c['rule-providers'][$url] = [
                            'type'     => 'http',
                            'url'      => $url,
                            'interval' => (int) $time,
                            'behavior' => $behavior,
                            'format'   => $m[1],
                        ];
                        switch ($type) {
                            case 'reject':
                            case 'REJECT':
                                array_unshift($c['rules'], [
                                    'RULE-SET', $url, strtoupper($type)
                                ]);
                                break;

                            default:
                                array_splice($c['rules'], count($c['rules']) - 1, 0, [[
                                    'RULE-SET', $url, strtoupper($type)
                                ]]);
                                break;
                        }
                    }
                }
            }
        }
        unset($c['add-rule-providers']);
        if (empty($c['rule-providers'])) {
            unset($c['rule-providers']);
        }
        return $c;
    }

public function clashRules($c, $uid, $domain)
    {
        $scheme = empty($this->nginxGetTypeCert()) ? 'http' : 'https';
        $hash   = $this->getHashBot();
        foreach ($c['rules'] as $v) {
            if (array_key_exists('list', $v)) {
                if ($v['type'] == 'RULE-SET') {
                    if (!empty($_GET['r']) && $v['name'] == $_GET['r']) {
                        header("Content-Disposition: attachment; filename={$v['name']}.yaml");
                        header('Content-Type: text/yaml');
                        switch ($v['name']) {
                            case 'process':
                            case 'package':
                                echo yaml_emit(['payload' => array_map(fn($e) => "PROCESS-NAME,$e", $v['list'])]);
                                break;

                            default:
                                echo yaml_emit(['payload' => array_map(function($e) {
                                    if (preg_match('~^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}(/\d{1,2})?$~', $e, $m)) {
                                        return "IP-CIDR,$e" . (empty($m[1]) ? '/32' : '');
                                    } else {
                                        return "DOMAIN-SUFFIX,$e";
                                    }
                                }, $v['list'])]);
                                break;

                        }
                        exit;
                    }
                    $c['rule-providers'][$v['name']] = [
                        'type'     => 'http',
                        'url'      => "$scheme://{$domain}/pac$hash/" . base64_encode(serialize([
                            'h' => $hash,
                            't' => 'cl',
                            's' => $uid,
                            'r' => $v['name'],
                        ])),
                        'interval' => $v['interval'],
                        'behavior' => $v['behavior'],
                        'format'   => 'yaml',
                    ];
                    $tmp[] = "{$v['type']}, {$v['name']}, {$v['action']}";
                } else {
                    if (!empty($v['list'])) {
                        foreach ($v['list'] as $j) {
                            $tmp[] = "{$v['type']}, $j, {$v['action']}";
                        }
                    }
                }
            } else {
                $tmp[] = implode(', ', $v);
            }
        }
        $c['rules'] = $tmp;
        return $c;
    }

public function replaceTags($subject, $tags)
    {
        return str_replace(array_keys($tags), array_values($tags), $subject);
    }

public function addRuleSet($route)
    {
        if (!empty($route['rules'])) {
            foreach ($route['rules'] as $k => $v) {
                if (!empty($v['addruleset'])) {
                    $t[$v['outbound'] ?: 'block'] = $k;
                }
            }
            $p = $this->getPacConf();
            if (!empty($p['rulessetlist'])) {
                foreach ($p['rulessetlist'] as $k => $v) {
                    if (!empty($v)) {
                        [$type, $time, $url] = explode(':', $k, 3);
                        if (preg_match('~\.srs$~', $url) && !empty($route['rules'][$t[$type]])) {
                            $route['rule_set'][] = [
                                "tag"             => $k,
                                "type"            => "remote",
                                "format"          => "binary",
                                "url"             => $url,
                                "update_interval" => $time
                            ];
                            $route['rules'][$t[$type]]['rule_set'][] = $k;
                        }
                    }
                }
            }
            foreach ($route['rules'] as $k => $v) {
                unset($route['rules'][$k]['addruleset']);
            }
        }
        return $route;
    }

public function cleanEmptyKeys(array $arr)
    {
        foreach ($arr as $k => $v) {
            if (empty($v)) {
                unset($arr[$k]);
            } elseif (is_array($v)) {
                $arr[$k] = $this->cleanEmptyKeys($v);
                if (empty($arr[$k])) {
                    unset($arr[$k]);
                }
            }
        }
        return $arr;
    }

public function createSrs(string $name, array $rules)
    {
        $rules = $this->cleanEmptyKeys($rules);
        header("Content-Disposition: attachment; filename=$name.srs");
        header('Content-Type: application/binary');
        $f = "/tmp/$name" . time() . rand(1, 100);
        foreach ($rules as $k => $v) {
            if (array_key_exists('domain_suffix', $v)) {
                foreach ($v['domain_suffix'] as $j) {
                    if (!preg_match('~^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}(/\d{1,2})?$~', $j, $m)) {
                        $domains[] = $j;
                    } else {
                        $ips[] = $j . (empty($m[1]) ? '/32' : '');
                    }
                }
                unset($rules[$k]['domain_suffix']);
                if (!empty($domains)) {
                    $rules[$k]['domain_suffix'] = $domains;
                }
                if (!empty($ips)) {
                    $rules[$k]['ip_cidr'] = $ips;
                }
            }
        }
        file_put_contents($f, json_encode([
            'version' => 1,
            'rules'   => $rules ?: [],
        ]));
        exec("sing-box rule-set compile $f");
        echo file_get_contents("$f.srs");
        unlink($f);
        unlink("$f.srs");
        exit;
    }

public function createRuleSet($route, $uid, $domain)
    {
        $scheme = empty($this->nginxGetTypeCert()) ? 'http' : 'https';
        $hash   = $this->getHashBot();

        foreach ($route['rules'] as $k => $v) {
            if (!empty($v['createruleset'])) {
                foreach ($v['createruleset'] as $r) {
                    if (!empty($_GET['r']) && $r['name'] == $_GET['r']) {
                        $this->createSrs($r['name'], $r['rules']);
                    }
                    $ruleset[] = [
                        "tag"             => $r['name'],
                        "url"             => "$scheme://{$domain}/pac$hash/" . base64_encode(serialize([
                            'h' => $hash,
                            't' => 'si',
                            's' => $uid,
                            'r' => $r['name'],
                        ])),
                        "update_interval" => $r['interval'],
                        "type"            => "remote",
                        "format"          => "binary",
                    ];
                    $route['rules'][$k]['rule_set'][] = $r['name'];
                }
                unset($route['rules'][$k]['createruleset']);
                if (empty($route['rules'][$k]['rule_set'])) {
                    unset($route['rules'][$k]);
                }
            }
        }
        if (!empty($route['rules'])) {
            $route['rules']    = array_values($route['rules']);
        }
        $route['rule_set'] = array_merge($route['rule_set'] ?: [], $ruleset ?: []);
        if (empty($route['rule_set'])) {
            unset($route['rule_set']);
        }
        return $route;
    }

public function getSingbox()
    {
        $pac = $this->getPacConf();
        return [
            'inbounds' => [
                [
                    'settings' => [
                        'clients' => $pac['singboxClients'] ?? [],
                    ],
                    'streamSettings' => [
                        'realitySettings' => [
                            'serverNames' => [$pac['reality']['domain'] ?? null],
                            'dest'        => $pac['reality']['destination'] ?? null,
                            'shortIds'    => [$pac['reality']['shortId'] ?? null],
                            'privateKey'  => $pac['reality']['privateKey'] ?? null,
                        ],
                    ],
                ],
            ],
            'outbounds' => $pac['singboxOutbounds'] ?? [],
            'routing'   => ['rules' => $pac['singboxRoutingRules'] ?? []],
        ];
    }

public function changeFakeDomain()
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
            'callback'       => 'setFakeDomain',
            'args'           => [],
        ];
    }

public function setFakeDomain($domain, $self = false)
    {
        $c = $this->getSingbox();
        $p = $this->getPacConf();
        $c['inbounds'][0]['streamSettings']['realitySettings']['serverNames'][0] = $domain;
        $c['inbounds'][0]['streamSettings']['realitySettings']['dest'] = $self ? "10.10.1.2:443" : "$domain:443";
        $p['reality']['domain'] = $domain;
        $p['reality']['destination'] = $self ? "10.10.1.2:443" : "$domain:443";
        $this->setPacConf($p);
        $this->restartSingbox($c);
        $this->setUpstreamDomain($domain);
        $this->singbox();
    }

public function selfFakeDomain()
    {
        $c = $this->getPacConf();
        if (!empty($c['domain'])) {
            $this->setFakeDomain($c['domain'], 1);
        } else{
            $this->answer($this->input['callback_id'], 'empty domain', true);
        }
    }

public function changeTransport($transport)
    {
        $p = $this->getPacConf();
        $x = $this->getSingbox();
        $h = $this->getHashBot();

        $p['reality']['domain']      = $p['reality']['domain'] ?: 'yandex.ru';
        $p['reality']['destination'] = $p['reality']['destination'] ?: $p['reality']['domain'] . ':443';
        $p['transport'] = $transport;

        $p['reality']['domain']      = $x['inbounds'][0]['streamSettings']['realitySettings']['serverNames'][0] ?? $p['reality']['domain'];
        $p['reality']['destination'] = $x['inbounds'][0]['streamSettings']['realitySettings']['dest'] ?? $p['reality']['destination'];
        $p['reality']['shortId']     = $x['inbounds'][0]['streamSettings']['realitySettings']['shortIds'][0] ?? $p['reality']['shortId'];

        if (empty($p['reality']['publicKey'])) {
            $shortId = trim($this->ssh('openssl rand -hex 8', 'sbx'));
            $keys    = $this->ssh('sing-box generate reality-keypair', 'sbx');
            preg_match('~PrivateKey:\s*([^\s]+)~', $keys, $m);
            $private = trim($m[1] ?? '');
            preg_match('~PublicKey:\s*([^\s]+)~', $keys, $m);
            $public = trim($m[1] ?? '');
            $p['reality']['publicKey'] = $public;
            $p['reality']['shortId']    = $shortId;
            $p['reality']['privateKey'] = $private;
        }


        switch ($transport) {
            case 'Reality':
                foreach ($x['inbounds'][0]['settings']['clients'] as $k => $v) {
                    $x['inbounds'][0]['settings']['clients'][$k]['flow'] = 'xtls-rprx-vision';
                }
                $x['inbounds'][0]['streamSettings'] = [
                    "network"         => "tcp",
                    "realitySettings" => [
                        "dest"         => $p['reality']['destination'] ?: $x['inbounds'][0]['streamSettings']['realitySettings']['dest'],
                        "maxClientVer" => "",
                        "maxTimeDiff"  => 0,
                        "minClientVer" => "",
                        "privateKey"   => $p['reality']['privateKey'],
                        "serverNames"  => [
                            $p['reality']['domain'] ?: $x['inbounds'][0]['streamSettings']['realitySettings']['serverNames'][0]
                        ],
                        "shortIds" => [$p['reality']['shortId']] ?: $x['inbounds'][0]['streamSettings']['realitySettings']['shortIds'][0],
                        "show"     => false,
                        "xver"     => 0
                    ],
                    "tcpSettings" => [
                        "acceptProxyProtocol" => true
                    ],
                    "sockopt" => [
                        "acceptProxyProtocol" => true
                    ],
                    "security" => "reality"
                ];
                break;

            default:
                $x['inbounds'][0]['streamSettings'] = [
                    "network"    => "ws",
                    "wsSettings" => [
                        "path" => "/ws$h"
                    ]
                ];
                foreach ($x['inbounds'][0]['settings']['clients'] as $k => $v) {
                    unset($x['inbounds'][0]['settings']['clients'][$k]['flow']);
                }
                break;
        }

        $this->setUpstreamDomain($transport == 'Reality'
            ? ($p['reality']['domain'] ?: $x['inbounds'][0]['streamSettings']['realitySettings']['serverNames'][0])
            : 't'
        );
        $this->setPacConf($p);
        $this->restartSingbox($x);
        $this->singbox();
    }
}

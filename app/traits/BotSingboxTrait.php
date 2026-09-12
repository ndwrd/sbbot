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

        // Единый хук на все места правки списка клиентов (restartSingbox()
        // вызывается отсюда 13 местами) — как список поменялся, все ноды
        // считаются "устаревшими" до подтверждённого пуша; subscription()
        // не должен предлагать клиенту ноду, пока usersSynced не true.
        if (!empty($this->getNodes())) {
            $this->nodeSyncAllUsers();
        }

        $sing = $this->buildSingboxConfig($pac);
        if (empty($norestart)) {
            $this->collectSession();
            file_put_contents('/config/sing-server.json', json_encode($sing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            // SIGHUP = sing-box's own graceful reload (validates the new config, then
            // swaps instances with a shutdown grace period for existing connections)
            // instead of a hard kill; falls back to a cold start if nothing is running yet.
            // $wait=false makes ssh() nohup-wrap the whole command — confirmed the hard
            // way that a bare `&` here does NOT survive the ssh channel closing (same
            // class of bug as `docker exec ... &` needing `exec -d` to actually detach).
            $this->ssh('pkill -HUP sing-box || sing-box run -c /sing.json', 'sbx', false);
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
                'name' => $v['username'] ?? $v['id'],
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
            $protocolUsers[] = ['name' => $v['username'] ?? $v['id'], 'password' => $v['password']];
        }

        $inbounds = [$inbound];

        $inbounds[] = [
            'type'        => 'naive',
            'tag'         => 'naive-in',
            'listen'      => '0.0.0.0',
            'listen_port' => 8444,
            // network: tcp — TLS терминируется на ng, до sing-box доходит только
            // обычный HTTP/2 по TCP; QUIC(UDP)-листенер naive требует TLS всегда,
            // поэтому его нужно явно отключить, иначе sing-box не стартует.
            'network'     => 'tcp',
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

        // log/dns — чистая статика из /config/sing-server.json, правится руками прямо
        // в файле. outbounds/route тоже читаются оттуда как база (чтобы админ мог
        // руками добавить что-то своё), но дальше в них домонтируются
        // pac['singboxOutbounds']/['singboxRoutingRules'] — их считает
        // buildWarpBlockOutboundsRules() (для main — из singboxUpdateRules(),
        // для ноды — из applyUsers(), куда main теперь пушит blocklist/warplist
        // вместе со списком клиентов). block — не outbound, а action:"reject"
        // прямо на правиле (в sing-box отдельного block-outbound'а по смыслу
        // нет); warp — настоящий socks-outbound на microsocks внутри wp. Тег
        // "warp" и правила с action:"reject" всегда вычищаются перед вставкой
        // свежих, иначе при каждом restartSingbox() файл читает сам себя и
        // они задваивались бы.
        $sing = json_decode(file_get_contents('/config/sing-server.json'), true) ?: [];
        $sing['inbounds']     = $inbounds;
        $sing['experimental'] = [
            'v2ray_api' => [
                'listen' => '127.0.0.1:8080',
                'stats'  => [
                    'enabled'   => true,
                    'inbounds'  => ['vless-in', 'naive-in', 'anytls-in', 'hysteria2-in'],
                    'outbounds' => ['direct'],
                    'users'     => array_unique(array_merge(array_column($users, 'name'), array_column($protocolUsers, 'name'))),
                ],
            ],
        ];

        $baseOutbounds = array_values(array_filter($sing['outbounds'] ?? [], fn($o) => ($o['tag'] ?? null) !== 'warp'));
        $sing['outbounds'] = array_merge($baseOutbounds, $pac['singboxOutbounds'] ?? []);

        $baseRules = array_values(array_filter($sing['route']['rules'] ?? [], fn($r) => ($r['outbound'] ?? null) !== 'warp' && ($r['action'] ?? null) !== 'reject'));
        $sing['route']['rules'] = array_merge($pac['singboxRoutingRules'] ?? [], $baseRules);

        return $sing;
    }

public function addXrUser()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} example: Description:username:uuid:password, (description required, username/uuid/password auto-generated if omitted)",
            $this->input['message_id'],
            reply: 'Description:username:uuid:password,',
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
            "@{$this->input['username']} Enter Description",
            $this->input['message_id'],
            reply: 'Enter Description',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'renXrUs',
            'args'           => [$i],
        ];
    }

public function queryV2raySingboxStats($host = null)
    {
        // experimental.v2ray_api (тег with_v2ray_api) — v2ray-совместимый gRPC
        // StatsService; PHP не умеет gRPC/protobuf нативно, поэтому спрашиваем через
        // grpcurl (готовый gRPC-клиент, как curl, только для gRPC) с тем же
        // stats.proto, что и собранный sing-box — оба зашиты в образ sbx.
        // Пустой pattern в QueryStats — значит "отдай вообще все счётчики разом".
        // $host — та же нода, что и везде: без него idёт локальный sbx, с ним —
        // docker exec в sbx на удалённом хосте (см. ssh()).
        $out = $this->ssh("grpcurl -plaintext -import-path /etc/singbox -proto stats.proto -d '{}' 127.0.0.1:8080 v2ray.core.app.stats.command.StatsService/QueryStats", 'sbx', true, '/dev/null', $host);
        $json = json_decode($out, true);
        $result = ['users' => [], 'inbounds' => []];
        foreach ($json['stat'] ?? [] as $stat) {
            // Имена счётчиков вида "user>>>ИМЯ>>>traffic>>>uplink" / "...downlink",
            // аналогично для "inbound>>>ТЕГ>>>...".
            $parts = explode('>>>', $stat['name'] ?? '');
            if (count($parts) < 4 || !in_array($parts[0], ['user', 'inbound'])) {
                continue;
            }
            $key   = $parts[0] === 'user' ? 'users' : 'inbounds';
            $field = $parts[3] === 'uplink' ? 'upload' : 'download';
            $result[$key][$parts[1]][$field] = (int) ($stat['value'] ?? 0);
        }
        return $result;
    }

public function getSingboxSysStats($host = null)
    {
        $out = $this->ssh("grpcurl -plaintext -import-path /etc/singbox -proto stats.proto -d '{}' 127.0.0.1:8080 v2ray.core.app.stats.command.StatsService/GetSysStats", 'sbx', true, '/dev/null', $host);
        // Регистр ключей в JSON-выдаче grpcurl под вопросом (поля в .proto — не
        // snake_case, а PascalCase, как есть) — приводим к нижнему регистру, чтобы
        // не гадать точное написание.
        return array_change_key_case(json_decode($out, true) ?: [], CASE_LOWER);
    }

public function getSingboxTotalTraffic($st)
    {
        // Верхнеуровневые $st['global']/$st['session'] нигде не заполняются
        // (только $st['inbounds'][tag][...] и $st['users'][i][...]) — total считаем
        // суммой по всем inbound'ам, а не читаем эти всегда-пустые ключи.
        $download = $upload = 0;
        foreach (['vless-in', 'naive-in', 'anytls-in', 'hysteria2-in'] as $tag) {
            $download += ($st['inbounds'][$tag]['global']['download'] ?? 0) + ($st['inbounds'][$tag]['session']['download'] ?? 0);
            $upload   += ($st['inbounds'][$tag]['global']['upload']   ?? 0) + ($st['inbounds'][$tag]['session']['upload']   ?? 0);
        }
        return [$download, $upload];
    }

public function singboxStatsUser()
    {
        // grpcurl лезет по ssh на sbx на каждый тик — держать это на общем
        // 10-секундном периоде cron() избыточно, обновляем раз в 90 секунд.
        if (!empty($this->time_singbox_stats) && (time() - $this->time_singbox_stats) < 90) {
            return;
        }
        $this->time_singbox_stats = time();
        $stats = $this->queryV2raySingboxStats();
        if (empty($stats['users']) && empty($stats['inbounds'])) {
            return;
        }
        $pac     = $this->getPacConf();
        $clients = $pac['singboxClients'] ?? [];
        $p       = $this->getSingboxStats();

        // Счётчики sing-box именованы по username, а $p['users'] (как уже читают
        // userXr()/sub()) индексирован числовой позицией клиента — сопоставляем.
        foreach ($clients as $i => $client) {
            $name = $client['username'] ?? null;
            if ($name === null || !isset($stats['users'][$name])) {
                continue;
            }
            $p['users'][$i]['session']['download'] = $stats['users'][$name]['download'] ?? 0;
            $p['users'][$i]['session']['upload']   = $stats['users'][$name]['upload'] ?? 0;
        }
        foreach ($stats['inbounds'] as $tag => $v) {
            $p['inbounds'][$tag]['session']['download'] = $v['download'] ?? 0;
            $p['inbounds'][$tag]['session']['upload']   = $v['upload'] ?? 0;
        }
        $this->setSingboxStats($p);

        // Лимит трафика — по согласованному плану только уведомляем админа, без
        // автоотключения; limitNotified не даёт слать одно и то же уведомление
        // повторно, пока трафик не упадёт обратно ниже лимита (сброс статы и т.п.).
        $changed = false;
        require dirname(__DIR__) . '/config.php';
        foreach ($clients as $i => $client) {
            if (empty($client['trafficlimit'])) {
                continue;
            }
            $stat = $p['users'][$i] ?? null;
            if (!$stat) {
                continue;
            }
            $total = ($stat['global']['download'] ?? 0) + ($stat['session']['download'] ?? 0)
                   + ($stat['global']['upload']   ?? 0) + ($stat['session']['upload']   ?? 0);
            if ($total >= $client['trafficlimit'] && empty($client['limitNotified'])) {
                $label = $client['description'] ?? ($client['username'] ?? $i);
                foreach ($c['admin'] as $admin) {
                    $this->send($admin, "$label: traffic limit exceeded (" . $this->getBytes($total) . ")");
                }
                $clients[$i]['limitNotified'] = true;
                $changed = true;
            } elseif ($total < $client['trafficlimit'] && !empty($client['limitNotified'])) {
                $clients[$i]['limitNotified'] = false;
                $changed = true;
            }
        }
        if ($changed) {
            $pac['singboxClients'] = $clients;
            $this->setPacConf($pac);
        }
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
                $elapsed = $now - $start;
                $periodsElapsed = floor($elapsed / $period);
                $lastScheduledReset = $start + ($periodsElapsed * $period);
                $lastResetTime = $pac['last_reset_singbox_time'] ?? 0;
                if ($lastResetTime < $lastScheduledReset) {
                    $pac['last_reset_singbox_time'] = $now;
                    $this->setPacConf($pac);
                    $st = $this->getSingboxStats();
                    [$download, $upload] = $this->getSingboxTotalTraffic($st);
                    $this->resetXrStats(1);
                    require dirname(__DIR__) . '/config.php';
                    foreach ($c['admin'] as $admin) {
                        $this->send($admin, "vless: monthly traffic ↓{$this->getBytes($download)} ↑{$this->getBytes($upload)}, stats reset");
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
        $this->sendFile($this->input['from'], new CURLStringFile($conf, $c['username'] . ($t == 'cl' ? '_mihomo.yaml' :($t == 'si' ? '_singbox.json' : '_xray.json'))));
    }

public function timerXr($k)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter time, example: +1 month or 2026-12-31 23:59:59",
            $this->input['message_id'],
            reply: 'example: +1 month or 2026-12-31 23:59:59',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'setTimerXr',
            'args'          => [$k],
        ];
    }

public function limitXr($i)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter traffic limit in GB, example: 50 (0 to disable)",
            $this->input['message_id'],
            reply: 'example: 50 (0 to disable)',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'setLimitXr',
            'args'          => [$i],
        ];
    }

public function setLimitXr($gb, $i)
    {
        $c  = $this->getSingbox();
        $gb = (float) $gb;
        if (empty($gb)) {
            unset($c['inbounds'][0]['settings']['clients'][$i]['trafficlimit']);
            unset($c['inbounds'][0]['settings']['clients'][$i]['limitNotified']);
        } else {
            $c['inbounds'][0]['settings']['clients'][$i]['trafficlimit']  = (int) round($gb * 1024 ** 3);
            $c['inbounds'][0]['settings']['clients'][$i]['limitNotified'] = false;
        }
        $this->restartSingbox($c, 1);
        $this->userXr($i);
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
        }
    }

public function buildWarpBlockOutboundsRules($pac)
    {
        // Общая логика для main (singboxUpdateRules()) и для ноды (applyUsers()) —
        // blocklist/warplist теперь синкаются на ноду вместе со списком клиентов
        // (nodeSyncUsersSilent()), и каждая нода должна собирать те же block/warp
        // outbound+правила у себя, а не только главный сервер.
        $toIpCidr = function ($k) {
            if (preg_match('~^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}(/\d{1,2})?$~', $k, $m)) {
                return $k . (empty($m[1]) ? '/32' : '');
            }
            return null;
        };
        // block — не outbound (в sing-box его смысла нет, современная схема —
        // action:"reject" прямо на правиле: https://sing-box.sagernet.org/
        // configuration/route/rule_action/#reject), warp — реальный outbound
        // (socks на microsocks внутри контейнера wp), поэтому у них разные
        // "довески" к правилу.
        $buildRules = function (array $list, array $extra) use ($toIpCidr) {
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
                $rules[] = array_merge(['domain_suffix' => $domains], $extra);
            }
            if (!empty($ips)) {
                $rules[] = array_merge(['ip_cidr' => $ips], $extra);
            }
            return $rules;
        };

        return [
            'outbounds' => [
                [
                    'type'        => 'socks',
                    'tag'         => 'warp',
                    'server'      => '10.10.0.13',
                    'server_port' => 1080,
                ],
            ],
            'rules' => array_merge(
                $buildRules($pac['blocklist'] ?? [], ['action' => 'reject']),
                $buildRules($pac['warplist'] ?? [], ['outbound' => 'warp']),
            ),
        ];
    }

public function singboxUpdateRules()
    {
        $c   = $this->getPacConf();
        $xr  = $this->getSingbox();
        $built = $this->buildWarpBlockOutboundsRules($c);
        $xr['outbounds']         = $built['outbounds'];
        $xr['routing']['rules'] = $built['rules'];
        $this->restartSingbox($xr);
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
        $this->statsMenu();
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
                return "sing-box://import-remote-profile/?url={$si}#{$c['inbounds'][0]['settings']['clients'][$i]['username']}";

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
                                    . "#{$c['inbounds'][0]['settings']['clients'][$i]['username']}";
                        break;

                    default:
                        $link =  "vless://{$c['inbounds'][0]['settings']['clients'][$i]['id']}@$domain:443"
                                    . "?flow="
                                    . "&path=%2Fws$hash"
                                    . "&security=tls"
                                    . "&sni=$domain"
                                    . "&fp=chrome"
                                    . "&type=ws"
                                    . "#{$c['inbounds'][0]['settings']['clients'][$i]['username']}";
                        break;
                }
                return $link;

        }
    }

public function happSubUrl($uid)
    {
        $pac    = $this->getPacConf();
        $domain = $this->getDomain($pac['transport'] != 'Reality');
        $scheme = empty($this->nginxGetTypeCert()) ? 'http' : 'https';
        $hash   = $this->getHashBot();
        return "$scheme://{$domain}/pac$hash/" . base64_encode(serialize([
            'h' => $hash,
            't' => 'hp',
            's' => $uid,
        ]));
    }

public function buildHappRouting($pac)
    {
        $c                 = json_decode(file_get_contents('/config/happ_routing.json'), true);
        $c['LastUpdated']  = date('Y-m-d');
        foreach (array_keys(array_filter(($pac['blocklist'] ?? null) ?: [])) as $d) {
            $c['BlockSites'][] = $d;
        }
        foreach (array_keys(array_filter(($pac['includelist'] ?? null) ?: [])) as $d) {
            $c['ProxySites'][] = $d;
        }
        foreach (array_keys(array_filter(($pac['warplist'] ?? null) ?: [])) as $d) {
            $c['ProxySites'][] = $d;
        }
        foreach (array_keys(array_filter(($pac['subnetlist'] ?? null) ?: [])) as $d) {
            $c['ProxyIp'][] = $d;
        }
        return base64_encode(json_encode($c, JSON_UNESCAPED_SLASHES));
    }

public function buildHappLinks($domain, $hash, $uid, $password)
    {
        // Своего selector/urltest у Happ нет — просто список ссылок, клиент
        // сам умеет пинговать/переключаться между ними в своём UI.
        $servers = !empty($this->getNodes())
            ? $this->getSubscriptionServers()
            : [['tag' => null, 'domain' => $domain, 'hash' => $hash, 'isMain' => true]];

        $lines = [];
        foreach ($servers as $s) {
            // Для main берём тот же $domain/$hash, что уже посчитан выше
            // (учитывает ?cdn= оверрайд) — у getSubscriptionServers() для
            // main это сырой pac['domain'], без CDN-подмены.
            $d      = !empty($s['isMain']) ? $domain : $s['domain'];
            $h      = !empty($s['isMain']) ? $hash : $s['hash'];
            $prefix = $s['tag'] ? "{$s['tag']}|" : '';
            $wspath = rawurlencode("/ws{$h}");
            $lines[] = "vless://{$uid}@{$d}:443"
                . "?flow=&path={$wspath}&security=tls&sni={$d}&fp=chrome&type=ws"
                . "#{$prefix}Vless";
            $lines[] = "hysteria2://{$password}@{$d}:443/"
                . "?insecure=0&sni={$d}"
                . "#{$prefix}HY2";
        }
        return implode("\n", $lines);
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
            $uuids[]     = $v['id'];
            $usernames[] = $v['username'];
        }
        foreach ($users as $user) {
            $description = $user[0] ?? '';
            $username    = $user[1] ?? '';
            $uuid        = $user[2] ?? '';
            $password    = $user[3] ?? '';
            if ($description === '') {
                $this->send($this->input['chat'], 'description is required');
                return $this->singbox();
            }
            // username — 8-символьный hex (буквы+цифры), тот же принцип анти-фингерпринта,
            // что и у naiveSubdomain/anytlsSubdomain: он же naive-username, он же имя конфига.
            $username = $username ?: bin2hex(random_bytes(4));
            $uuid     = $uuid ?: trim($this->ssh('sing-box generate uuid', 'sbx'));
            $password = $password ?: trim($this->ssh('openssl rand -base64 16', 'sbx'));
            if (in_array($uuid, $uuids ?: []) || in_array($username, $usernames ?: [])) {
                $this->send($this->input['chat'], "user {$username} already exists");
                return $this->singbox();
            }
            $client = [
                'id'          => $uuid,
                'username'    => $username,
                'description' => $description,
                'password'    => $password,
            ];
            if ($p['transport'] == 'Reality') {
                $client['flow'] = 'xtls-rprx-vision';
            }
            $c['inbounds'][0]['settings']['clients'][] = $client;
        }
        $this->restartSingbox($c);
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
        $c['inbounds'][0]['settings']['clients'][$i]['description'] = $name;
        $this->restartSingbox($c);
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
        $this->setSingboxStats($c);
        $this->restartSingbox($this->getSingbox());
        $this->userXr($i);
    }

public function resetXrStats($nomenu = false)
    {
        $this->restartSingbox($this->getSingbox());
        $this->setSingboxStats([]);
        if (empty($nomenu)) {
            $this->statsMenu();
        }
    }

public function statsMenu()
    {
        $st   = $this->getSingboxStats();
        $sys  = $this->getSingboxSysStats();
        $host = $this->getHostStats();
        $conf = $this->getPacConf();

        $text[] = "Menu -> " . $this->i18n('vless') . ' -> Stats';
        $text[] = '';
        $text[] = '<blockquote>';
        $text[] = '<b>System</b>';
        $text[] = "CPU {$host['cpu']}% · MEM {$host['mem']}% · DISK {$host['disk']}%";
        $text[] = 'Sing-box uptime: ' . $this->formatUptime($sys['uptime'] ?? 0);
        $text[] = 'Sing-box memory: ' . $this->getMB($sys['alloc'] ?? 0);
        $text[] = '</blockquote>';
        $text[] = '<blockquote>';
        $text[] = '<b>Traffic</b>';
        [$totalDownload, $totalUpload] = $this->getSingboxTotalTraffic($st);
        $text[] = "Total: ↓{$this->getBytes($totalDownload)} ↑{$this->getBytes($totalUpload)}";
        foreach ([
            'vless-in'     => 'Vless',
            'naive-in'     => 'Naive',
            'anytls-in'    => 'Anytls',
            'hysteria2-in' => 'Hysteria2',
        ] as $tag => $label) {
            $download = ($st['inbounds'][$tag]['global']['download'] ?? 0) + ($st['inbounds'][$tag]['session']['download'] ?? 0);
            $upload   = ($st['inbounds'][$tag]['global']['upload']   ?? 0) + ($st['inbounds'][$tag]['session']['upload']   ?? 0);
            $text[]   = "$label: ↓{$this->getBytes($download)} ↑{$this->getBytes($upload)}";
        }
        $text[] = '</blockquote>';

        $data[] = [
            [
                'text'          => $this->i18n('reset stats'),
                'callback_data' => "/resetXrStats",
            ],
            [
                'text'          => $this->i18n(!empty($conf['reset_monthly']) ? 'on' : 'off') . ' Reset monthly',
                'callback_data' => "/switchMonthlyStats",
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
            <code>~subnet~</code>
            <code>~domains~</code>
            <code>~process~</code>
            <code>~package~</code>
            <code>~warp~</code>
            <code>~block~</code>
            <code>~dns~</code>
            <code>~dnspath~</code>
            <code>~wspath~</code>
            <code>~uid~</code>
            <code>~password~</code>
            <code>~username~</code>
            <code>~ip~</code>
            <code>~domain~</code>
            <code>~naive_domain~</code>
            <code>~anytls_domain~</code>
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
                'text'          => "Origin",
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

public function singbox($page = 0)
    {
        $c      = $this->getSingbox();
        $p      = $this->getPacConf();
        $text[] = '';
        $text[] = "Menu -> " . $this->i18n('vless');
        $text[] = '';
        if (!empty($c['inbounds'][0]['streamSettings']['realitySettings']['serverNames'][0])) {
            $text[] = "fake domain: <code>{$c['inbounds'][0]['streamSettings']['realitySettings']['serverNames'][0]}</code>";
        }
        $text[] = 'Transport: ' . (($p['transport'] ?? null) ?: 'Websocket');
        $geoTag = $this->ensureMainGeoTag();
        $botTag = $this->countryFlag(preg_replace('~\d+$~', '', $geoTag)) . $geoTag;
        $text[] = '';
        $text[] = 'Outbound tag:';
        $text[] = "<code>~{$botTag}:outbounds~</code>";
        // Тот же вид, что и у ноды (nodeMenu()) — ровно те теги, что попадают
        // в selector/⚡️Auto реальной подписки (buildSingMultiOutbounds()),
        // чтобы можно было скопировать готовую строку прямо в свой шаблон.
        // Выключенный (toggleOutbound()) протокол пропадает и отсюда.
        $off    = $p['outboundsOff'] ?? [];
        $labels = array_filter(
            ['vless' => 'Vless', 'naive' => 'Naive', 'hysteria2' => 'Hy2', 'anytls' => 'Anytls'],
            fn ($k) => empty($off[$k]),
            ARRAY_FILTER_USE_KEY
        );
        if (!empty($labels)) {
            $text[] = '<blockquote>Outbounds:';
            foreach ($labels as $label) {
                $text[] = "<code>{$botTag}|{$label}</code>";
            }
            $text[] = '</blockquote>';
        }
        $st = $this->getSingboxStats();
        $data[] = [
            [
                'text'          => $this->i18n('outbounds'),
                'callback_data' => "/outboundsMenu",
            ],
            [
                'text'          => $this->i18n('templates'),
                'callback_data' => "/templatesMenu",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('routes'),
                'callback_data' => "/routes",
            ],
            [
                'text'          => 'Stats',
                'callback_data' => "/statsMenu",
            ],
        ];
        $data[] = [
            [
                'text'          => '─────────────',
                'callback_data' => "/singbox $page",
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
        uasort($clients, fn($a, $b) => (($a['time'] ?? null) ?: PHP_INT_MAX) <=> (($b['time'] ?? null) ?: PHP_INT_MAX));

        $all     = (int) ceil(count($clients) / $this->limit);
        $page    = min($page, $all - 1);
        $page    = $page == -2 ? $all - 1 : $page;
        $clients = $page != -1 ? array_slice($clients, $page * $this->limit, $this->limit, true) : $clients;
        foreach ($clients as $k => $v) {
            $time     = !empty($v['time']) ? $this->getTime($v['time']) : '';
            $limit    = !empty($v['trafficlimit']) ? '| ' . round($v['trafficlimit'] / (1024 ** 3), 2) . ' GB' : '';
            $download = ($st['users'][$k]['global']['download'] ?? 0) + ($st['users'][$k]['session']['download'] ?? 0);
            $upload   = ($st['users'][$k]['global']['upload']   ?? 0) + ($st['users'][$k]['session']['upload']   ?? 0);
            $data[]   = [
                [
                    'text'          => (!empty($v['description']) ? "{$v['description']} — " : '') . "{$v['username']}" . ($time ? ": $time" : '') . $limit . " ↓{$this->getBytes($download)} ↑{$this->getBytes($upload)}",
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
                'callback_data' => "/templates xray",
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
                'text'          => 'Domains',
                'callback_data' => "/xtlsproxy",
            ]],
            [[
                'text'          => "Subnet",
                'callback_data' => "/xtlssubnet",
            ]],
            [[
                'text'          => 'Process',
                'callback_data' => "/xtlsprocess",
            ]],
            [[
                'text'          => 'Package',
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
        $text[]    = "Menu -> " . $this->i18n('vless') . " -> {$c['inbounds'][0]['settings']['clients'][$i]['username']}\n";
        $templates = $pac["{$type}templates"];
        $data[]    = [
            [
                'text'          => 'Default',
                'callback_data' => "/choiceTemplate {$type}_$i",
            ],
        ];
        $data[] = [
            [
                'text'          => 'Origin',
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

        $text[] = "Menu -> " . $this->i18n('vless') . " -> {$c['username']}\n";
        if (file_exists(dirname(__DIR__) . '/subscription.php')) {
            $text[] = "<a href='$scheme://{$domain}/pac$hash/sub?id={$c['id']}'>subscription</a>";
        }
        $text[] = "<a href='$scheme://{$domain}/pac$hash?t=s&r=v&s={$c['id']}#{$c['username']}'>import://v2rayng</a>";
        $text[] = "<a href='$scheme://{$domain}/pac$hash?t=si&r=si&s={$c['id']}#{$c['username']}'>import://sing-box</a>";
        $text[] = "<a href='$scheme://{$domain}/pac$hash?t=s&r=st&s={$c['id']}#{$c['username']}'>import://streisand</a>";
        $text[] = "<a href='$scheme://{$domain}/pac$hash?t=si&r=h&s={$c['id']}#{$c['username']}'>import://hiddify</a>";
        $text[] = "<a href='$scheme://{$domain}/pac$hash?t=si&r=k&s={$c['id']}#{$c['username']}'>import://karing</a>";
        $text[] = "<a href='$scheme://{$domain}/pac$hash?t=cl&r=c&s={$c['id']}#{$c['username']}'>import://mihomo</a>";
        $text[] = "<a href='$scheme://{$domain}/pac$hash?t=cl&r=rh&s={$c['id']}#{$c['username']}'>import://rabbit-hole</a>";
        $text[] = "<a href='$scheme://{$domain}/pac$hash?t=hp&r=happ&s={$c['id']}#{$c['username']}'>routing://happ</a>";
        $text[] = "<a href='$scheme://{$domain}/pac$hash?t=hp&r=incy&s={$c['id']}#{$c['username']}'>routing://incy</a>";

        $text[] = "<pre><code>{$this->linkVless($i)}</code></pre>\n";

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

        $text[] = "\nxray config: <pre><code>$xr</code></pre>";
        $text[] = "sing-box config: <pre><code>$si</code></pre>";
        $text[] = "mihomo config: <pre><code>$cl</code></pre>";

        $hp = $this->happSubUrl($c['id']);
        $text[] = "happ/incy subscription: <pre><code>$hp</code></pre>";

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
                'text'    => $this->i18n('xray'),
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
                'text'    => $this->i18n('xray ⬇️'),
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
                'text'          => !empty($c['trafficlimit']) ? "limit: " . $this->getBytes($c['trafficlimit']) : $this->i18n('set limit'),
                'callback_data' => "/limitXr $i",
            ],
            [
                'text'          => $this->i18n($c['off'] ? 'off' : 'on'),
                'callback_data' => "/switchXr $i",
            ],
        ];
        $singtemplate  = $c['singtemplate'] ? base64_decode($c['singtemplate']) : 'default(' . ($pac['defaultsingtemplate'] && !empty($pac['singtemplates'][base64_decode($pac['defaultsingtemplate'])]) ? base64_decode($pac['defaultsingtemplate']) : 'origin') . ')';
        $xraytemplate = $c['xraytemplate'] ? base64_decode($c['xraytemplate']) : 'default(' . ($pac['defaultxraytemplate'] && !empty($pac['xraytemplates'][base64_decode($pac['defaultxraytemplate'])]) ? base64_decode($pac['defaultxraytemplate']) : 'origin') . ')';
        $clashtemplate = $c['clashtemplate'] ? base64_decode($c['clashtemplate']) : 'default(' . ($pac['defaultclashtemplate'] && !empty($pac['clashtemplates'][base64_decode($pac['defaultclashtemplate'])]) ? base64_decode($pac['defaultclashtemplate']) : 'origin') . ')';
        $data[]        = [
            [
                'text'          => $this->i18n('xray') . ": $xraytemplate",
                'callback_data' => "/templateUser xray $i",
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
                'text'          => $this->i18n('qr vless'),
                'callback_data' => "/qrVless $i",
            ],
            [
                'text'          => $this->i18n('qr xray'),
                'callback_data' => "/qrVless {$i}_1",
            ],
            [
                'text'          => $this->i18n('qr singbox'),
                'callback_data' => "/qrVless {$i}_2",
            ],
            [
                'text'          => $this->i18n('qr happ'),
                'callback_data' => "/qrHapp $i",
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
        $domain = ($_GET['cdn'] ?? null) ?: (($_SERVER['SERVER_NAME'] ?? null) ?: $this->getDomain($pac['transport'] != 'Reality'));
        $scheme = empty($this->nginxGetTypeCert()) ? 'http' : 'https';
        $hash   = $this->getHashBot();
        $flag   = true;
        foreach ($xr['inbounds'][0]['settings']['clients'] as $k => $v) {
            if ($v['id'] == $_GET['id']) {
                if (empty($v['off'])) {
                    $flag = false;
                }
                $uid      = $v['id'];
                $username = $v['username'];
                $expire   = $v['time'];
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
                $type = 'xray';
                break;
            case 'si':
                $type = 'sing';
                break;
            case 'cl':
                $type = 'clash';
                break;
            case 'hp':
                $type = 'happ';
                break;
        }
        $pac    = $this->getPacConf();
        $domain = ($_GET['cdn'] ?? null) ?: (($_SERVER['SERVER_NAME'] ?? null) ?: $this->getDomain($pac['transport'] != 'Reality'));
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
                $username = $v['username'];
                $password = $v['password'] ?? '';
                break;
            }
        }
        if ($flag) {
            header('500', true, 500);
            exit;
        }

        if ($_GET['t'] == 'hp') {
            // Отдельная кнопка "в один тап" — открывает сразу Happ/INCY и
            // активирует routing-профиль (happ://routing/onadd/... —
            // задокументированная у них самих deeplink-схема). Готового
            // deeplink'а на добавление именно подписки у них не
            // задокументировано, поэтому им остаётся только сама ссылка/QR
            // ниже — руками вставить в приложение.
            if (!empty($_GET['r']) && in_array($_GET['r'], ['happ', 'incy'], true)) {
                header("Location: {$_GET['r']}://routing/onadd/" . $this->buildHappRouting($pac));
                exit;
            }
            // Happ/INCY — не грузит наш JSON целиком как ядро (у них свой
            // движок), поэтому тут не тот же путь, что у si/s/cl: тело —
            // просто список vless/hysteria2 ссылок, а роутинг (direct/proxy/
            // block по доменам и IP) едет отдельным HTTP-заголовком
            // routing: happ://routing/onadd/<base64> поверх того же ответа.
            header('routing: happ://routing/onadd/' . $this->buildHappRouting($pac));
            header('Content-type: text/plain; charset=utf-8');
            echo base64_encode($this->buildHappLinks($domain, $hash, $uid, $password));
            return;
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
                    header("Location: clash://install-config/?url=$cl&overwrite=no&name=$username");
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
        // Копия ДО фильтрации по выключенным на Боте протоколам — нодам нужны
        // протокольные шаблоны (Vless/HY2/Naive/AnyTLS) для клонирования, даже
        // если конкретно у Бота этот протокол сейчас выключен: выключатели
        // Бота и ноды независимые, а "выключен у Бота" раньше по ошибке
        // означало ещё и "нечего клонировать нодам" — шаблон-то один на всех.
        $rawTemplate = $c;
        $c = $this->filterMainOutbounds($type, $c);

        // Ноды в подписку подмешиваются только для активного (не dormant
        // Reality/xhttp) транспорта — там шаблон уже в финальном WS-виде, и
        // есть повторяющийся набор протокольных outbound'ов, который можно
        // клонировать на сервер. $outbound остаётся тем же нейтральным тегом
        // группы/селектора, что и раньше — это НЕ тег конкретного сервера
        // (иначе он бы совпал с тегом клона главного сервера ниже).
        // getSubscriptionServers() трогает ensureMainGeoTag() (сетевой geo-IP
        // запрос на первый вызов) — не зовём её вообще, если нод ещё нет,
        // чтобы не тащить лишнюю сетевую зависимость в подписку однонодовых
        // (сейчас — почти всех) установок.
        $servers     = (!empty($this->getNodes()) && !in_array($pac['transport'] ?? null, ['Reality', 'xhttp'], true)) ? $this->getSubscriptionServers() : [];
        $multiServer = count($servers) > 1;
        // pac['outbound']/"Outbound name" убраны — sing-box и xray больше не
        // полагаются на этот алиас, у обоих в шаблоне фиксированный литерал
        // "Proxy" (см. buildSingMultiOutbounds(); для xray — просто тег
        // outbound'а + outboundTag в routing.rules, без selector'а, которого
        // у xray-core нет). clash пока использует его по-старому, до своей
        // очереди на такую же переделку. $outbound всегда равен 'proxy' (в
        // нижнем регистре — сеттера для pac['outbound'] больше нет), поэтому
        // для sing/xray-шаблонов (там тег "Proxy") ниже ничего не заменяет и
        // $index не находится — это ок, $index нужен только дормант-веткам
        // Reality/xhttp, которые сейчас не активны ни для одного из форматов.
        $outbound = ($pac['outbound'] ?? null) ?: 'proxy';
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
                // Как и у sing-box-шаблона: xray.json уже в финальном WS-виде для
                // единственного реально используемого сейчас транспорта — uuid/domain/
                // ~wspath~ заполнит общий replaceTags() ниже. Reality/xhttp — дормант-
                // задел (см. buildSingboxConfig()), сами достраивают всё с нуля, раз
                // шаблон больше не несёт realitySettings/mux по умолчанию.
                if (in_array($pac['transport'], ['Reality', 'xhttp'])) {
                    $c['outbounds'][$index]['settings']['vnext'][0]['address']  = '~domain~';
                    $c['outbounds'][$index]['settings']['vnext'][0]['users'][0] = [
                        'id'         => '~uid~',
                        'encryption' => 'none',
                    ];
                    switch ($pac['transport']) {
                        case 'Reality':
                            $c['outbounds'][$index]['settings']['vnext'][0]['users'][0]["flow"] = "xtls-rprx-vision";
                            $c['outbounds'][$index]['streamSettings']                           = [
                                "network"         => "tcp",
                                "security"        => "reality",
                                "realitySettings" => [
                                    "serverName"  => '~server_name~',
                                    "fingerprint" => 'chrome',
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
                                    "path" => "~wspath~",

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
                            break;
                    }
                }
                break;
            case 'si':
                // vless-out в шаблоне уже в финальном виде для единственного реально
                // используемого сейчас транспорта (WS) — uuid/domain/~wspath~ заполнит
                // общий replaceTags() ниже, точечная мутация по индексу не нужна.
                // Ветки Reality/xhttp — задел на случай, если транспорт когда-нибудь
                // переключат обратно (см. buildSingboxConfig(), где Reality тоже
                // отключён, но не удалён); шаблон больше не несёт tls.reality/flow сам
                // по себе, поэтому эти ветки досоздают их с нуля.
                if (in_array($pac['transport'], ['Reality', 'xhttp'])) {
                    foreach ($c['outbounds'] as $k => $v) {
                        if ($v['tag'] == 'vless-out') {
                            $vlessIndex = $k;
                            break;
                        }
                    }
                    switch ($pac['transport']) {
                        case 'Reality':
                            unset($c['outbounds'][$vlessIndex]["transport"]);
                            $c['outbounds'][$vlessIndex]['flow']               = 'xtls-rprx-vision';
                            $c['outbounds'][$vlessIndex]['tls']['reality']     = [
                                'enabled'    => true,
                                'public_key' => '~public_key~',
                                'short_id'   => '~short_id~',
                            ];
                            $c['outbounds'][$vlessIndex]['tls']['server_name'] = '~server_name~';
                            break;
                        case 'xhttp':
                            unset($c['outbounds'][$vlessIndex]['flow']);
                            unset($c['outbounds'][$vlessIndex]['tls']['reality']);

                            $c['outbounds'][$vlessIndex]["transport"] = [
                                "type" => "xhttp",
                                "host" => "~domain~",
                                "mode" => "packet-up",
                                "path" => "~wspath~",
                                "xmux" => [
                                    "max_concurrency"   => "16-32",
                                    "max_connections"   => "0-1",
                                    "c_max_reuse_times" => "0-1",
                                    "h_max_request_times" => "600-900",
                                    "h_max_reusable_secs" => "1800-3000",
                                    "h_keep_alive_period" => 60
                                ]
                            ];

                            $c['outbounds'][$vlessIndex]['tls'] = [
                                "enabled"     => true,
                                "insecure"    => false,
                                "server_name" => "~domain~",
                                "alpn"        => ["h2"]
                            ];
                            break;
                    }
                }
                break;
            case 'cl':
                // clash.json тоже уже в финальном WS-виде — как и у 's'/'si', точечная
                // мутация нужна только для дормант-транспортов Reality/xhttp.
                if (in_array($pac['transport'], ['Reality', 'xhttp'])) {
                    $c['proxies'][$index]['server'] = '~domain~';
                    $c['proxies'][$index]['uuid']   = '~uid~';
                    switch ($pac['transport']) {
                        case 'Reality':
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
                        case 'xhttp':
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
                                'path'                   => "~wspath~",
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
                    }
                }
                break;
        }
        if ($multiServer) {
            $c = $this->applyMultiServerOutbounds($type, $c, $rawTemplate, $outbound, $uid, $username, $password);
        }
        $c = json_decode($this->replaceTags(json_encode($c), [
            '"~domains~"'    => json_encode(array_keys(array_filter(($pac['includelist'] ?? null) ?: []))),
            '"~block~"'      => json_encode(array_keys(array_filter(($pac['blocklist'] ?? null) ?: []))),
            '"~warp~"'       => json_encode(array_keys(array_filter(($pac['warplist'] ?? null) ?: []))),
            '"~process~"'    => json_encode(array_keys(array_filter(($pac['processlist'] ?? null) ?: []))),
            '"~package~"'    => json_encode(array_keys(array_filter(($pac['packagelist'] ?? null) ?: []))),
            '"~subnet~"'     => json_encode(array_keys(array_filter(($pac['subnetlist'] ?? null) ?: []))),
            '~dns~'          => "https://$domain/dns-query$hash/$uid",
            '~dnspath~'      => "/dns-query$hash/$uid",
            '~wspath~'       => "/ws$hash",
            '~uid~'          => $uid,
            '~password~'     => $password,
            '~domain~'       => $domain,
            '~naive_domain~'  => "{$pac['naiveSubdomain']}.{$pac['domain']}",
            '~anytls_domain~' => "{$pac['anytlsSubdomain']}.{$pac['domain']}",
            '~short_id~'     => $xr['inbounds'][0]['streamSettings']['realitySettings']['shortIds'][0],
            '~username~'     => $username,
            '~public_key~'   => $pac['reality']['publicKey'],
            '~server_name~'  => $xr['inbounds'][0]['streamSettings']['realitySettings']['serverNames'][0],
            '~ip~'           => $this->ip,
            '~outbound~'     => $outbound,
        ]), true);

        switch ($_GET['t']) {
            case 'si':
                $c['route'] = $this->addRuleSet($c['route']);
                $c['route'] = $this->createRuleSet($c['route'], $uid, $domain);
                $c = $this->addDnsRuleSet($c);
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

public function getSubscriptionServers()
    {
        // Гео-теги считаются один раз при создании сервера (assignGeoTag()) и
        // тут просто читаются — пересчёта на лету нет, чтобы обозначение не
        // "плавало" между запросами подписки. Нода попадает в список только
        // если синхронизирована по пользователям и у неё есть рабочий домен+
        // сертификат — иначе клиент получил бы нерабочий фоллбэк.
        $pac         = $this->getPacConf();
        $mainGeoTag  = $this->ensureMainGeoTag();
        $mainCountry = preg_replace('~\d+$~', '', $mainGeoTag);
        $servers     = [[
            'tag'             => $this->countryFlag($mainCountry) . $mainGeoTag,
            'geoTag'          => $mainGeoTag,
            'domain'          => $pac['domain'] ?: $this->ip,
            'hash'            => $this->getHashBot(),
            'naiveSubdomain'  => $pac['naiveSubdomain'] ?? '',
            'anytlsSubdomain' => $pac['anytlsSubdomain'] ?? '',
            'outboundsOff'    => $pac['outboundsOff'] ?? [],
            'isMain'          => true,
        ]];
        foreach ($pac['nodes'] ?? [] as $node) {
            if (!empty($node['off']) || empty($node['usersSynced']) || empty($node['domain']) || empty($node['cert']) || empty($node['geoTag'])) {
                continue;
            }
            $country   = preg_replace('~\d+$~', '', $node['geoTag']);
            $servers[] = [
                'tag'             => $this->countryFlag($country) . $node['geoTag'],
                'geoTag'          => $node['geoTag'],
                'domain'          => $node['domain'],
                'hash'            => $node['hash'] ?? '',
                'naiveSubdomain'  => $node['naiveSubdomain'] ?? '',
                'anytlsSubdomain' => $node['anytlsSubdomain'] ?? '',
                'outboundsOff'    => $node['outboundsOff'] ?? [],
                'isMain'          => false,
            ];
        }
        return $servers;
    }

public function outboundProtocols()
    {
        // Канонический список протоколов-переключателей — общий для Бота и
        // нод, одни и те же ключи хранятся в pac['outboundsOff']/
        // node['outboundsOff']. Не каждый формат подписки поддерживает все 4
        // (clash — без naive, xray — только vless/hysteria2), но сам
        // переключатель один и тот же для всех форматов сразу.
        return [
            'vless'     => 'Vless',
            'hysteria2' => 'Hysteria2',
            'naive'     => 'Naive',
            'anytls'    => 'AnyTLS',
        ];
    }

public function outboundsOffFor($nodeId = null)
    {
        return $nodeId !== null
            ? ($this->getNode($nodeId)['outboundsOff'] ?? [])
            : ($this->getPacConf()['outboundsOff'] ?? []);
    }

public function toggleOutbound($proto, $nodeId = null)
    {
        if ($nodeId !== null) {
            $node = $this->getNode($nodeId);
            if (empty($node)) {
                return;
            }
            $node['outboundsOff'][$proto] = empty($node['outboundsOff'][$proto]);
            $this->setNode($nodeId, $node);
        } else {
            $pac = $this->getPacConf();
            $pac['outboundsOff'][$proto] = empty($pac['outboundsOff'][$proto]);
            $this->setPacConf($pac);
        }
        $this->outboundsMenu($nodeId);
    }

public function outboundsMenu($nodeId = null)
    {
        if ($nodeId !== null && empty($this->getNode($nodeId))) {
            $r = $this->nodesMenu();
            $this->update($this->input['chat'], $this->input['message_id'], $r['text'], $r['data']);
            return;
        }
        $off = $this->outboundsOffFor($nodeId);
        if ($nodeId !== null) {
            $node = $this->getNode($nodeId);
            $text[] = "Menu -> " . $this->i18n('nodes') . " -> {$node['label']} -> " . $this->i18n('outbounds');
        } else {
            $text[] = "Menu -> " . $this->i18n('vless') . " -> " . $this->i18n('outbounds');
        }
        $data = [];
        foreach ($this->outboundProtocols() as $key => $label) {
            $enabled = empty($off[$key]);
            $data[]  = [
                [
                    'text'          => $label,
                    'callback_data' => $nodeId !== null ? "/toggleOutbound {$key} {$nodeId}" : "/toggleOutbound {$key}",
                ],
                [
                    'text'          => $enabled ? '🟢' : '🔴',
                    'callback_data' => $nodeId !== null ? "/toggleOutbound {$key} {$nodeId}" : "/toggleOutbound {$key}",
                ],
            ];
        }
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => $nodeId !== null ? "/nodeMenu {$nodeId}" : "/singbox",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function filterMainOutbounds($type, $c)
    {
        // Выключенные на Боте протоколы вырезаются из origin-шаблона ещё до
        // любых других мутаций — единая точка для si/s/cl, срабатывает и без
        // нод (buildXxxMultiOutbounds() для однонодовых/безнодовых установок
        // вообще не вызывается).
        $off = $this->getPacConf()['outboundsOff'] ?? [];
        if (empty($off)) {
            return $c;
        }
        switch ($type) {
            case 'sing':
                return $this->filterSingOutbounds($c, $off);
            case 'clash':
                return $this->filterClashOutbounds($c, $off);
            case 'xray':
                return $this->filterXrayOutbounds($c, $off);
        }
        return $c;
    }

public function filterSingOutbounds($c, $off)
    {
        $removedTags = [];
        foreach ($c['outbounds'] ?? [] as $k => $o) {
            if (!empty($o['type']) && !empty($off[$o['type']])) {
                $removedTags[] = $o['tag'];
                unset($c['outbounds'][$k]);
            }
        }
        if (empty($removedTags)) {
            return $c;
        }
        $c['outbounds'] = array_values($c['outbounds']);
        foreach ($c['outbounds'] as $k => $o) {
            if (!in_array($o['tag'] ?? null, ['Proxy', '⚡️Auto'], true)) {
                continue;
            }
            $c['outbounds'][$k]['outbounds'] = array_values(array_diff($o['outbounds'], $removedTags));
            if (($o['tag'] ?? null) === 'Proxy' && in_array($o['default'] ?? null, $removedTags, true)) {
                $c['outbounds'][$k]['default'] = $c['outbounds'][$k]['outbounds'][0] ?? '';
            }
        }
        return $c;
    }

public function filterClashOutbounds($c, $off)
    {
        $removedNames = [];
        foreach ($c['proxies'] ?? [] as $k => $p) {
            if (!empty($p['type']) && !empty($off[$p['type']])) {
                $removedNames[] = $p['name'];
                unset($c['proxies'][$k]);
            }
        }
        if (empty($removedNames)) {
            return $c;
        }
        $c['proxies'] = array_values($c['proxies']);
        foreach ($c['proxy-groups'] ?? [] as $k => $g) {
            if (($g['name'] ?? null) === 'Proxy') {
                $c['proxy-groups'][$k]['proxies'] = array_values(array_diff($g['proxies'], $removedNames));
            }
        }
        return $c;
    }

public function filterXrayOutbounds($c, $off)
    {
        // У xray-core protocol для HY2 — "hysteria" (не "hysteria2", в
        // отличие от sing/clash), naive/anytls тут вообще нет outbound'ов.
        $map         = ['vless' => 'vless', 'hysteria2' => 'hysteria'];
        $removedTags = [];
        foreach ($c['outbounds'] ?? [] as $k => $o) {
            foreach ($map as $key => $xrayType) {
                if (($o['protocol'] ?? null) === $xrayType && !empty($off[$key])) {
                    $removedTags[] = $o['tag'];
                    unset($c['outbounds'][$k]);
                }
            }
        }
        if (empty($removedTags)) {
            return $c;
        }
        $c['outbounds'] = array_values($c['outbounds']);
        // "Proxy" (Vless) — единственный тег, на который ссылаются
        // routing.rules; если его выключили, а HY2 жив — переключаем правила
        // на HY2. Если выключить оба сразу — роутинг останется битым, это
        // осознанная граница: для xray без selector'а восстанавливать тут
        // нечем, а полное отключение проксирования — маловероятный сценарий.
        if (in_array('Proxy', $removedTags, true)) {
            $stillHasHy2 = (bool) array_filter($c['outbounds'], fn ($o) => ($o['tag'] ?? null) === 'HY2');
            if ($stillHasHy2) {
                foreach ($c['routing']['rules'] ?? [] as $k => $r) {
                    if (($r['outboundTag'] ?? null) === 'Proxy') {
                        $c['routing']['rules'][$k]['outboundTag'] = 'HY2';
                    }
                }
            }
        }
        return $c;
    }

public function applyMultiServerOutbounds($type, $c, $rawTemplate, $outbound, $uid, $username, $password)
    {
        switch ($type) {
            case 'sing':
                return $this->buildSingMultiOutbounds($c, $rawTemplate['outbounds'] ?? [], $this->getSubscriptionServers(), $outbound, $uid, $username, $password);
            case 'clash':
                return $this->buildClashMultiOutbounds($c, $rawTemplate['proxies'] ?? [], $this->getSubscriptionServers(), $outbound, $uid, $password);
            case 'xray':
                return $this->buildXrayMultiOutbounds($c, $rawTemplate['outbounds'] ?? [], $this->getSubscriptionServers(), $uid);
        }
        return $c;
    }

public function buildSingMultiOutbounds($c, $rawOutbounds, $servers, $outbound, $uid, $username, $password)
    {
        // Главный сервер уже в финальном виде прямо в origin-шаблоне —
        // correctSingOriginTags() один раз (при первом определении гео-тега
        // Бота) переименовала Vless/HY2/Naive/AnyTLS в "{флаг}{код}|Протокол"
        // и поправила outbounds/default у "Proxy"/"⚡️Auto". Поэтому тут
        // сопоставляем протокольные объекты по type (не по тегу — тег уже не
        // "vless-out", а конечное имя), и клонируем только под НОД, не под
        // главный — для него клонировать нечего, он уже там как есть.
        $protocols = [
            'vless'     => 'Vless',
            'hysteria2' => 'Hy2',
            'naive'     => 'Naive',
            'anytls'    => 'Anytls',
        ];
        // Шаблоны протоколов берём из $rawOutbounds (ДО filterMainOutbounds())
        // — иначе выключенный на Боте протокол вырезан из $c и клонировать
        // его для ноды, где он может быть включён, было бы нечем. Proxy/Auto
        // ищем в уже отфильтрованном $c — их filterMainOutbounds() не трогает.
        $templates = [];
        foreach ($rawOutbounds as $o) {
            if (isset($protocols[$o['type'] ?? ''])) {
                $templates[$o['type']] = $o;
            }
        }
        $proxyIdx = null;
        $autoIdx  = null;
        foreach ($c['outbounds'] ?? [] as $k => $o) {
            if (($o['tag'] ?? null) === 'Proxy') {
                $proxyIdx = $k;
            } elseif (($o['tag'] ?? null) === '⚡️Auto') {
                $autoIdx = $k;
            }
        }
        if ($proxyIdx === null || $autoIdx === null) {
            return $c;
        }
        // Нода, явно упомянутая где-то в шаблоне плейсхолдером
        // "~{тег}:outbounds~" (в любой другой группе — своей кастомной,
        // например), в дефолтные Proxy/⚡️Auto не попадает — ровно как
        // задокументировано в readme: только неупомянутые нигде ноды
        // заполняют группу по умолчанию. Сама она всё равно клонируется и
        // регистрируется в $byGeo — иначе той кастомной группе нечего будет
        // подставить.
        preg_match_all('/~(.+?):outbounds~/u', json_encode($c), $mClaimed);
        $claimed = array_unique($mClaimed[1] ?? []);

        $newTags      = [];
        $newOutbounds = [];
        $byGeo        = [];
        foreach ($servers as $s) {
            if (!empty($s['isMain'])) {
                // Уже есть в шаблоне как есть — просто регистрируем теги для
                // "~{тег}:outbounds~" в ручных группах, ничего не клонируем.
                // Выключенный на Боте протокол уже вырезан из $c
                // (filterMainOutbounds()) — тег под него не регистрируем.
                foreach ($protocols as $type => $label) {
                    if (!empty($s['outboundsOff'][$type])) {
                        continue;
                    }
                    $byGeo[$s['tag']][] = "{$s['tag']}|{$label}";
                }
                continue;
            }
            $excluded = in_array($s['tag'], $claimed, true);
            foreach ($protocols as $type => $label) {
                if (!empty($s['outboundsOff'][$type])) {
                    continue;
                }
                $tpl = $templates[$type] ?? null;
                if (empty($tpl)) {
                    continue;
                }
                $tag   = "{$s['tag']}|{$label}";
                $clone = json_decode($this->replaceTags(json_encode($tpl), [
                    '~domain~'        => $s['domain'],
                    '~uid~'           => $uid,
                    '~wspath~'        => "/ws{$s['hash']}",
                    '~naive_domain~'  => "{$s['naiveSubdomain']}.{$s['domain']}",
                    '~anytls_domain~' => "{$s['anytlsSubdomain']}.{$s['domain']}",
                    '~password~'      => $password,
                    '~username~'      => $username,
                ]), true);
                $clone['tag']   = $tag;
                $newOutbounds[] = $clone;
                $byGeo[$s['tag']][] = $tag;
                if (!$excluded) {
                    $newTags[] = $tag;
                }
            }
        }
        if (!empty($newTags)) {
            $c['outbounds'][$proxyIdx]['outbounds'] = array_merge($c['outbounds'][$proxyIdx]['outbounds'], $newTags);
            $c['outbounds'][$autoIdx]['outbounds']  = array_merge($c['outbounds'][$autoIdx]['outbounds'], $newTags);
        }
        $c['outbounds'] = array_merge($c['outbounds'], $newOutbounds);
        // Ручные группы админа (любой другой outbound с type:selector/urltest
        // в шаблоне) могут ссылаться на конкретный сервер плейсхолдером
        // "~{флаг}{код}:outbounds~" — он разворачивается тут же.
        return $this->expandGeoPlaceholders($c, $byGeo);
    }

public function expandGeoPlaceholders($c, $byGeo)
    {
        return $this->expandGeoInArray($c, $byGeo);
    }

public function expandGeoInArray($arr, $byGeo)
    {
        // Строковой заменой ("~🇷🇺RU:outbounds~" -> список тегов) это не
        // сделать безопасно: если нода недоступна и её нет в $byGeo, надо
        // УБРАТЬ элемент из массива, а не оставить битый текст — иначе
        // клиент получит невалидный JSON-конфиг и может отказаться
        // импортировать его целиком, не только эту группу. Поэтому
        // разбираем как массив. Плейсхолдер — "~{тег сервера}:outbounds~",
        // тег сервера — тот же, что уже виден в реальных outbound'ах
        // ({флаг}{код}, например "🇷🇺RU").
        if (!is_array($arr)) {
            return $arr;
        }
        $isList = array_keys($arr) === range(0, count($arr) - 1);
        $result = [];
        foreach ($arr as $k => $v) {
            if ($isList && is_string($v) && preg_match('/^~(.+):outbounds~$/u', $v, $m)) {
                foreach ($byGeo[$m[1]] ?? [] as $tag) {
                    $result[] = $tag;
                }
                continue;
            }
            $v = is_array($v) ? $this->expandGeoInArray($v, $byGeo) : $v;
            if ($isList) {
                $result[] = $v;
            } else {
                $result[$k] = $v;
            }
        }
        return $result;
    }

public function buildClashMultiOutbounds($c, $rawProxies, $servers, $outbound, $uid, $password)
    {
        // Тот же принцип, что и в buildSingMultiOutbounds(): протоколы matчатся
        // по type (не по имени — оно уже переименовано одноразовой коррекцией
        // origin-шаблона, correctClashOriginTags()), группа "Proxy" — по
        // фиксированному имени, не по алиасу $outbound. mihomo/clash не
        // поддерживает naive — в шаблоне его и нет. Отдельной "Auto"-группы
        // тут не нужно — сам type:fallback уже умеет health-check выбор.
        // Шаблоны — из $rawProxies (ДО filterMainOutbounds()), см. коммент в
        // buildSingMultiOutbounds() — иначе выключенный на Боте протокол
        // нечем клонировать для ноды, у которой он может быть включён.
        $protocols = [
            'vless'     => 'Vless',
            'hysteria2' => 'Hy2',
            'anytls'    => 'Anytls',
        ];
        $templates = [];
        foreach ($rawProxies as $p) {
            if (isset($protocols[$p['type'] ?? ''])) {
                $templates[$p['type']] = $p;
            }
        }
        $proxyIdx = null;
        foreach ($c['proxy-groups'] ?? [] as $k => $g) {
            if (($g['name'] ?? null) === 'Proxy') {
                $proxyIdx = $k;
                break;
            }
        }
        if ($proxyIdx === null) {
            return $c;
        }
        // Та же логика "упомянут где-то явно — не попадает в дефолт", что и
        // в buildSingMultiOutbounds() — см. коммент там.
        preg_match_all('/~(.+?):outbounds~/u', json_encode($c), $mClaimed);
        $claimed = array_unique($mClaimed[1] ?? []);

        $newNames   = [];
        $newProxies = [];
        $byGeo      = [];
        foreach ($servers as $s) {
            if (!empty($s['isMain'])) {
                // Уже корректный вид в самом шаблоне — просто регистрируем
                // для "~{тег}:outbounds~" в кастомных группах админа, повторно
                // не клонируем и не дублируем в proxies. Выключенный на Боте
                // протокол уже вырезан из $c (filterMainOutbounds()).
                foreach ($protocols as $type => $label) {
                    if (!empty($s['outboundsOff'][$type])) {
                        continue;
                    }
                    $byGeo[$s['tag']][] = "{$s['tag']}|{$label}";
                }
                continue;
            }
            $excluded = in_array($s['tag'], $claimed, true);
            foreach ($protocols as $type => $label) {
                if (!empty($s['outboundsOff'][$type])) {
                    continue;
                }
                $tpl = $templates[$type] ?? null;
                if (empty($tpl)) {
                    continue;
                }
                $name  = "{$s['tag']}|{$label}";
                $clone = json_decode($this->replaceTags(json_encode($tpl), [
                    '~domain~'        => $s['domain'],
                    '~uid~'           => $uid,
                    '~wspath~'        => "/ws{$s['hash']}",
                    '~anytls_domain~' => "{$s['anytlsSubdomain']}.{$s['domain']}",
                    '~password~'      => $password,
                ]), true);
                $clone['name'] = $name;
                $newProxies[]  = $clone;
                $byGeo[$s['tag']][] = $name;
                if (!$excluded) {
                    $newNames[] = $name;
                }
            }
        }
        if (!empty($newNames)) {
            $c['proxy-groups'][$proxyIdx]['proxies'] = array_merge($c['proxy-groups'][$proxyIdx]['proxies'], $newNames);
        }
        $c['proxies'] = array_merge($c['proxies'], $newProxies);
        return $this->expandGeoPlaceholders($c, $byGeo);
    }

public function buildXrayMultiOutbounds($c, $rawOutbounds, $servers, $uid)
    {
        // Нативного selector/urltest в xray-core нет — по договорённости
        // просто добавляем ноды как ещё outbound'ы в список, без auto-test.
        // Шаблон — из $rawOutbounds (ДО filterMainOutbounds()): если Vless
        // выключен у Бота, клонировать для ноды, где он может быть включён,
        // всё равно нужно из чего-то.
        $tpl = null;
        foreach ($rawOutbounds as $o) {
            if (($o['protocol'] ?? null) === 'vless' && isset($o['settings']['vnext'])) {
                $tpl = $o;
                break;
            }
        }
        if (empty($tpl)) {
            return $c;
        }
        foreach ($servers as $s) {
            if (!empty($s['isMain']) || !empty($s['outboundsOff']['vless'])) {
                continue;
            }
            $tag   = "{$s['tag']}|Vless";
            $clone = json_decode($this->replaceTags(json_encode($tpl), [
                '~domain~' => $s['domain'],
                '~uid~'    => $uid,
                '~wspath~' => "/ws{$s['hash']}",
            ]), true);
            $clone['tag']     = $tag;
            $c['outbounds'][] = $clone;
        }
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
                    $t[($v['outbound'] ?? null) ?: 'block'] = $k;
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

public function addDnsRuleSet($c)
    {
        // Правило route.rules с action:"reject" — это наш block (и встроенный
        // ~block~-ruleset из createRuleSet(), и любые внешние .srs, добавленные
        // через "Rulesset" с типом block, см. addRuleSet()) — оно всегда несёт
        // актуальный набор rule_set-тегов на момент сборки конфига. Просто зеркалим
        // этот же список в dns.rules, чтобы резолвинг для заблокированных доменов
        // не происходил вовсе (см. обсуждение: reject на 50+/30с уходит в тихий
        // drop, DNS-уровень этого избегает) — add/delete синхронизируются сами
        // собой, т.к. пересчитывается с нуля из route.rules при каждой сборке.
        // sing-box берёт первое совпавшее правило dns.rules по порядку — если
        // дописывать block в конец, его перехватывает более ранний catch-all
        // (например A/AAAA -> fakeip из шаблона) раньше, чем дело доходит до
        // блокировки, и она фактически не срабатывает. Поэтому block-правила
        // всегда идут ПЕРЕД всем, что уже есть в dns.rules, а не после.
        $blockRules = [];
        foreach ($c['route']['rules'] ?? [] as $v) {
            if (($v['action'] ?? null) === 'reject' && !empty($v['rule_set'])) {
                $blockRules[] = [
                    'rule_set' => $v['rule_set'],
                    'action'   => 'predefined',
                    'rcode'    => 'NOERROR',
                ];
            }
        }
        if (!empty($blockRules)) {
            $c['dns']['rules'] = array_merge($blockRules, $c['dns']['rules'] ?? []);
        }
        if (empty($c['dns']['rules'])) {
            unset($c['dns']['rules']);
        }
        return $c;
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
            'version' => 5,
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
        $route['rule_set'] = array_merge(($route['rule_set'] ?? null) ?: [], $ruleset ?: []);
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

        $p['reality']['domain']      = ($p['reality']['domain'] ?? null) ?: 'yandex.ru';
        $p['reality']['destination'] = ($p['reality']['destination'] ?? null) ?: $p['reality']['domain'] . ':443';
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
                        "dest"         => ($p['reality']['destination'] ?? null) ?: $x['inbounds'][0]['streamSettings']['realitySettings']['dest'],
                        "maxClientVer" => "",
                        "maxTimeDiff"  => 0,
                        "minClientVer" => "",
                        "privateKey"   => $p['reality']['privateKey'],
                        "serverNames"  => [
                            ($p['reality']['domain'] ?? null) ?: $x['inbounds'][0]['streamSettings']['realitySettings']['serverNames'][0]
                        ],
                        "shortIds" => [($p['reality']['shortId'] ?? null) ?: $x['inbounds'][0]['streamSettings']['realitySettings']['shortIds'][0]],
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
            ? (($p['reality']['domain'] ?? null) ?: $x['inbounds'][0]['streamSettings']['realitySettings']['serverNames'][0])
            : 't'
        );
        $this->setPacConf($p);
        $this->restartSingbox($x);
        $this->singbox();
    }
}

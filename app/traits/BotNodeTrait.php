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

public function nodesMenu()
    {
        $text[] = "Menu -> " . $this->i18n('nodes');
        $nodes  = $this->getNodes();
        if (empty($nodes)) {
            $text[] = $this->i18n('no nodes yet');
        }
        $data = [];
        foreach ($nodes as $id => $node) {
            $dot = !empty($node['off']) ? '⚪' : ($this->nodeIsOnline($node['ip']) ? '🟢' : '🔴');
            $data[] = [
                [
                    'text'          => "$dot {$node['label']}",
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
        $dot    = $off ? '⚪' : ($online ? '🟢' : '🔴');
        $status = $off ? $this->i18n('node off') : ($online ? $this->i18n('node online') : $this->i18n('node offline'));
        $text[] = "Menu -> " . $this->i18n('nodes') . " -> {$node['label']}";
        $text[] = "IP: {$node['ip']}";
        $text[] = "$dot $status";
        $data   = [
            [
                [
                    'text'          => "{$this->i18n('Domains')} & {$this->i18n('Ports')}",
                    'callback_data' => "/nodeDomains $id",
                ],
                [
                    'text'          => $this->i18n('mtproto'),
                    'callback_data' => "/nodeMtproto $id",
                ],
            ],
            [
                [
                    'text'          => 'Stats',
                    'callback_data' => "/nodeStats $id",
                ],
                [
                    'text'          => $this->i18n('restart'),
                    'callback_data' => "/nodeRestart $id",
                ],
            ],
            [
                [
                    'text'          => $this->i18n('update'),
                    'callback_data' => "/nodeUpdate $id",
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
                    'text'          => $off ? $this->i18n('turn on') : $this->i18n('turn off'),
                    'callback_data' => "/nodeToggleOff $id",
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
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $data);
    }

public function nodeStopSingbox($ip)
    {
        $this->ssh('pkill sing-box', 'sbx', true, '/dev/null', $ip);
    }

public function nodeStartSingbox($ip)
    {
        $this->ssh('pkill sing-box || sing-box run -c /sing.json', 'sbx', false, '/logs/singbox', $ip);
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
            $this->ssh('pkill mtproto-proxy', 'tg', true, '/dev/null', $node['ip']);
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

public function nodeDomains($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $pac  = $this->nodeConsole($node['ip'], 'getPacConf') ?: [];
        $cert = $this->nodeConsole($node['ip'], 'nginxGetTypeCert');

        $text[] = "Menu -> " . $this->i18n('nodes') . " -> {$node['label']} -> " . $this->i18n('Domains') . '/' . $this->i18n('Ports');
        if (!empty($pac['domain'])) {
            $text[] = "Domain: {$pac['domain']}";
            if (!empty($pac['naiveSubdomain'])) {
                $text[] = "Naive: {$pac['naiveSubdomain']}.{$pac['domain']}";
            }
            if (!empty($pac['anytlsSubdomain'])) {
                $text[] = "Anytls: {$pac['anytlsSubdomain']}.{$pac['domain']}";
            }
            $text[] = "SSL: " . ($cert ?: $this->i18n('not configured'));
        }

        $data = [
            [
                [
                    'text'          => !empty($pac['domain']) ? "{$this->i18n('delete')} {$pac['domain']}" : $this->i18n('install domain'),
                    'callback_data' => !empty($pac['domain']) ? "/nodeDelDomain $id" : "/nodeSetDomainDialog $id",
                ],
            ],
        ];
        if (empty($pac['domain'])) {
            $data[0][] = [
                'text'          => $this->i18n('nip.io'),
                'callback_data' => "/nodeAddNip $id",
            ];
        }
        if (!empty($pac['domain']) && empty($cert)) {
            $data[] = [
                [
                    'text'          => $this->i18n('Letsencrypt SSL'),
                    'callback_data' => "/nodeIssueSSL $id",
                ],
            ];
        }
        $data[] = [
            [
                'text'          => "MTProto {$this->i18n('Ports')}",
                'callback_data' => "/nodePortsDialog $id",
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
        $pac  = $this->nodeConsole($node['ip'], 'getPacConf') ?: [];
        $cert = $this->nodeConsole($node['ip'], 'nginxGetTypeCert');
        $hash = $this->nodeConsole($node['ip'], 'getHashBot');
        $conf = $this->getPacConf();
        $conf['nodes'][$id]['domain']          = $pac['domain'] ?? null;
        $conf['nodes'][$id]['naiveSubdomain']  = $pac['naiveSubdomain'] ?? null;
        $conf['nodes'][$id]['anytlsSubdomain'] = $pac['anytlsSubdomain'] ?? null;
        $conf['nodes'][$id]['hash']            = $hash ?: null;
        $conf['nodes'][$id]['cert']            = $cert ?: null;
        $this->setPacConf($conf);
    }

public function nodeSetDomain($domain, $id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        // addDomain() на ноде сам пытается прислать DNS-уведомление, но там
        // нет чата (console.php — не telegram-контекст) — шлём его сами, от
        // main, у которого чат есть.
        $this->nodeConsole($node['ip'], 'addDomain', trim($domain), '1');
        $this->nodeCacheDomain($id);
        $node = $this->getNode($id);
        if (!empty($node['domain']) && !preg_match('~^\d{1,3}-\d{1,3}-\d{1,3}-\d{1,3}\.nip\.io$~', $node['domain'])) {
            $hosts = "{$node['domain']}, {$node['naiveSubdomain']}.{$node['domain']}, {$node['anytlsSubdomain']}.{$node['domain']}";
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
        $this->send($this->input['chat'], "{$this->i18n('restart')}?");
        $this->nodeDomains($id);
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
        // Тот же механизм, что и make r/restart() на главном: пишем в
        // /update/pipe, а уже поднятый на ноде update.sh (стартует автоматом
        // с make u при провижининге) сам делает down+up на своём хосте.
        $this->ssh('echo 2 > ~/sbbot/update/pipe', null, true, '/dev/null', $node['ip']);
        $this->send($this->input['chat'], "{$node['label']}: {$this->i18n('restarting')}");
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
        $this->ssh('echo 1 > ~/sbbot/update/pipe', null, true, '/dev/null', $node['ip']);
        $this->send($this->input['chat'], "{$node['label']}: {$this->i18n('node updating')}");
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
        // Данные ноды на этом шаге ещё не подтверждены (доступ не проверен) —
        // держим их во временной записи, а не в conf['nodes'], пока bootstrap
        // по одному из двух способов ниже не пройдёт успешно.
        $tmpId = bin2hex(random_bytes(4));
        $conf  = $this->getPacConf();
        $conf['nodesPending'][$tmpId] = ['label' => $label, 'ip' => trim($ip)];
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

public function nodeAuthPassword($tmpId)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} " . $this->i18n('key is safer warning') . "\n" . $this->i18n('enter node password'),
            $this->input['message_id'],
            reply: $this->i18n('enter node password'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'addNodePassword',
            'args'          => [$tmpId],
        ];
    }

public function nodeAuthKey($tmpId)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} " . $this->i18n('send ssh key file or text'),
            $this->input['message_id'],
            reply: $this->i18n('send ssh key file or text'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'addNodeKey',
            'args'          => [$tmpId],
        ];
    }

public function addNodePassword($password, $tmpId)
    {
        $this->finishAddNode($tmpId, 'password', trim($password));
    }

public function addNodeKey($text, $tmpId)
    {
        // Некоторые генераторы ключей отдают их как текст для копипаста, а не
        // файл — поддерживаем оба варианта одним обработчиком.
        if (!empty($this->input['file_id'])) {
            $r   = $this->request('getFile', ['file_id' => $this->input['file_id']]);
            $key = file_get_contents($this->file . $r['result']['file_path']);
        } else {
            $key = $text;
        }
        $this->finishAddNode($tmpId, 'key', $key);
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
            'label'  => $pending['label'],
            'ip'     => $pending['ip'],
            'login'  => $login,
            'geoTag' => $geoTag,
        ];
        $this->setPacConf($conf);

        // Доступ есть, но sbbot на ноде ещё не установлен — гоняем init.sh
        // (тот же скрипт, что и вручную на голом сервере) удалённо. Не ждём
        // синхронно: apt/docker/git clone/make u — это минуты, а не секунды,
        // $wait=false фонит команду на хосте ноды через nohup (см. ssh()).
        // Ключ — случайный, не настоящий токен бота (нода не должна его знать).
        $nodeKey = bin2hex(random_bytes(16));
        $this->ssh(
            "curl -fsSL https://raw.githubusercontent.com/ndwrd/sbbot/main/scripts/init.sh | bash -s $nodeKey main node",
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

public function checkNodeProvisioning()
    {
        $conf   = $this->getPacConf();
        $changed = false;
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
                unset($conf['nodes'][$id]['provisioning']);
                $changed = true;
            } elseif ($elapsed > 900) {
                $this->update($p['chat'], $p['messageId'], "{$this->i18n('node added')}: {$node['label']} — ⚠️ {$this->i18n('node install timeout')}");
                unset($conf['nodes'][$id]['provisioning']);
                $changed = true;
            } elseif (time() - $p['lastPing'] >= 30) {
                $min = (int) floor($elapsed / 60);
                $this->update($p['chat'], $p['messageId'], "{$this->i18n('node added')}: {$node['label']} — ⏳ {$this->i18n('node install in progress')} {$min} " . $this->i18n('min'));
                $conf['nodes'][$id]['provisioning']['lastPing'] = time();
                $changed = true;
            }
        }
        if ($changed) {
            $this->setPacConf($conf);
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
        if (!empty($pac['geoTag'])) {
            return $pac['geoTag'];
        }
        $tag = $this->assignGeoTag($this->geoCountryCode($this->ip) ?: 'XX');
        $pac['geoTag'] = $tag;
        $this->setPacConf($pac);
        return $tag;
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

public function nodeMtprotoMenu($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            $this->nodeMenu($id);
            return;
        }
        $secret     = $node['mtprotosecret'] ?? '';
        $fakedomain = $node['mtprotodomain'] ?? 'yandex.ru';
        $st         = $this->ssh('pgrep mtproto-proxy', 'tg', true, '/dev/null', $node['ip']) ? 'on' : 'off';

        $text[] = "Menu -> " . $this->i18n('nodes') . " -> {$node['label']} -> MTProto";
        $text[] = "status: $st";
        $text[] = "fake domain: <code>$fakedomain</code>";
        if ($st == 'on' && !empty($secret)) {
            $text[] = $this->nodeLinkMtproto($id);
        }
        $data[] = [
            [
                'text'          => $this->i18n('generateSecret'),
                'callback_data' => "/nodeGenerateSecret $id",
            ],
            [
                'text'          => $this->i18n('setSecret'),
                'callback_data' => "/nodeSetSecret $id",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('changeFakeDomain'),
                'callback_data' => "/nodeChangeTGDomain $id",
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

public function nodeGenerateSecret($id)
    {
        $node = $this->getNode($id);
        if (empty($node)) {
            return;
        }
        $node['mtprotosecret'] = exec('head -c 16 /dev/urandom | xxd -ps');
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
        $this->ssh('pkill mtproto-proxy', 'tg', true, '/dev/null', $node['ip']);
        if (preg_match('~^\w{32}$~', $secret)) {
            $this->ssh("mtproto-proxy --domain $fakedomain -u nobody -H 443 --nat-info 10.10.0.8:{$node['ip']} -S $secret --aes-pwd /proxy-secret /proxy-multi.conf -M 1", 'tg', false, '/logs/mtproto', $node['ip']);
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
        $data[] = [
            [
                'text'          => $this->i18n('clean all'),
                'callback_data' => "/nodeCleanLog $id",
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
        $conf = $this->getPacConf();
        foreach ($conf['nodes'] ?? [] as $id => $node) {
            $conf['nodes'][$id]['usersSynced'] = false;
        }
        $this->setPacConf($conf);
        foreach (array_keys($conf['nodes'] ?? []) as $id) {
            $this->nodeSyncUsersSilent($id);
        }
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
        $pac     = $this->getPacConf();
        $payload = json_encode([
            'singboxClients' => $pac['singboxClients'] ?? [],
            'transport'      => $pac['transport'] ?? 'Websocket',
            'reality'        => $pac['reality'] ?? [],
        ]);
        $ok = $this->nodeConsole($node['ip'], 'applyUsers', $payload) === 'ok';
        if ($ok) {
            $conf = $this->getPacConf();
            $conf['nodes'][$id]['usersSynced'] = true;
            $this->setPacConf($conf);
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
        $this->setPacConf($pac);
        $sing = $this->buildSingboxConfig($pac);
        file_put_contents('/config/sing-server.json', json_encode($sing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->ssh('pkill -HUP sing-box || sing-box run -c /sing.json', 'sbx', false);
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
        $d  = exec("echo $d | tr -d '\\n' | xxd -ps -c 200");
        return "https://t.me/proxy?server={$node['ip']}&port=443&secret=ee$s$d";
    }
}

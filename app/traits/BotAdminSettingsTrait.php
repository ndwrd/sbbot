<?php

trait BotAdminSettingsTrait
{
public function addOverrideHtml()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} attach html",
            $this->input['message_id'],
            reply: 'attach html',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'setOverrideHtml',
            'args'           => [],
        ];
    }

public function setOverrideHtml()
    {
        $r = $this->request('getFile', ['file_id' => $this->input['file_id']]);
        if (!empty($f = file_get_contents($this->file . $r['result']['file_path']))) {
            file_put_contents('/app/webapp/override.html', $f);
        }
    }

public function importList($type)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} send the export file:",
            $this->input['message_id'],
            reply: 'send the export file:',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'importListFile',
            'args'           => [$type],
        ];
    }

public function importListFile($message, $type)
    {
        $r = $this->request('getFile', ['file_id' => $this->input['file_id']]);
        $f = file_get_contents($this->file . $r['result']['file_path']);
        if (!empty($f)) {
            foreach (explode("\n", $f) as $v) {
                if (!empty($s = trim($v))) {
                    $t = explode(';', $s);
                    if ($type == 'rulessetlist') {
                        if (preg_match('~^.+:.+:https?://.+~', $t[0])) {
                            $list[$t[0]] = (bool) $t[1];
                        }
                    } else {
                        $list[$t[0]] = (bool) $t[1];
                    }
                }
            }
            $p = $this->getPacConf();
            $p[$type] = $list;
            $this->setPacConf($p);
        }
        $this->backXtlsList($type);
    }

public function checkBackup()
    {
        // Решение "пора" и отметка времени — одной атомарной транзакцией, а не
        // getPacConf()/setPacConf() вокруг них: отметка тут работает как
        // "заявка" (чтобы бэкап не сделался дважды), а она этого не гарантирует,
        // если между чтением и записью влезет чужая запись всего конфига. Сам
        // pinBackup() — уже снаружи блокировки, он долгий.
        $due = false;
        $this->updatePacConf(function ($c) use (&$due) {
            if (empty($c['backup'])) {
                return null;
            }
            $now = time();
            [$start, $period] = explode('/', $c['backup']);
            $start  = strtotime(trim($start));
            $period = strtotime(trim($period), 0);
            if (empty($start) || empty($period) || $now < $start) {
                return null;
            }
            $lastScheduledBackup = $start + (floor(($now - $start) / $period) * $period);
            if (($c['last_backup_time'] ?? 0) >= $lastScheduledBackup) {
                return null;
            }
            $c['last_backup_time'] = $now;
            $due = true;
            return $c;
        });
        if ($due) {
            $this->pinBackup();
        }
    }

public function pinAdmin($pin, $unpin = false)
    {
        require dirname(__DIR__) . '/config.php';
        if ($unpin) {
            return $this->unpin($c['admin'][0], $pin);
        } else {
            return $this->pin($c['admin'][0], $pin);
        }
    }

public function pinBackup($file = false)
    {
        require dirname(__DIR__) . '/config.php';
        $conf = $this->getPacConf();
        $bot  = preg_replace('~[\W]~iu', '_', $this->request('getMyName', [])['result']['name']);
        $json = $this->export();
        if (!empty($file)) {
            file_put_contents($file, $json);
        }
        if (!empty($conf['pinbackup'])) {
            $this->pinAdmin($conf['pinbackup'], 1);
        }
        $conf['pinbackup'] = $this->upload("{$bot}_export_" . date('d_m_Y_H_i') . '.json', $json, $c['admin'][0])['result']['message_id'];
        $this->setPacConf($conf);
        $this->pinAdmin($conf['pinbackup']);
    }

public function originConfigFiles()
    {
        // Конфиги, которые правит админ, но которые лежат файлами, а не записями
        // в pac.json — то есть единственное, что не переносилось бэкапом.
        //
        //   xray/sing/clash — origin-шаблоны клиентских конфигов (кнопка Origin
        //     в меню шаблонов, saveTemplate('origin', ...)). Именованные
        //     шаблоны хранятся в pac["{type}templates"] и ездили и раньше,
        //     а origin — нет, и после восстановления на чистый сервер он
        //     приезжал из репозитория в исходном виде.
        //   sing-server — серверный конфиг. Его log/dns и дописанные руками
        //     outbounds/route читаются как база при каждом restartSingbox()
        //     (см. buildSingboxConfig()), то есть это тоже правки админа, а не
        //     производные данные; производную часть restartSingbox() всё равно
        //     пересоберёт поверх.
        //
        // Список заодно работает белым списком при восстановлении: файл бэкапа
        // загружает пользователь, и без него произвольный ключ в JSON означал бы
        // запись произвольного /config/<что угодно>.json.
        return ['xray', 'sing', 'clash', 'sing-server'];
    }

public function export()
    {
        $origin = [];
        foreach ($this->originConfigFiles() as $t) {
            $j = json_decode(@file_get_contents("/config/$t.json") ?: '', true);
            if (is_array($j)) {
                $origin[$t] = $j;
            }
        }
        $conf = [
            'pac'    => $this->getPacConf(),
            'origin' => $origin ?: false,
            'ssl' => file_exists('/certs/cert_private') && preg_match('~BEGIN PRIVATE KEY~', file_get_contents('/certs/cert_private')) ? [
                'private' => file_get_contents('/certs/cert_private'),
                'public'  => file_get_contents('/certs/cert_public'),
            ] : false,
            'dnstt' => file_exists('/config/dnstt/server.key') ? [
                'private' => file_get_contents('/config/dnstt/server.key'),
                'public'  => file_get_contents('/config/dnstt/server.pub'),
            ] : false,
            'mtproto'       => @file_get_contents('/config/mtprotosecret') ?: '',
            'mtprotodomain' => @file_get_contents('/config/mtprotodomain') ?: '',
            'mtprotoadtag'  => file_exists('/config/mtprotoadtag') ? file_get_contents('/config/mtprotoadtag') : '',
            'singbox'       => $this->getSingbox(),
        ];
        return json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

public function import()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} send the export file:",
            $this->input['message_id'],
            reply: 'send the export file:',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'importFile',
            'args'           => [],
        ];
    }

public function importFile($file = false)
    {
        if (!empty($file)) {
            $json = json_decode(file_get_contents($file), true);
        } else {
            $r    = $this->request('getFile', ['file_id' => $this->input['file_id']]);
            $json = json_decode(file_get_contents($this->file . $r['result']['file_path']), true);
        }
        if (empty($json) || !is_array($json)) {
            $this->answer($this->input['callback_id'], 'error', true);
        } else {
            if (!empty($json['ssl'])) {
                $out[] = 'update certificates';
                $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
                file_put_contents('/certs/cert_private', $json['ssl']['private']);
                file_put_contents('/certs/cert_public', $json['ssl']['public']);
            }
            $domainMigrated = false;
            if (!empty($json['pac'])) {
                $out[] = 'update pac';
                $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
                $domainMigrated = $this->reconcileDomainForNewServer($json['pac']);
                $this->setPacConf($json['pac']);
            }
            if (!empty($json['mtproto'])) {
                $out[] = 'update mtproto';
                $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
                file_put_contents('/config/mtprotosecret', $json['mtproto']);
                file_put_contents('/config/mtprotodomain', ($json['mtprotodomain'] ?? null) ?: '');
                file_put_contents('/config/mtprotoadtag', trim($json['mtprotoadtag'] ?? ''));
                $this->restartTG();
            }
            // Строго ДО блока 'singbox': restartSingbox() ниже читает
            // /config/sing-server.json как базу для сборки серверного конфига,
            // и к этому моменту там уже должен лежать наш файл, а не исходный
            // из репозитория. Ключи берём из originConfigFiles(), а не из
            // самого $json — файл бэкапа загружает пользователь, и без белого
            // списка произвольный ключ означал бы запись произвольного
            // /config/<что угодно>.json.
            if (!empty($json['origin']) && is_array($json['origin'])) {
                $out[] = 'update origin templates';
                $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
                foreach ($this->originConfigFiles() as $t) {
                    if (!empty($json['origin'][$t]) && is_array($json['origin'][$t])) {
                        file_put_contents("/config/$t.json", json_encode($json['origin'][$t], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    }
                }
            }
            if (!empty($json['singbox'])) {
                $out[] = 'update singbox';
                $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
                $this->restartSingbox($json['singbox']);
                $this->setUpstreamDomain(($json['pac']['transport'] ?? null) != 'Reality' ? 't' : (($json['pac']['reality']['domain'] ?? null) ?: ($json['singbox']['inbounds'][0]['streamSettings']['realitySettings']['serverNames'][0] ?? '')));
            }
            if (!empty($json['dnstt'])) {
                $out[] = 'update dnstt certificates';
                $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
                file_put_contents('/config/dnstt/server.key', $json['dnstt']['private']);
                file_put_contents('/config/dnstt/server.pub', $json['dnstt']['public']);
            }
            // domain migrated to this server's IP (nip.io) — old cert doesn't match
            // the new domain string, letsencrypt needs to be reissued for it
            if ($domainMigrated) {
                $out[] = 'reissue SSL for new domain';
                $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
                $this->setSSL('letsencrypt');
            }
            $out[] = 'reset nginx';
            $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));

            $this->cloakNginx();

            $out[] = "end import";
            $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
            $this->language = ($this->getPacConf()['language'] ?? null) ?: 'en';
            $this->limit    = ($this->getPacConf()['limitpage'] ?? null) ?: 5;
            if (empty($file)) {
                sleep(3);
                $this->menu();
            }
        }
    }

public function enterAdmin()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter id",
            $this->input['message_id'],
            reply: 'enter id',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'addAdmin',
            'args'          => [],
        ];
    }

public function changePort($container)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} number port",
            $this->input['message_id'],
            reply: 'number port',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'setPort',
            'args'          => [$container],
        ];
    }

public function addAdmin($id)
    {
        $file = dirname(__DIR__) . '/config.php';
        require $file;
        $c['admin'][] = $id;
        file_put_contents($file, "<?php\n\n\$c = " . var_export($c, true) . ";\n");
        $this->menu('config');
    }

public function delAdmin($id)
    {
        $file = dirname(__DIR__) . '/config.php';
        require $file;
        unset($c['admin'][array_search($id, $c['admin'])]);
        file_put_contents($file, "<?php\n\n\$c = " . var_export($c, true) . ";\n");
        $this->menu('config');
    }

public function getPorts()
    {
        $f = '/docker/compose';
        $c =  yaml_parse_file($f);
        $r = [];
        foreach ($this->ports as $k => $v) {
            $r[$k] = [
                'port'   => !empty($c['services'][$k]['ports']) ? explode(':', $c['services'][$k]['ports'][0])[0] : explode('/', $v)[0],
                'enable' => !empty($c['services'][$k]['ports']),
            ];
        }
        return $r;
    }

public function menuLang()
    {
        $data = [];
        $lang = [];
        foreach ($this->i18n as $k => $v) {
            $lang = array_merge($lang, array_keys($v));
        }
        $lang = array_unique($lang);
        foreach ($lang as $v) {
            if ($v != $this->language) {
                $data[] = [
                    [
                        'text'          => $v,
                        'callback_data' => "/lang $v",
                    ],
                ];
            }
        }
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/menu config",
            ],
        ];
        return [
            'text' => 'Language',
            'data' => $data,
        ];
    }

public function applyupdatebot()
    {
        $this->pinBackup($this->update);
        $r = $this->sendDraft($this->input['from'], 1, 'update...');
        file_put_contents('/update/reload_message', "{$this->input['from']}:{$r['result']['message_id']}");
        file_put_contents('/update/key', $this->key);
        file_put_contents('/update/curl', json_encode([
            'chat_id'    => $this->input['chat'],
            'message_id' => $r['result']['message_id'],
            'text'       => '~t~'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        file_put_contents('/update/pipe', '1');
        $this->delete($this->input['from'], $this->input['message_id']);
    }

public function restart()
    {
        $r = $this->sendDraft($this->input['from'], 1, 'restart...');
        file_put_contents('/update/reload_message', "{$this->input['from']}:{$r['result']['message_id']}");
        file_put_contents('/update/key', $this->key);
        file_put_contents('/update/curl', json_encode([
            'chat_id'    => $this->input['chat'],
            'message_id' => $r['result']['message_id'],
            'text'       => '~t~'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        file_put_contents('/update/pipe', '2');
        $this->delete($this->input['from'], $this->input['message_id']);
    }

public function domainsMenu()
    {
        $conf = $this->getPacConf();
        $cert = $this->nginxGetTypeCert();
        if (!empty($conf['domain'])) {
            $ssl_expiry = $this->expireCert();
            $certs      = $this->domainsCert() ?: [];

            $text[] = "<blockquote>";
            $text[] = "Domains:";
            $text[] = "General: {$conf['domain']}";
            if (!empty($conf['naiveSubdomain'])) {
                $text[] = "Naive: {$conf['naiveSubdomain']}.{$conf['domain']}";
            }
            if (!empty($conf['anytlsSubdomain'])) {
                $text[] = "Anytls: {$conf['anytlsSubdomain']}.{$conf['domain']}";
            }
            if (in_array($conf['domain'], $certs)) {
                $text[] = "SSL: " . date('Y-m-d H:i:s', $ssl_expiry);
            }
            $text[] = "</blockquote>";
        }

        $data = [
            [
                [
                    'text'          => $conf['domain'] ? "{$this->i18n('delete')} {$conf['domain']}" : $this->i18n('install domain'),
                    'callback_data' => $conf['domain'] ? '/deldomain' : '/domain',
                ],
                [
                    'text'          => $this->i18n('nip.io'),
                    'callback_data' => '/addNipdomain',
                ],
            ],
        ];
        if ($conf['domain']) {
            if ($cert) {
                switch ($cert) {
                    case 'letsencrypt':
                        $data[] = [
                            [
                                'text'          => $this->i18n('renew SSL'),
                                'callback_data' => "/setSSL letsencrypt",
                            ],
                            [
                                'text'          => $this->i18n('delete SSL'),
                                'callback_data' => "/deletessl",
                            ],
                        ];
                        break;
                    case 'self':
                        $data[] = [
                            [
                                'text'          => $this->i18n('delete SSL'),
                                'callback_data' => "/deletessl",
                            ],
                        ];
                        break;
                }
            } else {
                $data[] = [
                    [
                        'text'          => $this->i18n('Letsencrypt SSL'),
                        'callback_data' => "/setSSL letsencrypt",
                    ],
                    [
                        'text'          => $this->i18n('Self SSL'),
                        'callback_data' => "/selfssl",
                    ],
                ];
            }
        }
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/menu config",
            ],
        ];
        return [
            'text' => implode("\n", $text),
            'data' => $data,
        ];
    }

public function configMenu()
    {
        $conf   = $this->getPacConf();
        $text[] = "Menu -> " . $this->i18n('config');

        $data = [
            [
                [
                    'text'          => $this->i18n('Domains'),
                    'callback_data' => "/menu domains",
                ],
                [
                    'text'          => $this->i18n('Ports'),
                    'callback_data' => "/ports",
                ],
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('logs'),
                'callback_data' => "/logs",
            ],
            [
                'text'          => "{$this->i18n('page')}: " . (($conf['limitpage'] ?? null) ?: 5),
                'callback_data' => "/enterPage",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('export'),
                'callback_data' => "/export",
            ],
            [
                'text'          => $this->i18n('import'),
                'callback_data' => "/import",
            ],
        ];
        $backup = array_filter(explode('/', $conf['backup'] ?? ''));
        if (!empty($backup)) {
            if (!empty(strtotime($backup[0])) && !empty(strtotime($backup[1]))) {
                $backup = "{$backup[0]} start / {$backup[1]} period";
            } else {
                $backup = $this->i18n('off') . " {$conf['backup']} - wrong format";
            }
        }
        $data[] = [
            [
                'text'          => $this->i18n('backup') . ': ' . ($backup ?: $this->i18n('off')),
                'callback_data' => "/backup",
            ],
            [
                'text'          => $this->i18n('autoupdate') . ': ' .  $this->i18n(!empty($conf['autoupdate']) ? 'on' : 'off'),
                'callback_data' => "/autoupdate",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('lang'),
                'callback_data' => "/menu lang",
            ],
            [
                'text'          => $this->i18n('restart'),
                'callback_data' => "/restart",
            ],
        ];
        $file = dirname(__DIR__) . '/config.php';
        opcache_invalidate($file);
        require $file;
        // Админов может не быть: список заводится в auth() при первом /start.
        // Кнопка "удалить" тогда просто не рисуется — раньше на этом месте было
        // не предупреждение, а падение: array_slice(null, 1) бросает TypeError,
        // и всё меню конфигурации не открывалось.
        $admins = array_values((array) ($c['admin'] ?? []));
        $row    = [
            [
                'text'          => "{$this->i18n('add')} {$this->i18n('admin')}",
                'callback_data' => "/addadmin",
            ],
        ];
        if (isset($admins[0])) {
            $row[] = [
                'text'          => $this->i18n('delete') . " {$admins[0]}",
                'callback_data' => "/deladmin {$admins[0]}",
            ];
        }
        $data[] = $row;
        foreach (array_slice($admins, 1) as $v) {
            $data[] = [
                [
                    'text'          => $this->i18n('delete') . " $v",
                    'callback_data' => "/deladmin $v",
                ],
            ];
        }
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

public function ports()
    {
        $text[] = 'Settings -> Ports';
        $f      = '/docker/compose';
        $c      = yaml_parse_file($f)['services'];
        $pac = $this->getPacConf();
        $data   = [
            [[
                'text'          => $this->i18n($c['tg'] ? 'on' : 'off') . ' ' . explode(':', $c['tg']['ports'][0])[0] . ' MTProto ',
                'callback_data' => "/changePort tg",
            ]],
            [[
                'text'          => $this->i18n($c['dnstt'] ? 'on' : 'off') . ' 53 dnstt',
                'callback_data' => "/hidePort dnstt",
            ]],
        ];
        if (!empty($pac['restart'])) {
            $data[] = [
                [
                    'text'          => $this->i18n('restart'),
                    'callback_data' => "/restart",
                ],
            ];
        }
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/menu config",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

public function hidePort($container)
    {
        $ports = [
            'dnstt' => '53:53/udp',
        ];
        $f = '/docker/compose';
        $content = file_exists($f) ? file_get_contents($f) : '';

        $overrides = [];
        if (preg_match_all('/(\w+):\s*\n\s+ports:\s*!override/m', $content, $matches)) {
            foreach ($matches[1] as $service) {
                $overrides[$service] = true;
            }
        }

        $c = $content ? yaml_parse($content) : [];

        if (!empty($c['services'][$container])) {
            unset($c['services'][$container]);
        } else {
            $c['services'][$container]['ports'][] = $ports[$container];
        }

        if (empty($c['services'])) {
            file_put_contents($f, '');
        } else {
            $yaml = yaml_emit($c);
            foreach ($overrides as $service => $val) {
                $yaml = preg_replace(
                    '/(' . preg_quote($service, '/') . ':\s*\n\s+)ports:/m',
                    '${1}ports: !override',
                    $yaml
                );
            }
            file_put_contents($f, $yaml);
        }

        $pac = $this->getPacConf();
        $pac['restart'] = 1;
        $this->setPacConf($pac);
        $this->ports();
    }

public function setPort($port, $container)
    {
        $port  = (int) $port;
        $ports = $this->ports;
        $f = '/docker/compose';
        $content = file_exists($f) ? file_get_contents($f) : '';

        $overrides = [];
        if (preg_match_all('/(\w+):\s*\n\s+ports:\s*!override/m', $content, $matches)) {
            foreach ($matches[1] as $service) {
                $overrides[$service] = true;
            }
        }

        $c = $content ? yaml_parse($content) : [];

        if (!empty($port) && is_numeric($port) && $port != 443 && $port != 80) {
            $c['services'][$container]['ports'] = ["$port:$ports[$container]"];
        } else {
            unset($c['services'][$container]);
        }

        if (empty($c['services'])) {
            file_put_contents($f, '');
        } else {
            $yaml = yaml_emit($c);
            foreach ($overrides as $service => $val) {
                $yaml = preg_replace(
                    '/(' . preg_quote($service, '/') . ':\s*\n\s+)ports:/m',
                    '${1}ports: !override',
                    $yaml
                );
            }
            file_put_contents($f, $yaml);
        }

        $pac = $this->getPacConf();
        $pac['restart'] = 1;
        $this->setPacConf($pac);
        $this->ports();
    }

public function logs()
    {
        $p = $this->getPacConf();
        foreach (scandir('/logs/') as $k => $v) {
            if (!preg_match('~^\.~', $v)) {
                $size   = filesize("/logs/$v");
                $data[] = [
                    [
                        'text'          => "$size $v",
                        'callback_data' => "/getLog $k",
                    ],
                    [
                        'text'          => $this->i18n('clean'),
                        'callback_data' => "/clearLog $k",
                    ],
                ];
            }
        }
        $data[] = [
            [
                'text'          => $this->i18n('clean all'),
                'callback_data' => "/cleanLog",
            ],
        ];
        $autocleanlogs = array_filter(explode('/', $p['autocleanlogs']));
        if (!empty($autocleanlogs)) {
            if (!empty(strtotime($autocleanlogs[0])) && !empty(strtotime($autocleanlogs[1]))) {
                $autocleanlogs = "{$autocleanlogs[0]} start / {$autocleanlogs[1]} period";
            } else {
                $autocleanlogs = $this->i18n('off') . " {$p['autocleanlogs']} - wrong format";
            }
        }
        $data[] = [
            [
                'text'          => $this->i18n('autoclean'). ': ' . ($autocleanlogs ?: $this->i18n('off')),
                'callback_data' => "/autoCleanLogs",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/menu config",
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", ['...']),
            $data ?: false,
        );
    }

public function getLog($i)
    {
        foreach (scandir('/logs/') as $k => $v) {
            if (!preg_match('~^\.~', $v)) {
                $logs[$k] = $v;
            }
        }
        $this->sendFile(
            $this->input['chat'],
            curl_file_create("/logs/{$logs[$i]}"),
        );
    }

public function clearLog($i)
    {
        foreach (scandir('/logs/') as $k => $v) {
            if ($i == $k) {
                file_put_contents("/logs/$v", '');
                break;
            }
        }
        $this->logs();
    }

public function cleanLog()
    {
        foreach (scandir('/logs/') as $k => $v) {
            file_put_contents("/logs/$v", '');
        }
        $this->logs();
    }

public function checkLogs()
    {
        // Решение + отметка времени атомарно, сама чистка — снаружи блокировки.
        // См. checkBackup().
        $due = false;
        $this->updatePacConf(function ($c) use (&$due) {
            if (empty($c['autocleanlogs'])) {
                return null;
            }
            $now = time();
            [$start, $period] = explode('/', $c['autocleanlogs']);
            $start  = strtotime(trim($start));
            $period = strtotime(trim($period), 0);
            if (empty($start) || empty($period) || $now < $start) {
                return null;
            }
            $lastScheduledClean = $start + (floor(($now - $start) / $period) * $period);
            if (($c['last_clean_logs_time'] ?? 0) >= $lastScheduledClean) {
                return null;
            }
            $c['last_clean_logs_time'] = $now;
            $due = true;
            return $c;
        });
        if ($due) {
            $this->cleanLog();
        }
    }

public function delLog($i)
    {
        foreach (scandir('/logs/') as $k => $v) {
            if ($i == $k) {
                unlink("/logs/$v");
                break;
            }
        }
        $this->logs();
    }

public function selfUpdate()
    {
        $ip                         = getenv('IP');
        // Файлов нет, пока обновление ни разу не запускалось (их создаёт
        // update.sh) — на свежей установке это два warning'а на каждый старт.
        $rm                         = explode(':', trim(@file_get_contents('/update/reload_message') ?: ''));
        $m                          = @file_get_contents('/update/message') ?: '';
        $this->input['chat']        = $rm[0];
        $this->input['message_id']  = $rm[1] ?? false;
        $this->input['callback_id'] = $rm[1] ?? false;
        if (file_exists($this->update)) {
            $this->selfupdate = true;
            if (!empty($m)) {
                $this->send($this->input['chat'], "<pre>$m</pre>", (int) ($rm[1] ?? 0));
            }
            $r = $this->send($this->input['chat'], "import settings");
            $this->input['message_id']  = $r['result']['message_id'];
            $this->input['callback_id'] = $r['result']['message_id'];
            $this->importFile($this->update);
            unlink($this->update);
        }
        file_put_contents('/update/message', '');
        file_put_contents('/update/reload_message', '');
        $pac = $this->getPacConf();
        unset($pac['restart']);
        $this->setPacConf($pac);
    }

public function backup()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter like: start / period",
            $this->input['message_id'],
            reply: 'enter like: now / 12 hours',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'setBackup',
            'args'           => [],
        ];
    }

public function autoCleanLogs()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter like: start / period",
            $this->input['message_id'],
            reply: 'enter like: now / 12 hours',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'setAutoCleanLogs',
            'args'           => [],
        ];
    }

public function setBackup($text)
    {
        $text = trim($text);
        $c    = $this->getPacConf();
        if (empty($text)) {
            $c['backup'] = '';
        } else {
            [$start, $period] = explode('/', $text);
            if (!empty(strtotime($start)) && !empty(strtotime($period))) {
                $c['backup'] = implode(' / ', [date('Y-m-d H:i', strtotime($start)), trim($period)]);
            } else {
                $this->send($this->input['from'], $this->input['message'] . ' - wrong format');
            }
        }
        if ($c['pinbackup']) {
            $this->pinAdmin($c['pinbackup'], 1);
            $c['pinbackup'] = '';
        }
        $this->setPacConf($c);
        $this->menu('config');
    }

public function setAutoCleanLogs($text)
    {
        $text = trim($text);
        $c    = $this->getPacConf();
        if (empty($text)) {
            $c['autocleanlogs'] = '';
        } else {
            [$start, $period] = explode('/', $text);
            if (!empty(strtotime($start)) && !empty(strtotime($period))) {
                $c['autocleanlogs'] = implode(' / ', [date('Y-m-d H:i', strtotime($start)), trim($period)]);
            } else {
                $this->send($this->input['from'], $this->input['message'] . ' - wrong format');
            }
        }
        $this->setPacConf($c);
        $this->logs();
    }
}

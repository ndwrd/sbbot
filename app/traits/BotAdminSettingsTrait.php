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
        $c = $this->getPacConf();
        if (!empty($c['backup'])) {
            $now = time();
            [$start, $period] = explode('/', $c['backup']);
            $start  = strtotime(trim($start));
            $period = strtotime(trim($period), 0);

            if (
                !empty($start)
                && !empty($period)
                && $now >= $start
            ) {
                // Вычисляем, сколько полных периодов прошло с момента start
                $elapsed = $now - $start;
                $periodsElapsed = floor($elapsed / $period);

                // Время последнего планового бэкапа
                $lastScheduledBackup = $start + ($periodsElapsed * $period);

                // Проверяем, делали ли уже бэкап в этом периоде
                $lastBackupTime = $c['last_backup_time'] ?? 0;

                // Если последний бэкап был сделан до начала текущего периода - делаем бэкап
                if ($lastBackupTime < $lastScheduledBackup) {
                    $c['last_backup_time'] = $now;
                    $this->setPacConf($c);
                    $this->pinBackup();
                }
            }
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

public function export()
    {
        $conf = [
            'ad'  => yaml_parse_file($this->adguard),
            'pac' => $this->getPacConf(),
            'ssl' => file_exists('/certs/cert_private') && preg_match('~BEGIN PRIVATE KEY~', file_get_contents('/certs/cert_private')) ? [
                'private' => file_get_contents('/certs/cert_private'),
                'public'  => file_get_contents('/certs/cert_public'),
            ] : false,
            'dnstt' => file_exists('/config/dnstt/server.key') ? [
                'private' => file_get_contents('/config/dnstt/server.key'),
                'public'  => file_get_contents('/config/dnstt/server.pub'),
            ] : false,
            'mtproto'       => file_get_contents('/config/mtprotosecret'),
            'mtprotodomain' => file_get_contents('/config/mtprotodomain'),
            'mtprotoadtag'  => file_exists('/config/mtprotoadtag') ? file_get_contents('/config/mtprotoadtag') : '',
            'singbox'       => $this->getSingbox(),
            'singboxstats'  => $this->getSingboxStats(),
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
            // certs
            if (!empty($json['ssl'])) {
                $out[] = 'update certificates';
                $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
                file_put_contents('/certs/cert_private', $json['ssl']['private']);
                file_put_contents('/certs/cert_public', $json['ssl']['public']);
            }
            // pac
            if (!empty($json['pac'])) {
                $out[] = 'update pac';
                $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
                $this->setPacConf($json['pac']);
            }
            // ad
            if (!empty($json['ad'])) {
                $out[] = 'update adguard';
                $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
                $this->stopAd();
                yaml_emit_file($this->adguard, $json['ad']);
                $this->startAd();
            }
            // mtproto
            if (!empty($json['mtproto'])) {
                $out[] = 'update mtproto';
                $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
                file_put_contents('/config/mtprotosecret', $json['mtproto']);
                file_put_contents('/config/mtprotodomain', ($json['mtprotodomain'] ?? null) ?: '');
                file_put_contents('/config/mtprotoadtag', trim($json['mtprotoadtag'] ?? ''));
                $this->restartTG();
            }
            // singbox
            if (!empty($json['singbox'])) {
                $out[] = 'update singbox';
                $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
                $this->restartSingbox($json['singbox']);
                $this->adguardSingboxClients();
                $this->setUpstreamDomain($json['pac']['transport'] != 'Reality' ? 't' : (($json['pac']['reality']['domain'] ?? null) ?: $json['singbox']['inbounds'][0]['streamSettings']['realitySettings']['serverNames'][0]));
            }
            // singboxstats
            if (!empty($json['singboxstats'])) {
                $out[] = 'update singbox stats';
                $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
                $this->setSingboxStats($json['singboxstats']);
            }
            // dnstt
            if (!empty($json['dnstt'])) {
                $out[] = 'update dnstt certificates';
                $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
                file_put_contents('/config/dnstt/server.key', $json['dnstt']['private']);
                file_put_contents('/config/dnstt/server.pub', $json['dnstt']['public']);
            }
            // nginx
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

public function chpsswd($pass)
    {
        $out[] = 'Restart Adguard Home';
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
        $out[] = $this->stopAd();
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
        $c = yaml_parse_file($this->adguard);
        $c['users'][0]['password'] = password_hash($pass, PASSWORD_DEFAULT);
        yaml_emit_file($this->adguard, $c);
        $p = $this->getPacConf();
        $p['adpswd'] = $pass;
        $this->setPacConf($p);
        $out[] = $this->startAd();
        $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
        sleep(3);
        $this->menu('adguard');
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
            if (!empty($conf['adguardkey'])) {
                $text[] = "{$conf['adguardkey']}.{$conf['domain']} adguard DOT";
            }
            if (in_array($conf['domain'], $certs)) {
                $text[] = "SSL: " . date('Y-m-d H:i:s', $ssl_expiry);
            }
            $text[] = "</blockquote>";
            if (empty($cert)) {
                $text[] = "Настройте DNS A-записи на IP этого сервера для: {$conf['domain']}, {$conf['naiveSubdomain']}.{$conf['domain']}, {$conf['anytlsSubdomain']}.{$conf['domain']} — и только после этого нажимайте «Letsencrypt SSL».";
            }
        } else {
            $text[] = $this->i18n('domain explain');
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
                'text'          => $this->i18n('IP ban'),
                'callback_data' => "/ipMenu",
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
        $backup = array_filter(explode('/', $conf['backup']));
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
                'text'          => $this->i18n('autoupdate') . ': ' .  $this->i18n($conf['autoupdate'] ? 'on' : 'off'),
                'callback_data' => "/autoupdate",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('restart'),
                'callback_data' => "/restart",
            ],
            [
                'text'          => "{$this->i18n('add')} {$this->i18n('admin')}",
                'callback_data' => "/addadmin",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('lang'),
                'callback_data' => "/menu lang",
            ],
            [
                'text'          => "{$this->i18n('page')}: " . (($conf['limitpage'] ?? null) ?: 5),
                'callback_data' => "/enterPage",
            ],
        ];
        $file = dirname(__DIR__) . '/config.php';
        opcache_invalidate($file);
        require $file;
        foreach ($c['admin'] as $k => $v) {
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
                'text'          => $this->i18n($c['ad'] ? 'on' : 'off') . ' 853 AdguardHome DoT',
                'callback_data' => "/hidePort ad",
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
            'ad'    => '853:853',
            'dnstt' => '53:53/udp',
        ];
        $f = '/docker/compose';
        $content = file_exists($f) ? file_get_contents($f) : '';

        // Находим все сервисы с !override для ports
        $overrides = [];
        if (preg_match_all('/(\w+):\s*\n\s+ports:\s*!override/m', $content, $matches)) {
            foreach ($matches[1] as $service) {
                $overrides[$service] = true;
            }
        }

        // Парсим YAML
        $c = $content ? yaml_parse($content) : [];

        // Изменяем структуру
        if (!empty($c['services'][$container])) {
            unset($c['services'][$container]);
        } else {
            $c['services'][$container]['ports'][] = $ports[$container];
        }

        // Записываем обратно
        if (empty($c['services'])) {
            file_put_contents($f, '');
        } else {
            $yaml = yaml_emit($c);
            // Восстанавливаем !override для ports тех сервисов где он был
            foreach ($overrides as $service => $val) {
                // Заменяем "ports:" на "ports: !override" для конкретного сервиса
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

        // Находим все сервисы с !override для ports
        $overrides = [];
        if (preg_match_all('/(\w+):\s*\n\s+ports:\s*!override/m', $content, $matches)) {
            foreach ($matches[1] as $service) {
                $overrides[$service] = true;
            }
        }

        // Парсим YAML
        $c = $content ? yaml_parse($content) : [];

        // Изменяем структуру
        if (!empty($port) && is_numeric($port) && $port != 443 && $port != 80) {
            $c['services'][$container]['ports'] = ["$port:$ports[$container]"];
        } else {
            unset($c['services'][$container]);
        }

        // Записываем обратно
        if (empty($c['services'])) {
            file_put_contents($f, '');
        } else {
            $yaml = yaml_emit($c);
            // Восстанавливаем !override для ports тех сервисов где он был
            foreach ($overrides as $service => $val) {
                // Заменяем "ports:" на "ports: !override" для конкретного сервиса
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
        $rm                         = explode(':', trim(file_get_contents('/update/reload_message')));
        $m                          = file_get_contents('/update/message');
        $this->input['chat']        = $rm[0];
        $this->input['message_id']  = $rm[1] ?? false;
        $this->input['callback_id'] = $rm[1] ?? false;
        if (file_exists($this->update)) {
            $this->selfupdate = true;
            if (!empty($m)) {
                $this->send($this->input['chat'], "<pre>$m</pre>", $rm[1]);
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

<?php

trait BotDomainSslTrait
{
public function checkCert()
    {
        try {
            require dirname(__DIR__) . '/config.php';
            if (!empty($c['admin']) && date('H') == 12 && (empty($this->time2) || ((time() - $this->time2) > 4600))) {
                $this->time2 = time();
                $cert = $this->expireCert();
                if (!empty($cert) && $cert - 60 * 60 * 24 * 14 < time()) {
                    foreach ($c['admin'] as $k => $v) {
                        $this->send($v, "certificate expire: " . date('Y-m-d H:i:s', $cert));
                    }
                }
            }
        } catch (Exception $e) {
        }
    }

public function addNipdomain()
    {
        $this->addDomain(str_replace('.', '-', $this->ip) . '.nip.io');
    }

public function addDomain($domain, $nomenu = false)
    {
        $domain = trim($domain);
        if (!empty($domain)) {
            $conf = $this->getPacConf();
            $conf['domain'] = idn_to_ascii($domain);
            $conf = $this->ensureProtocolSubdomains($conf);
            $this->setPacConf($conf);
            $this->cloakNginx();
        }
        if (empty($nomenu)) {
            sleep(3);
            $this->menu('domains');
        }
    }

public function ensureProtocolSubdomains(array $conf)
    {
        // Поддомены naive/anytls намеренно случайные (не "naive.domain"/"anytls.domain") —
        // буквальное имя протокола в SNI/DNS сразу выдаёт censor'у, что тут за сервис.
        $conf['naiveSubdomain']  = $conf['naiveSubdomain']  ?: bin2hex(random_bytes(4));
        $conf['anytlsSubdomain'] = $conf['anytlsSubdomain'] ?: bin2hex(random_bytes(4));
        return $conf;
    }

public function regenSubdomains()
    {
        $conf = $this->getPacConf();
        $conf['naiveSubdomain']  = bin2hex(random_bytes(4));
        $conf['anytlsSubdomain'] = bin2hex(random_bytes(4));
        $this->setPacConf($conf);
        $this->cloakNginx();
        if ($conf['domain'] && $this->nginxGetTypeCert() == 'letsencrypt') {
            $this->setSSL('letsencrypt');
        } else {
            $this->menu('domains');
        }
    }

public function sslip()
    {
        require dirname(__DIR__) . '/config.php';
        $p  = $this->getPacConf();
        $ip = getenv('IP');
        $r  = $this->send($c['admin'][0], "start $ip");

        $this->input['chat']        = $c['admin'][0];
        $this->input['message_id']  = $r['result']['message_id'];
        $this->input['callback_id'] = false;
        if (empty($p)) {
            $this->addDomain(str_replace('.', '-', $this->ip) . '.nip.io', 1);
            $this->setSSL('letsencrypt');
        }
        $this->menu();
    }

public function comment($text, $tag)
    {
        $text = explode("\n", $text);
        foreach ($text as $k => $v) {
            if (preg_match("~##$tag~", $v)) {
                $text[$k] = "#-$tag";
                continue;
            }
            $text[$k] = "#$v";
        }
        return implode("\n", $text);
    }

public function uncomment($text, $tag)
    {
        $text = explode("\n", $text);
        foreach ($text as $k => $v) {
            if (preg_match("~#-$tag~", $v)) {
                $text[$k] = "##$tag";
                continue;
            }
            $text[$k] = preg_replace('~#~', '', $v, 1);
        }
        return implode("\n", $text);
    }

public function deleteSSL($notmenu = false)
    {
        unlink('/certs/cert_private');
        unlink('/certs/cert_public');
        $conf = $this->getPacConf();
        unset($conf['letsencrypt']);
        $this->setPacConf($conf);
        $this->adguardSync();
        $this->cloakNginx();
        if (!$notmenu) {
            $this->menu('domains');
        }
    }

public function updateUnitInitConfig()
    {
        $unit = $this->controlUnit('config');
        file_put_contents('/config/unit.json', $unit);
    }

public function setSSL($name)
    {
        $conf = $this->getPacConf();
        switch ($name) {
            case 'letsencrypt':
                $out[] = 'Install certificate:';
                $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
                $conf = $this->ensureProtocolSubdomains($conf);
                $adguardClient = $conf['adguardkey'] ? "-d {$conf['adguardkey']}.{$conf['domain']}" : '';
                // naive/anytls живут на своих (случайных, не протокол-именованных) поддоменах
                // (см. cloakNginx()/upstream.conf) — сертификат должен покрывать их SAN-записями,
                // иначе TLS-хендшейк не пройдёт.
                // 80/tcp на хосте держится закрытым всё остальное время — этот файл-маркер
                // (общая bind-mount папка /certs/) видит scripts/watch_port80.sh на хосте и
                // сам открывает/закрывает порт через ufw, т.к. контейнер не может дёрнуть
                // firewall хоста напрямую.
                touch('/certs/.want_port80');
                usleep(500000);
                exec("certbot certonly --force-renew --preferred-chain 'ISRG Root X1' -n --agree-tos --email mail@{$conf['domain']} -d {$conf['domain']} -d {$conf['naiveSubdomain']}.{$conf['domain']} -d {$conf['anytlsSubdomain']}.{$conf['domain']} $adguardClient --webroot -w /certs/ --logs-dir /logs --max-log-backups 0 2>&1", $out, $code);
                @unlink('/certs/.want_port80');
                if ($code > 0) {
                    $this->send($this->input['chat'], "ERROR\n" . implode("\n", $out));
                    break;
                }
                $out[] = 'Generate bundle';
                $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $out));
                $bundle = file_get_contents("/etc/letsencrypt/live/{$conf['domain']}/privkey.pem") . file_get_contents("/etc/letsencrypt/live/{$conf['domain']}/fullchain.pem");
                $conf['letsencrypt'] = 'letsencrypt';
                break;
            case 'self':
                $r      = $this->request('getFile', ['file_id' => $this->input['file_id']]);
                $bundle = file_get_contents($this->file . $r['result']['file_path']);
                $conf['letsencrypt'] = 'self';
                break;
        }
        if (preg_match('~[^\s]+BEGIN PRIVATE KEY.+?END PRIVATE KEY[^\s]+~s', $bundle, $m)) {
            $this->setPacConf($conf);
            file_put_contents('/certs/cert_private', $m[0]);
            file_put_contents('/certs/cert_public', preg_replace('~[^\s]+BEGIN PRIVATE KEY.+?END PRIVATE KEY[^\s]+~s', '', $bundle));
            $this->adguardSync();
            $this->cloakNginx();
        } else {
            $this->update($this->input['chat'], $this->input['message_id'], "wrong format key");
        }
        sleep(3);
        $this->menu('domains');
    }

public function controlUnit($url, $method = 'GET', $json = false, $bundle = false)
    {
        $ch = curl_init();
        $opt = [
            CURLOPT_CUSTOMREQUEST    => $method,
            CURLOPT_URL              => "http://localhost/$url",
            CURLOPT_RETURNTRANSFER   => 1,
            CURLOPT_UNIX_SOCKET_PATH => '/var/run/control.unit.sock',
            CURLOPT_TIMEOUT          => 10,
        ];
        if ($json) {
            $opt[CURLOPT_POSTFIELDS] = $json;
        }
        if ($bundle) {
            $opt[CURLOPT_POSTFIELDS] = ['file' => new CURLStringFile($bundle, 'bundle.pem', 'text/plain')];
        }
        curl_setopt_array($ch, $opt);
        $r = curl_exec($ch);
        curl_close($ch);
        return $r ?: 'lost connect to unit';
    }

public function delDomain()
    {
        $this->deleteSSL(1);
        $conf = $this->getPacConf();
        unset($conf['domain']);
        $this->setPacConf($conf);
        $this->adguardSync();
        $this->cloakNginx();
        $this->menu('domains');
    }

public function domain()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter domain",
            $this->input['message_id'],
            reply: 'enter domain',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'addDomain',
            'args'          => [],
        ];
    }

public function selfssl()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} send file with your certificate chain and private key <code>cat key.pem ca.pem cert.pem</code>",
            $this->input['message_id'],
            reply: 'send file with your certificate chain and private key',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'selfsslInstall',
            'args'          => [],
        ];
    }

public function addSubdomain()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter subdomain",
            $this->input['message_id'],
            reply: 'enter subdomain',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'setSubdomain',
            'args'          => [],
        ];
    }

public function setSubdomain($text)
    {
        $c = $this->getPacConf();
        if (empty($text)) {
            unset($c['subdomain']);
        } else {
            $c['subdomain'] = array_filter(explode(',', $text), fn($e) => !empty(trim($e)));
        }
        $this->setPacConf($c);
        $this->menu('config');
    }

public function selfsslInstall()
    {
        $this->setSSL('self');
    }

public function getDomain($cdn = false)
    {
        $c = $this->getPacConf();
        if ($cdn && $c['linkdomain']) {
            return $c['linkdomain'];
        }
        return $c['domain'] ?: $this->ip;
    }

public function setUpstreamDomain($domain)
    {
        $nginx = file_get_contents('/config/upstream.conf');
        $t = preg_replace('~#domain.+#domain~s', "#domain\n$domain reality;\n#domain", $nginx);
        file_put_contents('/config/upstream.conf', $t);
        $this->ssh("nginx -s reload 2>&1", 'up');
    }

public function cloakNginx()
    {
        $conf     = $this->getPacConf();
        $template = file_get_contents('/config/nginx_default.conf');
        // $template = preg_replace('~server_name ip~', "server_name {$this->ip}", $template);
        $template = preg_replace('~server_name domain~', "server_name " . ($conf['domain'] ? " *.{$conf['domain']} {$conf['domain']}" : '_'), $template);
        $before = $conf;
        $conf   = $this->ensureProtocolSubdomains($conf);
        if ($conf !== $before) {
            $this->setPacConf($conf);
        }
        if ($conf['domain'] && $conf['letsencrypt']) {
            $template = preg_replace('/#~([^\n]+)?/', "#~{$conf['letsencrypt']}", $template);
            foreach (['domain', 'naive', 'anytls'] as $tag) {
                preg_match_all("~#-$tag.+?#-$tag~s", $template, $m);
                foreach ($m[0] as $v) {
                    $template = preg_replace("~#-$tag.+?#-$tag~s", $this->uncomment($v, $tag), $template, 1);
                }
            }
        }
        $h = $this->getHashBot();
        $s = empty($conf['adgbrowser']) ? '' : '#';
        $r = <<<CONF
        location /adguard/ {
                access_log /logs/nginx_adguard_access;
                if (\$cookie_c != "$h") {
                    $s rewrite .* /webapp redirect;
                }
                proxy_pass http://ad/;
                proxy_redirect / /adguard/;
                proxy_cookie_path / /adguard/;
            }
            location
        CONF;
        $template = preg_replace('~(location /adguard.+?})\s*location~s', $r, $template);
        $template = preg_replace('~(/webapp|/pac|/adguard|/ws|location /dns-query)~', '${1}' . $h, $template);
        file_put_contents('/config/nginx.conf', $template);
        // путь /ws$hash считается заново в buildSingboxConfig() из getHashBot() при каждом
        // restartSingbox() — достаточно один раз перегенерировать конфиг после смены hash.
        $this->restartSingbox($this->getSingbox());

        if ($conf['domain']) {
            $up = file_get_contents('/config/upstream.conf');
            $up = preg_replace('~#naive.+#naive~s', "#naive\n{$conf['naiveSubdomain']}.{$conf['domain']} ng-naive;\n#naive", $up);
            $up = preg_replace('~#anytls.+#anytls~s', "#anytls\n{$conf['anytlsSubdomain']}.{$conf['domain']} ng-anytls;\n#anytls", $up);
            file_put_contents('/config/upstream.conf', $up);
            $this->ssh('nginx -s reload 2>&1', 'up');
        }

        $test = $this->ssh('nginx -t 2>&1', 'ng');
        if (stripos($test, 'successful') === false) {
            // Не шлём reload на заведомо битый конфиг — nginx просто продолжит молча
            // работать со старым, и ошибка останется незамеченной (как уже было).
            require dirname(__DIR__) . '/config.php';
            foreach ($c['admin'] ?? [] as $admin) {
                $this->send($admin, "nginx config test failed, reload skipped:\n$test");
            }
            return $test;
        }
        return $this->ssh('nginx -s reload', 'ng');
    }

public function expireCert()
    {
        $c = openssl_x509_read(file_get_contents("/certs/cert_public"));
        return openssl_x509_parse($c)["validTo_time_t"] ?: false;
    }

public function domainsCert()
    {
        $domains = openssl_x509_parse(openssl_x509_read(file_get_contents("/certs/cert_public")))['extensions']["subjectAltName"];
        if (empty($domains)) {
            return false;
        }
        return array_map(fn($e) => trim($e), explode(',', str_replace('DNS:', '', $domains)));
    }

public function nginxGetTypeCert()
    {
        $conf = $this->ssh('cat /etc/nginx/nginx.conf', 'ng');
        preg_match("/#~([^\s]+)/", $conf, $m);
        return $m[1];
    }
}

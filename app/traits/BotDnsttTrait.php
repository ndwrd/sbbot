<?php

trait BotDnsttTrait
{
public function dnsttDomain()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter domain",
            $this->input['message_id'],
            reply: 'enter domain',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'setdnsttDomain',
            'args'          => [],
        ];
    }

public function dnsttPassword()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter password",
            $this->input['message_id'],
            reply: 'enter password',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'setdnsttPassword',
            'args'          => [],
        ];
    }

public function setdnsttPassword($text)
    {
        $c = $this->getPacConf();
        if ($text) {
            $c['dnsttPassword'] = $text;
        } else {
            unset($c['dnsttPassword']);
        }
        $this->setPacConf($c);
        $this->dnsttStart();
        $this->dnstt();
    }

public function setdnsttDomain($text)
    {
        $c = $this->getPacConf();
        if ($text) {
            $c['dnsttDomain'] = $text;
        } else {
            unset($c['dnsttDomain']);
        }
        $this->setPacConf($c);
        $this->dnsttStart();
        $this->dnstt();
    }

public function dnsttStart()
    {
        $c = $this->getPacConf();
        $this->ssh('pkill dnstt', 'dnstt');
        if (!empty($c['dnsttDomain']) && !empty($c['dnsttPassword'])) {
            $this->ssh("adduser -D -s /bin/sh vpnbot", 'dnstt');
            $this->ssh("echo 'vpnbot:{$c['dnsttPassword']}' | chpasswd", 'dnstt');
            if (!file_exists('/config/dnstt/server.key')) {
                $this->ssh("dnstt-server -gen-key -privkey-file /dnstt/server.key -pubkey-file /dnstt/server.pub", 'dnstt');
            }
            $this->ssh("dnstt-server -udp :53 -privkey-file /dnstt/server.key {$c['dnsttDomain']} 127.0.0.1:22", 'dnstt' , false, '/logs/dnstt');
        }
    }

public function dnsttDownload()
    {
        $this->sendFile($this->input['from'], curl_file_create('/config/dnstt/server.pub'));
    }

public function dnstt($update = false)
    {
        $c      = $this->getPacConf();
        $pubkey = file_get_contents('/config/dnstt/server.pub');
        $text[] = "dnstt";
        if (!empty($c['dnsttDomain']) && !empty($c['dnsttPassword'])) {
            $text[] = "<pre>set the NS record for {$c['dnsttDomain']}: tns.{$c['domain']}\nset A record for tns.{$c['domain']}: {$this->ip}</pre>";
            $text[] = "account: <code>vpnbot:{$c['dnsttPassword']}</code>";
            $text[] = "server name: <code>{$c['dnsttDomain']}</code>";
            $text[] = "public key: <code>$pubkey</code>";
            $data[] = [
                [
                    'text'          => $this->i18n('download pubkey'),
                    'callback_data' => "/dnsttDownload",
                ],
            ];
        } else {
            $text[] = "set subdomain and password";
        }

        $data[] = [
            [
                'text'          => $this->i18n('set subdomain'),
                'callback_data' => "/dnsttDomain",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('set password'),
                'callback_data' => "/dnsttPassword",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/menu",
            ],
        ];
        if ($update) {
            $this->update(
                $this->input['chat'],
                $this->input['message_id'],
                implode("\n", $text),
                $data ?: false,
            );
        } else {
            $this->send(
                $this->input['chat'],
                implode("\n", $text),
                $this->input['message_id'],
                $data ?: false,
            );
        }
    }
}

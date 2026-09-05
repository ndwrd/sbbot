<?php

trait BotMtprotoTrait
{
public function generateSecret()
    {
        $this->secretSet(exec('head -c 16 /dev/urandom | xxd -ps'));
    }

public function setSecret()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter key or 0 for stop mtproto",
            $this->input['message_id'],
            reply: 'enter key or 0 for stop mtproto',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'secretSet',
            'args'           => [],
        ];
    }

public function secretSet($secret)
    {
        file_put_contents('/config/mtprotosecret', $secret);
        $this->restartTG();
        $this->mtproto();
    }

public function setTelegramDomain($domain)
    {
        file_put_contents('/config/mtprotodomain', $domain);
        $this->restartTG();
        $this->mtproto();
    }

public function setTelegramAdtag($adtag)
    {
        $adtag = trim($adtag);
        if ($adtag === '0') {
            $adtag = '';
        } elseif (!preg_match('~^[a-f0-9]{32}$~i', $adtag)) {
            $this->update($this->input['chat'], $this->input['message_id'], 'wrong adtag');
            sleep(2);
            $this->mtproto();
            return;
        }
        file_put_contents('/config/mtprotoadtag', strtolower($adtag));
        $this->restartTG();
        $this->mtproto();
    }

public function restartTG()
    {
        $secret     = file_get_contents('/config/mtprotosecret');
        $fakedomain = file_get_contents('/config/mtprotodomain') ?: 'yandex.ru';
        $adtag      = trim(file_exists('/config/mtprotoadtag') ? file_get_contents('/config/mtprotoadtag') : '');
        $this->ssh('pkill mtproto-proxy', 'tg');
        if (preg_match('~^\w{32}$~', $secret)) {
            $proxyTag = $adtag ? " -P " . escapeshellarg($adtag) : '';
            $this->ssh("mtproto-proxy --domain $fakedomain -u nobody -H 443 --nat-info 10.10.0.8:{$this->ip} -S $secret --aes-pwd /proxy-secret /proxy-multi.conf -M 1$proxyTag", 'tg', false, '/logs/mtproto');
        }
    }

public function linkMtproto()
    {
        $s  = file_get_contents('/config/mtprotosecret');
        $p  = $this->getPorts()['tg']['port'];
        $d  = trim(file_get_contents('/config/mtprotodomain') ?: 'yandex.ru');
        $d  = exec("echo $d | tr -d '\\n' | xxd -ps -c 200");
        $ip = $this->getDomain();
        return "https://t.me/proxy?server=$ip&port=$p&secret=ee$s$d";
    }

public function mtproto()
    {
        $d      = file_get_contents('/config/mtprotodomain') ?: 'yandex.ru';
        $adtag  = trim(file_exists('/config/mtprotoadtag') ? file_get_contents('/config/mtprotoadtag') : '');
        $st     = $this->ssh('pgrep mtproto-proxy', 'tg') ? 'on' : 'off';
        $text[] = "Menu -> MTProto\n";
        $text[] = "status: $st\n";
        $text[] = "fake domain: <code>$d</code>\n";
        $text[] = "adtag: <code>" . ($adtag ?: 'off') . "</code>\n";
        if ($st == 'on') {
            $text[] = $this->linkMtproto();
        }
        $data[] = [
            [
                'text'          => $this->i18n('generateSecret'),
                'callback_data' => "/generateSecret",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('setSecret'),
                'callback_data' => "/setSecret",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('changeFakeDomain'),
                'callback_data' => "/changeTGDomain",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('setAdTag'),
                'callback_data' => "/setTGAdtag",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('show QR'),
                'callback_data' => "/qrMtproto",
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

public function changeTGDomain()
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
            'callback'       => 'setTelegramDomain',
            'args'           => [],
        ];
    }

public function changeTGAdtag()
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter adtag or 0 for disable",
            $this->input['message_id'],
            reply: 'enter adtag or 0 for disable',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => 'setTelegramAdtag',
            'args'           => [],
        ];
    }
}

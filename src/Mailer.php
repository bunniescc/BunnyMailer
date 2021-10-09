<?php

namespace Bunny\Message;
/**
 * @package Bunny\Message
 * @author IvanLu
 * @time 2019/2/28 21:05
 */
class Mailer
{
    private int $time_out;
    private $host;
    private $port;
    private $auth;
    private $user;
    private $pass;
    private $sock;

    function __construct($host = '', $port = 25, $auth = false, $user = '', $pass = '')
    {
        $this->port = $port;
        $this->host = $host;
        $this->time_out = 30;
        $this->auth = $auth;
        $this->user = $user;
        $this->pass = $pass;
        $this->sock = null;
    }

    function sendMail($to, $from, $subject = '', $body = '', $mailType = 'HTML', $nickname = '', $cc = '', $bcc = '', $additional_headers = ''): bool
    {
        $mail_from = $this->get_address($this->strip_comment($from));
        $nickname = ($nickname == '') ? $from : $nickname;
        $body = preg_replace("/(^|(\r\n))(\.)/", "\1.\3", $body);
        $header = "MIME-Version:1.0\r\n";
        if ($mailType == 'HTML') $header .= "Content-Type:text/html;charset=utf-8\r\n";
        $header .= "To: {$to} \r\n";
        if ($cc != "") $header .= "Cc: {$cc}\r\n";
        $header .= "From: {$nickname}<{$from}>\r\n";
        $header .= "Subject: $subject\r\n";
        $header .= $additional_headers;
        $header .= 'Date: ' . date('r') . "\r\n";
        $header .= "X-Mailer:By BunnyPHP\r\n";
        list($mSec, $sec) = explode(" ", microtime());
        $header .= 'Message-ID: <' . date('YmdHis', $sec) . '.' . ($mSec * 1000000) . '.' . $mail_from . ">\r\n";
        $TO = explode(",", $this->strip_comment($to));
        if ($cc != "") {
            $TO = array_merge($TO, explode(',', $this->strip_comment($cc)));
        }
        if ($bcc != "") {
            $TO = array_merge($TO, explode(',', $this->strip_comment($bcc)));
        }
        $sent = true;
        foreach ($TO as $rcpt_to) {
            $rcpt_to = $this->get_address($rcpt_to);
            if (!$this->connect($rcpt_to)) {
                $sent = false;
                continue;
            }
            if (!$this->sendContent($mail_from, $rcpt_to, $header, $body)) {
                $sent = false;
            }
            fclose($this->sock);
        }
        return $sent;
    }

    private function sendContent($from, $to, $header, $body = ''): bool
    {
        if (!$this->sendCommand('HELO', 'localhost')) return false;
        if ($this->auth) {
            if (!$this->sendCommand('AUTH LOGIN', base64_encode($this->user))) return false;
            if (!$this->sendCommand('', base64_encode($this->pass))) return false;
        }
        if (!$this->sendCommand('MAIL', "FROM:<{$from}>")) return false;
        if (!$this->sendCommand('RCPT', "TO:<{$to}>")) return false;
        if (!$this->sendCommand('DATA')) return false;
        if (!$this->sendMessage($header, $body)) return false;
        if (!$this->sendEOM()) return false;
        if (!$this->sendCommand('QUIT')) return false;
        return true;
    }

    private function connect($address): bool
    {
        if ($this->host == '') {
            return $this->connectMX($address);
        } else {
            return $this->connectRelay();
        }
    }

    private function connectRelay(): bool
    {
        $this->sock = @fsockopen($this->host, $this->port, $errno, $error_message, $this->time_out);
        if (!($this->sock && $this->sendOk())) return false;
        return true;
    }

    private function connectMX($address): bool
    {
        $domain = preg_replace("/^.+@([^@]+)$/", "\1", $address);
        if (!@getmxrr($domain, $MX_HOSTS)) return false;
        foreach ($MX_HOSTS as $host) {
            $this->sock = @fsockopen($host, $this->port, $errno, $error_message, $this->time_out);
            if (!($this->sock && $this->sendOk())) {
                continue;
            }
            return true;
        }
        return false;
    }

    private function sendMessage($header, $body): bool
    {
        fputs($this->sock, $header . "\r\n" . $body);
        return true;
    }

    private function sendEOM(): bool
    {
        fputs($this->sock, "\r\n.\r\n");
        return $this->sendOk();
    }

    private function sendOk(): bool
    {
        $response = str_replace("\r\n", '', fgets($this->sock, 512));
        if (!preg_match("/^[23]/", $response)) {
            fputs($this->sock, "QUIT\r\n");
            fgets($this->sock, 512);
            return false;
        }
        return true;
    }

    private function sendCommand($cmd, $arg = ''): bool
    {
        if ($arg != '') {
            if ($cmd == '') $cmd = $arg;
            else $cmd = $cmd . ' ' . $arg;
        }
        fputs($this->sock, $cmd . "\r\n");
        return $this->sendOk();
    }

    private function strip_comment($address)
    {
        $comment = "/\([^()]*\)/";
        while (preg_match($comment, $address)) {
            $address = preg_replace($comment, '', $address);
        }
        return $address;
    }

    private function get_address($address)
    {
        $address = preg_replace("/([ \t\r\n])+/", '', $address);
        return preg_replace("/^.*<(.+)>.*$/", "\1", $address);
    }
}
#!/usr/bin/env php
<?php
require_once dirname(__FILE__) . '/vendor/autoload.php';

$dotenv = new Dotenv\Dotenv(getcwd());
$dotenv->load();

$telegram = new \Telegram\Telegram();

$message = '🔥 Signal 🔥

👉VIB/BTC

BUY:  0.00001663

TARGET1:  0.00001831
TARGET2:  0.00002073

STOP LOSS:  0.00001421';
$channelUsername = 'cryptobullet';

$telegram->test($message, $channelUsername);
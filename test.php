#!/usr/bin/env php
<?php
require_once dirname(__FILE__) . '/vendor/autoload.php';

$dotenv = new Dotenv\Dotenv(getcwd());
$dotenv->load();

$telegram = new \Telegram\Telegram();

$message = '#SLR
BUY 5590
Sell 6100-6700-7800';
$channelUsername = 'VipCryptoZ';

$telegram->test($message, $channelUsername);
<?php

namespace Telegram;

use danog\MadelineProto\API;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Exception as ApiException;

class Telegram
{
    protected $MadelineProto;

    protected $duplicatesCache = [];

    /**
     * Telegram constructor.
     * @throws ApiException
     */
    public function __construct()
    {
        $settings = json_decode(getenv('MTPROTO_SETTINGS'), true) ?: [];

        echo 'Deserializing MadelineProto from session.madeline...'.PHP_EOL;
        $this->MadelineProto = false;
        try {
            $this->MadelineProto = new API('session.madeline');
        } catch (ApiException $e) {
            echo 'Starting new session. This may take a minute...' . PHP_EOL;
        }

        if ($this->MadelineProto === false) {
            $this->MadelineProto = new API($settings);

            $sentCode = $this->MadelineProto->phone_login(readline('Enter your phone number: '));
            Logger::log([$sentCode], Logger::NOTICE);
            echo 'Enter the code you received: ';
            $code = fgets(STDIN, (isset($sentCode['type']['length']) ? $sentCode['type']['length'] : 5) + 1);
            $authorization = $this->MadelineProto->complete_phone_login($code);
            Logger::log([$authorization], Logger::NOTICE);
            if ($authorization['_'] === 'account.noPassword') {
                throw new ApiException('2FA is enabled but no password is set!');
            }
            if ($authorization['_'] === 'account.password') {
                Logger::log(['2FA is enabled'], Logger::NOTICE);
                $authorization = $this->MadelineProto->complete_2fa_login(readline('Please enter your password (hint '.$authorization['hint'].'): '));
            }
            if ($authorization['_'] === 'account.needSignup') {
                Logger::log(['Registering new user'], Logger::NOTICE);
                $this->MadelineProto->complete_signup(readline('Please enter your first name: '), readline('Please enter your last name (can be empty): '));
            }
        }

        $this->MadelineProto->session = 'session.madeline';
    }

    public function updateHandler($update)
    {
        var_dump($update);
    }

    public function getUpdates()
    {
        $channels = $this->getChannels();

        $offset = 0;
        while (true) {
            $updates = $this->MadelineProto->API->get_updates(['offset' => $offset, 'limit' => 50, 'timeout' => 1]);
            if (!count($updates)) {
                echo '-';
            }
            // Set the offset to the last update
            foreach ($updates as $update) {
                $offset = $update['update_id']; // offset must be set to the last update_id
            }
            foreach ($updates as $update) {
                if (!isset($update['update']['_'])
                    || $update['update']['_'] !== 'updateNewChannelMessage'
                    || !isset($update['update']['message'])
                    || !isset($update['update']['message']['message'])
                    || !trim($update['update']['message']['message'])
                ) {
                    continue;
                }

//                echo json_encode($update, JSON_PRETTY_PRINT) . PHP_EOL;

                $message = $update['update']['message']['message'];

                // Check a local cache array to see if this message is a duplicate
                if (in_array($message, $this->duplicatesCache)) {
                    // Skip if it was already added to the duplicates cache
                    continue;
                }

                // Add the message content to the duplicates cache
                $this->duplicatesCache[] = $message;

                // Make sure the duplicates cache has no more than 100 messages in it
                if (count($this->duplicatesCache) > 100) {
                    array_shift($this->duplicatesCache);
                }

                $timestamp = $update['update']['message']['date'];

//                if (!preg_match('/(buy|signal)/i', $message)) {
//                    echo '.';
//                    continue;
//                }

                $channelInfo = $this->MadelineProto->get_info('channel#' . $update['update']['message']['to_id']['channel_id']);
//                echo json_encode($channelInfo, JSON_PRETTY_PRINT) . PHP_EOL;

                $channelTitle = $channelInfo['Chat']['title'];
                $channelUsername = (isset($channelInfo['Chat']['username']) ? $channelInfo['Chat']['username'] : null);

                if ($channelUsername && isset($channels[$channelUsername])) {
                    try {
                        $this->parseMessageForChannel($message, $channels[$channelUsername]);
                    } catch (\Exception $e) {
                        echo $e->getMessage() . PHP_EOL;
                        $this->outputMessage($message, $timestamp, $channelTitle, $channelUsername);
                    }
                } else {
                    $this->outputMessage($message, $timestamp, $channelTitle, $channelUsername);
                }

            }

            $this->MadelineProto->serialize();
        }
    }

    public function test($message, $channelUsername)
    {
        $channels = $this->getChannels();

        if ($channelUsername && isset($channels[$channelUsername])) {
            $this->parseMessageForChannel($message, $channels[$channelUsername]);
        } else {
            throw new \Exception('Channel not configured for username: ' . $channelUsername);
        }
    }

    protected function getChannels()
    {
        return [
            'cryptobullet' => [
                'name' => 'CryptoBullet (Bittrex)',
                'exchange' => 'bittrex',
                'parse' => function($message) {
                    $parsed = [];
                    // Get coin pair
                    if (!preg_match('/([A-Z]{3,4})\/([A-Z]{3,4})/', $message, $matches)) {
                        throw new \Exception('GetCoinPair Regular expression failed for CryptoBullet: ' . $message);
                    }
                    $parsed['coinPair'] = [
                        'alt' => $matches[1],
                        'main' => 'BTC',
                    ];

                    // Get buy price
                    if (!preg_match('/BUY:\s*([\d.]+)/', $message, $matches)) {
                        throw new \Exception('GetBuyPrice Regular expression failed for CryptoBullet: ' . $message);
                    }
                    $parsed['buyPrice'] = floatval($matches[1]);

                    // Get targets
                    $parsed['targets'] = [];
                    if (!preg_match('/TARGET1:\s*([\d.]+)/', $message, $matches)) {
                        throw new \Exception('GetTargets Regular expression failed for CryptoBullet: ' . $message);
                    }
                    $parsed['targets'][] = $matches[1];
                    if (preg_match('/TARGET2:\s*([\d.]+)/', $message, $matches)) {
                        $parsed['targets'][] = floatval($matches[1]);
                    }
                    if (preg_match('/TARGET3:\s*([\d.]+)/', $message, $matches)) {
                        $parsed['targets'][] = floatval($matches[1]);
                    }

                    // Get stop loss
                    if (!preg_match('/STOP LOSS:\s*([\d.]+)/', $message, $matches)) {
                        throw new \Exception('GetStopLoss Regular expression failed for CryptoBullet: ' . $message);
                    }
                    $parsed['stopLoss'] = floatval($matches[1]);

                    return $parsed;
                },
            ],
            'CryptoLionSignals' => [
                'name' => 'CryptoLionSignals (Bittrex)',
                'exchange' => 'bittrex',
                'parse' => function($message) {
                    $parsed = [];
                    // Get coin pair
                    if (!preg_match('/#([A-Z]{3,4})/', $message, $matches)) {
                        throw new \Exception('GetCoinPair Regular expression failed for CryptoLionSignals: ' . $message);
                    }
                    $parsed['coinPair'] = [
                        'alt' => $matches[1],
                        'main' => 'BTC',
                    ];

                    // Get buy price
                    if (!preg_match('/Buy price\s*[\d.]+ - ([\d.]+)/', $message, $matches)) {
                        throw new \Exception('GetBuyPrice Regular expression failed for CryptoLionSignals: ' . $message);
                    }
                    $parsed['buyPrice'] = floatval($matches[1]);

                    // Get targets
                    $parsed['targets'] = [];
                    if (!preg_match('/Sell price\s*([\d.]+) - ([\d.]+)/', $message, $matches)) {
                        throw new \Exception('GetTargets Regular expression failed for CryptoLionSignals: ' . $message);
                    }
                    $parsed['targets'][] = $matches[1];
                    $parsed['targets'][] = $matches[2];

                    // Calculate stop loss based on buy price
                    $parsed['stopLoss'] = floatval(($parsed['buyPrice'] / 100) * 90);

                    return $parsed;
                },
            ],
            'VipCryptoZ' => [
                'name' => 'VipCryptoZ (Bittrex)',
                'exchange' => 'bittrex',
                'parse' => function($message) {
                    $parsed = [];
                    // Get coin pair
                    if (!preg_match('/#([A-Z]{3,4})/', $message, $matches)) {
                        throw new \Exception('GetCoinPair Regular expression failed for VipCryptoZ: ' . $message);
                    }
                    $parsed['coinPair'] = [
                        'alt' => $matches[1],
                        'main' => 'BTC',
                    ];

                    // Get buy price
                    if (!preg_match('/BUY[\s:]*([\d.]+)/i', $message, $matches)) {
                        throw new \Exception('GetBuyPrice Regular expression failed for VipCryptoZ: ' . $message);
                    }
                    $parsed['buyPrice'] = floatval($matches[1]);

                    // Get targets
                    $parsed['targets'] = [];
                    if (!preg_match('/Sell[\s:]*([\d.]+)[\s-]*([\d.]+)/i', $message, $matches)) {
                        throw new \Exception('GetTargets Regular expression failed for CryptoLionSignals: ' . $message);
                    }
                    $parsed['targets'][] = $matches[1];
                    $parsed['targets'][] = $matches[2];

                    // Get stop loss
                    if (!preg_match('/STOP LOSS?[\s:]*([\d.]+)/i', $message, $matches)) {
                        throw new \Exception('GetBuyPrice Regular expression failed for VipCryptoZ: ' . $message);
                    }
                    $parsed['buyPrice'] = floatval($matches[1]);

                    return $parsed;
                },
            ],
        ];
    }

    protected function outputMessage($message, $timestamp, $channelTitle, $channelUsername)
    {
        echo PHP_EOL;
        echo '==============================================================================================' . PHP_EOL;
        echo 'Channel: ' . $channelTitle . ($channelUsername ? ' (' . $channelUsername . ')' : '') . PHP_EOL;
        echo 'Time: ' . date('Y-m-d H:i:s', $timestamp) . PHP_EOL;
        echo '==============================================================================================' . PHP_EOL;


        echo trim($message) . PHP_EOL;
        echo '----------------------------------------------------------------------------------------------' . PHP_EOL . PHP_EOL;
    }

    protected function parseMessageForChannel($message, $channel)
    {
        $parsed = $channel['parse']($message);

        switch ($channel['exchange']) {
            case 'bittrex' :
                $response = file_get_contents('https://bittrex.com/api/v1.1/public/getticker?market=' . $parsed['coinPair']['main'] . '-' . $parsed['coinPair']['alt']);
                if (!$response) {
                    throw new \Exception('Unable to get response from Bittrex ticker.');
                }
                $ticker = json_decode($response, true);
                if (!$ticker['success']) {
                    throw new \Exception('Error getting Bittrex ticker: ' . $ticker['message']);
                }

                $priceDiff = $parsed['buyPrice'] - $ticker['result']['Last'];
                $exchangeUrl = 'https://bittrex.com/Market/Index?MarketName=' . $parsed['coinPair']['main'] . '-' . $parsed['coinPair']['alt'];

                break;
            default :
                throw new \Exception('Invalid exchange type: ' . $channel['exchange']);
        }

        echo PHP_EOL;
        echo '==============================================================================================' . PHP_EOL;
        echo '==============================================================================================' . PHP_EOL;
        echo '==============================================================================================' . PHP_EOL;
        echo 'Channel: ' . $channel['name'] . PHP_EOL;
        echo '==============================================================================================' . PHP_EOL;


        echo 'Coin Pair: ' . implode('-', $parsed['coinPair']) . PHP_EOL;
        echo 'BUY: ' . number_format($parsed['buyPrice'], 8) . PHP_EOL;
        echo 'TARGETS: ' . PHP_EOL;
        foreach ($parsed['targets'] as $i => $target) {
            echo '    ' . ($i+1) . ': ' . number_format($target, 8) . PHP_EOL;
        }
        echo 'STOP LOSS: ' . number_format($parsed['stopLoss'], 8) . PHP_EOL;
        echo 'PRICE DIFF: ' . number_format($priceDiff, 8) . PHP_EOL;
        echo 'EXCHANGE: ' . $exchangeUrl . PHP_EOL;
        echo '----------------------------------------------------------------------------------------------' . PHP_EOL;
        echo '----------------------------------------------------------------------------------------------' . PHP_EOL;
        echo '----------------------------------------------------------------------------------------------' . PHP_EOL;
        echo PHP_EOL;
    }

}
<?php

namespace Telegram;

use danog\MadelineProto\API;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Exception as ApiException;

class Telegram
{
    protected $MadelineProto;

    protected $duplicatesCache = [];

    protected $channels = [];
    protected $currencies = [];

    /**
     * Telegram constructor.
     * @throws ApiException
     */
    public function __construct()
    {
        $this->initTelegram();

        $this->getChannels();

        $this->getCurrencies();
    }

    protected function initTelegram()
    {
        $settings = json_decode(getenv('MTPROTO_SETTINGS'), true) ?: [];

        $settings['updates'] = [
            'handle_updates' => true,
            'handle_old_updates' => true,
            'callback' => [$this, 'handleUpdate'],
        ];

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

    public function handleUpdate($update)
    {
//        echo json_encode($update, JSON_PRETTY_PRINT) . PHP_EOL;

        // Check the update type is a new message, and that the message has content
        if (!isset($update['_'])
            || $update['_'] !== 'updateNewChannelMessage'
            || !isset($update['message'])
            || !isset($update['message']['message'])
            || !trim($update['message']['message'])
        ) {
            return;
        }

        // Get the message body
        $message = $update['message']['message'];

        // Check a local cache array to see if this message is a duplicate
        if (isset($this->duplicatesCache[$message])) {
            // Skip if it was already added to the duplicates cache
            return;
        }

        // Add the message content to the duplicates cache
        $this->duplicatesCache[$message] = 1;

        // Make sure the duplicates cache has no more than 100 messages in it
        if (count($this->duplicatesCache) > 100) {
            array_shift($this->duplicatesCache);
        }

        $timestamp = $update['message']['date'];

        if ($currencyFound = $this->messageContainsCurrency($message)) {
            echo 'Message contains currency: ' . $currencyFound . PHP_EOL;
        } else {
            return;
        }

        $channelInfo = $this->MadelineProto->get_info('channel#' . $update['message']['to_id']['channel_id']);

//        echo json_encode($channelInfo, JSON_PRETTY_PRINT) . PHP_EOL;

        $channelTitle = $channelInfo['Chat']['title'];
        $channelUsername = (isset($channelInfo['Chat']['username']) ? $channelInfo['Chat']['username'] : null);

        if ($channelUsername && isset($this->channels[$channelUsername])) {
            try {
                $this->parseMessageForChannel($message, $this->channels[$channelUsername]);
            } catch (\Exception $e) {
                echo $e->getMessage() . PHP_EOL;
                $this->outputMessage($message, $timestamp, $channelTitle, $channelUsername);
            }
        } else {
            $this->outputMessage($message, $timestamp, $channelTitle, $channelUsername);
        }
    }

    public function getUpdates()
    {
        while (true) {
            $this->MadelineProto->API->get_updates(['limit' => 50, 'timeout' => 1]);

            $this->MadelineProto->serialize();
        }
    }

    public function test($message, $channelUsername)
    {
        if ($currencyFound = $this->messageContainsCurrency($message)) {
            echo 'Message contains currency: ' . $currencyFound . PHP_EOL;
        }
        if ($channelUsername && isset($this->channels[$channelUsername])) {
            $this->parseMessageForChannel($message, $this->channels[$channelUsername]);
        } else {
            throw new \Exception('Channel not configured for username: ' . $channelUsername);
        }
    }

    protected function getChannels()
    {
        $this->channels = [
            'cryptobullet' => [
                'name' => 'CryptoBullet (Bittrex)',
                'exchange' => 'bittrex',
                'parse' => function($message) {
                    $parsed = [];
                    // Get coin pair
                    if (!preg_match('/([A-Z]{3,5})\/([A-Z]{3,5})/i', $message, $matches)) {
                        throw new \Exception('GetCoinPair Regular expression failed for CryptoBullet: ' . $message);
                    }
                    $parsed['coinPair'] = [
                        'alt' => strtoupper($matches[1]),
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
                    if (preg_match('/STOP LOSS:\s*([\d.]+)/', $message, $matches)) {
                        $parsed['stopLoss'] = floatval($matches[1]);
                    } else {
                        // Calculate stop loss based on buy price
                        $parsed['stopLoss'] = floatval(($parsed['buyPrice'] / 100) * 90);
                    }

                    return $parsed;
                },
            ],
            'CryptoLionSignals' => [
                'name' => 'CryptoLionSignals (Bittrex)',
                'exchange' => 'bittrex',
                'parse' => function($message) {
                    $parsed = [];
                    // Get coin pair
                    if (!preg_match('/#([A-Z]{3,5})/i', $message, $matches)) {
                        throw new \Exception('GetCoinPair Regular expression failed for CryptoLionSignals: ' . $message);
                    }
                    $parsed['coinPair'] = [
                        'alt' => strtoupper($matches[1]),
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
                    if (!preg_match('/#([A-Z]{3,5})/i', $message, $matches)) {
                        throw new \Exception('GetCoinPair Regular expression failed for VipCryptoZ: ' . $message);
                    }
                    $parsed['coinPair'] = [
                        'alt' => strtoupper($matches[1]),
                        'main' => 'BTC',
                    ];

                    // Get buy price
                    if (!preg_match('/BUY[\s:]*([\d.]+)/i', $message, $matches)) {
                        throw new \Exception('GetBuyPrice Regular expression failed for VipCryptoZ: ' . $message);
                    }
                    $parsed['buyPrice'] = (floatval($matches[1]) / 100000000);

                    // Get targets
                    $parsed['targets'] = [];
                    if (!preg_match('/Sell[\s:]*([\d.]+)[\s-]*([\d.]+)/i', $message, $matches)) {
                        throw new \Exception('GetTargets Regular expression failed for VipCryptoZ: ' . $message);
                    }
                    $parsed['targets'][] = (floatval($matches[1]) / 100000000);
                    $parsed['targets'][] = (floatval($matches[2]) / 100000000);

                    // Get stop loss
                    if (preg_match('/STOP LOSS?[\s:]*([\d.]+)/i', $message, $matches)) {
                        $parsed['stopLoss'] = (floatval($matches[1]) / 100000000);
                    } else {
                        // Calculate stop loss based on buy price
                        $parsed['stopLoss'] = floatval(($parsed['buyPrice'] / 100) * 90);
                    }

                    return $parsed;
                },
            ],
            'kingcryptosignal' => [
                'name' => 'CRYPTO SKY SIGNAL (Bittrex)',
                'exchange' => 'bittrex',
                'parse' => function($message) {
                    $parsed = [];
                    // Get coin pair
                    if (!preg_match('/#([A-Z]{3,5})/i', $message, $matches)) {
                        throw new \Exception('GetCoinPair Regular expression failed for CRYPTO SKY SIGNAL: ' . $message);
                    }
                    $parsed['coinPair'] = [
                        'alt' => strtoupper($matches[1]),
                        'main' => 'BTC',
                    ];

                    // Get buy price
                    if (!preg_match('/BUY[\s:]*([\d.]+)/i', $message, $matches)) {
                        throw new \Exception('GetBuyPrice Regular expression failed for CRYPTO SKY SIGNAL: ' . $message);
                    }
                    $parsed['buyPrice'] = (floatval($matches[1]) / 100000000);

                    // Get targets
                    $parsed['targets'] = [];
                    if (!preg_match('/Sell targets:[\s:]*([\d.]+)[\s-]*([\d.]+)/i', $message, $matches)) {
                        throw new \Exception('GetTargets Regular expression failed for CRYPTO SKY SIGNAL: ' . $message);
                    }
                    $parsed['targets'][] = (floatval($matches[1]) / 100000000);
                    $parsed['targets'][] = (floatval($matches[2]) / 100000000);

                    // Calculate stop loss based on buy price
                    $parsed['stopLoss'] = floatval(($parsed['buyPrice'] / 100) * 90);

                    return $parsed;
                },
            ],
            'bittrexxnews' => [
                'name' => 'Bittrex signals (Bittrex)',
                'exchange' => 'bittrex',
                'parse' => function($message) {
                    $parsed = [];
                    // Get coin pair & buy price
                    if (!preg_match('/Buy #([A-Z]{3,5})\s*(\d+)/i', $message, $matches)) {
                        throw new \Exception('GetCoinPair Regular expression failed for CRYPTO SKY SIGNAL: ' . $message);
                    }
                    $parsed['coinPair'] = [
                        'alt' => strtoupper($matches[1]),
                        'main' => 'BTC',
                    ];

                    $parsed['buyPrice'] = (floatval($matches[2]) / 100000000);

                    // Get targets
                    $parsed['targets'] = [];
                    if (!preg_match('/Sell\s*([\d.]+)[\s-]*([\d.]+)([\s-]*([\d.]+))?([\s-]*([\d.]+))?/i', $message, $matches)) {
                        throw new \Exception('GetTargets Regular expression failed for CRYPTO SKY SIGNAL: ' . $message);
                    }
                    $parsed['targets'][] = (floatval($matches[1]) / 100000000);
                    $parsed['targets'][] = (floatval($matches[2]) / 100000000);
                    if (isset($matches[4])) {
                        $parsed['targets'][] = (floatval($matches[4]) / 100000000);
                    }
                    if (isset($matches[6])) {
                        $parsed['targets'][] = (floatval($matches[6]) / 100000000);
                    }

                    // Calculate stop loss based on buy price
                    $parsed['stopLoss'] = floatval(($parsed['buyPrice'] / 100) * 90);

                    return $parsed;
                },
            ],
            'cryptovipsignall' => [
                'name' => 'Vip Signal ™ (Bittrex)',
                'exchange' => 'bittrex',
                'parse' => function($message) {
                    $parsed = [];
                    // Get coin pair & buy price
                    if (!preg_match('/#([A-Z]{3,5})/i', $message, $matches)) {
                        throw new \Exception('GetCoinPair Regular expression failed for Vip Signal ™: ' . $message);
                    }
                    $parsed['coinPair'] = [
                        'alt' => strtoupper($matches[1]),
                        'main' => 'BTC',
                    ];

                    // Get buy price
                    if (!preg_match('/Buy[A-Z\s\@]*([\d.]+)/i', $message, $matches)) {
                        throw new \Exception('GetBuyPrice Regular expression failed for Vip Signal ™: ' . $message);
                    }
                    $parsed['buyPrice'] = (floatval($matches[1]) / 100000000);

                    // Get targets
                    $parsed['targets'] = [];
                    if (!preg_match('/Targets[\s:]*([\d.]+)[\s-]*([\d.]+)([\s-]*([\d.]+))?/i', $message, $matches)) {
                        throw new \Exception('GetTargets Regular expression failed for Vip Signal ™: ' . $message);
                    }
                    $parsed['targets'][] = (floatval($matches[1]) / 100000000);
                    $parsed['targets'][] = (floatval($matches[2]) / 100000000);
                    if (isset($matches[4])) {
                        $parsed['targets'][] = (floatval($matches[4]) / 100000000);
                    }

                    // Calculate stop loss based on buy price
                    $parsed['stopLoss'] = floatval(($parsed['buyPrice'] / 100) * 90);

                    return $parsed;
                },
            ],
            'cryptonerds23' => [
                'name' => 'CRYPTO NERDS (Bittrex)',
                'exchange' => 'bittrex',
                'parse' => function($message) {
                    $parsed = [];
                    // Get coin pair
                    if (!preg_match('/#coin name - ([A-Z]{3,5})/i', $message, $matches)) {
                        throw new \Exception('GetCoinPair Regular expression failed for CRYPTO SKY SIGNAL: ' . $message);
                    }
                    $coinName = $matches[1];
                    if (strtolower($coinName) === 'bcash') {
                        $coinName = 'BCC';
                    }
                    $parsed['coinPair'] = [
                        'alt' => strtoupper($coinName),
                        'main' => 'BTC',
                    ];

                    // Get buy price
                    if (!preg_match('/Buy price - [\s:]*([\d.]+)/i', $message, $matches)) {
                        throw new \Exception('GetBuyPrice Regular expression failed for CRYPTO SKY SIGNAL: ' . $message);
                    }
                    $parsed['buyPrice'] = floatval($matches[1]);

                    // Get targets
                    $parsed['targets'] = [];
                    if (!preg_match('/TARGET\s+1[\s-]*([\d.]+)/i', $message, $matches)) {
                        throw new \Exception('GetTargets Regular expression failed for CRYPTO SKY SIGNAL: ' . $message);
                    }
                    $parsed['targets'][] = floatval($matches[1]);
                    if (!preg_match('/TARGET\s+2[\s-]*([\d.]+)/i', $message, $matches)) {
                        throw new \Exception('GetTargets Regular expression failed for CRYPTO SKY SIGNAL: ' . $message);
                    }
                    $parsed['targets'][] = floatval($matches[1]);
                    if (!preg_match('/TARGET\s+3[\s-]*([\d.]+)/i', $message, $matches)) {
                        throw new \Exception('GetTargets Regular expression failed for CRYPTO SKY SIGNAL: ' . $message);
                    }
                    $parsed['targets'][] = floatval($matches[1]);

                    // Calculate stop loss based on buy price
                    $parsed['stopLoss'] = floatval(($parsed['buyPrice'] / 100) * 90);

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

                $exchangePrice = $ticker['result']['Last'];
                $priceDiff = $parsed['buyPrice'] - $exchangePrice;
                $exchangeUrl = 'https://bittrex.com/Market/Index?MarketName=' . $parsed['coinPair']['main'] . '-' . $parsed['coinPair']['alt'];

                break;
            default :
                throw new \Exception('Invalid exchange type: ' . $channel['exchange']);
        }

        $priceDiffPct = ($priceDiff / $parsed['buyPrice']) * 100;

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
        echo 'EXCHANGE PRICE: ' . number_format($exchangePrice, 8) . PHP_EOL;
        echo 'PRICE DIFF: %' . number_format($priceDiffPct, 2) . PHP_EOL;
        echo 'EXCHANGE: ' . $exchangeUrl . PHP_EOL;
        echo '----------------------------------------------------------------------------------------------' . PHP_EOL;
        echo '----------------------------------------------------------------------------------------------' . PHP_EOL;
        echo '----------------------------------------------------------------------------------------------' . PHP_EOL;
        echo PHP_EOL;

        $coinMonitorPath = realpath(dirname(__FILE__) . '/../../coinmonitor/coinmonitor.php');
        $args = [
            $parsed['coinPair']['alt'],
            $channel['exchange'],
            number_format($parsed['buyPrice'], 8),
            number_format($parsed['targets'][0], 8) . ',' . number_format($parsed['targets'][1], 8),
            number_format($parsed['stopLoss'], 8),
        ];
        $command = 'php ' . $coinMonitorPath . ' ' . implode(' ', $args) . ' &';

        echo $command . PHP_EOL;

        pclose(popen($command, 'r'));
    }

    protected function getCurrencies()
    {
        $currencies = [
            'bittrex' => [],
        ];
        foreach (json_decode(file_get_contents(dirname(__FILE__) . '/../bittrex-currencies.json')) as $currency) {
            $currencies['bittrex'][] = $currency->Currency;
        }

        $this->currencies = $currencies;
    }

    protected function messageContainsCurrency($message)
    {
        foreach($this->currencies['bittrex'] as $currency) {
            if (stripos($message, $currency) !== false) {
                return $currency;
            }
        }

        return false;
    }

}
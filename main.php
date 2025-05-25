<?php

require '/root/big_manage_telegram/utilities/utilities.php';
$token = get_keys_from_file(
    "telegram_token"
);

if ($token === null) {
    exit("No Discord token found");
}
ini_set('memory_limit', '-1');
require '/root/vendor/autoload.php';

require '/root/big_manage_telegram/utilities/sql.php';
require '/root/big_manage_telegram/utilities/communication.php';
require '/root/big_manage_telegram/utilities/evaluator.php';

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\TelegramLog;

$files = evaluator::run(
    array(
        "/var/www/.structure/library/bigmanage/init.php"
    )
);

if (!empty($files)) {
    foreach ($files as $path) {
        require $path;
    }
}

global $token;

try {
    $telegram = new Telegram(
        $token,
        "IdealisticBigManageBot"
    );
    $telegram->useGetUpdatesWithoutDatabase();
    $telegram->addCommandsPath('/root/big_manage_telegram/commands');

    // Setup logging
    TelegramLog::initialize(
        new \Monolog\Logger('telegram', [new \Monolog\Handler\StreamHandler(__DIR__ . '/bot.log')]),
        new \Monolog\Logger('telegram_error', [new \Monolog\Handler\StreamHandler(__DIR__ . '/bot_error.log')])
    );
} catch (Throwable $e) {
    exit('Error initializing Telegram bot: ' . $e->getMessage());
}

while (true) {
    try {
        $telegram->handle();
    } catch (Throwable $e) {
        echo 'Error: ' . $e->getMessage();
    }
    sleep(1); // Prevent tight loop in case of errors
}

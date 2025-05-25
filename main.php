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
use React\EventLoop\Loop;

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

try {
    $telegram = new Telegram(
        $token[0],
        "IdealisticBigManageBot"
    );
    $telegram->useGetUpdatesWithoutDatabase();
    $telegram->addCommandsPath('/root/big_manage_telegram/commands');
} catch (Throwable $e) {
    exit('Error initializing Telegram bot: ' . $e->getMessage());
}

// Separator

class TelegramBotHandler
{
    public static $queue = array();
}

$loop = Loop::get();
$lastUpdateId = 0;

$loop->addPeriodicTimer(0, function () use (&$lastUpdateId, $telegram) {
    $response = Request::getUpdates([
        'offset' => $lastUpdateId + 1,
        'timeout' => 1
    ]);

    if ($response->isOk()) {
        try {
            foreach ($response->getResult() as $update) {
                $telegram->setCustomInput(json_encode($update));
                $telegram->handle();
                $lastUpdateId = $update->getUpdateId();
            }
        } catch (Throwable $e) {
            exit('Error handling update: ' . $e->getMessage());
        }
    }
});

$loop->run();

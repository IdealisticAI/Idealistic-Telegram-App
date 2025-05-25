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

use Longman\TelegramBot\Telegram;

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
    $result = $telegram->deleteWebhook();
    echo $result->isOk() ? 'Webhook deleted successfully' : $result->getDescription();
} catch (Throwable $e) {
    exit('Error initializing Telegram bot: ' . $e->getMessage());
}

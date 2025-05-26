<?php

require '/root/big_manage_telegram/utilities/utilities.php';
$token = get_keys_from_file(
    "telegram_token"
);

if ($token === null) {
    exit("No Telegram token found");
}
ini_set('memory_limit', '-1');
require '/root/vendor/autoload.php';

require '/root/big_manage_telegram/utilities/sql.php';
require '/root/big_manage_telegram/utilities/communication.php';
require '/root/big_manage_telegram/utilities/evaluator.php';

use Longman\TelegramBot\Entities\Message;
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
    public static array $queue = array();
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

$loop->addPeriodicTimer(
    BigManageLimit::EXTERNAL_APPLICATION_QUERY_SECONDS,
    function () {
        // todo notifications
    }
);

$loop->addPeriodicTimer(
    BigManageLimit::EXTERNAL_APPLICATION_QUERY_SECONDS,
    function () {
        foreach (TelegramBotHandler::$queue as $promptID => $details) {
            $user = $details[0];
            $message = $details[1];
            $time = $details[2];
            $updateCooldown = $details[3];

            if (!($user instanceof BigManageUser)
                || !($message instanceof Message)
                || !is_int($time)
                || !is_numeric($updateCooldown)) {
                unset(TelegramBotHandler::$queue[$promptID]);
                Request::editMessageText([
                    "chat_id" => $message->getChat()->getId(),
                    "message_id" => $message->getMessageId(),
                    "text" => BigManageGeneralMessage::EXCEPTION_THROWN
                ]);
                continue;
            }
            try {
                $prompt = $user->getPrompt($promptID);

                if ($prompt === null) {
                    continue;
                }
                $processing = $prompt->isProcessing();
                $replies = $prompt->getReplies();

                if (empty($replies)) {
                    if (!$processing) {
                        unset(TelegramBotHandler::$queue[$promptID]);
                        Request::editMessageText([
                            "chat_id" => $message->getChat()->getId(),
                            "message_id" => $message->getMessageId(),
                            "text" => BigManageGeneralMessage::EXCEPTION_THROWN
                        ]);
                    }
                    continue;
                }
                if ($processing) {
                    if (microtime(true) < $updateCooldown) {
                        continue;
                    }
                    TelegramBotHandler::$queue[$promptID][3] = microtime(true) + 0.5;
                } else {
                    unset(TelegramBotHandler::$queue[$promptID]);
                }
                $byteCount = array();
                $messageAttachments = array();
                $lastMessage = 0;
                $pieces = array();

                foreach ($replies as $reply) {
                    if (!($reply instanceof BigManageHistoryReply)) {
                        continue;
                    }
                    $pieces = array_merge(
                        $pieces,
                        str_split(
                            $reply->getAnswer(),
                            BigManageLimit::MESSAGE_CHARACTER_LIMIT[BigManageAccessPlatform::TELEGRAM]
                        )
                    );
                }
                foreach ($pieces as $key => $piece) {
                    $byteCount[$key] = strlen($piece);
                }
                $attachments = array_merge(
                    $prompt->getCreatedAttachments(),
                    $prompt->getRequestedAttachments(false)
                );

                if (!empty($attachments)) {
                    $byteLimit = floor(BigManageLimit::ATTACHMENT_BYTES_LIMIT[BigManageAccessPlatform::TELEGRAM] * 0.99);

                    foreach ($attachments as $attachment) {
                        if (!($attachment instanceof BigManageAttachment)) {
                            continue;
                        }
                        $fullBytes = $attachment->getFullBytes();

                        if ($attachment->getName() !== null
                            && $fullBytes <= $byteLimit
                            && ($attachment->nameHasFormat()
                                || $attachment->getSimpleFormat() !== null)) {
                            $data = $attachment->getDecodedData();

                            if ($data !== null) {
                                if (($byteCount[$lastMessage] ?? 0) + $fullBytes > $byteLimit) {
                                    $lastMessage++;
                                }
                                if (array_key_exists($lastMessage, $byteCount)) {
                                    $byteCount[$lastMessage] += $fullBytes;
                                } else {
                                    $byteCount[$lastMessage] = $fullBytes;
                                }
                                if (array_key_exists($lastMessage, $messageAttachments)) {
                                    $messageAttachments[$lastMessage][] = $attachment;
                                } else {
                                    $messageAttachments[$lastMessage] = array($attachment);
                                }
                            }
                        }
                    }
                }
                Request::editMessageText([
                    "chat_id" => $message->getChat()->getId(),
                    "message_id" => $message->getMessageId(),
                    "text" => array_shift($pieces)
                ]);

                if (!empty($pieces)) {
                    foreach ($pieces as $piece) {
                        Request::sendMessage([
                            "chat_id" => $message->getChat()->getId(),
                            "text" => $piece
                        ]);
                    }
                }
                if (!empty($messageAttachments)) {
                    foreach ($messageAttachments as $attachments) {
                        $builder = MessageBuilder::new();

                        foreach ($attachments as $attachment) {
                            if (!($attachment instanceof BigManageAttachment)) {
                                continue;
                            }
                            $builder->addFileFromContent(
                                $attachment->getName()
                                . ($attachment->nameHasFormat()
                                    ? ""
                                    : "." . $attachment->getSimpleFormat()),
                                $attachment->getDecodedData()
                            );
                        }
                        $message->reply($builder);
                    }
                }
            } catch (Throwable $e) {
                BigManageError::storeThrowable(
                    $user->getTeam(),
                    $user,
                    $e
                );
                Request::editMessageText([
                    "chat_id" => $message->getChat()->getId(),
                    "message_id" => $message->getMessageId(),
                    "text" => BigManageGeneralMessage::EXCEPTION_THROWN
                ]);
            }
        }
    }
);

$loop->run();

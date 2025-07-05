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
    } else {
        $error = $response->getDescription();

        if ($error) {
            echo "Error handling response: $error\n";
        } else {
            echo "Unknown error occurred while handling response.\n";
        }
    }
});

$loop->addPeriodicTimer(
    BigManageLimit::EXTERNAL_APPLICATION_QUERY_SECONDS,
    function () {
        $notifications = BigManageNotifications::retrieve(BigManageAccessPlatform::TELEGRAM);

        if (!empty($notifications)) {
            foreach ($notifications as $notification) {
                if (!($notification instanceof BigManageNotification)) {
                    continue;
                }
                $identity = $notification->getUser()->getLastIdentity();

                if ($identity === null
                    || $identity->getPlatformID() !== BigManageAccessPlatform::TELEGRAM) {
                    continue;
                }
                $chat_id = $identity->getPlatformChatID();

                if ($chat_id === null) {
                    continue;
                }
                if ($notification->process()) {
                    if ($notification->getAttachmentName() !== null
                        && $notification->getAttachmentContent() !== null
                        || $notification->getMessage() !== null) {
                        if ($notification->getAttachmentContent() === null) {
                            Request::sendMessage([
                                "chat_id" => $chat_id,
                                "text" => $notification->getMessage()
                            ]);
                        } else {
                            $data = $notification->isBase64()
                                ? base64_decode($notification->getAttachmentContent())
                                : $notification->getAttachmentContent();
                            $tempPath = sys_get_temp_dir() . "/" . $notification->getAttachmentName();
                            file_put_contents($tempPath, $data);
                            $file = Request::encodeFile($tempPath);
                            $data = [
                                "chat_id" => $chat_id,
                            ];

                            if ($notification->getMessage() !== null) {
                                $data["caption"] = $notification->getMessage();
                            }
                            if ($notification->isImage()) {
                                $data["photo"] = $file;
                                Request::sendPhoto($data);
                            } else if ($notification->isAudio()) {
                                $data["audio"] = $file;
                                Request::sendAudio($data);
                            } else if ($notification->isVideo()) {
                                $data["video"] = $file;
                                Request::sendVideo($data);
                            } else {
                                $data["document"] = $file;
                                Request::sendDocument($data);
                            }
                            unlink($tempPath);
                        }
                    }
                }
            }
        }
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
                    "text" => BigManageGeneralMessage::EXCEPTION_THROWN . " (#193746820)"
                ]);
                continue;
            }
            $chat_id = $message->getChat()->getId();

            try {
                $prompt = $user->getPrompt($promptID);

                if ($prompt === null) {
                    continue;
                }
                $replies = $prompt->getReplies();

                if ($prompt->isProcessing()) {
                    if (microtime(true) < $updateCooldown) {
                        continue;
                    }
                } else {
                    unset(TelegramBotHandler::$queue[$promptID]);

                    if (empty($replies)
                        && !$prompt->sentNotification()) {
                        Request::editMessageText([
                            "chat_id" => $chat_id,
                            "message_id" => $message->getMessageId(),
                            "text" => BigManageStrings::translateMessage(
                                BigManageGeneralMessage::EXCEPTION_THROWN . " (#910428913)",
                                $user
                            )
                        ]);
                        continue;
                    }
                }
                $pieces = array();

                if (!empty($replies)) {
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

                    if (!empty($pieces)) {
                        if (Request::editMessageText([
                            "chat_id" => $chat_id,
                            "message_id" => $message->getMessageId(),
                            "text" => array_shift($pieces)
                        ])->isOk()) {
                            TelegramBotHandler::$queue[$promptID][3] = microtime(true) + 0.5;
                        }

                        if (!empty($pieces)) {
                            foreach ($pieces as $piece) {
                                Request::sendMessage([
                                    "chat_id" => $chat_id,
                                    "text" => $piece,
                                    "reply_to_message_id" => $message->getMessageId()
                                ]);
                            }
                        }
                    }
                }
                $attachments = array_merge(
                    $prompt->getCreatedAttachments(),
                    $prompt->getRequestedAttachments(false)
                );

                if (!empty($attachments)) {
                    foreach ($attachments as $attachment) {
                        if (!($attachment instanceof BigManageAttachment)
                            || $attachment->getBytes() > BigManageLimit::ATTACHMENT_BYTES_LIMIT[BigManageAccessPlatform::TELEGRAM]) {
                            continue;
                        }
                        $tempPath = sys_get_temp_dir() . "/" . $attachment->getName();
                        file_put_contents($tempPath, $attachment->getDecodedData());
                        $file = Request::encodeFile($tempPath);
                        $data = [
                            "chat_id" => $chat_id,
                        ];

                        if ($attachment->getAnalyzedDescription() !== null) {
                            $data["caption"] = $attachment->getAnalyzedDescription();
                        }
                        if ($attachment->isImage()) {
                            $data["photo"] = $file;
                            Request::sendPhoto($data);
                        } else if ($attachment->isAudio()) {
                            $data["audio"] = $file;
                            Request::sendAudio($data);
                        } else if ($attachment->isVideo()) {
                            $data["video"] = $file;
                            Request::sendVideo($data);
                        } else {
                            $data["document"] = $file;
                            Request::sendDocument($data);
                        }
                        unlink($tempPath);
                    }
                }
            } catch (Throwable $e) {
                BigManageError::storeThrowable(
                    $user->getTeam(),
                    $user,
                    $e
                );
                Request::editMessageText([
                    "chat_id" => $chat_id,
                    "message_id" => $message->getMessageId(),
                    "text" => BigManageGeneralMessage::EXCEPTION_THROWN . " (#582013947)"
                ]);
            }
        }
    }
);

$loop->run();

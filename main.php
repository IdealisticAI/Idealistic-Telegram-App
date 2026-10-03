<?php

require '/root/idealistic_telegram/utilities/utilities.php';
$token = get_keys_from_file(
    "telegram_token"
);

if ($token === null) {
    exit("No Telegram token found");
}
ini_set('memory_limit', '-1');
require '/root/vendor/autoload.php';
require '/root/idealistic_telegram/utilities/response.php';
require '/root/idealistic_telegram/utilities/sql.php';
require '/root/idealistic_telegram/utilities/communication.php';
require '/root/idealistic_telegram/utilities/evaluator.php';

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use React\EventLoop\Loop;

$files = evaluator::run(
    array(
        "/var/www/.structure/library/idealistic_office/init.php"
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
        "IdealisticBot"
    );
    $telegram->useGetUpdatesWithoutDatabase();
    $telegram->addCommandsPath('/root/idealistic_telegram/commands');
} catch (Throwable $e) {
    exit('Error initializing Telegram bot: ' . $e->getMessage());
}

TelegramServerResponse::handle(Request::setMyCommands([
    "commands" => [
        [
            "command" => "idealistic_setup",
            "description" => "Install a portal in this group or topic."
        ],
        [
            "command" => "idealistic_remove",
            "description" => "Uninstall the portal of this group or topic."
        ]
    ],
    "scope" => [
        "type" => "all_chat_administrators"
    ]
]));

// Separator

class TelegramBotHandler
{
    public static array $queue = array();
}

$loop = Loop::get();
$lastUpdateId = 0;

$loop->addPeriodicTimer(0, function () use (&$lastUpdateId, $telegram) {
    $response = TelegramServerResponse::handle(Request::getUpdates([
        'offset' => $lastUpdateId + 1,
        'timeout' => 1
    ]));

    if (TelegramServerResponse::handle($response)->isOk()) {
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
    IdealisticOfficeLimit::EXTERNAL_APPLICATION_QUERY_SECONDS,
    function () {
        $notifications = IdealisticOfficeNotifications::retrieve(IdealisticOfficeAccessPlatform::TELEGRAM);

        if (!empty($notifications)) {
            foreach ($notifications as $notification) {
                $identity = $notification->getUser()?->getLastIdentity();

                if ($identity === null
                    || $identity->getPlatformID() !== IdealisticOfficeAccessPlatform::TELEGRAM) {
                    continue;
                }
                $chat_id = $identity->getPlatformChatID();

                if ($chat_id === null) {
                    continue;
                }
                if ($notification->process()
                    && ($notification->getAttachmentName() !== null
                        && $notification->getAttachmentContent() !== null
                        || $notification->getMessage() !== null)) {
                    if ($notification->getAttachmentContent() === null) {
                        TelegramServerResponse::handle(Request::sendMessage([
                            "chat_id" => $chat_id,
                            "text" => $notification->getMessage()
                        ]));
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
                            TelegramServerResponse::handle(Request::sendPhoto($data));
                        } else if ($notification->isAudio()) {
                            $data["audio"] = $file;
                            TelegramServerResponse::handle(Request::sendAudio($data));
                        } else if ($notification->isVideo()) {
                            $data["video"] = $file;
                            TelegramServerResponse::handle(Request::sendVideo($data));
                        } else {
                            $data["document"] = $file;
                            TelegramServerResponse::handle(Request::sendDocument($data));
                        }
                        unlink($tempPath);
                    }
                }
            }
        }
    }
);

$loop->addPeriodicTimer(
    IdealisticOfficeLimit::EXTERNAL_APPLICATION_QUERY_SECONDS,
    function () {
        $updateSeconds = 2;

        foreach (TelegramBotHandler::$queue as $promptID => $details) {
            $user = $details[0];
            $message = $details[1];
            $time = $details[2];
            $updateCooldown = $details[3];

            if (!($user instanceof IdealisticOfficeUser)
                || !($message instanceof Message)
                || !is_int($time)
                || !is_numeric($updateCooldown)) {
                unset(TelegramBotHandler::$queue[$promptID]);

                if ($message instanceof Message) {
                    TelegramServerResponse::handle(Request::editMessageText([
                        "chat_id" => $message->getChat()->getId(),
                        "message_id" => $message->getMessageId(),
                        "text" => IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#193746820)"
                    ]));
                }
                continue;
            }
            $chat_id = $message->getChat()->getId();

            try {
                $microtime = microtime(true);

                if ($microtime < $updateCooldown) {
                    continue;
                }
                $prompt = $user->getPrompt($promptID);

                if ($prompt === null) {
                    continue;
                }
                $processing = $prompt->isProcessing();
                $replies = $prompt->getReplies();

                if ($processing) {
                    TelegramBotHandler::$queue[$promptID][3] = $microtime + $updateSeconds;
                } else {
                    unset(TelegramBotHandler::$queue[$promptID]);
                }
                $pieces = array();

                if (!empty($replies)) {
                    foreach ($replies as $reply) {
                        $pieces = array_merge(
                            $pieces,
                            str_split(
                                $reply->getAnswer(),
                                IdealisticOfficeLimit::MESSAGE_CHARACTER_LIMIT[IdealisticOfficeAccessPlatform::TELEGRAM]
                            )
                        );
                    }

                    if (!empty($pieces)) {
                        Request::editMessageText([
                            "chat_id" => $chat_id,
                            "message_id" => $message->getMessageId(),
                            "text" => array_shift($pieces)
                        ]); // Do not handle this one, prone to same text edit errors

                        if (!$processing) {
                            foreach ($pieces as $piece) {
                                TelegramServerResponse::handle(Request::sendMessage([
                                    "chat_id" => $chat_id,
                                    "text" => $piece,
                                    "reply_to_message_id" => $message->getMessageId()
                                ]));
                            }
                        }
                    }
                }
                if (!$processing) {
                    $attachments = $prompt->getCreatedAttachments();

                    if (!empty($attachments)) {
                        foreach ($attachments as $attachment) {
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
                                TelegramServerResponse::handle(Request::sendPhoto($data));
                            } else if ($attachment->isAudio()) {
                                $data["audio"] = $file;
                                TelegramServerResponse::handle(Request::sendAudio($data));
                            } else if ($attachment->isVideo()) {
                                $data["video"] = $file;
                                TelegramServerResponse::handle(Request::sendVideo($data));
                            } else {
                                $data["document"] = $file;
                                TelegramServerResponse::handle(Request::sendDocument($data));
                            }
                            unlink($tempPath);
                        }
                    }
                }
            } catch (Throwable $e) {
                IdealisticOfficeError::storeThrowable(
                    $user->getTeam(),
                    $user,
                    $e
                );
                TelegramServerResponse::handle(Request::editMessageText([
                    "chat_id" => $chat_id,
                    "message_id" => $message->getMessageId(),
                    "text" => IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#582013947)"
                ]));
            }
        }
    }
);

$loop->run();

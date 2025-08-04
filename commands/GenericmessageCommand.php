<?php

namespace Longman\TelegramBot\Commands\SystemCommands;

use BigManageAccessPlatform;
use BigManageAttachment;
use BigManageError;
use BigManageGeneralMessage;
use BigManageOutcome;
use BigManageStrings;
use BigManageTeamInitiator;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\PhotoSize;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Voice;
use Longman\TelegramBot\Request;
use stdClass;
use TelegramBotHandler;
use Throwable;

class GenericmessageCommand extends SystemCommand
{
    protected $name = 'genericmessage';
    protected $description = 'Handle any generic message';
    protected $version = '1.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chat = $message->getChat();

        try {
            if (!$chat->isPrivateChat()) {
                return Request::leaveChat(["chat_id" => $chat->getId()]);
            }
            $author = $message->getFrom();

            if ($author === null) {
                return Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => "No Telegram message author found.",
                    "reply_to_message_id" => $message->getMessageId()
                ]);
            }
            if ($author->getId() === $this->getTelegram()->getBotId()) {
                return Request::emptyResponse();
            }
            $user = BigManageTeamInitiator::findUser(
                BigManageAccessPlatform::TELEGRAM,
                $author->getId(),
                $author->getUsername()
            );

            if ($user instanceof BigManageOutcome) {
                return Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => $user->getTranslatedMessage(),
                    "reply_to_message_id" => $message->getMessageId()
                ]);
            }
            $request = Request::sendMessage([
                "chat_id" => $chat->getId(),
                "text" => BigManageStrings::translateMessage(
                    BigManageGeneralMessage::PROMPT_WAIT_RESPONSE,
                    $user
                ),
                "reply_to_message_id" => $message->getMessageId()
            ]);

            if ($request->isOk()) {
                global $token;
                $newMessage = $request->getResult();

                if (!($newMessage instanceof Message)) {
                    return Request::sendMessage([
                        "chat_id" => $chat->getId(),
                        "text" => BigManageGeneralMessage::EXCEPTION_THROWN . " (#748291603)",
                        "reply_to_message_id" => $message->getMessageId()
                    ]);
                }
                $content = null;
                $attachments = array();
                $photoSize = $message->getPhoto();
                $photoSize = is_array($photoSize)
                    ? array_pop($photoSize)
                    : null;

                if ($photoSize instanceof PhotoSize) {
                    $fileID = $photoSize->getFileId();
                    $response = Request::getFile(['file_id' => $fileID]);

                    if (!$response->isOk()) {
                        return Request::editMessageText([
                            'chat_id' => $chat->getId(),
                            'message_id' => $newMessage->getMessageId(),
                            'text' => BigManageStrings::translateMessage(
                                BigManageGeneralMessage::ATTACHMENT_FAILED_PROCESSING,
                                $user
                            )
                        ]);
                    }
                    $contents = @file_get_contents(
                        "https://api.telegram.org/file/bot"
                        . $token[0] . "/" . $response->getResult()->getFilePath()
                    );

                    if ($contents === false) {
                        return Request::editMessageText([
                            'chat_id' => $chat->getId(),
                            'message_id' => $newMessage->getMessageId(),
                            'text' => BigManageStrings::translateMessage(
                                BigManageGeneralMessage::ATTACHMENT_FAILED_PROCESSING,
                                $user
                            )
                        ]);
                    } else {
                        $attachments[] = new BigManageAttachment(
                            null,
                            $fileID,
                            null,
                            "image/jpeg",
                            null,
                            $photoSize->getFileSize(),
                            $photoSize->getWidth(),
                            $photoSize->getHeight(),
                            null,
                            base64_encode($contents),
                            null,
                            true
                        );
                        $content = $message->getCaption();
                    }
                }
                $voice = $message->getVoice();

                if ($voice instanceof Voice) {
                    $fileID = $voice->getFileId();
                    $response = Request::getFile(['file_id' => $fileID]);

                    if (!$response->isOk()) {
                        return Request::editMessageText([
                            'chat_id' => $chat->getId(),
                            'message_id' => $newMessage->getMessageId(),
                            'text' => BigManageStrings::translateMessage(
                                BigManageGeneralMessage::ATTACHMENT_FAILED_PROCESSING,
                                $user
                            )
                        ]);
                    }
                    $contents = @file_get_contents(
                        "https://api.telegram.org/file/bot"
                        . $token[0] . "/" . $response->getResult()->getFilePath()
                    );

                    if ($contents === false) {
                        return Request::editMessageText([
                            'chat_id' => $chat->getId(),
                            'message_id' => $newMessage->getMessageId(),
                            'text' => BigManageStrings::translateMessage(
                                BigManageGeneralMessage::ATTACHMENT_FAILED_PROCESSING,
                                $user
                            )
                        ]);
                    } else {
                        $attachments[] = new BigManageAttachment(
                            null,
                            $fileID,
                            null,
                            "audio/ogg",
                            null,
                            $voice->getFileSize(),
                            null,
                            null,
                            null,
                            base64_encode($contents),
                            null,
                            true
                        );
                    }
                }
                $repliedMessage = $message->getReplyToMessage();

                if ($repliedMessage === null
                    || $repliedMessage->getText() === null
                    && $repliedMessage->getCaption() === null) {
                    if ($content === null) {
                        $content = $message->getText();
                    }
                } else {
                    $object = new stdClass();
                    $object->content = $content ?? $message->getText();
                    $object->referenced_message = $repliedMessage->getText() ?? $repliedMessage->getCaption();
                    $content = json_encode($object);
                }
                $prompt = BigManageTeamInitiator::createPrompt(
                    $user,
                    BigManageAccessPlatform::TELEGRAM,
                    $author->getId(),
                    $chat->getId(),
                    $message->getMessageId(),
                    $author->getUsername(),
                    trim($author->getFirstName() . " " . $author->getLastName()),
                    $content ?? "",
                    $attachments
                );

                if (!$prompt->isPositiveOutcome()) {
                    return Request::editMessageText([
                        'chat_id' => $chat->getId(),
                        'message_id' => $newMessage->getMessageId(),
                        'text' => BigManageStrings::translateMessage(
                            BigManageGeneralMessage::EXCEPTION_THROWN . " (#102938475)",
                            $user
                        )
                    ]);
                }
                TelegramBotHandler::$queue[$prompt->getRawMessage()] = array($user, $newMessage, time(), microtime(true));
            }
            return $request;
        } catch (Throwable $e) {
            BigManageError::storeThrowable(
                null,
                null,
                $e
            );
            if (isset($newMessage)) {
                return Request::editMessageText([
                    'chat_id' => $chat->getId(),
                    'message_id' => $newMessage->getMessageId(),
                    'text' => BigManageGeneralMessage::EXCEPTION_THROWN . " (#365910472)"
                ]);
            } else {
                return Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => BigManageGeneralMessage::EXCEPTION_THROWN . " (#837294105)",
                    "reply_to_message_id" => $message->getMessageId()
                ]);
            }
        }
    }
}
<?php

namespace Longman\TelegramBot\Commands\SystemCommands;

use IdealisticOfficeAccessPlatform;
use IdealisticOfficeAttachment;
use IdealisticOfficeError;
use IdealisticOfficeGeneralMessage;
use IdealisticOfficeOutcome;
use IdealisticOfficeReader;
use IdealisticOfficeStrings;
use IdealisticOfficeTeamInitiator;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\PhotoSize;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Voice;
use Longman\TelegramBot\Request;
use stdClass;
use TelegramBotHandler;
use TelegramServerResponse;
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
                return Request::emptyResponse();
            }
            $author = $message->getFrom();

            if ($author === null) {
                return TelegramServerResponse::handle(Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => "No Telegram message author found.",
                    "reply_to_message_id" => $message->getMessageId()
                ]));
            }
            if ($author->getId() === $this->getTelegram()->getBotId()) {
                return Request::emptyResponse();
            }
            $user = IdealisticOfficeTeamInitiator::findUser(
                IdealisticOfficeAccessPlatform::TELEGRAM,
                $author->getId()
            );

            if ($user instanceof IdealisticOfficeOutcome) {
                return TelegramServerResponse::handle(Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => $user->getTranslatedMessage(),
                    "reply_to_message_id" => $message->getMessageId()
                ]));
            }
            $request = TelegramServerResponse::handle(Request::sendMessage([
                "chat_id" => $chat->getId(),
                "text" => IdealisticOfficeStrings::translateMessage(
                    IdealisticOfficeGeneralMessage::PROMPT_WAIT_RESPONSE,
                    $user
                ),
                "reply_to_message_id" => $message->getMessageId()
            ]));

            if ($request->isOk()) {
                global $token;
                $newMessage = $request->getResult();

                if (!($newMessage instanceof Message)) {
                    return TelegramServerResponse::handle(Request::sendMessage([
                        "chat_id" => $chat->getId(),
                        "text" => IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#748291603)",
                        "reply_to_message_id" => $message->getMessageId()
                    ]));
                }
                $content = null;
                $attachments = array();
                $photoSize = $message->getPhoto();
                $photoSize = is_array($photoSize)
                    ? array_pop($photoSize)
                    : null;

                if ($photoSize instanceof PhotoSize) {
                    $fileID = $photoSize->getFileId();
                    $response = TelegramServerResponse::handle(Request::getFile(['file_id' => $fileID]));

                    if (!$response->isOk()) {
                        return TelegramServerResponse::handle(Request::editMessageText([
                            'chat_id' => $chat->getId(),
                            'message_id' => $newMessage->getMessageId(),
                            'text' => IdealisticOfficeStrings::translateMessage(
                                IdealisticOfficeGeneralMessage::ATTACHMENT_FAILED_PROCESSING,
                                $user
                            )
                        ]));
                    }
                    $contents = @file_get_contents(
                        "https://api.telegram.org/file/bot"
                        . $token[0] . "/" . $response->getResult()->getFilePath()
                    );

                    if ($contents === false) {
                        return TelegramServerResponse::handle(Request::editMessageText([
                            'chat_id' => $chat->getId(),
                            'message_id' => $newMessage->getMessageId(),
                            'text' => IdealisticOfficeStrings::translateMessage(
                                IdealisticOfficeGeneralMessage::ATTACHMENT_FAILED_PROCESSING,
                                $user
                            )
                        ]));
                    } else {
                        $timezone = $user->getTimezone(false);
                        $attachments[] = new IdealisticOfficeAttachment(
                            null,
                            $fileID,
                            "image/jpeg",
                            null,
                            $photoSize->getFileSize(),
                            $photoSize->getWidth(),
                            $photoSize->getHeight(),
                            null,
                            base64_encode($contents),
                            null,
                            true,
                            IdealisticOfficeReader::getCurrentDate($timezone),
                            $timezone->getTimeZone(),
                            $user
                        );
                        $content = $message->getCaption();
                    }
                }
                $voice = $message->getVoice();

                if ($voice instanceof Voice) {
                    $fileID = $voice->getFileId();
                    $response = TelegramServerResponse::handle(Request::getFile(['file_id' => $fileID]));

                    if (!$response->isOk()) {
                        return TelegramServerResponse::handle(Request::editMessageText([
                            'chat_id' => $chat->getId(),
                            'message_id' => $newMessage->getMessageId(),
                            'text' => IdealisticOfficeStrings::translateMessage(
                                IdealisticOfficeGeneralMessage::ATTACHMENT_FAILED_PROCESSING,
                                $user
                            )
                        ]));
                    }
                    $contents = @file_get_contents(
                        "https://api.telegram.org/file/bot"
                        . $token[0] . "/" . $response->getResult()->getFilePath()
                    );

                    if ($contents === false) {
                        return TelegramServerResponse::handle(Request::editMessageText([
                            'chat_id' => $chat->getId(),
                            'message_id' => $newMessage->getMessageId(),
                            'text' => IdealisticOfficeStrings::translateMessage(
                                IdealisticOfficeGeneralMessage::ATTACHMENT_FAILED_PROCESSING,
                                $user
                            )
                        ]));
                    } else {
                        $timezone = $user->getTimezone(false);
                        $attachments[] = new IdealisticOfficeAttachment(
                            null,
                            $fileID,
                            "audio/ogg",
                            null,
                            $voice->getFileSize(),
                            null,
                            null,
                            null,
                            base64_encode($contents),
                            null,
                            true,
                            IdealisticOfficeReader::getCurrentDate($timezone),
                            $timezone->getTimeZone(),
                            $user
                        );
                    }
                }
                $document = $message->getDocument();

                if ($document !== null) {
                    $fileID = $document->getFileId();
                    $response = TelegramServerResponse::handle(Request::getFile(['file_id' => $fileID]));

                    if (!$response->isOk()) {
                        return TelegramServerResponse::handle(Request::editMessageText([
                            'chat_id' => $chat->getId(),
                            'message_id' => $newMessage->getMessageId(),
                            'text' => IdealisticOfficeStrings::translateMessage(
                                IdealisticOfficeGeneralMessage::ATTACHMENT_FAILED_PROCESSING,
                                $user
                            )
                        ]));
                    }
                    $contents = @file_get_contents(
                        "https://api.telegram.org/file/bot"
                        . $token[0] . "/" . $response->getResult()->getFilePath()
                    );

                    if ($contents === false) {
                        return TelegramServerResponse::handle(Request::editMessageText([
                            'chat_id' => $chat->getId(),
                            'message_id' => $newMessage->getMessageId(),
                            'text' => IdealisticOfficeStrings::translateMessage(
                                IdealisticOfficeGeneralMessage::ATTACHMENT_FAILED_PROCESSING,
                                $user
                            )
                        ]));
                    } else {
                        $timezone = $user->getTimezone(false);
                        $attachments[] = new IdealisticOfficeAttachment(
                            null,
                            $fileID,
                            $document->getMimeType(),
                            null,
                            $document->getFileSize(),
                            null,
                            null,
                            null,
                            base64_encode($contents),
                            null,
                            true,
                            IdealisticOfficeReader::getCurrentDate($timezone),
                            $timezone->getTimeZone(),
                            $user
                        );
                        $content = $message->getCaption();
                    }
                }
                $video = $message->getVideo();

                if ($video !== null) {
                    $fileID = $video->getFileId();
                    $response = TelegramServerResponse::handle(Request::getFile(['file_id' => $fileID]));

                    if (!$response->isOk()) {
                        return TelegramServerResponse::handle(Request::editMessageText([
                            'chat_id' => $chat->getId(),
                            'message_id' => $newMessage->getMessageId(),
                            'text' => IdealisticOfficeStrings::translateMessage(
                                IdealisticOfficeGeneralMessage::ATTACHMENT_FAILED_PROCESSING,
                                $user
                            )
                        ]));
                    }
                    $contents = @file_get_contents(
                        "https://api.telegram.org/file/bot"
                        . $token[0] . "/" . $response->getResult()->getFilePath()
                    );

                    if ($contents === false) {
                        return TelegramServerResponse::handle(Request::editMessageText([
                            'chat_id' => $chat->getId(),
                            'message_id' => $newMessage->getMessageId(),
                            'text' => IdealisticOfficeStrings::translateMessage(
                                IdealisticOfficeGeneralMessage::ATTACHMENT_FAILED_PROCESSING,
                                $user
                            )
                        ]));
                    } else {
                        $timezone = $user->getTimezone(false);
                        $attachments[] = new IdealisticOfficeAttachment(
                            null,
                            $fileID,
                            "video/mp4",
                            null,
                            $video->getFileSize(),
                            null,
                            null,
                            null,
                            base64_encode($contents),
                            null,
                            true,
                            IdealisticOfficeReader::getCurrentDate($timezone),
                            $timezone->getTimeZone(),
                            $user
                        );
                        $content = $message->getCaption();
                    }
                }
                $audio = $message->getAudio();

                if ($audio !== null) {
                    $fileID = $audio->getFileId();
                    $response = TelegramServerResponse::handle(Request::getFile(['file_id' => $fileID]));

                    if (!$response->isOk()) {
                        return TelegramServerResponse::handle(Request::editMessageText([
                            'chat_id' => $chat->getId(),
                            'message_id' => $newMessage->getMessageId(),
                            'text' => IdealisticOfficeStrings::translateMessage(
                                IdealisticOfficeGeneralMessage::ATTACHMENT_FAILED_PROCESSING,
                                $user
                            )
                        ]));
                    }
                    $contents = @file_get_contents(
                        "https://api.telegram.org/file/bot"
                        . $token[0] . "/" . $response->getResult()->getFilePath()
                    );

                    if ($contents === false) {
                        return TelegramServerResponse::handle(Request::editMessageText([
                            'chat_id' => $chat->getId(),
                            'message_id' => $newMessage->getMessageId(),
                            'text' => IdealisticOfficeStrings::translateMessage(
                                IdealisticOfficeGeneralMessage::ATTACHMENT_FAILED_PROCESSING,
                                $user
                            )
                        ]));
                    } else {
                        $timezone = $user->getTimezone(false);
                        $attachments[] = new IdealisticOfficeAttachment(
                            null,
                            $fileID,
                            "audio/mpeg",
                            null,
                            $audio->getFileSize(),
                            null,
                            null,
                            null,
                            base64_encode($contents),
                            null,
                            true,
                            IdealisticOfficeReader::getCurrentDate($timezone),
                            $timezone->getTimeZone(),
                            $user
                        );
                        $content = $message->getCaption();
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
                $prompt = IdealisticOfficeTeamInitiator::createPrompt(
                    $user,
                    IdealisticOfficeAccessPlatform::TELEGRAM,
                    $author->getId(),
                    $chat->getId(),
                    null,
                    $message->getMessageId(),
                    $author->getUsername(),
                    trim($author->getFirstName() . " " . $author->getLastName()),
                    $content ?? "",
                    $attachments
                );

                if (!$prompt->isPositiveOutcome()) {
                    return TelegramServerResponse::handle(Request::editMessageText([
                        'chat_id' => $chat->getId(),
                        'message_id' => $newMessage->getMessageId(),
                        'text' => $prompt->getTranslatedMessage($user)
                    ]));
                }
                TelegramBotHandler::$queue[$prompt->getRawMessage()] = array($user, $newMessage, time(), microtime(true));
            }
            return $request;
        } catch (Throwable $e) {
            IdealisticOfficeError::storeThrowable(
                null,
                null,
                $e
            );
            if (isset($newMessage)) {
                return TelegramServerResponse::handle(Request::editMessageText([
                    'chat_id' => $chat->getId(),
                    'message_id' => $newMessage->getMessageId(),
                    'text' => IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#365910472)"
                ]));
            } else {
                return TelegramServerResponse::handle(Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#837294105)",
                    "reply_to_message_id" => $message->getMessageId()
                ]));
            }
        }
    }
}
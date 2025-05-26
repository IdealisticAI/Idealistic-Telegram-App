<?php

namespace Longman\TelegramBot\Commands\SystemCommands;

use Account;
use BigManageAccessPlatform;
use BigManageAttachment;
use BigManageError;
use BigManageGeneralMessage;
use BigManageOutcome;
use BigManageStrings;
use BigManageTeam;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\PhotoSize;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use stdClass;
use TelegramBotHandler;
use Throwable;

class GenericmessageCommand extends SystemCommand
{
    protected $name = 'genericmessage';
    protected $description = 'Handle any generic message';
    protected $version = '1.0.0';

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
            $account = new Account(Account::BIGMANAGE_APPLICATION_ID);
            $account = $account->getAccounts()->getAccountFromType(
                BigManageAccessPlatform::TELEGRAM,
                $author->getUsername()
            );

            if ($account === null) {
                return Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => BigManageGeneralMessage::NO_TELEGRAM_ACCOUNT_CORRELATION_FOUND,
                    "reply_to_message_id" => $message->getMessageId()
                ]);
            }
            $team = new BigManageTeam($account);

            if (!$team->hasEstablishedAccess()) {
                if (empty($team->getAccesses())) {
                    return Request::sendMessage([
                        "chat_id" => $chat->getId(),
                        "text" => BigManageGeneralMessage::NO_TEAM_ACCESSES_FOUND,
                        "reply_to_message_id" => $message->getMessageId()
                    ]);
                } else {
                    return Request::sendMessage([
                        "chat_id" => $chat->getId(),
                        "text" => BigManageGeneralMessage::TELEGRAM_SELECT_TEAM_ACCESSES,
                        "reply_to_message_id" => $message->getMessageId()
                    ]);
                }
            }
            $user = $team->findUser($account);

            if ($user instanceof BigManageOutcome) {
                return Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => $user->getTranslatedMessage($team),
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
                $newMessage = $request->getResult();

                if (!($newMessage instanceof Message)) {
                    return Request::emptyResponse();
                }
                $attachments = array();

                $photoSize = $message->getPhoto();
                $photoSize = array_pop($photoSize);

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
                    global $token;
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
                    }
                }
                //$voice = $message->getVoice();
                $repliedMessage = $message->getReplyToMessage();

                if ($repliedMessage === null
                    || $repliedMessage->getText() === null) {
                    $content = $message->getText() ?? $message->getCaption();
                } else {
                    $object = new stdClass();
                    $object->content = $message->getText() ?? $message->getCaption();
                    $object->referenced_message = $repliedMessage->getText();
                    $content = json_encode($object);
                }
                $prompt = $user->createPrompt(
                    BigManageAccessPlatform::TELEGRAM,
                    $author->getId(),
                    $chat->getId(),
                    $message->getMessageId(),
                    $author->getUsername(),
                    $author->getFirstName() . ' ' . $author->getLastName(),
                    $content,
                    $attachments
                );

                if ($prompt === null) {
                    return Request::editMessageText([
                        'chat_id' => $chat->getId(),
                        'message_id' => $newMessage->getMessageId(),
                        'text' => BigManageStrings::translateMessage(
                            BigManageGeneralMessage::EXCEPTION_THROWN,
                            $user
                        )
                    ]);
                }
                TelegramBotHandler::$queue[$prompt] = array($user, $newMessage, time(), microtime(true));
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
                    'text' => BigManageGeneralMessage::EXCEPTION_THROWN
                ]);
            } else {
                return Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => BigManageGeneralMessage::EXCEPTION_THROWN,
                    "reply_to_message_id" => $message->getMessageId()
                ]);
            }
        }
    }
}
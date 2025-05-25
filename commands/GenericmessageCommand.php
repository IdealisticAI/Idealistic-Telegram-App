<?php

namespace Longman\TelegramBot\Commands\SystemCommands;

use Account;
use BigManageAccessPlatform;
use BigManageAttachment;
use BigManageGeneralMessage;
use BigManageOutcome;
use BigManageStrings;
use BigManageTeam;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use TelegramBotHandler;

class GenericmessageCommand extends SystemCommand
{
    protected $name = 'genericmessage';
    protected $description = 'Handle any generic message';
    protected $version = '1.0.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chat = $message->getChat();

        if (!$chat->isPrivateChat()) {
            return Request::leaveChat(["chat_id" => $chat->getId()]);
        }
        $author = $message->getFrom();

        if ($author === null) {
            return Request::sendMessage([
                "chat_id" => $chat->getId(),
                "text" => "No Telegram message author found."
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
                "text" => BigManageGeneralMessage::NO_TELEGRAM_ACCOUNT_CORRELATION_FOUND
            ]);
        }
        $team = new BigManageTeam($account);

        if (!$team->hasEstablishedAccess()) {
            if (empty($team->getAccesses())) {
                return Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => BigManageGeneralMessage::NO_TEAM_ACCESSES_FOUND
                ]);
            } else {
                return Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => BigManageGeneralMessage::TELEGRAM_SELECT_TEAM_ACCESSES
                ]);
            }
        }
        $user = $team->findUser($account);

        if ($user instanceof BigManageOutcome) {
            return Request::sendMessage([
                "chat_id" => $chat->getId(),
                "text" => $user->getTranslatedMessage($team)
            ]);
        }
        $request = Request::sendMessage([
            'chat_id' => $chat->getId(),
            'text' => BigManageStrings::translateMessage(
                BigManageGeneralMessage::PROMPT_WAIT_RESPONSE,
                $user
            ),
        ]);

        if ($request->isOk()) {
            $newMessage = $request->getResult();

            if (!($newMessage instanceof Message)) {
                return Request::emptyResponse();
            }
            $attachments = array();

            foreach ($message-> as $attachment) {
                $contents = @file_get_contents($attachment->url);

                if ($contents === false) {
                    $contents = @file_get_contents($attachment->proxy_url);
                }
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
                        $attachment->filename,
                        $attachment->description,
                        $attachment->content_type,
                        $attachment->url ?? $attachment->proxy_url,
                        $attachment->size,
                        $attachment->width,
                        $attachment->height,
                        null,
                        base64_encode($contents),
                        null,
                        true
                    );
                }
            }
            $repliedMessage = $message->getReplyToMessage();

            if ($repliedMessage === null) {
                $content = $message->getText();
            } else {
                $object = new stdClass();
                $object->content = $message->getText();
                $object->referenced_message = $repliedMessage->getText();
                $content = json_encode($object);
            }
            $prompt = $user->createPrompt(
                BigManageAccessPlatform::TELEGRAM,
                $author->getId(),
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
    }
}
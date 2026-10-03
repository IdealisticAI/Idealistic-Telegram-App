<?php

namespace Longman\TelegramBot\Commands\UserCommands;

use IdealisticOfficeAccessPlatform;
use IdealisticOfficeError;
use IdealisticOfficeGeneralMessage;
use IdealisticOfficePortalIndependent;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use TelegramServerResponse;
use Throwable;

class IdealisticSetupCommand extends UserCommand
{
    protected $name = 'idealistic_setup';
    protected $description = 'Install a portal in this group or topic.';
    protected $usage = '/idealistic_setup <portal>';
    protected $version = '1.0';
    
    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chat = $message->getChat();

        try {
            if ($chat->isPrivateChat()) {
                return TelegramServerResponse::handle(Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => "This command can only be used in groups.",
                    "reply_to_message_id" => $message->getMessageId()
                ]));
            }
            $author = $message->getFrom();
            $member = $author === null
                ? null
                : Request::getChatMember([
                    "chat_id" => $chat->getId(),
                    "user_id" => $author->getId()
                ]);

            if ($member === null
                || !$member->isOk()
                || !in_array($member->getResult()->getStatus(), array("creator", "administrator"), true)) {
                return TelegramServerResponse::handle(Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => "You need to be an administrator of this group to use this command.",
                    "reply_to_message_id" => $message->getMessageId()
                ]));
            }
            $portalId = trim($message->getText(true) ?? "");

            if ($portalId === "") {
                return TelegramServerResponse::handle(Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => "Usage: " . $this->getUsage(),
                    "reply_to_message_id" => $message->getMessageId()
                ]));
            }
            $outcome = IdealisticOfficePortalIndependent::installPortal(
                IdealisticOfficeAccessPlatform::TELEGRAM,
                $portalId,
                $author->getId(),
                null,
                $chat->getId(),
                $message->getIsTopicMessage() ? $message->getMessageThreadId() : null,
                $message->getMessageId()
            );
            return TelegramServerResponse::handle(Request::sendMessage([
                "chat_id" => $chat->getId(),
                "text" => $outcome->getTranslatedMessage(),
                "reply_to_message_id" => $message->getMessageId()
            ]));
        } catch (Throwable $e) {
            IdealisticOfficeError::storeThrowable(
                null,
                null,
                $e
            );
            return TelegramServerResponse::handle(Request::sendMessage([
                "chat_id" => $chat->getId(),
                "text" => IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#529174638)",
                "reply_to_message_id" => $message->getMessageId()
            ]));
        }
    }
}

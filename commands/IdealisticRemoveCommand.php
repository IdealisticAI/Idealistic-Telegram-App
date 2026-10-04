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

class IdealisticRemoveCommand extends UserCommand
{
    protected $name = 'idealistic_remove';
    protected $description = 'Uninstall the portal of this group or topic.';
    protected $usage = '/idealistic_remove';
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
                || $member->getResult()->getStatus() !== "creator"
                && ($member->getResult()->getStatus() !== "administrator"
                    || !$member->getResult()->getCanChangeInfo())) {
                return TelegramServerResponse::handle(Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => "You need the 'Change Group Info' admin right to use this command.",
                    "reply_to_message_id" => $message->getMessageId()
                ]));
            }
            $outcome = IdealisticOfficePortalIndependent::uninstallPortal(
                IdealisticOfficeAccessPlatform::TELEGRAM,
                $author->getId(),
                null,
                $chat->getId(),
                $message->getIsTopicMessage() ? $message->getMessageThreadId() : null
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
                "text" => IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#861305274)",
                "reply_to_message_id" => $message->getMessageId()
            ]));
        }
    }
}

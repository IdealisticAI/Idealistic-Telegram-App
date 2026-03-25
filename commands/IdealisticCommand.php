<?php

namespace Longman\TelegramBot\Commands\UserCommands;

use IdealisticOfficeAccessPlatform;
use IdealisticOfficeError;
use IdealisticOfficeGeneralMessage;
use IdealisticOfficeOutcome;
use IdealisticOfficeStrings;
use IdealisticOfficeTeamInitiator;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use TelegramServerResponse;
use Throwable;

class IdealisticCommand extends UserCommand
{
    protected $name = \IdealisticOfficeVariable::APPLICATION_COMMAND;
    protected $description = 'Manage your access';
    protected $usage = '/idealistic';
    protected $version = '1.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chat = $message->getChat();

        try {
            if (!$chat->isPrivateChat()) {
                return TelegramServerResponse::handle(Request::leaveChat(["chat_id" => $chat->getId()]));
            }
            $author = $this->getMessage()->getFrom();

            if ($author === null
                || $author->getId() === $this->getTelegram()->getBotId()) {
                return Request::emptyResponse();
            }
            $user = IdealisticOfficeTeamInitiator::findUser(
                IdealisticOfficeAccessPlatform::TELEGRAM,
                $author->getId(),
                $author->getUsername()
            );

            if ($user instanceof IdealisticOfficeOutcome) {
                return TelegramServerResponse::handle(Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => $user->getTranslatedMessage(),
                    "reply_to_message_id" => $message->getMessageId()
                ]));
            }
            $team = $user->getEvolvedTeam(false);

            if ($team->hasEstablishedAccess()) {
                if (empty($team->getAccesses())) {
                    return TelegramServerResponse::handle(Request::sendMessage([
                        "chat_id" => $chat->getId(),
                        "text" => IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#728395021)",
                        "reply_to_message_id" => $message->getMessageId()
                    ]));
                } else if (sizeof($team->getAccesses()) === 1) {
                    return TelegramServerResponse::handle(Request::sendMessage([
                        "chat_id" => $chat->getId(),
                        "text" => IdealisticOfficeStrings::translateMessage(
                            str_replace(
                                "{name}",
                                $team->getName(),
                                IdealisticOfficeGeneralMessage::ALREADY_ESTABLISHED_ACCESS_AND_NO_EXTRA
                            ),
                            $team
                        ),
                        "reply_to_message_id" => $message->getMessageId()
                    ]));
                }
            } else if (empty($team->getAccesses())) {
                return TelegramServerResponse::handle(Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => IdealisticOfficeGeneralMessage::NO_ACCESS_TO_ESTABLISH,
                    "reply_to_message_id" => $message->getMessageId()
                ]));
            }
            $choices = array();

            foreach ($team->getAccesses() as $index => $teamAccess) {
                $choices[] = [
                    "text" => $teamAccess->getName(),
                    "callback_data" => $index
                ];
            }
            return TelegramServerResponse::handle(Request::sendMessage([
                "chat_id" => $chat->getId(),
                "text" => 'Please select an option:',
                "reply_markup" => new InlineKeyboard($choices),
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
                "text" => IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#183047592)",
                "reply_to_message_id" => $message->getMessageId()
            ]));
        }
    }

}
<?php

namespace Longman\TelegramBot\Commands\UserCommands;

use IdealisticOfficeAccessPlatform;
use IdealisticOfficeError;
use IdealisticOfficeGeneralMessage;
use IdealisticOfficeOutcome;
use IdealisticOfficeStrings;
use IdealisticOfficeTeamInitiator;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Throwable;

class CallbackqueryCommand extends \Longman\TelegramBot\Commands\SystemCommands\CallbackqueryCommand
{
    public function execute(): ServerResponse
    {
        $callback = $this->getCallbackQuery();
        $message = $callback->getMessage();
        $chat_id = $message->getChat()->getId();

        try {
            if (time() - $callback->getMessage()->getDate() > 60) {
                return Request::emptyResponse();
            }
            $data = $callback->getData();

            if (!is_numeric($data)) {
                return Request::emptyResponse();
            }
            $author = $callback->getFrom();

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
                return Request::sendMessage([
                    "chat_id" => $chat_id,
                    "text" => $user->getTranslatedMessage(),
                    "reply_to_message_id" => $message->getMessageId()
                ]);
            }
            $team = $user->getEvolvedTeam(false);

            if ($team->hasEstablishedAccess()) {
                if (empty($team->getAccesses())) {
                    return Request::sendMessage([
                        "chat_id" => $chat_id,
                        "text" => IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#592813014)",
                    ]);
                } else if (sizeof($team->getAccesses()) === 1) {
                    return Request::sendMessage([
                        "chat_id" => $chat_id,
                        "text" => IdealisticOfficeStrings::translateMessage(
                            str_replace(
                                "{name}",
                                $team->getName(),
                                IdealisticOfficeGeneralMessage::ALREADY_ESTABLISHED_ACCESS_AND_NO_EXTRA
                            ),
                            $team
                        ),
                        "reply_to_message_id" => $message->getMessageId()
                    ]);
                }
            } else if (empty($team->getAccesses())) {
                return Request::sendMessage([
                    "chat_id" => $chat_id,
                    "text" => IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#290184123)",
                    "reply_to_message_id" => $message->getMessageId()
                ]);
            }
            $choice = $team->selectAccess(
                $data,
                $user->getAccount(),
                false
            );
            return Request::sendMessage([
                "chat_id" => $chat_id,
                "text" => $choice->getTranslatedMessage($user),
                "reply_to_message_id" => $message->getMessageId()
            ]);
        } catch (Throwable $e) {
            IdealisticOfficeError::storeThrowable(
                null,
                null,
                $e
            );
            return Request::sendMessage([
                "chat_id" => $chat_id,
                "text" => IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#594301827)",
                "reply_to_message_id" => $message->getMessageId()
            ]);
        }
    }
}


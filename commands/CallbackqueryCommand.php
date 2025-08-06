<?php

namespace Longman\TelegramBot\Commands\UserCommands;

use BigManageAccessPlatform;
use BigManageError;
use BigManageGeneralMessage;
use BigManageOutcome;
use BigManageStrings;
use BigManageTeamInitiator;
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
            $user = BigManageTeamInitiator::findUser(
                BigManageAccessPlatform::TELEGRAM,
                $author->getId(),
                $author->getUsername()
            );

            if ($user instanceof BigManageOutcome) {
                return Request::sendMessage([
                    "chat_id" => $chat_id,
                    "text" => $user->getTranslatedMessage(),
                    "reply_to_message_id" => $message->getMessageId()
                ]);
            }
            $team = $user->getTeam();

            if ($team->hasEstablishedAccess()) {
                if (empty($team->getAccesses())) {
                    return Request::sendMessage([
                        "chat_id" => $chat_id,
                        "text" => BigManageGeneralMessage::EXCEPTION_THROWN . " (#592813014)",
                    ]);
                } else if (sizeof($team->getAccesses()) === 1) {
                    return Request::sendMessage([
                        "chat_id" => $chat_id,
                        "text" => BigManageStrings::translateMessage(
                            str_replace(
                                "{name}",
                                $team->getName(),
                                BigManageGeneralMessage::ALREADY_ESTABLISHED_ACCESS_AND_NO_EXTRA
                            ),
                            $team
                        ),
                        "reply_to_message_id" => $message->getMessageId()
                    ]);
                }
            } else if (empty($team->getAccesses())) {
                return Request::sendMessage([
                    "chat_id" => $chat_id,
                    "text" => BigManageGeneralMessage::EXCEPTION_THROWN . " (#290184123)",
                    "reply_to_message_id" => $message->getMessageId()
                ]);
            }
            $choice = $team->selectAccess(
                $data,
                $user->getAccount()
            );
            return Request::sendMessage([
                "chat_id" => $chat_id,
                "text" => $choice->getTranslatedMessage($user),
                "reply_to_message_id" => $message->getMessageId()
            ]);
        } catch (Throwable $e) {
            BigManageError::storeThrowable(
                null,
                null,
                $e
            );
            return Request::sendMessage([
                "chat_id" => $chat_id,
                "text" => BigManageGeneralMessage::EXCEPTION_THROWN . " (#594301827)",
                "reply_to_message_id" => $message->getMessageId()
            ]);
        }
    }
}


<?php

namespace Longman\TelegramBot\Commands\SystemCommands;

use Account;
use BigManageAccessPlatform;
use BigManageGeneralMessage;
use BigManageOutcome;
use BigManageTeam;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

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
        return Request::sendMessage([
            'chat_id' => $chat->getId(),
            'text' => 'You said: ' . $message->getText(),
        ]);
    }
}
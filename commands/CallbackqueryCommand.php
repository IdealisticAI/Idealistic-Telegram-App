<?php

namespace Longman\TelegramBot\Commands\UserCommands;

use Account;
use BigManageAccessPlatform;
use BigManageGeneralMessage;
use BigManageOutcome;
use BigManageStrings;
use BigManageTeam;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class CallbackqueryCommand extends \Longman\TelegramBot\Commands\SystemCommands\CallbackqueryCommand
{
    public function execute(): ServerResponse
    {
        $callback = $this->getCallbackQuery();

        if (false && time() - $callback->getMessage()->getDate() > 60) {
            return Request::emptyResponse();
        }
        $data = $callback->getData();

        if (!is_numeric($data)) {
            return Request::emptyResponse();
        }
        $chat_id = $callback->getMessage()->getChat()->getId();
        $author = $callback->getFrom();

        if ($author === null
            || $author->getId() === $this->getTelegram()->getBotId()) {
            return Request::emptyResponse();
        }
        $account = new Account(Account::BIGMANAGE_APPLICATION_ID);
        $account = $account->getAccounts()->getAccountFromType(
            BigManageAccessPlatform::TELEGRAM,
            $author->getUsername()
        );

        if ($account === null) {
            return Request::sendMessage([
                'chat_id' => $chat_id,
                'text' => BigManageGeneralMessage::NO_TELEGRAM_ACCOUNT_CORRELATION_FOUND
            ]);
        }
        $team = new BigManageTeam($account);
        $user = $team->findUser($account);

        if ($user instanceof BigManageOutcome) {
            return Request::sendMessage([
                'chat_id' => $chat_id,
                'text' => $user->getTranslatedMessage($team)
            ]);
        }
        if ($team->hasEstablishedAccess()) {
            if (empty($team->getAccesses())) {
                return Request::sendMessage([
                    'chat_id' => $chat_id,
                    'text' => BigManageGeneralMessage::NO_TEAM_ACCESSES_FOUND
                ]);
            } else if (sizeof($team->getAccesses()) === 1) {
                return Request::sendMessage([
                    'chat_id' => $chat_id,
                    'text' => BigManageStrings::translateMessage(
                        str_replace(
                            "{title}",
                            $team->getTitle(),
                            BigManageGeneralMessage::ALREADY_ESTABLISHED_ACCESS_AND_NO_EXTRA
                        ),
                        $team
                    )
                ]);
            }
        } else if (empty($team->getAccesses())) {
            return Request::sendMessage([
                'chat_id' => $chat_id,
                'text' => BigManageGeneralMessage::NO_TEAM_ACCESSES_FOUND
            ]);
        }
        $choice = $team->selectAccess(
            $data,
            $account
        );
        return Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => $choice->getTranslatedMessage($user)
        ]);
    }
}


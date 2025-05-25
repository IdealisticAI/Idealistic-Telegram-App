<?php

namespace Longman\TelegramBot\Commands\UserCommands;

use Account;
use BigManageAccessPlatform;
use BigManageError;
use BigManageGeneralMessage;
use BigManageOutcome;
use BigManageStrings;
use BigManageTeam;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Throwable;

class BigManageCommand extends UserCommand
{
    protected $name = 'bigmanage';
    protected $description = 'Manage your access';
    protected $usage = '/bigmanage';
    protected $version = '1.0.0';

    public function execute(): ServerResponse
    {
        $chat = $this->getMessage()->getChat();

        if (!$chat->isPrivateChat()) {
            return Request::leaveChat(['chat_id' => $chat->getId()]);
        }
        try {
            $author = $this->getMessage()->getFrom();

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
                    'chat_id' => $chat->getId(),
                    'text' => BigManageGeneralMessage::NO_TELEGRAM_ACCOUNT_CORRELATION_FOUND
                ]);
            }
            $team = new BigManageTeam($account);
            $user = $team->findUser($account);

            if ($user instanceof BigManageOutcome) {
                return Request::sendMessage([
                    'chat_id' => $chat->getId(),
                    'text' => $user->getTranslatedMessage($team)
                ]);
            }
            $buildMenu = false;

            if ($team->hasEstablishedAccess()) {
                if (empty($team->getAccesses())) {
                    return Request::sendMessage([
                        'chat_id' => $chat->getId(),
                        'text' => BigManageGeneralMessage::NO_TEAM_ACCESSES_FOUND
                    ]);
                } else if (false && sizeof($team->getAccesses()) === 1) {
                    return Request::sendMessage([
                        'chat_id' => $chat->getId(),
                        'text' => BigManageStrings::translateMessage(
                            "You already have established access to the team '"
                            . $team->getTitle() . "' and have no other team accesses.",
                            $team
                        )
                    ]);
                } else {
                    $buildMenu = true;
                }
            } else {
                if (empty($team->getAccesses())) {
                    return Request::sendMessage([
                        'chat_id' => $chat->getId(),
                        'text' => BigManageGeneralMessage::NO_TEAM_ACCESSES_FOUND
                    ]);
                } else {
                    $buildMenu = true;
                }
            }

            if ($buildMenu || true) {
                $choices = array();

                foreach ($team->getAccesses() as $index => $teamAccess) {
                    $choices[] = [
                        "text" => $teamAccess->getTitle(),
                        "callback_data" => $index
                    ];
                }
                return Request::sendMessage([
                    'chat_id' => $chat->getId(),
                    'text' => 'Please select an option:',
                    'reply_markup' => new InlineKeyboard($choices)
                ]);
            }
        } catch (Throwable $e) {
            BigManageError::storeThrowable(
                null,
                null,
                $e
            );
            return Request::sendMessage([
                'chat_id' => $chat->getId(),
                'text' => BigManageGeneralMessage::EXCEPTION_THROWN
            ]);
        }
        return Request::leaveChat(['chat_id' => $chat->getId()]);
    }
}
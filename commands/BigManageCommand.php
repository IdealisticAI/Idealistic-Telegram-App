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
        $message = $this->getMessage();
        $chat = $message->getChat();

        try {
            if (!$chat->isPrivateChat()) {
                return Request::leaveChat(["chat_id" => $chat->getId()]);
            }
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
                    "chat_id" => $chat->getId(),
                    "text" => BigManageGeneralMessage::NO_TELEGRAM_ACCOUNT_CORRELATION_FOUND,
                    "reply_to_message_id" => $message->getMessageId()
                ]);
            }
            $team = new BigManageTeam($account);
            $user = $team->findUser($account);

            if ($user instanceof BigManageOutcome) {
                return Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => $user->getTranslatedMessage($team),
                    "reply_to_message_id" => $message->getMessageId()
                ]);
            }
            if ($team->hasEstablishedAccess()) {
                if (empty($team->getAccesses())) {
                    return Request::sendMessage([
                        "chat_id" => $chat->getId(),
                        "text" => BigManageGeneralMessage::NO_TEAM_ACCESSES_FOUND,
                        "reply_to_message_id" => $message->getMessageId()
                    ]);
                } else if (sizeof($team->getAccesses()) === 1) {
                    return Request::sendMessage([
                        "chat_id" => $chat->getId(),
                        "text" => BigManageStrings::translateMessage(
                            str_replace(
                                "{title}",
                                $team->getTitle(),
                                BigManageGeneralMessage::ALREADY_ESTABLISHED_ACCESS_AND_NO_EXTRA
                            ),
                            $team
                        ),
                        "reply_to_message_id" => $message->getMessageId()
                    ]);
                }
            } else if (empty($team->getAccesses())) {
                return Request::sendMessage([
                    "chat_id" => $chat->getId(),
                    "text" => BigManageGeneralMessage::NO_TEAM_ACCESSES_FOUND,
                    "reply_to_message_id" => $message->getMessageId()
                ]);
            }
            $choices = array();

            foreach ($team->getAccesses() as $index => $teamAccess) {
                $choices[] = [
                    "text" => $teamAccess->getTitle(),
                    "callback_data" => $index
                ];
            }
            return Request::sendMessage([
                "chat_id" => $chat->getId(),
                "text" => 'Please select an option:',
                "reply_markup" => new InlineKeyboard($choices),
                "reply_to_message_id" => $message->getMessageId()
            ]);
        } catch (Throwable $e) {
            BigManageError::storeThrowable(
                null,
                null,
                $e
            );
            return Request::sendMessage([
                "chat_id" => $chat->getId(),
                "text" => BigManageGeneralMessage::EXCEPTION_THROWN,
                "reply_to_message_id" => $message->getMessageId()
            ]);
        }
    }
}
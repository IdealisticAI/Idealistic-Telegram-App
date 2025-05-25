<?php

namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class BigManageCommand extends UserCommand
{
    protected $name = 'bigmanage';
    protected $description = 'Manage your access';
    protected $usage = '/bigmanage';
    protected $version = '1.0.0';

    public function execute(): ServerResponse
    {
        $chat = $this->getMessage()->getChat();

        if ($chat->isPrivateChat()) {
            return Request::sendMessage([
                'chat_id' => $chat->getId(),
                'text' => 'Welcome! This is a private bot.',
            ]);
        }
        return Request::leaveChat(['chat_id' => $chat->getId()]);
    }
}
<?php

namespace Longman\TelegramBot\Commands\SystemCommands;

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

        if ($chat->isPrivateChat()) {
            return Request::sendMessage([
                'chat_id' => $chat->getId(),
                'text' => 'You said: ' . $message->getText(),
            ]);
        }
        return Request::leaveChat(['chat_id' => $chat->getId()]);
    }
}
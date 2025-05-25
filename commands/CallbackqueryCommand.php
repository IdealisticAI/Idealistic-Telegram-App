<?php

namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class CallbackqueryCommand extends \Longman\TelegramBot\Commands\SystemCommands\CallbackqueryCommand
{
    public function execute(): ServerResponse
    {
        $callback = $this->getCallbackQuery();

        if (time() - $callback->getMessage()->getDate() > 60) {
            return Request::emptyResponse();
        }
        $data = $callback->getData();
        $chat_id = $callback->getMessage()->getChat()->getId();
        return Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => "You selected: " . strtoupper($data),
        ]);
    }
}


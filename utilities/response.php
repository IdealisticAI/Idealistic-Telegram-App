<?php

use Longman\TelegramBot\Entities\ServerResponse;

class TelegramServerResponse {

    public static function handle(ServerResponse $response): ServerResponse
    {
        if (!$response->isOk()) {
            $object = new stdClass();
            $object->error_code = $response->getErrorCode();
            $object->description = $response->getDescription();
            $object->result = $response->getResult();
            IdealisticOfficeError::simpleStore($object);
        }
        return $response;
    }
}

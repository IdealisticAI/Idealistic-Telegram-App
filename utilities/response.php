<?php

use Longman\TelegramBot\Entities\ServerResponse;
use React\EventLoop\Loop;

class TelegramServerResponse {

    public const RETRY_ATTEMPTS = 3;

    public static function isTransient(ServerResponse $response): bool
    {
        $code = (int)$response->getErrorCode();
        return $code === 429 || $code >= 500;
    }

    public static function getRetryDelay(ServerResponse $response, int $attempt): int
    {
        if ((int)$response->getErrorCode() === 429
            && preg_match('/retry after (\d+)/i', (string)$response->getDescription(), $match) === 1) {
            return (int)$match[1] + 1;
        }
        return 2 * $attempt;
    }

    public static function handle(ServerResponse $response, bool $quietTransient = false): ServerResponse
    {
        if (!$response->isOk()
            && !($quietTransient && self::isTransient($response))) {
            $object = new stdClass();
            $object->error_code = $response->getErrorCode();
            $object->description = $response->getDescription();
            $object->result = $response->getResult();
            IdealisticOfficeError::simpleStore($object);
        }
        return $response;
    }

    public static function send(callable $request): void
    {
        self::sendInOrder(array($request));
    }

    public static function sendInOrder(array $requests, int $attempt = 1): void
    {
        while (!empty($requests)) {
            $response = $requests[0]();

            if (!$response->isOk()
                && self::isTransient($response)
                && $attempt < self::RETRY_ATTEMPTS) {
                Loop::get()->addTimer(
                    self::getRetryDelay($response, $attempt),
                    function () use ($requests, $attempt) {
                        self::sendInOrder($requests, $attempt + 1);
                    }
                );
                return;
            }
            self::handle($response);
            array_shift($requests);
            $attempt = 1;
        }
    }
}

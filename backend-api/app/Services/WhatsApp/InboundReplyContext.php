<?php

namespace App\Services\WhatsApp;

/**
 * QA fixes (2026-10-14) — a reply goes out from the number the customer wrote to.
 *
 * A customer who messages one of a client's paid extra numbers used to get
 * the chatbot / journey reply from the DEFAULT number (or none, when the
 * default was offline): the QR engine reported which number received the
 * message, but nothing passed it on. While an inbound message is processed
 * synchronously, run() remembers that number for the account, and
 * WhatsAppEngineFactory::make() uses it when the caller did not choose a
 * sender. A message deferred to a queue (busy conversation) falls back to
 * the default number, as before.
 */
final class InboundReplyContext
{
    /** @var array<int, int> account id => receiving number id */
    private static array $numbers = [];

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(int $accountId, ?int $numberId, callable $callback): mixed
    {
        $had = array_key_exists($accountId, self::$numbers);
        $previous = self::$numbers[$accountId] ?? null;
        if ($numberId !== null) {
            self::$numbers[$accountId] = $numberId;
        }

        try {
            return $callback();
        } finally {
            if ($had) {
                self::$numbers[$accountId] = $previous;
            } else {
                unset(self::$numbers[$accountId]);
            }
        }
    }

    public static function numberFor(int $accountId): ?int
    {
        return self::$numbers[$accountId] ?? null;
    }
}

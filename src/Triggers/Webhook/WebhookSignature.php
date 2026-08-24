<?php

namespace Nodeflow\Triggers\Webhook;

use RuntimeException;

class WebhookSignature
{
    public function valid(string $timestamp, string $signature, string $body, string $secret): bool
    {
        $window = config('nodeflow.webhooks.replay_window_seconds', 300);

        if (! is_int($window) || $window < 1) {
            throw new RuntimeException('Nodeflow webhook replay window must be a positive integer.');
        }

        if (! preg_match('/^(0|[1-9][0-9]*)$/D', $timestamp)
            || ! preg_match('/^sha256=([a-f0-9]{64})$/Di', $signature, $matches)) {
            return false;
        }

        $requestTime = filter_var($timestamp, FILTER_VALIDATE_INT);

        if (! is_int($requestTime) || abs(now()->timestamp - $requestTime) > $window) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return hash_equals($expected, strtolower($matches[1]));
    }

    public function maxBodyBytes(): int
    {
        $maximum = config('nodeflow.webhooks.max_body_bytes', 1_048_576);

        if (! is_int($maximum) || $maximum < 1) {
            throw new RuntimeException('Nodeflow webhook body limit must be a positive integer.');
        }

        return $maximum;
    }
}

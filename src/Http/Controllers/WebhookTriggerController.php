<?php

namespace Nodeflow\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use JsonException;
use Nodeflow\Models\WebhookEndpoint;
use Nodeflow\Triggers\TriggerActivationRepository;
use Nodeflow\Triggers\Webhook\WebhookOccurrence;
use Nodeflow\Triggers\Webhook\WebhookSignature;
use Nodeflow\Triggers\Webhook\WebhookSourceRejected;
use Nodeflow\Triggers\Webhook\WebhookTriggerDriver;
use Throwable;

class WebhookTriggerController extends Controller
{
    public function __invoke(
        Request $request,
        string $token,
        TriggerActivationRepository $activations,
        WebhookSignature $signatures,
        WebhookTriggerDriver $driver,
    ): JsonResponse {
        $activation = $activations->forWebhookToken($token);

        if ($activation === null) {
            abort(404);
        }

        $endpoint = WebhookEndpoint::query()->where('flow_id', $activation->flowId)->first();

        if ($endpoint === null || ! hash_equals((string) $endpoint->token, $token)) {
            abort(404);
        }

        $body = $request->getContent();
        $timestamp = $request->header('X-Nodeflow-Timestamp');
        $signature = $request->header('X-Nodeflow-Signature');

        try {
            $maximum = $signatures->maxBodyBytes();
        } catch (Throwable $e) {
            $this->reportSafely($e);

            return response()->json(['message' => 'Webhook verification is unavailable.'], 503);
        }

        if (strlen($body) > $maximum) {
            return response()->json(['message' => 'Webhook body is too large.'], 413);
        }

        try {
            $validSignature = is_string($timestamp)
                && is_string($signature)
                && $signatures->valid($timestamp, $signature, $body, (string) $endpoint->signing_secret);
        } catch (Throwable $e) {
            $this->reportSafely($e);

            return response()->json(['message' => 'Webhook verification is unavailable.'], 503);
        }

        if (! $validSignature) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $deliveryId = $request->header('Idempotency-Key');

        if (! is_string($deliveryId)) {
            return response()->json(['message' => 'A valid Idempotency-Key header is required.'], 422);
        }

        $deliveryId = trim($deliveryId);

        if ($deliveryId === '' || strlen($deliveryId) > 255) {
            return response()->json(['message' => 'A valid Idempotency-Key header is required.'], 422);
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'The webhook body must contain valid JSON.'], 422);
        }

        if (! is_array($payload)) {
            return response()->json(['message' => 'The webhook body must contain a JSON object or array.'], 422);
        }

        try {
            $run = $driver->dispatch($activation, new WebhookOccurrence(
                payload: $payload,
                deliveryId: $deliveryId,
                timestamp: (int) $timestamp,
            ));
        } catch (WebhookSourceRejected $e) {
            return response()->json(['message' => 'The webhook source could not resolve an audience.'], 422);
        } catch (Throwable $e) {
            $this->reportSafely($e);

            return response()->json(['message' => 'The webhook run could not be started.'], 503);
        }

        return response()->json([
            'run_id' => $run->id,
            'duplicate' => ! $run->wasRecentlyCreated,
        ], 202);
    }

    private function reportSafely(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // A host reporter cannot replace the webhook protocol response.
        }
    }
}

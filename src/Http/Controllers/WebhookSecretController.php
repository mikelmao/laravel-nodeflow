<?php

namespace Nodeflow\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nodeflow\Models\Flow;
use Nodeflow\Triggers\Webhook\WebhookCredentials;

class WebhookSecretController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Flow $flow, WebhookCredentials $credentials): JsonResponse
    {
        $this->authorize('update', $flow);
        $rotated = $credentials->rotate($flow);

        $response = response()->json([
            'secret' => $rotated['secret'],
            'rotated_at' => $rotated['endpoint']->secret_rotated_at?->toIso8601String(),
        ]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}

<?php

namespace Nodeflow\Triggers\Webhook;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Nodeflow\Execution\UniqueConstraintViolation;
use Nodeflow\Models\Flow;
use Nodeflow\Models\WebhookEndpoint;
use RuntimeException;

class WebhookCredentials
{
    private const ATTEMPTS = 5;

    private readonly Closure $generator;

    public function __construct(?Closure $generator = null)
    {
        $this->generator = $generator ?? static fn (): string => bin2hex(random_bytes(32));
    }

    /** @return array{endpoint: WebhookEndpoint, url: ?string, secret: ?string, created: bool} */
    public function forPublication(Flow $flow): array
    {
        $existing = WebhookEndpoint::query()->where('flow_id', $flow->id)->first();

        if ($existing !== null) {
            return [
                'endpoint' => $existing,
                'url' => $this->url($existing),
                'secret' => null,
                'created' => false,
            ];
        }

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $secret = $this->randomCredential();

            try {
                $endpoint = DB::transaction(fn () => WebhookEndpoint::createForFlow($flow, [
                    'token' => $this->randomCredential(),
                    'signing_secret' => $secret,
                    'secret_rotated_at' => now(),
                ]));

                return [
                    'endpoint' => $endpoint,
                    'url' => $this->url($endpoint),
                    'secret' => $secret,
                    'created' => true,
                ];
            } catch (QueryException $e) {
                if (! UniqueConstraintViolation::matches($e)) {
                    throw $e;
                }

                $winner = WebhookEndpoint::query()->where('flow_id', $flow->id)->first();

                if ($winner !== null) {
                    return [
                        'endpoint' => $winner,
                        'url' => $this->url($winner),
                        'secret' => null,
                        'created' => false,
                    ];
                }

                if ($attempt === self::ATTEMPTS) {
                    throw new RuntimeException('Unable to allocate a unique webhook endpoint token.', previous: $e);
                }
            }
        }

        throw new RuntimeException('Unable to allocate webhook credentials.');
    }

    /** @return array{endpoint: WebhookEndpoint, secret: string} */
    public function rotate(Flow $flow): array
    {
        return DB::transaction(function () use ($flow) {
            $endpoint = WebhookEndpoint::query()
                ->where('flow_id', $flow->id)
                ->lockForUpdate()
                ->firstOrFail();
            $secret = $this->randomCredential();

            $endpoint->signing_secret = $secret;
            $endpoint->secret_rotated_at = now();
            $endpoint->save();

            return ['endpoint' => $endpoint, 'secret' => $secret];
        });
    }

    public function url(WebhookEndpoint $endpoint): ?string
    {
        $routeName = $this->webhookRouteName();

        if ($routeName === null) {
            return null;
        }

        try {
            return route($routeName, ['token' => $endpoint->token]);
        } catch (UrlGenerationException) {
            // A host may place the route beneath parameters only it can resolve
            // (for example, a workspace slug). Credential publication is still
            // valid; the editor can receive a URL from a later host integration.
            return null;
        }
    }

    private function webhookRouteName(): ?string
    {
        $canonical = 'nodeflow.webhooks.receive';

        if (Route::has($canonical)) {
            return $canonical;
        }

        $matches = [];

        foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
            if (str_ends_with($name, $canonical)) {
                $matches[] = $name;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function randomCredential(): string
    {
        $credential = ($this->generator)();

        if (! is_string($credential)
            || strlen($credential) !== 64
            || preg_match('/^[a-f0-9]{64}$/D', $credential) !== 1) {
            throw new RuntimeException('Webhook credential generation failed.');
        }

        return $credential;
    }
}

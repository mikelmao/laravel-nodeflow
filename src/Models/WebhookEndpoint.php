<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class WebhookEndpoint extends Model
{
    private ?Flow $trustedCreationFlow = null;

    protected $table = 'nodeflow_webhook_endpoints';

    // Task 8 authors lowercase 64-character hex tokens. Their database
    // collation's case semantics therefore cannot create token aliases.
    protected $fillable = [
        'flow_id',
        'token',
        'signing_secret',
        'secret_rotated_at',
    ];

    protected $hidden = ['signing_secret'];

    protected $casts = [
        'signing_secret' => 'encrypted',
        'secret_rotated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $endpoint) {
            if ($endpoint->trustedCreationFlow === null) {
                // Direct host writes remain tenant-authorized, including the
                // unresolved-tenant failure mode of the configured scope.
                Flow::query()->findOrFail($endpoint->flow_id);

                return;
            }

            $parent = Flow::withoutTenancy()->findOrFail($endpoint->flow_id);

            if ((int) $parent->getKey() !== (int) $endpoint->trustedCreationFlow->getKey()
                || (string) $parent->tenant_id !== (string) $endpoint->trustedCreationFlow->tenant_id) {
                throw new LogicException('Trusted webhook endpoint flow does not match its persisted parent.');
            }
        });

        static::updating(function (self $endpoint) {
            if (! $endpoint->isDirty(['flow_id', 'token'])) {
                return;
            }

            $changed = implode(', ', array_keys($endpoint->getDirty()));

            throw new LogicException(
                "Webhook endpoint identity is immutable after creation; rotate secrets instead of changing [{$changed}]."
            );
        });
    }

    /**
     * Create credentials for a Flow already loaded and trusted by package code.
     *
     * @internal
     */
    public static function createForFlow(Flow $flow, array $attributes): static
    {
        $endpoint = new static;
        $endpoint->trustedCreationFlow = $flow;
        $endpoint->forceFill([
            ...$attributes,
            'flow_id' => $flow->getKey(),
        ]);
        $endpoint->save();

        return $endpoint;
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}

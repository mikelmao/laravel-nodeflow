<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class WebhookEndpoint extends Model
{
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
            Flow::query()->findOrFail($endpoint->flow_id);
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

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}

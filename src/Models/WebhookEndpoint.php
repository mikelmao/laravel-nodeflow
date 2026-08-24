<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEndpoint extends Model
{
    protected $table = 'nodeflow_webhook_endpoints';

    protected $guarded = [];

    protected $casts = [
        'signing_secret' => 'encrypted',
        'secret_rotated_at' => 'datetime',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}

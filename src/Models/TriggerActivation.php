<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Nodeflow\Models\Concerns\BelongsToTenant;

class TriggerActivation extends Model
{
    use BelongsToTenant;

    protected $table = 'nodeflow_trigger_activations';

    protected $guarded = [];

    protected $casts = ['descriptor' => 'array'];

    protected static function booted(): void
    {
        static::updating(function (self $activation) {
            $immutable = [
                'flow_id', 'flow_version_id', 'tenant_id', 'driver', 'source',
                'qualifier', 'trigger_node_id', 'descriptor',
            ];

            if (! $activation->isDirty($immutable)) {
                return;
            }

            $changed = implode(', ', array_keys($activation->getDirty()));

            throw new LogicException(
                "Trigger activation snapshots are immutable after creation; replace the activation instead of changing [{$changed}]."
            );
        });
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class);
    }
}

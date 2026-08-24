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
        static::creating(fn (self $activation) => $activation->assertReferences());

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

    private function assertReferences(): void
    {
        $flow = Flow::withoutTenancy()->find($this->flow_id);
        $version = FlowVersion::withoutTenancy()->find($this->flow_version_id);

        if ($flow === null) {
            throw InvalidTriggerActivationReferenceException::forMissingFlow($this->flow_id);
        }

        if ($version === null) {
            throw InvalidFlowVersionReferenceException::forMissing(
                self::class,
                'flow_version_id',
                $this->flow_version_id,
            );
        }

        if ((string) $version->flow_id !== (string) $flow->id) {
            throw InvalidTriggerActivationReferenceException::forVersionFlowMismatch(
                $version->id,
                $version->flow_id,
                $flow->id,
            );
        }

        if ((string) $this->tenant_id !== (string) $flow->tenant_id) {
            throw CrossTenantWriteException::forParentMismatch(
                self::class,
                "flow [{$flow->id}]",
                $flow->tenant_id,
                $this->tenant_id,
            );
        }

        if ((string) $this->tenant_id !== (string) $version->tenant_id) {
            throw CrossTenantWriteException::forReferenceMismatch(
                self::class,
                'flow_version_id',
                $version->id,
                $this->tenant_id,
                $version->tenant_id,
            );
        }
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

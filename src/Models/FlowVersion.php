<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nodeflow\Models\Concerns\BelongsToTenant;

class FlowVersion extends Model
{
    use BelongsToTenant;

    protected $table = 'nodeflow_flow_versions';

    protected $guarded = [];

    protected $casts = ['graph' => 'array', 'published_at' => 'datetime'];

    /**
     * A version belongs to the tenant of the flow it's for — that is structural,
     * not a convention callers must remember to stamp. Fills in only what
     * BelongsToTenant's own creating() hook left null (no explicit tenant_id and
     * no ambient tenant to fall back to): a console command, a queue worker, or
     * a direct create() with nothing bound. Reads the flow unscoped — this
     * version is being created for it, so entitlement is already established,
     * and a null ambient tenant must not stop the inheritance from happening.
     */
    protected static function booted(): void
    {
        static::creating(function (self $version) {
            if ($version->tenant_id === null && $version->flow_id !== null) {
                $version->tenant_id = Flow::withoutTenancy()->find($version->flow_id)?->tenant_id;
            }
        });
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * Deliberately reads without tenancy. A version's own row is already scoped,
     * so reaching it at all proves the caller is entitled to it — and the
     * question "does anything still depend on this version" is a system
     * question, asked by the boot-time and deploy-time node type checks in a
     * console context with no ambient tenant. Scoping it there would answer
     * "no live runs" for every version in the fleet and silently disarm the
     * check.
     */
    public function hasLiveRuns(): bool
    {
        return Run::withoutTenancy()
            ->where('flow_version_id', $this->id)
            ->whereIn('status', ['pending', 'running', 'waiting', 'blocked'])
            ->exists();
    }
}

<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nodeflow\Models\Concerns\BelongsToTenant;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;

class FlowVersion extends Model
{
    use BelongsToTenant;

    protected $table = 'nodeflow_flow_versions';

    protected $guarded = [];

    protected $casts = ['graph' => 'array', 'published_at' => 'datetime'];

    /**
     * A version belongs to the tenant of the flow it's for — that is structural,
     * not a convention callers must remember to stamp.
     *
     * The flow is the authority, so this hook reads it on every insert and
     * refuses a tenant_id that contradicts it, rather than only filling in a
     * null one. Filling in the null was not enough to make the claim above
     * true, and the gap was not academic: bootTraits() registers
     * BelongsToTenant's creating() hook before booted() runs, so by the time
     * this fires the trait's `??=` has already stamped the *ambient* tenant.
     * `FlowVersion::create(['flow_id' => <a flow belonging to org-2>])` with
     * org-1 ambient therefore produced a version labelled org-1 hanging off
     * org-2's flow, with no exception raised — and the whole reason the
     * untenanted child tables are safe is that everything above them is
     * labelled correctly.
     *
     * Reads the flow unscoped: this version is being created for it, so
     * entitlement is already established, and a null ambient tenant (console,
     * queue, cross-tenant fan-out) must not stop the inheritance happening.
     *
     * Honours TenancyGuardSuspension for the same reason the trait's own guard
     * does — the package's own writes take their tenant_id from a trusted row
     * rather than from request input, and the test-suite seeds do the same.
     * Suspension disables only the throw; the inheritance still happens.
     */
    protected static function booted(): void
    {
        static::creating(function (self $version) {
            if ($version->flow_id === null) {
                return;
            }

            // nodeflow_flows.tenant_id is NOT NULL, so a null here means the
            // flow row does not exist. Leave the version as it is and let the
            // insert fail on the foreign key rather than invent a tenant.
            $flowTenantId = Flow::withoutTenancy()->find($version->flow_id)?->tenant_id;

            if ($flowTenantId === null) {
                return;
            }

            if ($version->tenant_id === null) {
                $version->tenant_id = $flowTenantId;

                return;
            }

            if ((string) $version->tenant_id !== (string) $flowTenantId
                && ! TenancyGuardSuspension::isActive()) {
                throw CrossTenantWriteException::forParentMismatch(
                    self::class,
                    "flow [{$version->flow_id}]",
                    $flowTenantId,
                    $version->tenant_id,
                );
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

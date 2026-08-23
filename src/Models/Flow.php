<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Nodeflow\Models\Concerns\BelongsToTenant;

class Flow extends Model
{
    use BelongsToTenant;

    protected $table = 'nodeflow_flows';

    protected $guarded = [];

    protected $casts = [
        'trigger_config' => 'array',
        'draft_graph' => 'array',
        'draft_updated_at' => 'datetime',
        // The editor's concurrency token, compared with !== against a caller's
        // int. MySQL and Postgres commonly hand an unsigned integer column back
        // as a numeric string, where '1' !== 1 would refuse every save after the
        // first as stale. SaveDraft casts inline as well, and deliberately keeps
        // doing so: it is the file where the comparison lives, and a cast three
        // files away is not a thing the next reader of that `!==` will check.
        'draft_revision' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $flow) => $flow->assertCurrentVersionReference());

        static::updating(function (self $flow) {
            if ($flow->isDirty(['current_version_id', 'tenant_id'])) {
                $flow->assertCurrentVersionReference();
            }
        });
    }

    private function assertCurrentVersionReference(): void
    {
        FlowVersionReferenceGuard::assert($this, 'current_version_id', nullable: true);
    }

    // Unscoped: reaching this Flow already proved tenant entitlement, so its
    // own versions are not a second authorization decision — and this must
    // resolve with no ambient tenant at all (console, queue, fan-out).
    //
    // INVARIANT this depends on: every row in nodeflow_flow_versions with this
    // flow_id carries this flow's tenant_id. FlowVersion's creating() hook
    // enforces it on insert, and BelongsToTenant's updating() hook freezes
    // tenant_id afterwards. Break the invariant and this relation hands back
    // another tenant's rows with no scope left to stop it — so never accept
    // flow_id from request input for a version being created, and never let a
    // host update it.
    //
    // Do not "fix" this by constraining the relation to $this->tenant_id: an
    // eager load builds the relation from a fresh model instance whose
    // tenant_id is null, which would silently return nothing.
    public function versions(): HasMany
    {
        return $this->hasMany(FlowVersion::class)->withoutGlobalScope('nodeflow_tenant');
    }

    // Unscoped: reaching this Flow already proved tenant entitlement, so its
    // own current version is not a second authorization decision — and this
    // must resolve with no ambient tenant at all (console, queue, fan-out).
    //
    // INVARIANT this depends on: current_version_id points at a version in this
    // flow's own tenant. Nothing in the database enforces that — there is no
    // composite foreign key — but Eloquent instance writes validate both the
    // referenced version's existence and its tenant before persistence.
    // FlowVersion creation independently validates that its tenant matches its
    // Flow parent's tenant. This intentionally does not require the referenced
    // version to belong to this exact Flow: same-Flow identity is not part of
    // this invariant. Query-builder and raw SQL writes remain the explicit
    // bypass of these model events.
    //
    // Do not "fix" this by constraining the relation to $this->tenant_id: an
    // eager load builds the relation from a fresh model instance whose
    // tenant_id is null, so LoadGraphActivity and RunNodeActivity — the durable
    // execution path — would silently resolve nothing.
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class, 'current_version_id')->withoutGlobalScope('nodeflow_tenant');
    }

    public function runs(): HasManyThrough
    {
        return $this->hasManyThrough(Run::class, FlowVersion::class);
    }
}

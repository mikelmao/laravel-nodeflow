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

    protected $casts = ['trigger_config' => 'array'];

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
    // INVARIANT this depends on: current_version_id points at a version inside
    // this flow's own tenant. Nothing in the database enforces that — there is
    // no composite foreign key — and $guarded is empty here, so the only thing
    // standing between this relation and another tenant's version is that
    // current_version_id is written by PublishFlow from a version it just
    // created for this flow. Never accept it from request input: a host route
    // doing update($request->all()) with a foreign current_version_id turns
    // this unscoped read into a cross-tenant one.
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

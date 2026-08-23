<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nodeflow\Models\Concerns\BelongsToTenant;

class Run extends Model
{
    use BelongsToTenant;

    protected $table = 'nodeflow_runs';

    protected $guarded = [];

    protected $casts = [
        'is_test' => 'boolean',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $run) => $run->assertFlowVersionReference());

        static::updating(function (self $run) {
            if ($run->isDirty(['flow_version_id', 'tenant_id'])) {
                $run->assertFlowVersionReference();
            }
        });
    }

    private function assertFlowVersionReference(): void
    {
        FlowVersionReferenceGuard::assert($this, 'flow_version_id', nullable: false);
    }

    // Unscoped: reaching this Run already proved tenant entitlement, so its
    // own version is not a second authorization decision — and this must
    // resolve with no ambient tenant at all (console, queue, fan-out).
    //
    // INVARIANT this depends on: flow_version_id points at a version inside
    // this run's own tenant. Nothing in the database enforces that — there is
    // no composite foreign key — but the Eloquent creating and invariant-
    // changing updating guards validate the referenced version's existence and
    // tenant before persistence. Query-builder and raw SQL writes explicitly
    // bypass these model events. Never accept flow_version_id from request
    // input: a run started with a foreign version id would execute another
    // tenant's graph, and this relation would hand it over without a scope in
    // the way.
    //
    // Do not "fix" this by constraining the relation to $this->tenant_id: an
    // eager load builds the relation from a fresh model instance whose
    // tenant_id is null, so LoadGraphActivity and RunNodeActivity — the durable
    // execution path — would silently resolve nothing.
    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class)->withoutGlobalScope('nodeflow_tenant');
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(RunSubject::class);
    }

    public function nodeExecutions(): HasMany
    {
        return $this->hasMany(NodeExecution::class);
    }

    public function activeSubjectCount(): int
    {
        return $this->subjects()->where('status', 'active')->count();
    }
}

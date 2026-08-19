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

    // Unscoped: reaching this Run already proved tenant entitlement, so its
    // own version is not a second authorization decision — and this must
    // resolve with no ambient tenant at all (console, queue, fan-out).
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

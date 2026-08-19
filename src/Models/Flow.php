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
    public function versions(): HasMany
    {
        return $this->hasMany(FlowVersion::class)->withoutGlobalScope('nodeflow_tenant');
    }

    // Unscoped: reaching this Flow already proved tenant entitlement, so its
    // own current version is not a second authorization decision — and this
    // must resolve with no ambient tenant at all (console, queue, fan-out).
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class, 'current_version_id')->withoutGlobalScope('nodeflow_tenant');
    }

    public function runs(): HasManyThrough
    {
        return $this->hasManyThrough(Run::class, FlowVersion::class);
    }
}

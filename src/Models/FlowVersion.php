<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowVersion extends Model
{
    protected $table = 'nodeflow_flow_versions';

    protected $guarded = [];

    protected $casts = ['graph' => 'array', 'published_at' => 'datetime'];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    public function hasLiveRuns(): bool
    {
        return $this->runs()
            ->whereIn('status', ['pending', 'running', 'waiting', 'blocked'])
            ->exists();
    }
}

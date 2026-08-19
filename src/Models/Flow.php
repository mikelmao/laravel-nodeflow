<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Flow extends Model
{
    protected $table = 'nodeflow_flows';

    protected $guarded = [];

    protected $casts = ['trigger_config' => 'array'];

    public function versions(): HasMany
    {
        return $this->hasMany(FlowVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class, 'current_version_id');
    }

    public function runs(): HasManyThrough
    {
        return $this->hasManyThrough(Run::class, FlowVersion::class);
    }
}

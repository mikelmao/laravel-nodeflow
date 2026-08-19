<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeExecution extends Model
{
    protected $table = 'nodeflow_node_executions';

    protected $guarded = [];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}

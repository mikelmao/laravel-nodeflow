<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunSubject extends Model
{
    protected $table = 'nodeflow_run_subjects';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['exited_at' => 'datetime'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}

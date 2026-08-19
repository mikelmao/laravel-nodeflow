<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    protected $table = 'nodeflow_templates';

    protected $guarded = [];

    protected $casts = ['graph' => 'array'];
}

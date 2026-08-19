<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Nodeflow\Models\Concerns\BelongsToTenant;

class Template extends Model
{
    use BelongsToTenant;

    protected $table = 'nodeflow_templates';

    protected $guarded = [];

    protected $casts = ['graph' => 'array'];

    public function allowsGlobalTenantRows(): bool
    {
        return true;
    }
}

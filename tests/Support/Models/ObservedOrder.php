<?php

namespace Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ObservedOrder extends Model
{
    use SoftDeletes;

    protected $table = 'observed_orders';

    protected $guarded = [];

    public $timestamps = false;
}

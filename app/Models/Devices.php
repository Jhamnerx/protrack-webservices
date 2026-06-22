<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Devices extends Model
{
    protected $table = 'devices';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'services' => 'json',
        'last_position' => 'json',
        'last_alarm_check' => 'integer',
        'last_accstatus' => 'integer',
        'odometer_meters' => 'float',
    ];


    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}

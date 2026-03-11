<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $fillable = [
        'hostname',
        'ip_address',
        'os_version',
        'location',
        'eol_date',
        'model'
    ];
}

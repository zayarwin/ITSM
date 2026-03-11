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
        'model',
        'username',
        'password',
        'device_type'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Get the change requests associated with the device.
     */
    public function changeRequests()
    {
        return $this->belongsToMany(ChangeRequest::class);
    }
}

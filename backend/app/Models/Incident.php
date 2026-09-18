<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    protected $fillable = [
        'device_id',
        'facility',
        'severity',
        'mnemonic',
        'previous_state',
        'new_state',
        'raw_message',
        'status',
        'diagnostic_context',
        'ai_analysis',
        'detected_at',
        'resolved_at',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}

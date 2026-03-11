<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChangeRequest extends Model
{
    protected $fillable = [
        'crq_number',
        'purpose',
        'task',
        'requester_id',
        'implementer_id',
        'reviewer_id',
        'approver_id',
        'start_time',
        'end_time',
        'status',
        'attachment_path',
        'attachment_name'
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function implementer()
    {
        return $this->belongsTo(User::class, 'implementer_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function devices()
    {
        return $this->belongsToMany(Device::class);
    }
}

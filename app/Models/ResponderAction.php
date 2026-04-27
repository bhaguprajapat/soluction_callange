<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponderAction extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'emergency_id',
        'responder_id',
        'status',
        'created_at',
    ];

    public function emergency(): BelongsTo
    {
        return $this->belongsTo(Emergency::class);
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responder_id');
    }
}


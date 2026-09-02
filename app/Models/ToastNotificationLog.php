<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToastNotificationLog extends Model
{
    public const TYPE_GENERIC = 'generic';

    public const TYPE_MESSAGE = 'message';

    public const TYPE_RH_HIRE_REQUEST = 'rh_hire_request';

    public const TYPE_RH_TERMINATION_REQUEST = 'rh_termination_request';

    protected $fillable = [
        'type',
        'icon',
        'title',
        'text',
        'url',
        'key',
        'recipient_user_id',
        'actor_user_id',
        'audience',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

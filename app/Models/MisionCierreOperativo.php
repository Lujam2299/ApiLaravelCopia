<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MisionCierreOperativo extends Model
{
    protected $table = 'mision_cierres_operativos';

    protected $fillable = [
        'mision_id',
        'user_id',
        'fecha',
        'resumen',
        'novedades',
        'incidencias',
        'pendientes',
        'observaciones',
        'client_operation_id',
        'client_created_at',
    ];

    protected $casts = [
        'client_created_at' => 'datetime',
    ];

    public function mision(): BelongsTo
    {
        return $this->belongsTo(Misiones::class, 'mision_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

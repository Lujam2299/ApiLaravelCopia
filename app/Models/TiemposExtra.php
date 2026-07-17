<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TiemposExtra extends Model
{
    protected $table = 'tiempos_extras';

    protected $fillable = [
        'asistencia_id',
        'user_id',
        'fecha',
        'hora_inicio',
        'hora_fin',
        'total_horas',
        'autorizado_por',
        'observaciones',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

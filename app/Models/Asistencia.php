<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asistencia extends Model
{
    protected $table = 'asistencias';

    protected $fillable = [
        'user_id',
        'fecha',
        'hora_asistencia',
        'elementos_enlistados',
        'faltas',
        'descansos',
        'turnos',
        'fotos_asistentes',
        'observaciones',
        'coberturas',
        'punto',
        'empresa',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tiemposExtra()
    {
        return $this->hasMany(TiemposExtra::class);
    }

    public function retardos()
    {
        return $this->hasMany(Retardo::class);
    }
}

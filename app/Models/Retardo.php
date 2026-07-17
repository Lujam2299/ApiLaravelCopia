<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Retardo extends Model
{
    protected $table = 'retardos';

    protected $fillable = [
        'user_id',
        'asistencia_id',
        'fecha',
        'minutos_retardo',
        'registrado_por',
    ];
}

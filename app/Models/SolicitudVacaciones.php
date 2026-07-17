<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudVacaciones extends Model
{
    protected $table = 'solicitud_vacaciones';

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

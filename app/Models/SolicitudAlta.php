<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudAlta extends Model
{
    protected $table = 'solicitud_altas';

    protected $guarded = [];

    public function documentacion()
    {
        return $this->hasOne(DocumentacionAltas::class, 'solicitud_id');
    }
}

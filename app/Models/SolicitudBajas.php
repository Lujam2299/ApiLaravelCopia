<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudBajas extends Model
{
    protected $table = 'solicitud_bajas';

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

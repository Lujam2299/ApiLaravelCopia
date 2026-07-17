<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Punto extends Model
{
    protected $table = 'puntos';

    protected $fillable = [
        'nombre',
        'zona',
    ];

    public function subpuntos()
    {
        return $this->hasMany(Subpunto::class);
    }
}

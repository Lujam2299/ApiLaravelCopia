<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subpunto extends Model
{
    protected $table = 'subpuntos';

    protected $fillable = [
        'punto_id',
        'nombre',
        'codigo',
        'zona',
        'siglas',
        'roles',
    ];

    protected $casts = [
        'codigo' => 'integer',
        'roles' => 'array',
    ];

    public function punto()
    {
        return $this->belongsTo(Punto::class);
    }

    public function supervisores()
    {
        return $this->belongsToMany(User::class, 'supervisorpuntos', 'subpunto_id', 'supervisor_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class turno extends Model
{
    use HasFactory;
    protected $table = 'turno';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'User_id',
        'Nombre_elemento',
        'Tipo',
        'Hora_inicio',
        'Hora_final',
        'Km_inicio',
        'Km_final',
        'Punto',
        'Placas_unidad',
        'Rayas_gasolina_inicio',
        'Rayas_gasolina_final',
        'Evidencia_inicio',
        'Evidencia_final',
        'client_operation_id',
    ];


    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'Hora_inicio' => 'datetime:H:i',
        'Hora_final' => 'datetime:H:i',
        'Km_inicio' =>  'decimal:2',
        'Km_final' => 'decimal:2',
        'Rayas_gasolina_inicio' => 'decimal:2',
        'Rayas_gasolina_final' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relationship with User model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(apiUser::class, );
    }
       public function turnos()
    {
        return $this->hasMany(turno::class, 'User_id');
    }
}

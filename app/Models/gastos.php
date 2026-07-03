<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class gastos extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'gastos';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'user_name',
        'Monto',
        'Fecha',
        'Hora',
        'Evidencia',
        'Tipo',
        'Categoria',
        'Metodo_pago',
        'Descripcion',
        'Km',
        'Gasolina_antes_carga',
        'Gasolina_despues_carga',
        'client_operation_id',
    ];

    protected $casts = [
        'Fecha' => 'date',
        'Hora' => 'string',
        'Monto' => 'decimal:2',
        'Km' => 'decimal:2',
        'Gasolina_antes_carga' => 'decimal:2',
        'Gasolina_despues_carga' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(apiUser::class);
    }
}

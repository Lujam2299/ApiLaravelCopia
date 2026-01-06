<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentacionAltas extends Model
{
    use HasFactory;

    protected $table = 'documentacion_altas';

    protected $fillable = [
        'solicitud_id',
        'arch_acta_nacimiento',
        'arch_curp',
        'arch_ine',
        'arch_comprobante_domicilio',
        'arch_rfc',
        'arch_foto',
        'arch_contrato',
        'arch_solicitud_empleo',
        'arch_antidoping',
        'arch_visa',
        'arch_pasaporte',
        'arch_acuse_alta',
        'arch_modificacion_salario',
        'arch_acuse_imss',
        'arch_retencion_infonavit',
        'arch_comprobante_estudios',
        'arch_carta_rec_laboral',
        'arch_carta_rec_personal',
        'arch_cartilla_militar',
        'arch_infonavit',
        'arch_fonacot',
        'arch_licencia_conducir',
        'arch_carta_no_penales',
        'arch_solicitud_empleo',
        'created_at',
        'updated_at',
    ];

    protected $guarded = [];

    public function user()
    {
        return $this->hasOne(User::class, 'sol_docs_id', 'id');
    }
}

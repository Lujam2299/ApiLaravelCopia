<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Conversation> $conversations
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $table = 'users';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'rol',
        'punto',
        'empresa',
        'estatus',
        'fecha_ingreso',
        'num_empleado',
        'sol_alta_id',
        'sol_docs_id',
        'email_verified_at',
        'remember_token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function gastos()
    {
        return $this->hasMany(gastos::class);
    }

    public function documentacionAltas()
    {
        return $this->belongsTo(DocumentacionAltas::class, 'sol_docs_id', 'id');
    }

    public function solicitudAlta()
    {
        return $this->hasOne(SolicitudAlta::class, 'id', 'sol_alta_id');
    }

    public function subpuntosSupervisados()
    {
        return $this->belongsToMany(Subpunto::class, 'supervisorpuntos', 'supervisor_id', 'subpunto_id');
    }

    public function routeNotificationForFcm()
    {
        return $this->fcm_token;
    }

    public function routeNotificationForApn()
    {
        return $this->apn_token;
    }

    /**
     * Las conversaciones a las que pertenece el usuario
     */
    public function conversations()
    {
        return $this->belongsToMany(
            Conversation::class,
            'conversation_user',
            'api_user_id',             // FK hacia este modelo (User del API)
            'conversation_id'          // FK hacia Conversation
        )
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    /**
     * Los mensajes que ha enviado el usuario
     */
    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function turnos()
    {
        return $this->hasMany(Turno::class, 'User_id');
    }

    public function getPhotoUrlAttribute()
    {
        $storedPath = $this->documentacionAltas?->arch_foto;

        if (! $storedPath) {
            return null;
        }

        $storedPath = str_replace('\\', '/', trim($storedPath));

        if (filter_var($storedPath, FILTER_VALIDATE_URL)) {
            return $storedPath;
        }

        return URL::temporarySignedRoute(
            'media.user-photo',
            now()->addHours(6),
            ['documentacion' => $this->documentacionAltas->getKey()]
        );
    }
}

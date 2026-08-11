<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class location extends Model
{
    protected $table = 'locations';

    protected $fillable = [
        'user_id',
        'latitude',
        'longitude',
    ];
}

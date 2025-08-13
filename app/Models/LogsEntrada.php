<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogsEntrada extends Model
{
    protected $table = 'logs_entrada';

    protected $fillable = [
        'id_requisicao',
        'json',
        'data',
        'status',
        'motivo'
    ];

    protected $casts = [
        'json' => 'array',
        'data' => 'datetime',
    ];
}

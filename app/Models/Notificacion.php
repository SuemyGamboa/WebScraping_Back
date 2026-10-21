<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Notificacion extends Model
{
    use HasUuids;

    protected $table = 'notificaciones';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'producto_id',
        'usuario_id',
        'precio_detectado',
        'precio_objetivo',
        'canal',
    ];

    protected $casts = [
        'enviado_en' => 'datetime',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
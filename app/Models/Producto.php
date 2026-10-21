<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    use HasUuids;

    protected $table = 'productos';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    const CREATED_AT = 'creado_en';
    const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'usuario_id',
        'asin',
        'url',
        'titulo',
        'marca',
        'categoria',
        'imagen_url',
        'precio_actual',
        'precio_minimo',
        'precio_maximo',
        'precio_objetivo',
        'alerta_activa',
        'activo',
    ];

    protected $casts = [
        'alerta_activa' => 'boolean',
        'activo'        => 'boolean',
        'creado_en'     => 'datetime',
        'actualizado_en'=> 'datetime',
    ];

    public function historial()
    {
        return $this->hasMany(HistorialPrecio::class, 'producto_id');
    }

    public function notificaciones()
    {
        return $this->hasMany(Notificacion::class, 'producto_id');
    }
}
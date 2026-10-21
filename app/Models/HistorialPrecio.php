<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistorialPrecio extends Model
{
    protected $table = 'historial_precios';
    public $timestamps = false;

    protected $fillable = [
        'producto_id',
        'precio',
        'moneda',
        'en_stock',
        'fuente',
    ];

    protected $casts = [
        'en_stock'      => 'boolean',
        'registrado_en' => 'datetime',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
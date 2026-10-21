<?php

namespace App\Console\Commands;

use App\Models\Producto;
use App\Models\HistorialPrecio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class PriceFetch extends Command
{
    protected $signature = 'price:fetch {--fake : Genera un precio aleatorio en vez de scrapear}';
    protected $description = 'Obtiene el precio actual de los productos activos y lo guarda en el historial';

    public function handle()
    {
        $productos = Producto::where('activo', true)->get();

        if ($productos->isEmpty()) {
            $this->warn('No hay productos activos para rastrear.');
            return self::SUCCESS;
        }

        foreach ($productos as $producto) {
            $this->info("Procesando {$producto->titulo} ({$producto->asin})...");

            try {
                $precio = $this->option('fake')
                    ? $this->precioFalso($producto)
                    : $this->scrapearPrecio($producto->url);

                if ($precio === null) {
                    $this->warn("  No se pudo leer el precio de {$producto->asin}");
                    continue;
                }

                HistorialPrecio::create([
                    'producto_id' => $producto->id,
                    'precio' => $precio,
                    'en_stock' => true,
                    'fuente' => $this->option('fake') ? 'fake' : 'scraper',
                ]);

                $producto->refresh();
                $this->info("  Precio: $" . number_format($precio / 100, 2) . " MXN");

                // Comprobar si se disparó la alerta
                if ($producto->alerta_activa
                    && $producto->precio_objetivo
                    && $precio <= $producto->precio_objetivo) {
                    $this->warn("  ¡ALERTA! Precio por debajo del objetivo ({$producto->precio_objetivo})");
                    // Aquí después conectas el envío de notificación
                }

            } catch (\Throwable $e) {
                $this->error("  Error: {$e->getMessage()}");
            }

            // Pausa entre productos para no parecer bot agresivo
            sleep(2);
        }

        return self::SUCCESS;
    }

    private function precioFalso(Producto $producto): int
    {
        // Precio aleatorio ±10% del precio actual
        $base = $producto->precio_actual ?? 10000;
        $factor = 0.9 + (mt_rand(0, 200) / 1000); // 0.90 a 1.10
        return (int) round($base * $factor);
    }

    private function scrapearPrecio(string $url): ?int
    {
        // Placeholder: aquí va la lógica real de scraping
        // Por ahora devuelve null para que uses --fake mientras aprendes
        $this->warn('  Scraping real aún no implementado. Usa --fake para probar el flujo.');
        return null;
    }
}
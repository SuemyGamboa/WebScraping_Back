<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\HistorialPrecio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ProductoController extends Controller
{
    // GET /api/productos
    public function index()
    {
        return Producto::orderBy('creado_en', 'desc')->get();
    }

    // GET /api/productos/{id}/historial
    public function historial($id)
    {
        return HistorialPrecio::where('producto_id', $id)
            ->orderBy('registrado_en')
            ->get();
    }

    // POST /api/productos  { url }
    public function store(Request $request)
    {
        $data = $request->validate([
            'url' => 'required|string|min:10',
        ]);

        $url = $data['url'];

        if (!preg_match('/(?:\/dp\/|\/gp\/product\/)([A-Z0-9]{10})/i', $url, $m)) {
            return response()->json(['error' => 'URL de Amazon inválida'], 422);
        }

        $asin = strtoupper($m[1]);
        $usuarioId = '5ec8380e-575c-47fe-96c6-0beec179e125'; // usuario de prueba

        // Si ya existe, devolverlo
        $existente = Producto::where('usuario_id', $usuarioId)
            ->where('asin', $asin)
            ->first();

        if ($existente) {
            return $existente;
        }

        // Scrapear Amazon vía ScraperAPI
        $datos = $this->scrapearAmazon($url);

        $producto = Producto::create([
            'usuario_id' => $usuarioId,
            'asin' => $asin,
            'url' => $url,
            'titulo' => $datos['titulo'] ?? "Producto $asin",
            'marca' => $datos['marca'] ?? null,
            'categoria' => null,
            'imagen_url' => $datos['imagen_url'] ?? null,
            'precio_actual' => $datos['precio'] ?? null,
            'precio_objetivo' => $datos['precio']
                ? (int) round($datos['precio'] * 0.9)
                : null,
            'alerta_activa' => true,
            'activo' => true,
        ]);

        // Primer punto del historial
        if ($datos['precio']) {
            HistorialPrecio::create([
                'producto_id' => $producto->id,
                'precio' => $datos['precio'],
                'fuente' => 'scraper',
                'en_stock' => $datos['en_stock'] ?? true,
            ]);
            $producto->refresh();
        }

        return $producto;
    }

    // PATCH /api/productos/{id}
    public function update(Request $request, $id)
    {
        $producto = Producto::find($id);
        if (!$producto) {
            return response()->json(['error' => 'No encontrado'], 404);
        }
        $data = $request->only(['precio_objetivo', 'alerta_activa', 'activo']);
        $producto->update($data);
        return $producto;
    }

    // DELETE /api/productos/{id}
    public function destroy($id)
    {
        $producto = Producto::find($id);
        if (!$producto) {
            return response()->json(['error' => 'No encontrado'], 404);
        }
        $producto->delete();
        return response()->noContent();
    }

    // ---------- Scraping ----------
    private function scrapearAmazon(string $url): array
    {
        $apiKey = config('services.scraperapi.key');
        if (!$apiKey) {
            return [];
        }

        try {
            $resp = Http::timeout(60)->get('https://api.scraperapi.com', [
                'api_key' => $apiKey,
                'url' => $url,
                'country_code' => 'mx',
                'render' => 'false',
            ]);
        } catch (\Throwable $e) {
            return [];
        }

        if (!$resp->ok()) {
            return [];
        }

        $html = $resp->body();

        // Título
        $titulo = null;
        if (preg_match('/<span[^>]*id="productTitle"[^>]*>(.*?)<\/span>/s', $html, $m)) {
            $titulo = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
        }
        if (!$titulo && preg_match('/<title>(.*?)<\/title>/s', $html, $m)) {
            $titulo = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
        }

        // Precio (varios formatos de Amazon MX)
        $precio = null;
        if (preg_match('/<span class="a-price-whole">([\d.,]+)<\/span>/', $html, $m)) {
            $limpio = preg_replace('/[^\d]/', '', $m[1]);
            if ($limpio !== '') {
                $precio = (int) $limpio * 100; // a centavos
            }
        }
        if ($precio === null && preg_match('/"priceAmount":\s*([\d.]+)/', $html, $m)) {
            $precio = (int) round((float) $m[1] * 100);
        }

        // Imagen
        $imagen = null;
        if (preg_match('/<img[^>]*id="landingImage"[^>]*src="([^"]+)"/', $html, $m)) {
            $imagen = $m[1];
        }
        if (!$imagen && preg_match('/"hiRes":"([^"]+)"/', $html, $m)) {
            $imagen = $m[1];
        }

        // Marca
        $marca = null;
        if (preg_match('/<a[^>]*id="bylineInfo"[^>]*>(.*?)<\/a>/s', $html, $m)) {
            $marca = trim(strip_tags($m[1]));
            $marca = preg_replace('/^(Marca|Visita la tienda de|Brand)\s*:?\s*/i', '', $marca);
        }

        // Disponibilidad
        $enStock = !preg_match('/No disponible por el momento|Currently unavailable/i', $html);

        return [
            'titulo' => $titulo,
            'precio' => $precio,
            'imagen_url' => $imagen,
            'marca' => $marca ?: null,
            'en_stock' => $enStock,
        ];
    }
}
<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\HistorialPrecio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

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
            'url' => 'required|string|min:8',
        ]);

        $url = trim($data['url']);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['error' => 'El enlace proporcionado no es una URL válida.'], 422);
        }

        // Detectar tienda e identificador único (Amazon, Liverpool, Mercado Libre, etc.)
        $infoTienda = $this->extraerIdentificadorYTienda($url);
        $asin = $infoTienda['id'];
        $tienda = $infoTienda['tienda'];
        $usuarioId = '5ec8380e-575c-47fe-96c6-0beec179e125'; // usuario de prueba

        // Si ya existe, devolverlo
        $existente = Producto::where('usuario_id', $usuarioId)
            ->where('asin', $asin)
            ->first();

        if ($existente) {
            return $existente;
        }

        // Scrapear la tienda vía ScraperAPI
        $datos = $this->scrapearTienda($url, $tienda);

        $producto = Producto::create([
            'usuario_id'      => $usuarioId,
            'asin'            => $asin,
            'url'             => $url,
            'titulo'          => $datos['titulo'] ?? ($tienda . ' ' . $asin),
            'marca'           => $datos['marca'] ?? $tienda,
            'categoria'       => $infoTienda['categoria'] ?? $tienda,
            'imagen_url'      => $datos['imagen_url'] ?? null,
            'precio_actual'   => $datos['precio'] ?? null,
            'precio_objetivo' => $datos['precio']
                ? (int) round($datos['precio'] * 0.9)
                : null,
            'alerta_activa'   => true,
            'activo'          => true,
        ]);

        // Primer punto del historial
        if ($datos['precio']) {
            HistorialPrecio::create([
                'producto_id' => $producto->id,
                'precio'      => $datos['precio'],
                'fuente'      => 'scraper',
                'en_stock'    => $datos['en_stock'] ?? true,
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

    // GET /api/productos/{id}
    public function show($id)
    {
        $producto = Producto::find($id);
        if (!$producto) {
            return response()->json(['error' => 'No encontrado'], 404);
        }
        return $producto;
    }

    // GET /api/productos/{id}/preview — retorna HTML con Inspector & Editor interactivo
    public function preview($id)
    {
        $producto = Producto::find($id);
        if (!$producto) {
            return response('<p style="padding:20px;font-family:sans-serif">Producto no encontrado.</p>', 404)
                ->header('Content-Type', 'text/html');
        }

        $apiKey = config('services.scraperapi.key');
        if (!$apiKey) {
            return response('<p style="padding:20px;font-family:sans-serif">ScraperAPI no configurada.</p>', 500)
                ->header('Content-Type', 'text/html');
        }

        // Cachear el HTML por 1 hora en disco para carga ultrarrápida
        $cacheKey = "preview_html_{$producto->id}";
        $fetchHtml = function () use ($producto, $apiKey) {
            try {
                $resp = Http::timeout(60)->get('https://api.scraperapi.com', [
                    'api_key'      => $apiKey,
                    'url'          => $producto->url,
                    'country_code' => 'mx',
                    'render'       => 'false',
                ]);
                if ($resp->ok()) {
                    return $resp->body();
                }
            } catch (\Throwable) {}
            return null;
        };

        try {
            $html = Cache::store('file')->remember($cacheKey, 3600, $fetchHtml);
        } catch (\Throwable) {
            $html = $fetchHtml();
        }

        if (!$html) {
            $fallbackHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title></head><body style="margin:0;padding:40px 20px;font-family:-apple-system,BlinkMacSystemFont,sans-serif;text-align:center;background:#fafafb;color:#222;">' .
                '<div style="max-width:460px;margin:0 auto;background:#fff;padding:30px;border-radius:14px;box-shadow:0 4px 20px rgba(0,0,0,0.06);">' .
                '<div style="font-size:40px;margin-bottom:12px;">📡</div>' .
                '<h3 style="margin:0 0 10px 0;font-size:17px;color:#111;">No se pudo cargar la vista previa directa</h3>' .
                '<p style="margin:0 0 20px 0;font-size:13px;color:#666;line-height:1.5;">La conexión con la tienda tardó demasiado o la tienda requirió verificación humana temporal.</p>' .
                '<a href="' . htmlspecialchars($producto->url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:12px 20px;background:#ff9900;color:#111;text-decoration:none;border-radius:10px;font-weight:700;font-size:13px;">Abrir directamente en la tienda oficial ↗</a>' .
                '</div></body></html>';
            return response($fallbackHtml, 200)
                ->header('Content-Type', 'text/html; charset=utf-8')
                ->header('Access-Control-Allow-Origin', '*')
                ->header('X-Frame-Options', 'ALLOWALL');
        }

        // 1. Remover etiquetas <script> pesadas de Amazon/Liverpool para evitar trackers de terceros
        $html = preg_replace('/<script\b[^>]*>([\s\S]*?)<\/script>/i', '', $html);

        // 2. Inyectar <base> con el dominio de la tienda del producto
        $parsedUrl = parse_url($producto->url);
        $baseHref = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? 'www.amazon.com.mx') . '/';

        $html = preg_replace(
            '/<head([^>]*)>/i',
            '<head$1><base href="' . htmlspecialchars($baseHref, ENT_QUOTES) . '" target="_blank">',
            $html,
            1
        );

        // 3. Inyectar Toolbar interactivo de Edición + Inspector visual (tipo DevTools)
        $tiendaNombre = $producto->categoria ?: 'Tienda';
        $inspectorHtml = $this->generarToolbarInspector($tiendaNombre, $producto->url);

        if (stripos($html, '</body>') !== false) {
            $html = str_ireplace('</body>', $inspectorHtml . '</body>', $html);
        } else {
            $html .= $inspectorHtml;
        }

        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Access-Control-Allow-Origin', '*')
            ->header('X-Frame-Options', 'ALLOWALL')
            ->header('Content-Security-Policy', "frame-ancestors *; default-src 'self' 'unsafe-inline' 'unsafe-eval' https: data:;");
    }

    // ---------- Identificación y Scraping Multi-Tienda ----------
    private function extraerIdentificadorYTienda(string $url): array
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        // 1. Amazon
        if (str_contains($host, 'amazon')) {
            if (preg_match('/(?:\/(?:dp|gp\/product|gp\/aw\/d|product)\/|(?:\/|\?|&)asin=)([A-Z0-9]{10})/i', $url, $m)) {
                return ['id' => strtoupper($m[1]), 'tienda' => 'Amazon', 'categoria' => 'Amazon'];
            }
            if (preg_match('/\/([B0-9][A-Z0-9]{9})(?:[/?#&]|$)/i', $url, $m)) {
                return ['id' => strtoupper($m[1]), 'tienda' => 'Amazon', 'categoria' => 'Amazon'];
            }
            return ['id' => 'AMZ-' . substr(md5($url), 0, 8), 'tienda' => 'Amazon', 'categoria' => 'Amazon'];
        }

        // 2. Liverpool
        if (str_contains($host, 'liverpool')) {
            if (preg_match('/(?:\/pdp\/[^\/]+\/|\/p\/|\/)(\d{7,12})(?:[/?#&]|$)/i', $url, $m)) {
                return ['id' => 'LIV-' . $m[1], 'tienda' => 'Liverpool', 'categoria' => 'Liverpool'];
            }
            return ['id' => 'LIV-' . substr(md5($url), 0, 8), 'tienda' => 'Liverpool', 'categoria' => 'Liverpool'];
        }

        // 3. Mercado Libre
        if (str_contains($host, 'mercadolibre')) {
            if (preg_match('/(MLM-?\d+)/i', $url, $m)) {
                return ['id' => strtoupper(str_replace('-', '', $m[1])), 'tienda' => 'Mercado Libre', 'categoria' => 'Mercado Libre'];
            }
            return ['id' => 'MLM-' . substr(md5($url), 0, 8), 'tienda' => 'Mercado Libre', 'categoria' => 'Mercado Libre'];
        }

        // 4. Walmart
        if (str_contains($host, 'walmart')) {
            if (preg_match('/\/(\d{8,14})(?:[/?#&]|$)/i', $url, $m)) {
                return ['id' => 'WAL-' . $m[1], 'tienda' => 'Walmart', 'categoria' => 'Walmart'];
            }
            return ['id' => 'WAL-' . substr(md5($url), 0, 8), 'tienda' => 'Walmart', 'categoria' => 'Walmart'];
        }

        // 5. Genérico (Palacio de Hierro, Cyberpuerta, Coppel, etc.)
        $hostLimpio = preg_replace('/^(www\.|m\.)/i', '', $host);
        $tiendaNombre = ucfirst(explode('.', $hostLimpio)[0] ?? 'Web');
        return [
            'id' => strtoupper(substr($tiendaNombre, 0, 3)) . '-' . substr(md5($url), 0, 8),
            'tienda' => $tiendaNombre,
            'categoria' => $tiendaNombre,
        ];
    }

    private function scrapearTienda(string $url, string $tienda): array
    {
        $apiKey = config('services.scraperapi.key');
        if (!$apiKey) return [];

        // Para Amazon, ScraperAPI ofrece JSON estructurado vía autoparse
        if ($tienda === 'Amazon') {
            try {
                $auto = Http::timeout(60)->get('https://api.scraperapi.com', [
                    'api_key'      => $apiKey,
                    'url'          => $url,
                    'country_code' => 'mx',
                    'autoparse'    => 'true',
                ]);

                if ($auto->ok()) {
                    $d = $auto->json();
                    if (!empty($d['name'])) {
                        return [
                            'titulo'    => $d['name'],
                            'precio'    => $this->parsePrecio($d['pricing'] ?? $d['sale_price'] ?? null),
                            'imagen_url'=> is_array($d['images'] ?? null) ? ($d['images'][0] ?? null) : null,
                            'marca'     => $d['brand'] ?? 'Amazon',
                            'en_stock'  => !str_contains(strtolower($d['availability'] ?? ''), 'unavailable'),
                        ];
                    }
                }
            } catch (\Throwable) {}
        }

        // Petición HTML para Liverpool, Mercado Libre y otras tiendas
        try {
            $resp = Http::timeout(60)->get('https://api.scraperapi.com', [
                'api_key'      => $apiKey,
                'url'          => $url,
                'country_code' => 'mx',
                'render'       => 'false',
            ]);
        } catch (\Throwable) {
            return [];
        }

        if (!$resp->ok()) return [];

        return $this->extraerDatosHtml($resp->body(), $tienda);
    }

    private function extraerDatosHtml(string $html, string $tienda): array
    {
        $titulo = null;
        $precio = null;
        $imagen = null;
        $marca  = $tienda;
        $enStock = true;

        // 1. Schema.org JSON-LD (funciona en Liverpool, Walmart, Mercado Libre, Amazon, etc.)
        if (preg_match_all('/<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $jsonStr) {
                $data = json_decode(trim($jsonStr), true);
                if (!$data) continue;

                $items = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : (isset($data[0]) ? $data : [$data]);

                foreach ($items as $item) {
                    if (!is_array($item)) continue;
                    $type = $item['@type'] ?? '';
                    if ($type === 'Product' || (is_array($type) && in_array('Product', $type))) {
                        if (!$titulo && !empty($item['name'])) {
                            $titulo = trim(strip_tags($item['name']));
                        }
                        if (!$imagen && !empty($item['image'])) {
                            $imagen = is_array($item['image']) ? ($item['image'][0] ?? null) : $item['image'];
                        }
                        if ((!$marca || $marca === $tienda) && !empty($item['brand'])) {
                            $marca = is_array($item['brand']) ? ($item['brand']['name'] ?? null) : $item['brand'];
                        }
                        if ($precio === null && !empty($item['offers'])) {
                            $offers = is_array($item['offers']) ? $item['offers'] : [$item['offers']];
                            $firstOffer = isset($offers['price']) ? $offers : ($offers[0] ?? []);
                            $pRaw = $firstOffer['price'] ?? $firstOffer['lowPrice'] ?? null;
                            if ($pRaw) {
                                $precio = $this->parsePrecio((string)$pRaw);
                            }
                        }
                    }
                }
            }
        }

        // 2. OpenGraph Meta Tags
        if (!$titulo && preg_match('/<meta[^>]*property=["\']og:title["\'][^>]*content=["\'](.*?)["\']/i', $html, $m)) {
            $titulo = html_entity_decode(trim($m[1]));
        }
        if (!$imagen && preg_match('/<meta[^>]*property=["\']og:image["\'][^>]*content=["\'](.*?)["\']/i', $html, $m)) {
            $imagen = trim($m[1]);
        }
        if ($precio === null && preg_match('/<meta[^>]*property=["\'](?:product:price:amount|og:price:amount)["\'][^>]*content=["\'](.*?)["\']/i', $html, $m)) {
            $precio = $this->parsePrecio($m[1]);
        }

        // 3. Reglas específicas por tienda
        if ($tienda === 'Liverpool') {
            if (!$titulo && preg_match('/<h1[^>]*class="[^"]*(?:product-header-title|a-product__information--title)[^"]*"[^>]*>(.*?)<\/h1>/si', $html, $m)) {
                $titulo = trim(strip_tags($m[1]));
            }
            if ($precio === null && preg_match('/<p[^>]*class="[^"]*(?:paragraphDiscountPrice|paragraphRegularPrice)[^"]*"[^>]*>(.*?)<\/p>/si', $html, $m)) {
                $precio = $this->parsePrecio($m[1]);
            }
            if ($precio === null && preg_match('/"discountPrice":\s*([\d.]+)/i', $html, $m)) {
                $precio = (int) round((float)$m[1]);
            }
        } elseif ($tienda === 'Mercado Libre') {
            if (!$titulo && preg_match('/<h1[^>]*class="[^"]*ui-pdp-title[^"]*"[^>]*>(.*?)<\/h1>/si', $html, $m)) {
                $titulo = trim(strip_tags($m[1]));
            }
            if ($precio === null && preg_match('/class="[^"]*andes-money-amount__fraction[^"]*"[^>]*>([\d.,]+)<\/span>/i', $html, $m)) {
                $limpio = preg_replace('/[^\d]/', '', $m[1]);
                if ($limpio !== '') $precio = (int) $limpio;
            }
        } elseif ($tienda === 'Amazon') {
            if (!$titulo && preg_match('/<span[^>]*id="productTitle"[^>]*>(.*?)<\/span>/si', $html, $m)) {
                $titulo = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
            }
            if ($precio === null && preg_match('/<span class="a-price-whole">([\d.,]+)<\/span>/i', $html, $m)) {
                $limpio = preg_replace('/[^\d]/', '', $m[1]);
                if ($limpio !== '') $precio = (int) $limpio;
            }
            if ($precio === null && preg_match('/"priceAmount":\s*([\d.]+)/i', $html, $m)) {
                $precio = (int) round((float) $m[1]);
            }
            if (!$imagen && preg_match('/<img[^>]*id="landingImage"[^>]*src="([^"]+)"/i', $html, $m)) {
                $imagen = $m[1];
            }
        }

        // Título genérico si no se encontró
        if (!$titulo && preg_match('/<title>(.*?)<\/title>/si', $html, $m)) {
            $titulo = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
            $titulo = preg_replace('/\s*[-|]\s*(Liverpool|Amazon|Mercado Libre|Walmart).*$/i', '', $titulo);
        }

        return [
            'titulo'    => $titulo ?: "Producto $tienda",
            'precio'    => $precio,
            'imagen_url'=> $imagen,
            'marca'     => $marca ?: $tienda,
            'en_stock'  => $enStock,
        ];
    }

    private function parsePrecio(?string $raw): ?int
    {
        if (!$raw) return null;
        $raw = str_replace(',', '', $raw);
        $limpio = preg_replace('/[^\d.]/', '', $raw);
        if ($limpio === '') return null;
        return (int) round((float) $limpio);
    }

    private function generarToolbarInspector(string $tienda, string $url): string
    {
        $urlSegura = htmlspecialchars($url, ENT_QUOTES);
        $tiendaSegura = htmlspecialchars($tienda, ENT_QUOTES);

        return <<<HTML
<!-- BUENFEST INSPECTOR & EDITOR TOOLBAR -->
<style>
  #__bf_toolbar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 48px;
    background: rgba(18, 18, 20, 0.96);
    backdrop-filter: blur(8px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 16px;
    z-index: 2147483647;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: #f0f0f0;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
  }
  body { padding-top: 54px !important; }
  .__bf_btn {
    background: rgba(255, 255, 255, 0.08);
    color: #eee;
    border: 1px solid rgba(255, 255, 255, 0.15);
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
  }
  .__bf_btn:hover { background: rgba(255, 255, 255, 0.18); color: #fff; }
  .__bf_btn.active {
    background: #ff9900 !important;
    color: #111 !important;
    border-color: #ff9900 !important;
    font-weight: 700;
  }
  .__bf_highlight {
    outline: 2px dashed #ff9900 !important;
    outline-offset: 2px !important;
    background: rgba(255, 153, 0, 0.1) !important;
    cursor: crosshair !important;
  }
  #__bf_info_box {
    position: fixed;
    bottom: 16px;
    left: 16px;
    right: 16px;
    max-width: 420px;
    background: rgba(20, 20, 24, 0.95);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    padding: 12px 14px;
    font-size: 12px;
    z-index: 2147483647;
    box-shadow: 0 8px 30px rgba(0,0,0,0.4);
    display: none;
    line-height: 1.4;
  }
</style>

<div id="__bf_toolbar">
  <div style="display:flex;align-items:center;gap:10px;">
    <span style="font-weight:800;font-size:12px;background:#ff9900;color:#111;padding:3px 7px;border-radius:5px;">
      BuenFest Inspector
    </span>
    <span style="font-size:12px;color:#aaa;border-left:1px solid #444;padding-left:8px;">
      {$tiendaSegura}
    </span>
  </div>

  <div style="display:flex;align-items:center;gap:8px;">
    <button type="button" id="__bf_btn_normal" class="__bf_btn active">👁️ Normal</button>
    <button type="button" id="__bf_btn_edit" class="__bf_btn">✏️ Editar texto</button>
    <button type="button" id="__bf_btn_inspect" class="__bf_btn">🔍 Inspeccionar</button>
    <button type="button" id="__bf_btn_reset" class="__bf_btn">↺ Restaurar</button>
  </div>

  <div>
    <a href="{$urlSegura}" target="_blank" rel="noopener noreferrer" style="color:#ff9900;text-decoration:none;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
      Abrir tienda ↗
    </a>
  </div>
</div>

<div id="__bf_info_box"></div>

<script>
(function() {
  try {
    if (window.parent && window.parent !== window) {
      window.parent.postMessage({ type: 'BUENFEST_PREVIEW_READY' }, '*');
    }
  } catch(e) {}

  var mode = 'normal'; // 'normal' | 'edit' | 'inspect'
  var originalBodyHtml = document.body.innerHTML;
  var infoBox = document.getElementById('__bf_info_box');
  var hoveredEl = null;

  function showMsg(text, autoHide) {
    if (!infoBox) return;
    infoBox.innerHTML = text;
    infoBox.style.display = 'block';
    if (autoHide) {
      setTimeout(function() { infoBox.style.display = 'none'; }, 3500);
    }
  }

  function setMode(newMode) {
    mode = newMode;
    document.querySelectorAll('.__bf_btn').forEach(function(b) { b.classList.remove('active'); });
    if (hoveredEl) {
      hoveredEl.classList.remove('__bf_highlight');
      hoveredEl = null;
    }

    if (mode === 'edit') {
      document.getElementById('__bf_btn_edit').classList.add('active');
      document.designMode = 'on';
      showMsg('✏️ <b>Modo Edición Activado:</b> Haz clic en cualquier texto o precio de la página para reescribirlo o borrarlo directamente.', true);
    } else if (mode === 'inspect') {
      document.getElementById('__bf_btn_inspect').classList.add('active');
      document.designMode = 'off';
      showMsg('🔍 <b>Modo Inspector Activado:</b> Pasa el cursor sobre la página para ver las etiquetas HTML. Haz clic en un elemento para inspeccionar sus datos.', false);
    } else {
      document.getElementById('__bf_btn_normal').classList.add('active');
      document.designMode = 'off';
      infoBox.style.display = 'none';
    }
  }

  document.getElementById('__bf_btn_normal').onclick = function() { setMode('normal'); };
  document.getElementById('__bf_btn_edit').onclick = function() { setMode('edit'); };
  document.getElementById('__bf_btn_inspect').onclick = function() { setMode('inspect'); };
  document.getElementById('__bf_btn_reset').onclick = function() {
    if (confirm('¿Deseas restaurar la página a su contenido original?')) {
      location.reload();
    }
  };

  // Eventos para el modo Inspector
  document.addEventListener('mouseover', function(e) {
    if (mode !== 'inspect') return;
    if (e.target.closest('#__bf_toolbar') || e.target.closest('#__bf_info_box')) return;
    if (hoveredEl) hoveredEl.classList.remove('__bf_highlight');
    hoveredEl = e.target;
    hoveredEl.classList.add('__bf_highlight');
    
    var tag = hoveredEl.tagName.toLowerCase();
    var cls = hoveredEl.className ? ('.' + String(hoveredEl.className).trim().split(/\s+/).slice(0, 2).join('.')) : '';
    var textPreview = (hoveredEl.innerText || '').slice(0, 50).trim();
    showMsg('<b>&lt;' + tag + cls + '&gt;</b><br><span style="color:#aaa;">' + (textPreview || 'Elemento sin texto') + '</span>');
  }, true);

  document.addEventListener('click', function(e) {
    if (mode !== 'inspect') return;
    if (e.target.closest('#__bf_toolbar') || e.target.closest('#__bf_info_box')) return;
    e.preventDefault();
    e.stopPropagation();

    var el = e.target;
    var tag = el.tagName.toLowerCase();
    var val = (el.innerText || '').trim();
    var nuevo = prompt('Editar contenido del elemento <' + tag + '>:', val);
    if (nuevo !== null) {
      el.innerText = nuevo;
      showMsg('✅ Elemento actualizado correctamente.', true);
    }
  }, true);
})();
</script>
HTML;
    }
}
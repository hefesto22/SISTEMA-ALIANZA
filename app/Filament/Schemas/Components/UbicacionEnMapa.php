<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use Afsakar\LeafletMapPicker\LeafletMapPicker;
use Closure;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;

/**
 * Mapa para marcar dónde queda el negocio. Usa el plugin
 * afsakar/filament-leaflet-map-picker (Leaflet + OpenStreetMap) y guarda el
 * punto como {"lat": …, "lng": …}.
 *
 * Se marca con "Usar mi ubicación actual" (el GPS del teléfono; la página
 * debe abrirse con https), tocando el mapa o arrastrando el pin.
 *
 * Cada que se marca un punto el navegador llama a ubicar() y ahí corre lo
 * que se le dio en alMarcar(): por ejemplo llenar departamento, municipio y
 * referencia con lo que hay en ese punto (ClienteForm).
 *
 * Vista: resources/views/filament/forms/ubicacion-en-mapa.blade.php (envuelve
 * la del plugin).
 */
class UbicacionEnMapa extends LeafletMapPicker
{
    /** Santa Rosa de Copán: el mapa abre ahí, sobre la zona de las bodegas. */
    public const CENTRO = ['lat' => 14.7667, 'lng' => -88.7792];

    /** Pin propio (el del plugin pide publicar sus imágenes). */
    private const PIN = '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="44" viewBox="0 0 32 44">'
        .'<path d="M16 1.5C8 1.5 1.5 7.9 1.5 15.8c0 10.7 14.5 26.7 14.5 26.7s14.5-16 14.5-26.7C30.5 7.9 24 1.5 16 1.5z" fill="#e11d48" stroke="#ffffff" stroke-width="2"/>'
        .'<circle cx="16" cy="16" r="5.5" fill="#ffffff"/></svg>';

    protected ?Closure $alMarcar = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->view('filament.forms.ubicacion-en-mapa');
        $this->label('Ubicación en el mapa');
        $this->height('260px');
        $this->defaultLocation(self::CENTRO);
        $this->defaultZoom(fn (mixed $state): int => is_array($state) ? 16 : 9);
        $this->myLocationButtonLabel('Usar mi ubicación actual');
        $this->geolocationHighAccuracy();
        $this->geolocationTimeout(15000);
        $this->customMarker([
            'iconUrl'     => 'data:image/svg+xml;base64,'.base64_encode(self::PIN),
            'iconSize'    => [32, 44],
            'iconAnchor'  => [16, 43],
            'popupAnchor' => [0, -40],
        ]);
    }

    /**
     * Qué hacer cuando se marca un punto. Recibe $punto ({lat, lng}) y las
     * utilidades de Filament (Get, Set…).
     */
    public function alMarcar(?Closure $callback): static
    {
        $this->alMarcar = $callback;

        return $this;
    }

    /**
     * Lo llama el navegador después de marcar un punto (ya con el punto
     * nuevo en el formulario).
     */
    #[ExposedLivewireMethod]
    public function ubicar(): void
    {
        $punto = $this->getState();

        if ($punto === null || $this->alMarcar === null) {
            return;
        }

        $this->evaluate($this->alMarcar, ['punto' => $punto]);
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Mapa;

use App\Models\Departamento;
use App\Models\Municipio;
use App\Support\Texto;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Qué hay en un punto del mapa: departamento y municipio del catálogo y una
 * referencia escrita (calle, barrio, aldea…).
 *
 * Le pregunta a Nominatim (OpenStreetMap). El servicio público pide que la
 * aplicación se identifique (User-Agent), no más de una consulta por segundo
 * y guardar lo ya consultado: cada punto se guarda 30 días y hay un tope de
 * consultas por minuto. Configuración: services.nominatim.
 *
 * Si Nominatim no contesta devuelve null: el punto se guarda igual y se
 * escogen departamento y municipio a mano.
 */
final class BuscarLugar
{
    /** Unos 1.1 m: tocar casi en el mismo lugar reusa la consulta. */
    private const DECIMALES = 5;

    private const DIAS_GUARDADO = 30;

    private const CONSULTAS_POR_MINUTO = 40;

    /** Dónde viene el municipio en Honduras, de lo más seguro a lo menos. */
    private const CAMPOS_MUNICIPIO = ['municipality', 'county', 'city', 'town', 'village', 'hamlet'];

    /** Lo que sirve de referencia, en el orden en que se escribe. */
    private const CAMPOS_REFERENCIA = ['amenity', 'shop', 'building', 'road', 'neighbourhood', 'quarter', 'suburb', 'city_district', 'hamlet', 'village', 'town', 'city'];

    /** El nombre del pueblo para los avisos. */
    private const CAMPOS_PUEBLO = ['municipality', 'city', 'town', 'village', 'hamlet', 'county'];

    public function en(float $lat, float $lng): ?Lugar
    {
        $direccion = $this->direccionDe(round($lat, self::DECIMALES), round($lng, self::DECIMALES));

        return $direccion === null ? null : $this->lugarDe($direccion);
    }

    /**
     * Las partes de la dirección según Nominatim (road, village, state,
     * country_code…). [] = el punto no tiene dirección (el mar, por ejemplo).
     *
     * @return array<string, string>|null
     */
    private function direccionDe(float $lat, float $lng): ?array
    {
        $clave = "mapa:direccion:{$lat},{$lng}";
        $guardada = Cache::get($clave);

        if (is_array($guardada)) {
            /** @var array<string, string> $guardada */
            return $guardada;
        }

        if (RateLimiter::tooManyAttempts('nominatim', self::CONSULTAS_POR_MINUTO)) {
            return null;
        }

        RateLimiter::hit('nominatim', 60);

        try {
            $respuesta = Http::baseUrl(Config::string('services.nominatim.url'))
                ->withUserAgent(Config::string('services.nominatim.user_agent'))
                ->acceptJson()
                ->timeout(6)
                ->get('reverse', [
                    'format'          => 'jsonv2',
                    'lat'             => $lat,
                    'lon'             => $lng,
                    'zoom'            => 18,
                    'addressdetails'  => 1,
                    'accept-language' => 'es',
                ]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $respuesta->successful()) {
            return null;
        }

        $partes = $respuesta->json('address');
        $direccion = [];

        foreach (is_array($partes) ? $partes : [] as $campo => $valor) {
            if (is_string($campo) && is_string($valor) && trim($valor) !== '') {
                $direccion[$campo] = trim($valor);
            }
        }

        Cache::put($clave, $direccion, now()->addDays(self::DIAS_GUARDADO));

        return $direccion;
    }

    /**
     * @param array<string, string> $direccion
     */
    private function lugarDe(array $direccion): Lugar
    {
        if (($direccion['country_code'] ?? '') !== 'hn') {
            return new Lugar(null, null, '', $this->puebloDe($direccion, conPais: true), enHonduras: false);
        }

        $departamento = $this->departamentoDe($direccion['state'] ?? '');
        $municipio = $departamento === null ? null : $this->municipioDe($departamento, $direccion);

        return new Lugar(
            departamentoId: $departamento?->id,
            municipioId: $municipio?->id,
            referencia: $this->referenciaDe($direccion, $municipio, $departamento),
            descripcion: $municipio?->etiqueta() ?? $this->puebloDe($direccion),
            enHonduras: true,
        );
    }

    private function departamentoDe(string $nombre): ?Departamento
    {
        $clave = self::sinPrefijo(Texto::clave($nombre), 'DEPARTAMENTO');

        if ($clave === '') {
            return null;
        }

        return Departamento::query()
            ->get()
            ->first(fn (Departamento $departamento): bool => Texto::clave($departamento->nombre) === $clave);
    }

    /**
     * El primer nombre de la dirección que es un municipio de ese
     * departamento ("Distrito Central", "San Andrés"…).
     *
     * @param array<string, string> $direccion
     */
    private function municipioDe(Departamento $departamento, array $direccion): ?Municipio
    {
        $municipios = $departamento->municipios()->get();

        foreach (self::CAMPOS_MUNICIPIO as $campo) {
            $clave = self::sinPrefijo(Texto::clave($direccion[$campo] ?? ''), 'MUNICIPIO');

            if ($clave === '') {
                continue;
            }

            $municipio = $municipios->first(fn (Municipio $municipio): bool => Texto::clave($municipio->nombre) === $clave);

            if ($municipio !== null) {
                return $municipio->setRelation('departamento', $departamento);
            }
        }

        return null;
    }

    /**
     * "AVENIDA REAL FACTORÍA, BARRIO MERCEDES, EL CARRIZAL": lo que está
     * dentro del municipio (el municipio y el departamento ya van en su
     * campo). Si no hay nada más fino, el pueblo y el departamento.
     *
     * @param array<string, string> $direccion
     */
    private function referenciaDe(array $direccion, ?Municipio $municipio, ?Departamento $departamento): string
    {
        $omitir = array_filter([
            $municipio === null ? null : Texto::clave($municipio->nombre),
            $departamento === null ? null : Texto::clave($departamento->nombre),
        ]);

        $partes = [];

        foreach (self::CAMPOS_REFERENCIA as $campo) {
            $valor = $direccion[$campo] ?? '';
            $clave = Texto::clave($valor);

            if ($clave === '' || in_array($clave, $omitir, true) || isset($partes[$clave])) {
                continue;
            }

            $partes[$clave] = $valor;
        }

        $texto = $partes === [] ? $this->puebloDe($direccion) : implode(', ', $partes);

        return mb_substr(Texto::mayusculas($texto), 0, 255);
    }

    /**
     * "Copán Ruinas, Copán" (con el país si no es Honduras).
     *
     * @param array<string, string> $direccion
     */
    private function puebloDe(array $direccion, bool $conPais = false): string
    {
        $pueblo = null;

        foreach (self::CAMPOS_PUEBLO as $campo) {
            if (isset($direccion[$campo])) {
                $pueblo = $direccion[$campo];

                break;
            }
        }

        return implode(', ', array_filter([$pueblo, $direccion['state'] ?? null, $conPais ? ($direccion['country'] ?? null) : null]));
    }

    /**
     * "MUNICIPIO DEL DISTRITO CENTRAL" → "DISTRITO CENTRAL".
     */
    private static function sinPrefijo(string $clave, string $palabra): string
    {
        return (string) preg_replace('/^'.$palabra.'( DEL| DE)? /', '', $clave);
    }
}

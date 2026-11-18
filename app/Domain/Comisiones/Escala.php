<?php

declare(strict_types=1);

namespace App\Domain\Comisiones;

/**
 * Escala de comisión de un grupo: desde cuánto se vende (sin ISV, en el
 * periodo) y qué % se paga. Ej. LIMPIEZA:
 *
 *   desde L 0       → 0.5 %
 *   desde L 80,000  → 1 %
 *   desde L 100,000 → 2 %
 *
 * Se paga el % del escalón que alcanzó sobre TODA la venta del grupo:
 * L 105,000 → 2 % de 105,000 = L 2,100. La meta es el escalón más alto.
 *
 * Se guarda en JSON (comision_grupos.escalones, comision_metas.escalones)
 * con el porcentaje en % (2 = 2 %), ordenado de menor a mayor.
 */
final class Escala
{
    public const MAXIMO_PORCENTAJE = 100.0;

    /**
     * @param list<array{desde: float, porcentaje: float}> $escalones ordenados por "desde"
     */
    private function __construct(public readonly array $escalones) {}

    /**
     * De lo guardado o de lo que viene del formulario. Lo que no se entiende
     * se ignora; dos escalones desde el mismo monto: queda el último.
     */
    public static function de(mixed $escalones): self
    {
        if (is_string($escalones)) {
            $escalones = json_decode($escalones, true);
        }

        $porDesde = [];

        foreach (is_array($escalones) ? $escalones : [] as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $desde = self::numero($fila['desde'] ?? null);
            $porcentaje = self::numero($fila['porcentaje'] ?? null);

            if ($desde === null || $porcentaje === null || $desde < 0 || $porcentaje < 0) {
                continue;
            }

            $desde = round($desde, 2);
            $porDesde[(string) $desde] = ['desde' => $desde, 'porcentaje' => round(min($porcentaje, self::MAXIMO_PORCENTAJE), 4)];
        }

        $lista = array_values($porDesde);
        usort($lista, fn (array $a, array $b): int => $a['desde'] <=> $b['desde']);

        return new self($lista);
    }

    /**
     * Lo que está mal en las filas del formulario (null si está bien).
     */
    public static function error(mixed $filas): ?string
    {
        $vistos = [];
        $hay = false;

        foreach (is_array($filas) ? $filas : [] as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $desde = self::numero($fila['desde'] ?? null);
            $porcentaje = self::numero($fila['porcentaje'] ?? null);

            if ($desde === null || $porcentaje === null) {
                return 'Cada escalón necesita "desde" y el %.';
            }

            if ($desde < 0) {
                return 'El "desde" no puede ser negativo.';
            }

            if ($porcentaje < 0 || $porcentaje > self::MAXIMO_PORCENTAJE) {
                return 'El % va de 0 a 100.';
            }

            $clave = (string) round($desde, 2);

            if (isset($vistos[$clave])) {
                return 'Hay dos escalones desde '.lempiras($desde, 0).'.';
            }

            $vistos[$clave] = true;
            $hay = true;
        }

        return $hay ? null : 'Agregue al menos un escalón.';
    }

    public function vacia(): bool
    {
        return $this->escalones === [];
    }

    /**
     * El escalón que alcanzó con esa venta (null: no llegó ni al primero).
     *
     * @return array{desde: float, porcentaje: float}|null
     */
    public function escalon(float $venta): ?array
    {
        $alcanzado = null;

        foreach ($this->escalones as $escalon) {
            if ($venta + 0.000001 >= $escalon['desde']) {
                $alcanzado = $escalon;
            }
        }

        return $alcanzado;
    }

    /**
     * El % que le toca (2.0 = 2 %).
     */
    public function porcentaje(float $venta): float
    {
        return $this->escalon($venta)['porcentaje'] ?? 0.0;
    }

    /**
     * Lo que gana: el % del escalón sobre toda la venta (nada si quedó en cero o en negativo).
     */
    public function comision(float $venta): float
    {
        return $venta > 0 ? round($venta * $this->porcentaje($venta) / 100, 2) : 0.0;
    }

    /**
     * El escalón que sigue (para "le faltan L 15,000 para el 2 %").
     *
     * @return array{desde: float, porcentaje: float}|null
     */
    public function siguiente(float $venta): ?array
    {
        foreach ($this->escalones as $escalon) {
            if ($escalon['desde'] > $venta + 0.000001) {
                return $escalon;
            }
        }

        return null;
    }

    /**
     * La meta: el escalón más alto (null si la escala es un solo % desde 0).
     */
    public function meta(): ?float
    {
        $ultimo = $this->escalones === [] ? null : $this->escalones[count($this->escalones) - 1];

        return $ultimo !== null && $ultimo['desde'] > 0 ? $ultimo['desde'] : null;
    }

    /**
     * "0.5% · 1% desde L. 80,000 · 2% desde L. 100,000".
     */
    public function texto(): string
    {
        if ($this->escalones === []) {
            return 'Sin escala';
        }

        return implode(' · ', array_map(
            fn (array $e): string => self::porciento($e['porcentaje']).($e['desde'] > 0 ? ' desde '.lempiras($e['desde'], 0) : ''),
            $this->escalones,
        ));
    }

    /**
     * Para guardar en JSON y para llenar el repetidor del formulario.
     *
     * @return list<array{desde: float, porcentaje: float}>
     */
    public function paraGuardar(): array
    {
        return $this->escalones;
    }

    public function igualA(self $otra): bool
    {
        return $this->escalones === $otra->escalones;
    }

    /**
     * 2 → "2%", 0.5 → "0.5%", 1.25 → "1.25%".
     */
    public static function porciento(float $porcentaje): string
    {
        return rtrim(rtrim(number_format($porcentaje, 2, '.', ','), '0'), '.').'%';
    }

    private static function numero(mixed $valor): ?float
    {
        if (is_string($valor)) {
            $valor = str_replace([',', ' ', 'L.', 'L', '%'], '', $valor);
        }

        return is_numeric($valor) ? (float) $valor : null;
    }
}

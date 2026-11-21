<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Bodega;
use App\Models\User;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Http\Request;

/**
 * En qué papel sale la factura: cada bodega escoge el de su impresora
 * (Bodegas → Impresora de facturas). La hoja que se abre en otra pestaña
 * deja cambiarlo en el momento (?hoja=carta).
 */
enum FormatoFactura: string implements HasDescription, HasLabel
{
    case Continuo = 'continuo';
    case Carta = 'carta';

    public function getLabel(): string
    {
        return match ($this) {
            self::Continuo => 'Papel continuo media carta (Epson LX-350)',
            self::Carta    => 'Carta (impresora normal)',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Continuo => 'Matriciales como la LX-350, en papel continuo de 8.5 × 5.5 pulgadas: una factura por media hoja.',
            self::Carta    => 'Láser o de tinta, en hoja carta: una factura por hoja, con espacio para muchos productos.',
        };
    }

    /**
     * Corto, para la barra de la hoja.
     */
    public function corto(): string
    {
        return match ($this) {
            self::Continuo => 'Media carta (LX-350)',
            self::Carta    => 'Carta',
        };
    }

    /**
     * Para el botón de la impresora en el modal de Imprimir.
     */
    public function impresora(): string
    {
        return match ($this) {
            self::Continuo => 'Epson LX-350 · media carta',
            self::Carta    => 'Impresora normal · carta',
        };
    }

    /**
     * El tamaño de la hoja para @page.
     */
    public function pagina(): string
    {
        return match ($this) {
            self::Continuo => '8.5in 5.5in',
            self::Carta    => '8.5in 11in',
        };
    }

    /**
     * El que se escogió en la hoja (?hoja=) o, si no, el de la bodega.
     */
    public static function para(mixed $escogido, ?Bodega $bodega): self
    {
        return (is_string($escogido) ? self::tryFrom($escogido) : null)
            ?? $bodega->formato_factura
            ?? self::Continuo;
    }

    /**
     * El de la bodega del usuario (para imprimir varias de un solo); sin
     * bodega (administración), media carta.
     */
    public static function delUsuario(?User $usuario): self
    {
        return $usuario->bodega->formato_factura ?? self::Continuo;
    }

    /**
     * La misma hoja en cada papel, para los botones de la barra. La firma
     * del enlace no cuenta ?hoja=, así que el original lo sigue siendo.
     *
     * @return array<string, string>
     */
    public static function enlaces(Request $request): array
    {
        $enlaces = [];

        foreach (self::cases() as $formato) {
            $enlaces[$formato->value] = $request->fullUrlWithQuery(['hoja' => $formato->value]);
        }

        return $enlaces;
    }
}

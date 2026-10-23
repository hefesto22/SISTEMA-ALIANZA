<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Datos del obligado tributario que van impresos en la factura
 * (Art. 10, Acuerdo 481-2017): razón social, nombre comercial, RTN,
 * dirección de la casa matriz, teléfono y correo.
 *
 * Una sola fila: DatosFiscales::actual(). Se editan en Administración > CAI.
 *
 * @property int $id
 * @property string|null $razon_social
 * @property string|null $nombre_comercial
 * @property string|null $rtn
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $correo
 * @property string|null $leyenda
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DatosFiscales extends Model
{
    use GuardaEnMayusculas;

    protected $table = 'datos_fiscales';

    /** @var list<string> */
    protected $fillable = [
        'razon_social',
        'nombre_comercial',
        'rtn',
        'direccion',
        'telefono',
        'correo',
        'leyenda',
    ];

    public static function actual(): self
    {
        return self::query()->orderBy('id')->firstOrCreate([]);
    }

    /**
     * Sin razón social, RTN y dirección no se puede emitir una factura.
     */
    public function estanCompletos(): bool
    {
        return filled($this->razon_social) && filled($this->rtn) && filled($this->direccion);
    }

    /**
     * El nombre grande de la factura: el comercial si hay, si no la razón social.
     */
    public function nombreParaMostrar(): string
    {
        return (string) ($this->nombre_comercial ?: $this->razon_social ?: config('app.name'));
    }

    /**
     * @return list<string>
     */
    protected function camposEnMayusculas(): array
    {
        return ['razon_social', 'nombre_comercial', 'direccion', 'leyenda'];
    }
}

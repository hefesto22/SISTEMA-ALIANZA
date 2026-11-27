<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use Carbon\CarbonInterface;
use Database\Factories\PromocionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Promoción "si compra esto, aquello sale gratis / a mitad / con N% de descuento".
 *
 * La condición puede ser uno o varios productos, cada uno con su cantidad
 * en una de sus presentaciones: "2 cajas de 12 de BUTTER TOFFEE + 1 caja de
 * 24 de NUTRINE → 1 unidad de BABY NUTRINE gratis". Cuenta la presentación
 * EXACTA, como las arma el proveedor: "2 CJ18" solo la cumplen 2 CJ18 (una
 * CJ24 o 36 sueltas no).
 * Si el regalo es uno de los productos de la condición queda como
 * "compre 2 y lleve 1 más gratis".
 *
 * Gratis (100 %) es una bonificación: el regalo se agrega solo al pedido,
 * en su propia línea (RecalcularPedido). A mitad o con otro % se descuenta
 * de lo que ya viene en el pedido de esa presentación.
 *
 * El código (PROMO-0001) se genera solo. Solo cuenta en los pedidos
 * mientras esté activa y dentro de sus fechas; el cálculo vive en
 * App\Domain\Promociones\MotorPromociones.
 *
 * Bodegas: vale en las que tenga marcadas; sin ninguna, en todas.
 * Administración las arma para una o varias; el encargado, solo para la
 * suya (y solo cambia las que son únicamente de su bodega).
 *
 * Plan del proveedor (PlanPromocion): cada línea autorizada del plan es una
 * promoción con plan_id, que se arma y se cambia desde el plan. Cuenta
 * UNIDADES (compra_unidades) de cada producto aparte: "24+1" se cumple con
 * 24 unidades de un mismo producto, en cualquier presentación (con
 * se_mezclan, de cualquiera de sus productos juntos), y se regala el
 * producto de la hoja (producto_beneficio_id) o, con regala_el_mismo, de
 * ese mismo producto. Con tope_unidades (de toda
 * la línea), no se regala más de lo autorizado: lo que queda lo dice
 * App\Domain\Promociones\UsoDePromociones.
 *
 * @property int $id
 * @property int|null $plan_id
 * @property int|null $compra_unidades N de "N+M" contando unidades de cada producto de la condición (null = presentación exacta)
 * @property bool $se_mezclan en las que cuentan unidades: sus productos se juntan para completar N
 * @property bool $regala_el_mismo en las que cuentan unidades: cada producto regala de sí mismo (si no, el producto del beneficio)
 * @property int|null $tope_unidades unidades de regalo autorizadas (null = sin tope)
 * @property float|null $porcentaje_autorizado
 * @property string|null $codigo
 * @property string $nombre
 * @property string|null $descripcion
 * @property int $producto_beneficio_id
 * @property int $cantidad_beneficio
 * @property int $beneficio_presentacion_id
 * @property float $descuento_porcentaje
 * @property bool $activa
 * @property Carbon|null $vigente_desde
 * @property Carbon|null $vigente_hasta
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PromocionCondicion> $condiciones
 * @property-read Collection<int, Bodega> $bodegas
 * @property-read Producto $productoBeneficio
 * @property-read Presentacion $beneficioPresentacion
 * @property-read User|null $creador
 * @property-read PlanPromocion|null $plan
 */
class Promocion extends Model
{
    use GuardaEnMayusculas;

    /** @use HasFactory<PromocionFactory> */
    use HasFactory;

    /** Lo que el motor de promociones necesita cargado. */
    public const PARA_CALCULAR = ['condiciones.presentacion.producto', 'condiciones.presentacion.unidadMedida', 'beneficioPresentacion.unidadMedida', 'productoBeneficio'];

    protected $table = 'promociones';

    /** @var list<string> */
    protected $fillable = [
        'plan_id',
        'nombre',
        'descripcion',
        'compra_unidades',
        'se_mezclan',
        'regala_el_mismo',
        'tope_unidades',
        'porcentaje_autorizado',
        'producto_beneficio_id',
        'cantidad_beneficio',
        'beneficio_presentacion_id',
        'descuento_porcentaje',
        'activa',
        'vigente_desde',
        'vigente_hasta',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'cantidad_beneficio'   => 1,
        'descuento_porcentaje' => 100,
        'activa'               => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'producto_beneficio_id'     => 'integer',
            'cantidad_beneficio'        => 'integer',
            'beneficio_presentacion_id' => 'integer',
            'descuento_porcentaje'      => 'float',
            'plan_id'                   => 'integer',
            'compra_unidades'           => 'integer',
            'se_mezclan'                => 'boolean',
            'regala_el_mismo'           => 'boolean',
            'tope_unidades'             => 'integer',
            'porcentaje_autorizado'     => 'float',
            'activa'                    => 'boolean',
            'vigente_desde'             => 'date',
            'vigente_hasta'             => 'date',
        ];
    }

    /**
     * Código propio de la promoción: PROMO-0001. Sin presentación del
     * regalo, la base del producto.
     */
    protected static function booted(): void
    {
        static::saving(function (Promocion $promocion): void {
            if (empty($promocion->beneficio_presentacion_id)) {
                $promocion->beneficio_presentacion_id = Presentacion::query()->where('producto_id', $promocion->producto_beneficio_id)->where('es_base', true)->valueOrFail('id');
            }
        });

        static::created(function (Promocion $promocion): void {
            if ($promocion->codigo !== null) {
                return;
            }

            $promocion->codigo = 'PROMO-'.str_pad((string) $promocion->id, 4, '0', STR_PAD_LEFT);
            $promocion->saveQuietly();
        });
    }

    /**
     * Lo que el cliente tiene que comprar (uno o varios productos).
     *
     * @return HasMany<PromocionCondicion, $this>
     */
    public function condiciones(): HasMany
    {
        return $this->hasMany(PromocionCondicion::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Producto, $this>
     */
    public function productoBeneficio(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_beneficio_id');
    }

    /**
     * En qué presentación es el regalo (UN, CJ12…).
     *
     * @return BelongsTo<Presentacion, $this>
     */
    public function beneficioPresentacion(): BelongsTo
    {
        return $this->belongsTo(Presentacion::class, 'beneficio_presentacion_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * El plan del proveedor del que sale (null = promoción propia).
     *
     * @return BelongsTo<PlanPromocion, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanPromocion::class, 'plan_id');
    }

    /**
     * Bodegas donde vale; sin ninguna, vale en todas.
     *
     * @return BelongsToMany<Bodega, $this>
     */
    public function bodegas(): BelongsToMany
    {
        return $this->belongsToMany(Bodega::class, 'promocion_bodega');
    }

    /**
     * Líneas de pedido donde se aplicó.
     *
     * @return HasMany<PedidoItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PedidoItem::class);
    }

    /**
     * Activas y dentro de sus fechas (sin fecha = sin límite).
     *
     * @param Builder<Promocion> $query
     *
     * @return Builder<Promocion>
     */
    public function scopeVigentes(Builder $query, ?CarbonInterface $fecha = null): Builder
    {
        $dia = ($fecha ?? today())->toDateString();

        return $query
            ->where('activa', true)
            ->where(function (Builder $q) use ($dia): void {
                $q->whereNull('vigente_desde')->orWhere('vigente_desde', '<=', $dia);
            })
            ->where(function (Builder $q) use ($dia): void {
                $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $dia);
            });
    }

    /**
     * Las que valen en esa bodega: las de todas las bodegas y las que la
     * tienen marcada. Sin bodega (administración sin escoger), solo las de
     * todas.
     *
     * @param Builder<Promocion> $query
     *
     * @return Builder<Promocion>
     */
    public function scopeParaBodega(Builder $query, ?int $bodegaId): Builder
    {
        return $query->where(function (Builder $q) use ($bodegaId): void {
            $q->whereDoesntHave('bodegas');

            if ($bodegaId !== null) {
                $q->orWhereHas('bodegas', fn (Builder $bodega): Builder => $bodega->where('bodegas.id', $bodegaId));
            }
        });
    }

    /**
     * Administración ve todas; los demás, las que valen en su bodega.
     *
     * @param Builder<Promocion> $query
     *
     * @return Builder<Promocion>
     */
    public function scopeVisiblesPara(Builder $query, User $user): Builder
    {
        return $user->veTodasLasBodegas() ? $query : $this->scopeParaBodega($query, $user->bodega_id);
    }

    public function esVisiblePara(User $user): bool
    {
        if ($user->veTodasLasBodegas() || $this->bodegas->isEmpty()) {
            return true;
        }

        return $user->bodega_id !== null && $this->bodegas->contains('id', $user->bodega_id);
    }

    /**
     * Administración cambia todas; el encargado, solo las que son únicamente
     * de su bodega (las de varias bodegas o de todas son de administración).
     */
    public function esEditablePor(User $user): bool
    {
        if ($user->veTodasLasBodegas()) {
            return true;
        }

        return $user->bodega_id !== null
            && $this->bodegas->count() === 1
            && $this->bodegas->first()?->id === $user->bodega_id;
    }

    /**
     * "Todas las bodegas" o "SANTA ROSA DE COPÁN, OCOTEPEQUE".
     */
    public function bodegasTexto(): string
    {
        return $this->bodegas->isEmpty() ? 'Todas las bodegas' : $this->bodegas->pluck('nombre')->sort()->implode(', ');
    }

    public function estaVigente(?CarbonInterface $fecha = null): bool
    {
        return $this->estado($fecha) === 'Vigente';
    }

    /**
     * Vigente, Programada (empieza después), Vencida o Inactiva.
     */
    public function estado(?CarbonInterface $fecha = null): string
    {
        $dia = ($fecha ?? today())->toDateString();

        return match (true) {
            ! $this->activa                                                              => 'Inactiva',
            $this->vigente_desde !== null && $this->vigente_desde->toDateString() > $dia => 'Programada',
            $this->vigente_hasta !== null && $this->vigente_hasta->toDateString() < $dia => 'Vencida',
            default                                                                      => 'Vigente',
        };
    }

    public function colorEstado(): string
    {
        return match ($this->estado()) {
            'Vigente'    => 'success',
            'Programada' => 'info',
            'Vencida'    => 'danger',
            default      => 'gray',
        };
    }

    /**
     * gratis (100%), mitad (50%) u otro porcentaje.
     */
    public function tipo(): string
    {
        return self::tipoPara($this->descuento_porcentaje);
    }

    public static function tipoPara(float $porcentaje): string
    {
        return match (true) {
            abs($porcentaje - 100) < 0.001 => 'gratis',
            abs($porcentaje - 50) < 0.001  => 'mitad',
            default                        => 'otro',
        };
    }

    /**
     * Gratis: el regalo es una bonificación que se agrega sola al pedido.
     */
    public function esBonificacion(): bool
    {
        return $this->descuento_porcentaje >= 100 - 0.001;
    }

    /**
     * "24+1": cuenta unidades de cada uno de sus productos (o de todos
     * juntos, si se mezclan).
     */
    public function cuentaUnidades(): bool
    {
        return $this->compra_unidades !== null && $this->compra_unidades > 0;
    }

    /**
     * Tiene un tope de regalos (lo que autorizó el proveedor).
     */
    public function tieneTope(): bool
    {
        return $this->tope_unidades !== null && $this->esBonificacion();
    }

    /**
     * Línea del plan que este mes no tiene unidades autorizadas (tope 0): no
     * se ofrece en la preventa ni da regalo.
     */
    public function sinAutorizadas(): bool
    {
        return $this->tieneTope() && (int) $this->tope_unidades === 0;
    }

    /**
     * "24+1" (o "8+1"). Vacío si no cuenta unidades.
     */
    public function mecanica(): string
    {
        return $this->cuentaUnidades() ? "{$this->compra_unidades}+{$this->cantidad_beneficio}" : '';
    }

    /**
     * El regalo es uno de los productos que hay que comprar ("compre 2 y lleve 1 más").
     */
    public function regaloEnCondicion(): bool
    {
        return $this->condiciones->contains(fn (PromocionCondicion $c): bool => $c->producto_id === $this->producto_beneficio_id);
    }

    /**
     * "gratis", "a mitad de precio" o "con 30% de descuento".
     */
    public function beneficioTexto(): string
    {
        return match ($this->tipo()) {
            'gratis' => 'gratis',
            'mitad'  => 'a mitad de precio',
            default  => 'con '.self::porcentajeTexto($this->descuento_porcentaje).' de descuento',
        };
    }

    /**
     * Lo que se le explica al cliente, por ejemplo:
     * "Compra 2 cajas de 12 de BUTTER TOFFEE + 1 caja de 24 de NUTRINE → 1 unidad de BABY NUTRINE gratis".
     */
    public function resumen(): string
    {
        if ($this->cuentaUnidades()) {
            return $this->resumenEnUnidades();
        }

        $compra = $this->condiciones
            ->map(fn (PromocionCondicion $c): string => $c->texto())
            ->implode(' + ');

        $beneficio = $this->beneficioPresentacion->enPalabras($this->cantidad_beneficio);

        if ($this->regaloEnCondicion()) {
            if ($this->condiciones->count() === 1) {
                return "Compra {$compra} y lleva {$beneficio} más {$this->beneficioTexto()}";
            }

            return "Compra {$compra} → {$beneficio} más de {$this->productoBeneficio->descripcion} {$this->beneficioTexto()}";
        }

        return "Compra {$compra} → {$beneficio} de {$this->productoBeneficio->descripcion} {$this->beneficioTexto()}";
    }

    /**
     * "Por cada 48 unidades de UNOX lleva 2 unidades más gratis"; "Por cada
     * 12 unidades de RAPTOR 600 lleva 1 unidad de RAPTOR 300 gratis"; con
     * varios productos: "Por cada 24 unidades de un mismo producto (NECTAR
     * PERA o NECTAR PIÑA) lleva 1 unidad de NECTAR MANZANA gratis; no se
     * mezclan" o, si se mezclan, "Por cada 12 unidades de TERSO DELICADA o
     * TERSO INTENSAS (se pueden mezclar) lleva 1 unidad de TERSO DELICADA
     * gratis".
     */
    private function resumenEnUnidades(): string
    {
        $productos = $this->productosQueCompra();

        if (count($productos) <= 1) {
            return "Por cada {$this->unidadesTexto()} de ".($productos[0] ?? '')." lleva {$this->llevaEnUnidades()}";
        }

        $cuales = implode(', ', array_slice($productos, 0, -1)).' o '.$productos[count($productos) - 1];

        if ($this->se_mezclan) {
            return "Por cada {$this->unidadesTexto()} de {$cuales} (se pueden mezclar) lleva {$this->llevaEnUnidades()}";
        }

        return "Por cada {$this->unidadesTexto()} de un mismo producto ({$cuales}) lleva {$this->llevaEnUnidades()}; no se mezclan";
    }

    /**
     * La promoción en partes, para la tarjeta de la preventa en el teléfono:
     * la mecánica ("20+1"; vacía si no cuenta unidades), el título (las del
     * plan no llevan: la mecánica, el regalo y los productos lo dicen todo),
     * qué compra, de qué productos y qué lleva.
     *
     * @return array{mecanica: string, titulo: string, compra: string, productos: list<string>, lleva: string}
     */
    public function enPartes(): array
    {
        $beneficio = $this->beneficioPresentacion->enPalabras($this->cantidad_beneficio);

        if ($this->cuentaUnidades()) {
            $productos = $this->productosQueCompra();
            $compra = match (true) {
                count($productos) <= 1 => "Por cada {$this->unidadesTexto()}",
                $this->se_mezclan      => "Por cada {$this->unidadesTexto()}, juntando cualquiera de estos (se pueden mezclar)",
                default                => "Por cada {$this->unidadesTexto()} de un mismo producto (no se mezclan)",
            };

            return [
                'mecanica'  => $this->mecanica(),
                'titulo'    => '',
                'compra'    => $compra,
                'productos' => $productos,
                'lleva'     => 'Lleva '.$this->llevaEnUnidades(),
            ];
        }

        $soloElRegalo = $this->regaloEnCondicion() && $this->condiciones->count() === 1;

        return [
            'mecanica'  => '',
            'titulo'    => $this->nombre,
            'compra'    => 'Compra',
            'productos' => array_values($this->condiciones->map(fn (PromocionCondicion $c): string => $c->texto())->all()),
            'lleva'     => $soloElRegalo
                ? "Lleva {$beneficio} más {$this->beneficioTexto()}"
                : "Lleva {$beneficio} de {$this->productoBeneficio->descripcion} {$this->beneficioTexto()}",
        ];
    }

    /**
     * Lo que lleva en las que cuentan unidades: "1 unidad de ese mismo
     * producto gratis", "2 unidades más gratis" (un solo producto que regala
     * de sí mismo) o "1 unidad de NECTAR MANZANA gratis".
     */
    private function llevaEnUnidades(): string
    {
        $varios = count($this->productosQueCompra()) > 1;
        $beneficio = $this->beneficioPresentacion->enPalabras($this->cantidad_beneficio);

        return match (true) {
            $this->regala_el_mismo && $varios                                   => "{$beneficio} de ese mismo producto {$this->beneficioTexto()}",
            ! $varios && ($this->regala_el_mismo || $this->regaloEnCondicion()) => "{$beneficio} más {$this->beneficioTexto()}",
            default                                                             => "{$beneficio} de {$this->productoBeneficio->descripcion} {$this->beneficioTexto()}",
        };
    }

    /**
     * Los productos que compra, sin repetir, en el orden de la promoción.
     *
     * @return list<string>
     */
    private function productosQueCompra(): array
    {
        return array_values($this->condiciones->map(fn (PromocionCondicion $c): string => $c->producto->descripcion)->unique()->all());
    }

    /**
     * "24 unidades" (en la unidad de sus productos).
     */
    private function unidadesTexto(): string
    {
        $base = $this->condiciones->first()?->presentacion;

        return $base instanceof Presentacion ? $base->enPalabras((int) $this->compra_unidades) : $this->compra_unidades.' unidades';
    }

    public static function porcentajeTexto(float $porcentaje): string
    {
        return rtrim(rtrim(number_format($porcentaje, 2), '0'), '.').'%';
    }

    /**
     * @return list<string>
     */
    protected function camposEnMayusculas(): array
    {
        return ['nombre', 'descripcion'];
    }
}

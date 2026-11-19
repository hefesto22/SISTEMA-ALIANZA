<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Comisiones\CerrarPeriodoComision;
use App\Domain\Comisiones\GuardarEscalaBodega;
use App\Domain\Comisiones\GuardarMetaComision;
use App\Domain\Comisiones\PeriodoComision;
use App\Domain\Devoluciones\RegistrarDevolucion;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Facturacion\ImprimirFacturas;
use App\Domain\Inventario\AjustarExistencia;
use App\Domain\Inventario\RegistrarCompra;
use App\Domain\Pedidos\AnularPedido;
use App\Domain\Pedidos\CorregirPedido;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Domain\Precios\CalculadoraPrecio;
use App\Enums\EstadoPedido;
use App\Enums\ModoImpresion;
use App\Enums\Rol;
use App\Enums\TipoDevolucion;
use App\Models\Bodega;
use App\Models\Cai;
use App\Models\Cliente;
use App\Models\ComisionEscalaBodega;
use App\Models\ComisionGrupo;
use App\Models\ComisionMeta;
use App\Models\Compra;
use App\Models\Configuracion;
use App\Models\DatosFiscales;
use App\Models\Devolucion;
use App\Models\Existencia;
use App\Models\Factura;
use App\Models\Municipio;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\Proveedor;
use App\Models\Ruta;
use App\Models\User;
use App\Support\Texto;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Operación de DEMOSTRACIÓN para ver reportes, KPIs y comisiones con datos
 * (php artisan demo:historial). Del día 1 de hace dos meses hasta hoy la
 * empresa trabaja como de verdad, con las mismas acciones del panel:
 *
 *  - Cartera: el vendedor sin ruta recibe una con municipios de la zona de
 *    su bodega, y cada uno tiene clientes (pulperías, súper y mayoristas)
 *    para que la cobertura sea creíble.
 *  - Inventario inicial (ajuste "INVENTARIO INICIAL") y compras de cada
 *    proveedor una vez por semana, en cajas, que reponen lo que se vende.
 *    El proveedor más grande sube sus costos 4 % a mitad del segundo mes y
 *    el segundo 2.5 % al empezar el tercero.
 *  - Pedidos de lunes a sábado (el 15 de septiembre no se trabaja): el
 *    vendedor los levanta en el día, bodega los valida en la tarde (abre
 *    cajas si faltan sueltas) e imprime las facturas. Los de hoy quedan
 *    pendientes.
 *  - Algunos pedidos anulados, corregidos y devoluciones (cambios y créditos).
 *  - Dos metas propias, una escala de bodega, dos promociones desde el
 *    segundo mes y el cierre de cada periodo de comisiones que ya terminó.
 *
 * Venta de la empresa sin ISV: VENTA_MES el primer mes, 6 % más el segundo
 * y 10 % más el tercero. Todo sale de un generador con semilla fija: con la
 * misma base da lo mismo.
 */
final class HistorialDemo
{
    /** Venta de la empresa sin ISV en el primer mes. */
    public const VENTA_MES = 5_600_000.0;

    public const NOTA_INICIAL = 'INVENTARIO INICIAL';

    public const PROMOCION = 'PROMOCIÓN DE DEMOSTRACIÓN';

    /** Cuánto vende la empresa cada mes contra el primero. */
    private const CRECIMIENTO = [1.0, 1.06, 1.10];

    /** Días que no se trabaja (mes-día). */
    private const FERIADOS = ['09-15'];

    /**
     * Ticket (mediana sin ISV), probabilidad de pedir por caja, cuántas cajas
     * como máximo y cuántas líneas como máximo, por tipo de cliente.
     *
     * @var array<string, array{ticket: float, caja: float, cajas: int, lineas: int}>
     */
    private const TIPOS = [
        'pulperia'  => ['ticket' => 2_300.0, 'caja' => 0.35, 'cajas' => 2, 'lineas' => 8],
        'super'     => ['ticket' => 8_500.0, 'caja' => 0.70, 'cajas' => 4, 'lineas' => 14],
        'mayorista' => ['ticket' => 24_000.0, 'caja' => 0.90, 'cajas' => 10, 'lineas' => 20],
        'mostrador' => ['ticket' => 900.0, 'caja' => 0.10, 'cajas' => 1, 'lineas' => 4],
        'detalle'   => ['ticket' => 1_600.0, 'caja' => 0.15, 'cajas' => 1, 'lineas' => 6],
    ];

    /** Tipo de negocio => tipo de cliente. */
    private const NEGOCIOS = [
        'PULPERÍA'      => 'pulperia',
        'TIENDA'        => 'pulperia',
        'ABARROTERÍA'   => 'pulperia',
        'BODEGUITA'     => 'pulperia',
        'VARIEDADES'    => 'pulperia',
        'MERCADITO'     => 'pulperia',
        'MINI SÚPER'    => 'super',
        'SUPERMERCADO'  => 'super',
        'SURTIDORA'     => 'super',
        'DESPENSA'      => 'super',
        'DISTRIBUIDORA' => 'mayorista',
        'MAYORISTA'     => 'mayorista',
    ];

    private const NOMBRES = [
        'LA BENDICIÓN', 'DOÑA ROSA', 'EL BUEN PASTOR', 'SAN ANTONIO', 'LOS HERMANOS', 'DON BETO', 'LA FAVORITA',
        'JIREH', 'EBEN-EZER', 'BETESDA', 'LA ECONÓMICA', 'MARGARITA', 'DOÑA TINA', 'EL PROGRESO', 'SANTA FE',
        'LA PROVIDENCIA', 'EL OLIVO', 'MI ESPERANZA', 'LAS GEMELAS', 'DON MARIO', 'DOÑA CARMEN', 'EL ROBLE',
        'SAN MIGUEL', 'EL MANANTIAL', 'NUEVA ESPERANZA', 'EL SHADDAI', 'MANÁ', 'LA HONRADEZ', 'EL REY', 'LUPITA',
        'KARLA', 'DANIELA', 'LOS GARCÍA', 'LOS PINEDA', 'HERMANOS PAZ', 'DON CHUS', 'DOÑA JUANA', 'EL GUANACASTE',
        'EL PINO', 'LA CUMBRE', 'EL MIRADOR', 'EL AMIGO', 'LA AMISTAD', 'SAN RAFAEL', 'SANTA LUCÍA', 'LA MERCED',
        'EL CALVARIO', 'LOS ALPES', 'EL QUETZAL', 'LA PALMA', 'DOÑA MERY', 'DON TONO', 'EL CHELE', 'LA BODEGONA',
        'EL TRIUNFO', 'LA VICTORIA', 'EL MILAGRO', 'BELÉN', 'GETSEMANÍ', 'SION', 'EL EDÉN', 'LA ROCA', 'EL ARCA',
        'LOS LIRIOS', 'LAS ROSAS', 'EL GIRASOL', 'LA ORQUÍDEA', 'DOÑA LILIAN', 'DON RAMÓN', 'LOS CUATES',
    ];

    private const DIRECCIONES = [
        'BARRIO EL CENTRO, FRENTE AL PARQUE CENTRAL',
        'BARRIO MERCEDES, MEDIA CUADRA AL SUR DE LA IGLESIA',
        'COLONIA LAS FLORES, CALLE PRINCIPAL',
        'BARRIO SAN MARTÍN, CONTIGUO A LA ESCUELA',
        'SALIDA PRINCIPAL, FRENTE A LA GASOLINERA',
        'MERCADO MUNICIPAL, LOCAL 14',
        'BARRIO EL CALVARIO, ESQUINA OPUESTA A LA FARMACIA',
        'COLONIA SANTA TERESA, 2 CUADRAS AL NORTE DEL KÍNDER',
        'ALDEA EL ROSARIO, ENTRADA PRINCIPAL',
        'BARRIO LAS PALMAS, A LA PAR DEL CENTRO DE SALUD',
        'COLONIA EL BOSQUE, PASAJE 3',
        'BARRIO EL CARMEN, FRENTE A LA CANCHA',
        'CARRETERA PRINCIPAL, KM 2, A LA PAR DEL TALLER',
        'BARRIO LA CRUZ, FRENTE AL INSTITUTO',
    ];

    private const NOTAS = [
        'ENTREGAR ANTES DE LAS 10 AM',
        'LLAMAR AL LLEGAR',
        'EL CLIENTE PAGA AL RECIBIR',
        'ENTREGAR POR LA PUERTA DE ATRÁS',
    ];

    private const MOTIVOS_FUERA = [
        'CUBRIENDO LA RUTA DE OTRO VENDEDOR',
        'EL CLIENTE LLAMÓ A LA OFICINA',
    ];

    private const MOTIVOS_DANADO = [
        'VENÍA GOLPEADO',
        'EMPAQUE ROTO',
        'PRODUCTO VENCIDO',
        'SE DERRAMÓ EN EL CAMIÓN',
    ];

    private const MOTIVOS_BUENO = [
        'EL CLIENTE NO LO PIDIÓ',
        'SE LE LLEVÓ DE MÁS',
        'NO LO PUDO VENDER',
    ];

    private Randomizer $azar;

    private CarbonImmutable $desde;

    private CarbonImmutable $hasta;

    /** La hora real en que se corre: los pedidos de hoy llegan hasta aquí. */
    private CarbonImmutable $ahora;

    private ?User $admin = null;

    /** @var array<int, User> [bodega_id => encargado] */
    private array $encargados = [];

    /** @var array<int, array{user: User, bodega: int, perfil: string, meta: float, ticket: float, clientes: list<array{id: int, tipo: string}>, fuera: list<array{id: int, tipo: string}>}> */
    private array $vendedores = [];

    /** @var list<int> */
    private array $bodegas = [];

    /** @var array<int, array{id: int, proveedor: int, gravado: bool, un: int, precio: float, cj: int|null, contenido: int, precio_cj: float}> */
    private array $productos = [];

    /** @var array<int, list<int>> [proveedor_id => productos] */
    private array $porProveedor = [];

    /** @var list<int> proveedores, del que más productos tiene al que menos */
    private array $proveedores = [];

    /** @var list<int> productos, del que más se pide al que menos (con su peso en acumulado) */
    private array $ids = [];

    /** @var list<float> peso acumulado para escoger un producto */
    private array $acumulado = [];

    /** @var array<int, Presentacion> */
    private array $presentaciones = [];

    /** @var array<int, float> [presentacion_id => costo sin ISV al empezar] */
    private array $costoBase = [];

    /** @var array<int, array<int, int>> [bodega_id][presentacion_id] => existencia */
    private array $hay = [];

    /** @var array<int, array<int, int>> [bodega_id][presentacion_id] => en pedidos sin validar */
    private array $apartado = [];

    /** @var array<int, array<int, float>> [bodega_id][producto_id] => unidades que se venden al día */
    private array $demanda = [];

    /** @var array<int, int> [proveedor_id => último número de factura] */
    private array $facturaProveedor = [];

    /** @var array<string, list<int>> [fecha => pedidos que ese día traen devolución] */
    private array $porDevolver = [];

    /** @var list<int> pedidos impresos hoy (alguno se corrige en la noche) */
    private array $porCorregir = [];

    /** @var array<string, float> [Y-m => suma de los pesos de sus días] */
    private array $pesosMes = [];

    private ?PeriodoComision $periodo = null;

    /** @var array<int, array{caja: int, cajas: int}> [producto_id => la caja y cuántas pide su promoción] */
    private array $promos = [];

    private ?CarbonImmutable $promosDesde = null;

    /** El día de los pedidos que se arman (null al estimar la demanda). */
    private ?CarbonImmutable $hoy = null;

    public function __construct(
        private readonly AjustarExistencia $ajustar,
        private readonly RegistrarCompra $registrarCompra,
        private readonly RecalcularPedido $recalcular,
        private readonly ValidarDespacho $validar,
        private readonly ImprimirFacturas $imprimir,
        private readonly AnularPedido $anular,
        private readonly CorregirPedido $corregir,
        private readonly RegistrarDevolucion $devolucion,
        private readonly CerrarPeriodoComision $cerrar,
        private readonly GuardarMetaComision $guardarMeta,
        private readonly GuardarEscalaBodega $guardarEscala,
    ) {
        $this->azar = new Randomizer(new Mt19937(2026));
        $this->desde = $this->hasta = $this->ahora = CarbonImmutable::today();
    }

    /**
     * Genera la operación de $desde a $hasta (los dos incluidos). Necesita la
     * base sin pedidos, compras ni kárdex (LimpiarMovimientos).
     *
     * @param (callable(string): void)|null $avance recibe una línea por semana
     */
    public function generar(CarbonImmutable $desde, CarbonImmutable $hasta, float $ventaMes = self::VENTA_MES, ?callable $avance = null, int $semilla = 2026): void
    {
        $this->ahora = CarbonImmutable::now();
        $this->desde = $desde->startOfDay();
        $this->hasta = $hasta->startOfDay();
        $this->azar = new Randomizer(new Mt19937($semilla));

        try {
            $this->viajar($this->desde->subDays(3)->setTime(9, 0));
            DB::transaction(function () use ($ventaMes): void {
                $this->preparar($ventaMes);
            });

            $this->estimarDemanda($semilla + 1);

            $this->viajar($this->desde->setTime(6, 30));
            DB::transaction(function (): void {
                $this->inventarioInicial();
            });

            for ($dia = $this->desde; $dia->lte($this->hasta); $dia = $dia->addDay()) {
                DB::transaction(function () use ($dia): void {
                    $this->trabajar($dia);
                });

                if ($avance !== null && ($dia->isSaturday() || $dia->eq($this->hasta))) {
                    $avance($this->semana($dia));
                }
            }
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    /**
     * Cada mes: pedidos, facturas vigentes, venta sin y con ISV, compras con
     * ISV y devoluciones.
     *
     * @return list<array{mes: string, pedidos: int, facturas: int, venta: float, con_isv: float, compras: float, devoluciones: int}>
     */
    public static function resumen(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $filas = [];

        for ($mes = $desde->startOfMonth(); $mes->lte($hasta); $mes = $mes->addMonthNoOverflow()) {
            $inicio = $mes->startOfDay();
            $fin = $mes->endOfMonth()->endOfDay();
            $facturas = Factura::query()->whereNull('anulada_at')->whereBetween('fecha_emision', [$inicio, $fin]);

            $filas[] = [
                'mes'          => $mes->format('m/Y'),
                'pedidos'      => Pedido::query()->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])->count(),
                'facturas'     => (clone $facturas)->count(),
                'venta'        => round((float) (clone $facturas)->sum(DB::raw('importe_exento + importe_gravado')), 2),
                'con_isv'      => round((float) (clone $facturas)->sum('total'), 2),
                'compras'      => round((float) Compra::query()->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])->sum('total'), 2),
                'devoluciones' => Devolucion::query()->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])->count(),
            ];
        }

        return $filas;
    }

    private function preparar(float $ventaMes): void
    {
        $admin = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $roles): Builder => $roles->whereIn('name', [ShieldUtils::getSuperAdminName(), Rol::Administrador->value]))
            ->orderBy('id')
            ->first();

        if (! $admin instanceof User) {
            throw new ReglaNegocioException('Hace falta un usuario activo con rol super_admin o administrador (php artisan db:seed --class=AdminUserSeeder).');
        }

        $this->admin = $admin;

        if (! DatosFiscales::actual()->estanCompletos()) {
            throw new ReglaNegocioException('Faltan los datos fiscales: corra php artisan db:seed --class=FacturacionPruebaSeeder.');
        }

        foreach (User::query()->where('is_active', true)->whereNotNull('bodega_id')->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', Rol::Bodega->value))->orderBy('id')->get() as $encargado) {
            $this->encargados[(int) $encargado->bodega_id] ??= $encargado;
        }

        $this->vendedores($ventaMes);

        foreach ($this->bodegas as $bodegaId) {
            if (Cai::paraBodega($bodegaId) === null) {
                throw new ReglaNegocioException('Una bodega con vendedores no tiene CAI para facturar: corra php artisan db:seed --class=FacturacionPruebaSeeder.');
            }
        }

        $this->catalogo();
        $this->cartera();
        $this->metas();
        $this->promociones();
    }

    /**
     * Los vendedores activos con bodega y lo que vende cada uno al mes: el
     * mostrador y los de detalle menos; los demás entre 0.75 y 1.35 del
     * promedio. Entre todos, $ventaMes.
     */
    private function vendedores(float $ventaMes): void
    {
        $usuarios = User::query()
            ->where('is_active', true)
            ->whereNotNull('bodega_id')
            ->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', Rol::Vendedor->value))
            ->orderBy('id')
            ->get();

        if ($usuarios->isEmpty()) {
            throw new ReglaNegocioException('No hay vendedores activos con bodega: corra php artisan db:seed --class=PersonalSeeder.');
        }

        $filas = [];

        foreach ($usuarios as $usuario) {
            $nombre = mb_strtoupper((string) $usuario->name);
            $perfil = match (true) {
                str_contains($nombre, 'MOSTRADOR') => 'mostrador',
                str_contains($nombre, 'DETALLE')   => 'detalle',
                default                            => 'normal',
            };
            $peso = match ($perfil) {
                'mostrador' => 0.6,
                'detalle'   => 0.45,
                default     => 0.75 + 0.6 * $this->azar->nextFloat(),
            };
            $filas[] = [$usuario, $perfil, $peso];
        }

        $total = array_sum(array_column($filas, 2));

        foreach ($filas as [$usuario, $perfil, $peso]) {
            $this->vendedores[] = [
                'user'     => $usuario,
                'bodega'   => (int) $usuario->bodega_id,
                'perfil'   => $perfil,
                'meta'     => $ventaMes * $peso / $total,
                'ticket'   => 1.0,
                'clientes' => [],
                'fuera'    => [],
            ];
        }

        $this->bodegas = array_values(array_unique(array_column($this->vendedores, 'bodega')));
    }

    /**
     * Los productos que se venden y cuánto se piden: cada proveedor se lleva
     * una parte según cuántos productos tiene (raíz cuadrada) y, dentro, unos
     * pocos productos se llevan casi todo (Zipf).
     */
    private function catalogo(): void
    {
        $productos = Producto::query()->activos()->with('presentaciones')->orderBy('id')->get();

        foreach ($productos as $producto) {
            $base = $producto->presentacionesActivas()->first(fn (Presentacion $presentacion): bool => $presentacion->es_base && $presentacion->precio > 0);

            if (! $base instanceof Presentacion) {
                continue;
            }

            $caja = $producto->presentacionMayor();
            $caja = $caja instanceof Presentacion && $caja->precio > 0 ? $caja : null;

            $this->productos[$producto->id] = [
                'id'        => $producto->id,
                'proveedor' => $producto->proveedor_id,
                'gravado'   => $producto->gravado,
                'un'        => $base->id,
                'precio'    => $base->precio,
                'cj'        => $caja instanceof Presentacion ? $caja->id : null,
                'contenido' => $caja instanceof Presentacion ? $caja->contenido : 1,
                'precio_cj' => $caja instanceof Presentacion ? $caja->precio : 0.0,
            ];
            $this->porProveedor[$producto->proveedor_id][] = $producto->id;

            foreach ([$base, $caja] as $presentacion) {
                if ($presentacion instanceof Presentacion) {
                    $this->presentaciones[$presentacion->id] = $presentacion;
                    $this->costoBase[$presentacion->id] = $presentacion->costo > 0 ? $presentacion->costo : round($presentacion->precio * 0.82, 4);
                }
            }
        }

        if (count($this->productos) < 5) {
            throw new ReglaNegocioException('Hacen falta productos activos con precio: corra php artisan db:seed --class=CatalogoSeeder.');
        }

        $partes = array_map(fn (array $ids): float => sqrt(count($ids)), $this->porProveedor);
        $suma = array_sum($partes);
        $pesos = [];

        foreach ($this->porProveedor as $proveedorId => $ids) {
            $zipf = [];

            foreach (array_values($this->azar->shuffleArray($ids)) as $lugar => $id) {
                $zipf[(int) $id] = 1 / (($lugar + 1) ** 1.05);
            }

            $total = array_sum($zipf);

            foreach ($zipf as $id => $peso) {
                $pesos[$id] = $peso / $total * $partes[$proveedorId] / $suma;
            }
        }

        arsort($pesos);
        $acumulado = 0.0;

        foreach ($pesos as $id => $peso) {
            $acumulado += $peso;
            $this->ids[] = $id;
            $this->acumulado[] = $acumulado;
        }

        uasort($this->porProveedor, fn (array $a, array $b): int => count($b) <=> count($a));
        $this->proveedores = array_keys($this->porProveedor);
    }

    /**
     * Cada vendedor sin ruta recibe una: el mostrador y los de detalle, el
     * municipio de la bodega; los demás se reparten los otros municipios del
     * departamento (el primero también lleva el de la bodega). Después se le
     * completan clientes en sus municipios.
     */
    private function cartera(): void
    {
        /** @var array<int, int> $sedes [bodega_id => municipio_id] */
        $sedes = Bodega::query()->whereNotNull('municipio_id')->pluck('municipio_id', 'id')->map(fn (mixed $id): int => (int) $id)->all();
        /** @var array<string, true> $nombres */
        $nombres = Cliente::query()->whereNotNull('nombre_clave')->pluck('nombre_clave')->mapWithKeys(fn (mixed $clave): array => [(string) $clave => true])->all();
        $porBodega = [];

        foreach ($this->vendedores as $i => $vendedor) {
            $porBodega[$vendedor['bodega']][] = $i;
        }

        foreach ($porBodega as $bodegaId => $indices) {
            $sede = $sedes[$bodegaId] ?? null;

            if ($sede === null) {
                throw new ReglaNegocioException('Una bodega con vendedores no tiene municipio: póngaselo en Bodegas o corra php artisan db:seed --class=BodegaSeeder.');
            }

            $departamento = (int) Municipio::query()->whereKey($sede)->value('departamento_id');
            /** @var list<int> $zona */
            $zona = Municipio::query()
                ->where('departamento_id', $departamento)
                ->whereNotIn('id', array_values($sedes))
                ->orderBy('nombre')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $normales = array_values(array_filter($indices, fn (int $i): bool => $this->vendedores[$i]['perfil'] === 'normal'));
            $porVendedor = (int) max(2, min(5, ceil(count($zona) / max(1, count($normales)))));
            $siguiente = 0;

            foreach ($indices as $i) {
                $vendedor = $this->vendedores[$i]['user'];
                /** @var list<int> $municipios */
                $municipios = Ruta::municipiosDe($vendedor)->pluck('municipio_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

                if ($municipios === []) {
                    if ($this->vendedores[$i]['perfil'] !== 'normal' || $zona === []) {
                        $municipios = [$sede];
                    } else {
                        for ($k = 0; $k < $porVendedor; $k++) {
                            $municipios[] = $zona[$siguiente % count($zona)];
                            $siguiente++;
                        }

                        if ($i === $normales[0]) {
                            array_unshift($municipios, $sede);
                        }

                        $municipios = array_values(array_unique($municipios));
                    }

                    $this->crearRuta($bodegaId, $vendedor, $municipios);
                }

                $this->clientes($i, $bodegaId, $municipios, $nombres);
            }
        }
    }

    /**
     * @param list<int> $municipios
     */
    private function crearRuta(int $bodegaId, User $vendedor, array $municipios): void
    {
        $nombres = Municipio::query()->whereKey($municipios)->orderBy('nombre')->pluck('nombre')->implode(' - ');
        $ruta = new Ruta([
            'bodega_id' => $bodegaId,
            'nombre'    => mb_substr('RUTA '.($vendedor->codigo ?? $vendedor->id).' · '.$nombres, 0, 120),
            'activa'    => true,
        ]);
        $ruta->save();
        $ruta->municipios()->attach($municipios);
        $ruta->vendedores()->attach($vendedor->id);
    }

    /**
     * Completa la cartera del vendedor ($i) en sus municipios y guarda sus
     * clientes (y los de su bodega fuera de su ruta, para los pedidos fuera
     * de ruta).
     *
     * @param list<int> $municipios
     * @param array<string, true> $nombres claves de los nombres que ya existen
     */
    private function clientes(int $i, int $bodegaId, array $municipios, array &$nombres): void
    {
        $vendedor = $this->vendedores[$i];
        $necesita = match ($vendedor['perfil']) {
            'mostrador' => 45,
            'detalle'   => 40,
            default     => (int) max(25, round($vendedor['meta'] / 6_500)),
        };
        $tiene = Cliente::query()->where('activo', true)->where('bodega_id', $bodegaId)->whereIn('municipio_id', $municipios)->count();
        $nombresMunicipio = Municipio::query()->whereKey($municipios)->pluck('nombre', 'id');

        for ($k = $tiene; $k < $necesita; $k++) {
            $municipioId = $municipios[$k % count($municipios)];
            $this->nuevoCliente($bodegaId, $municipioId, (string) $nombresMunicipio->get($municipioId), $vendedor['perfil'], $nombres);
        }

        $vendedor['clientes'] = self::listaClientes(Cliente::query()->whereIn('municipio_id', $municipios), $bodegaId);
        $vendedor['fuera'] = array_slice(self::listaClientes(Cliente::query()->whereNotIn('municipio_id', $municipios), $bodegaId), 0, 40);
        $this->vendedores[$i] = $vendedor;
    }

    /**
     * Los clientes activos de la bodega (de esa consulta) con su tipo.
     *
     * @param Builder<Cliente> $clientes
     *
     * @return list<array{id: int, tipo: string}>
     */
    private static function listaClientes(Builder $clientes, int $bodegaId): array
    {
        $lista = [];

        foreach ($clientes->where('activo', true)->where('bodega_id', $bodegaId)->orderBy('id')->get(['id', 'nombre']) as $cliente) {
            $lista[] = ['id' => $cliente->id, 'tipo' => self::tipoDe($cliente->nombre)];
        }

        return $lista;
    }

    /**
     * @param array<string, true> $nombres
     */
    private function nuevoCliente(int $bodegaId, int $municipioId, string $municipio, string $perfil, array &$nombres): void
    {
        $r = $this->azar->nextFloat();
        $tipo = $perfil === 'normal'
            ? ($r < 0.72 ? 'pulperia' : ($r < 0.96 ? 'super' : 'mayorista'))
            : ($r < 0.9 ? 'pulperia' : 'super');
        $negocios = array_keys(array_filter(self::NEGOCIOS, fn (string $t): bool => $t === $tipo));
        $nombre = '';
        $clave = '';

        for ($intento = 0; $intento < 30; $intento++) {
            $nombre = $this->uno($negocios).' '.$this->uno(self::NOMBRES).($intento >= 15 ? ' - '.$municipio : '');
            $clave = Texto::clave($nombre);

            if (! isset($nombres[$clave])) {
                break;
            }
        }

        if (isset($nombres[$clave])) {
            $nombre .= ' '.(count($nombres) + 1);
            $clave = Texto::clave($nombre);
        }

        $nombres[$clave] = true;
        $conRtn = $tipo === 'pulperia' ? $this->azar->nextFloat() < 0.12 : $this->azar->nextFloat() < 0.75;
        $creado = $this->desde->subDays($this->azar->getInt(20, 700))->setTime($this->azar->getInt(8, 16), $this->azar->getInt(0, 59));

        $cliente = new Cliente([
            'nombre'       => $nombre,
            'rtn'          => $conRtn ? sprintf('%04d%04d%06d', $this->azar->getInt(401, 1628), $this->azar->getInt(1960, 2002), $this->azar->getInt(1, 999_999)) : null,
            'telefono'     => sprintf('%d%03d-%04d', $this->uno([9, 8, 3]), $this->azar->getInt(0, 999), $this->azar->getInt(0, 9_999)),
            'direccion'    => $this->uno(self::DIRECCIONES),
            'municipio_id' => $municipioId,
            'bodega_id'    => $bodegaId,
            'activo'       => true,
        ]);
        $cliente->forceFill(['created_at' => $creado, 'updated_at' => $creado])->save();
    }

    private static function tipoDe(string $nombre): string
    {
        $nombre = mb_strtoupper($nombre);

        return match (true) {
            str_contains($nombre, 'DISTRIBUIDORA'), str_contains($nombre, 'MAYORISTA'), str_contains($nombre, 'COMERCIAL')                        => 'mayorista',
            str_contains($nombre, 'SÚPER'), str_contains($nombre, 'SUPER'), str_contains($nombre, 'SURTIDORA'), str_contains($nombre, 'DESPENSA') => 'super',
            default                                                                                                                               => 'pulperia',
        };
    }

    /**
     * El que más vende lleva meta propia en LIMPIEZA (más alta), el segundo en
     * CUIDADO PERSONAL, y la segunda bodega pone su escala en UNILEVER (más
     * baja). Solo si no las tienen ya.
     */
    private function metas(): void
    {
        $grupos = ComisionGrupo::query()->where('activo', true)->pluck('id', 'nombre');
        $normales = array_values(array_filter($this->vendedores, fn (array $vendedor): bool => $vendedor['perfil'] === 'normal'));
        usort($normales, fn (array $a, array $b): int => $b['meta'] <=> $a['meta']);

        $propias = [
            ['LIMPIEZA', 0, [['desde' => 0, 'porcentaje' => 0.5], ['desde' => 120_000, 'porcentaje' => 1], ['desde' => 150_000, 'porcentaje' => 2]]],
            ['CUIDADO PERSONAL', 1, [['desde' => 0, 'porcentaje' => 1], ['desde' => 30_000, 'porcentaje' => 1.5], ['desde' => 45_000, 'porcentaje' => 2]]],
        ];

        foreach ($propias as [$grupo, $lugar, $escalones]) {
            $grupoId = $grupos->get($grupo);
            $vendedor = $normales[$lugar] ?? null;

            if (! is_numeric($grupoId) || $vendedor === null || ComisionMeta::query()->where('comision_grupo_id', (int) $grupoId)->where('vendedor_id', $vendedor['user']->id)->exists()) {
                continue;
            }

            $this->guardarMeta->ejecutar(null, (int) $grupoId, $vendedor['user']->id, $escalones, $this->admin());
        }

        $unilever = $grupos->get('UNILEVER');
        $bodegaId = $this->bodegas[1] ?? $this->bodegas[0];

        if (is_numeric($unilever) && ! ComisionEscalaBodega::query()->where('comision_grupo_id', (int) $unilever)->where('bodega_id', $bodegaId)->exists()) {
            $this->guardarEscala->ejecutar((int) $unilever, $bodegaId, [['desde' => 0, 'porcentaje' => 0.5], ['desde' => 50_000, 'porcentaje' => 1], ['desde' => 75_000, 'porcentaje' => 1.5]], $this->admin());
        }
    }

    /**
     * Desde el segundo mes y hasta fin del mes de hoy: con 3 cajas del
     * producto que más se vende, 1 unidad gratis; con 2 cajas de otro (de
     * otro proveedor), la tercera con 25 % menos. Si ya se habían creado
     * (otra corrida), solo se les ponen las fechas de esta.
     */
    private function promociones(): void
    {
        $desde = $this->desde->addMonthNoOverflow()->startOfMonth()->toDateString();
        $hasta = $this->hasta->endOfMonth()->toDateString();

        $this->promosDesde = CarbonImmutable::parse($desde);

        if (Promocion::query()->where('descripcion', self::PROMOCION)->exists()) {
            Promocion::query()->where('descripcion', self::PROMOCION)->update(['vigente_desde' => $desde, 'vigente_hasta' => $hasta, 'activa' => true]);
        } else {
            $this->crearPromociones($desde, $hasta);
        }

        // Los vendedores las empujan: con qué caja y cuántas se gana cada una.
        foreach (Promocion::query()->where('descripcion', self::PROMOCION)->with('condiciones')->get() as $promocion) {
            foreach ($promocion->condiciones as $condicion) {
                $this->promos[$condicion->producto_id] = [
                    'caja'  => $condicion->presentacion_id,
                    'cajas' => $condicion->cantidad + ($promocion->beneficio_presentacion_id === $condicion->presentacion_id ? $promocion->cantidad_beneficio : 0),
                ];
            }
        }
    }

    private function crearPromociones(string $desde, string $hasta): void
    {
        $conCaja = array_values(array_filter($this->ids, fn (int $id): bool => $this->productos[$id]['cj'] !== null && $this->productos[$id]['contenido'] >= 6));
        $primero = $conCaja[0] ?? null;

        if ($primero === null) {
            return;
        }

        $segundo = null;

        foreach ($conCaja as $id) {
            if ($this->productos[$id]['proveedor'] !== $this->productos[$primero]['proveedor']) {
                $segundo = $id;

                break;
            }
        }

        $promociones = [[$primero, 3, 'un', 1, 100.0]];

        if ($segundo !== null) {
            $promociones[] = [$segundo, 2, 'cj', 1, 25.0];
        }

        foreach ($promociones as [$productoId, $cajas, $regalo, $cuantas, $descuento]) {
            $p = $this->productos[$productoId];
            $caja = $this->presentaciones[(int) $p['cj']];
            $descripcion = (string) Producto::query()->whereKey($productoId)->value('descripcion');
            $nombre = $descuento >= 100
                ? "{$cajas} {$caja->sigla()} {$descripcion} + 1 UN GRATIS"
                : "{$cajas} {$caja->sigla()} {$descripcion} + LA SIGUIENTE CON 25 %";

            $promocion = Promocion::query()->create([
                'nombre'                    => mb_substr($nombre, 0, 120),
                'descripcion'               => self::PROMOCION,
                'producto_beneficio_id'     => $productoId,
                'cantidad_beneficio'        => $cuantas,
                'beneficio_presentacion_id' => $regalo === 'un' ? $p['un'] : $caja->id,
                'descuento_porcentaje'      => $descuento,
                'activa'                    => true,
                'vigente_desde'             => $desde,
                'vigente_hasta'             => $hasta,
                'created_by'                => $this->admin()->id,
            ]);
            $promocion->condiciones()->create(['producto_id' => $productoId, 'presentacion_id' => $caja->id, 'cantidad' => $cajas]);
        }
    }

    /**
     * Con una muestra de 60 pedidos de cada vendedor (sin tocar la base):
     * cuánto le compra en promedio cada cliente y, con lo que vende al día,
     * cuántas unidades de cada producto se venden al día en cada bodega.
     */
    private function estimarDemanda(int $semilla): void
    {
        $azar = new Randomizer(new Mt19937($semilla));
        $muestra = 60;

        foreach ($this->vendedores as $i => $vendedor) {
            $valor = 0.0;
            $unidades = [];

            for ($n = 0; $n < $muestra; $n++) {
                $tipo = $this->tipoPara($vendedor, $azar);

                foreach ($this->armarLineas($tipo, $this->ticket($tipo, $azar), $azar, null) as $linea) {
                    $unidades[$linea['producto']] = ($unidades[$linea['producto']] ?? 0) + $linea['cantidad'] * $linea['contenido'];
                    $valor += $linea['valor'];
                }
            }

            $ticket = max(1.0, $valor / $muestra);
            $pedidosAlDia = $vendedor['meta'] / 24.5 / $ticket;
            $vendedor['ticket'] = $ticket;
            $this->vendedores[$i] = $vendedor;

            foreach ($unidades as $productoId => $cuantas) {
                $this->demanda[$vendedor['bodega']][$productoId] = ($this->demanda[$vendedor['bodega']][$productoId] ?? 0.0) + $cuantas / $muestra * $pedidosAlDia;
            }
        }
    }

    /**
     * Lo que hay el primer día: 15 días de venta, en cajas (y unas sueltas).
     */
    private function inventarioInicial(): void
    {
        foreach ($this->bodegas as $bodegaId) {
            $encargado = $this->encargados[$bodegaId] ?? $this->admin();

            foreach ($this->demanda[$bodegaId] ?? [] as $productoId => $diario) {
                $p = $this->productos[$productoId];
                $unidades = (int) ceil($diario * 15);

                if ($p['cj'] === null) {
                    $this->ajustar->ejecutar($bodegaId, $this->presentaciones[$p['un']], max(1, $unidades), $encargado, self::NOTA_INICIAL);

                    continue;
                }

                $this->ajustar->ejecutar($bodegaId, $this->presentaciones[$p['cj']], intdiv($unidades, $p['contenido']) + 1, $encargado, self::NOTA_INICIAL);
                $sueltas = $this->azar->getInt(0, intdiv($p['contenido'], 2));

                if ($sueltas > 0) {
                    $this->ajustar->ejecutar($bodegaId, $this->presentaciones[$p['un']], $sueltas, $encargado, self::NOTA_INICIAL);
                }
            }
        }

        foreach (Existencia::query()->whereIn('bodega_id', $this->bodegas)->get(['bodega_id', 'presentacion_id', 'cantidad']) as $existencia) {
            $this->hay[$existencia->bodega_id][$existencia->presentacion_id] = $existencia->cantidad;
        }
    }

    private function trabajar(CarbonImmutable $dia): void
    {
        if ($this->pesoDia($dia) <= 0.0) {
            return;
        }

        $this->apartado = [];
        $this->cerrarComisiones($dia);

        if ($dia->isMonday()) {
            $this->refrescarPrecios();
        }

        $this->comprar($dia);
        $this->devolver($dia);
        $ids = $this->levantar($dia);

        // Los de hoy quedan pendientes: bodega los valida en la tarde.
        if ($dia->eq($this->hasta)) {
            return;
        }

        $this->despachar($dia, $this->anularAlgunos($dia, $ids));
        $this->corregirAlguno($dia);
    }

    /**
     * Cierra (y paga) cada periodo de comisiones que terminó antes de hoy.
     */
    private function cerrarComisiones(CarbonImmutable $dia): void
    {
        $configuracion = Configuracion::actual();
        $periodo = $this->periodo ?? PeriodoComision::para($this->desde, $configuracion->comision_frecuencia, $configuracion->comision_corte);

        while ($periodo->hasta->lt($dia)) {
            $this->viajar($dia->setTime(7, 15));
            $this->cerrar->ejecutar($periodo, $this->admin(), null, $dia);
            $periodo = PeriodoComision::para($periodo->hasta->addDay(), $configuracion->comision_frecuencia, $configuracion->comision_corte);
        }

        $this->periodo = $periodo;
    }

    /**
     * Los precios "Con %" cambian con las compras: los pedidos se arman con los de hoy.
     */
    private function refrescarPrecios(): void
    {
        $precios = Presentacion::query()->whereKey(array_keys($this->presentaciones))->pluck('precio', 'id');

        foreach ($this->productos as $id => $p) {
            $this->productos[$id]['precio'] = (float) ($precios->get($p['un']) ?? $p['precio']);

            if ($p['cj'] !== null) {
                $this->productos[$id]['precio_cj'] = (float) ($precios->get($p['cj']) ?? $p['precio_cj']);
            }
        }
    }

    /**
     * Cada proveedor tiene su día (lunes a viernes). Ese día, cada bodega le
     * compra lo que le alcanza para menos de 13 días, hasta tener para 24.
     */
    private function comprar(CarbonImmutable $dia): void
    {
        $crece = $this->crecimiento($dia);

        foreach ($this->proveedores as $k => $proveedorId) {
            if (1 + ($k % 5) !== $dia->dayOfWeekIso) {
                continue;
            }

            $proveedor = Proveedor::query()->findOrFail($proveedorId);

            foreach ($this->bodegas as $bodegaId) {
                $lineas = [];

                foreach ($this->porProveedor[$proveedorId] as $productoId) {
                    $diario = ($this->demanda[$bodegaId][$productoId] ?? 0.0) * $crece;

                    if ($diario <= 0) {
                        continue;
                    }

                    $p = $this->productos[$productoId];
                    $tiene = ($this->hay[$bodegaId][$p['un']] ?? 0) + ($p['cj'] !== null ? ($this->hay[$bodegaId][$p['cj']] ?? 0) * $p['contenido'] : 0);

                    if ($tiene >= $diario * 13) {
                        continue;
                    }

                    $presentacionId = $p['cj'] ?? $p['un'];
                    $contenido = $p['cj'] !== null ? $p['contenido'] : 1;
                    $costo = $this->costoBase[$presentacionId] * $this->alza($proveedorId, $dia) * (0.99 + 0.025 * $this->azar->nextFloat());

                    $lineas[] = [
                        'producto_id'     => $productoId,
                        'presentacion_id' => $presentacionId,
                        'cantidad'        => max(1, (int) ceil(($diario * 24 - $tiene) / $contenido)),
                        'costo'           => round($costo * ($p['gravado'] ? 1 + CalculadoraPrecio::tasaIsv() : 1.0), 2),
                    ];
                }

                if ($lineas === []) {
                    continue;
                }

                $this->viajar($dia->setTime(7, 30)->addMinutes($k * 7 + $bodegaId));
                $this->facturaProveedor[$proveedorId] = ($this->facturaProveedor[$proveedorId] ?? 10_000 + $proveedorId * 7_919) + $this->azar->getInt(3, 40);
                $this->registrarCompra->ejecutar(
                    Bodega::query()->findOrFail($bodegaId),
                    $proveedor,
                    $lineas,
                    $this->encargados[$bodegaId] ?? $this->admin(),
                    '001-001-01-'.str_pad((string) $this->facturaProveedor[$proveedorId], 8, '0', STR_PAD_LEFT),
                );

                foreach ($lineas as $linea) {
                    $this->hay[$bodegaId][$linea['presentacion_id']] = ($this->hay[$bodegaId][$linea['presentacion_id']] ?? 0) + $linea['cantidad'];
                }
            }
        }
    }

    /**
     * El proveedor más grande sube 4 % desde el día 12 del segundo mes; el
     * segundo, 2.5 % desde el día 3 del tercero.
     */
    private function alza(int $proveedorId, CarbonImmutable $dia): float
    {
        $alza = 1.0;

        if ($proveedorId === ($this->proveedores[0] ?? null) && $dia->gte($this->desde->addMonthsNoOverflow(1)->addDays(11))) {
            $alza *= 1.04;
        }

        if ($proveedorId === ($this->proveedores[1] ?? null) && $dia->gte($this->desde->addMonthsNoOverflow(2)->addDays(2))) {
            $alza *= 1.025;
        }

        return $alza;
    }

    /**
     * Las devoluciones que tocan hoy: cambio (casi siempre por dañado) o
     * crédito. Sin existencia para reponer, el cambio se vuelve crédito.
     */
    private function devolver(CarbonImmutable $dia): void
    {
        foreach ($this->porDevolver[$dia->toDateString()] ?? [] as $n => $pedidoId) {
            $pedido = Pedido::query()->with('items')->find($pedidoId);

            if (! $pedido instanceof Pedido || $pedido->estado !== EstadoPedido::Impreso) {
                continue;
            }

            $lineas = $pedido->items->filter(fn (PedidoItem $item): bool => ! $item->bonificacion && $item->cantidadFacturable() > 0)->values();
            $sueltas = $lineas->filter(fn (PedidoItem $item): bool => $item->presentacion_id === ($this->productos[$item->producto_id]['un'] ?? null))->values();
            $opciones = $sueltas->isNotEmpty() && $this->azar->nextFloat() < 0.85 ? $sueltas : $lineas;
            $linea = $opciones->get($this->azar->getInt(0, max(0, $opciones->count() - 1)));

            if (! $linea instanceof PedidoItem || ! isset($this->productos[$linea->producto_id])) {
                continue;
            }

            $p = $this->productos[$linea->producto_id];
            $cuantas = $linea->presentacion_id === $p['un'] ? min($linea->cantidadFacturable(), $this->azar->getInt(1, 3)) : 1;
            $tipo = $this->azar->nextFloat() < 0.7 ? TipoDevolucion::Cambio : TipoDevolucion::Credito;
            $danadas = $tipo === TipoDevolucion::Cambio || $this->azar->nextFloat() < 0.6;

            if ($tipo === TipoDevolucion::Cambio && ! $this->alcanza($pedido->bodega_id, $p, $linea->presentacion_id, $cuantas)) {
                $tipo = TipoDevolucion::Credito;
            }

            $this->viajar($dia->setTime(9, 0)->addMinutes($n * 4));

            try {
                $this->devolucion->ejecutar(
                    $pedido,
                    $tipo,
                    [$linea->id => ['buenas' => $danadas ? 0 : $cuantas, 'danadas' => $danadas ? $cuantas : 0]],
                    $this->encargados[$pedido->bodega_id] ?? $this->admin(),
                    $this->uno($danadas ? self::MOTIVOS_DANADO : self::MOTIVOS_BUENO),
                );
            } catch (ReglaNegocioException) {
                continue;
            }

            $this->refrescar($pedido->bodega_id, [$linea->producto_id]);
        }
    }

    /**
     * Los pedidos del día de cada vendedor: los que le tocan para vender lo
     * de ese día (±20 %) con su ticket promedio. Hoy, solo hasta la hora en
     * que se corre.
     *
     * @return list<int>
     */
    private function levantar(CarbonImmutable $dia): array
    {
        $parte = $this->crecimiento($dia) * $this->pesoDia($dia) / $this->pesoMes($dia);
        $this->hoy = $dia;
        $limite = $dia->eq($this->hasta) ? $this->ahora : null;
        $creados = [];

        foreach ($this->vendedores as $vendedor) {
            $esperados = $vendedor['meta'] * $parte * (0.8 + 0.4 * $this->azar->nextFloat()) / $vendedor['ticket'];
            $cuantos = (int) floor($esperados + $this->azar->nextFloat());
            $paso = (int) max(8, min(70, 450 / max(1.0, $esperados)));
            $hora = $dia->setTime(7, 40)->addMinutes($this->azar->getInt(0, 30));
            $atendidos = [];

            for ($n = 0; $n < $cuantos; $n++) {
                if ($limite !== null && $hora->gt($limite)) {
                    break;
                }

                [$clienteId, $tipo, $fuera] = $this->cliente($vendedor, $atendidos);

                if ($clienteId === null) {
                    break;
                }

                $lineas = $this->armarLineas($tipo, $this->ticket($tipo, $this->azar), $this->azar, $vendedor['bodega']);

                if ($lineas === []) {
                    continue;
                }

                $creados[] = $this->pedido($vendedor, $clienteId, $fuera, $dia, $hora, $lineas);
                $hora = $hora->addMinutes($paso + $this->azar->getInt(0, $paso));
            }
        }

        return $creados;
    }

    /**
     * Un cliente de su ruta que todavía no compró hoy (3 de cada 100, uno de
     * su bodega fuera de su ruta) y su tipo: el mostrador y los de detalle
     * venden siempre poco.
     *
     * @param array{user: User, bodega: int, perfil: string, meta: float, ticket: float, clientes: list<array{id: int, tipo: string}>, fuera: list<array{id: int, tipo: string}>} $vendedor
     * @param array<int, true> $atendidos
     *
     * @return array{0: int|null, 1: string, 2: bool}
     */
    private function cliente(array $vendedor, array &$atendidos): array
    {
        $fuera = $vendedor['fuera'] !== [] && $this->azar->nextFloat() < 0.03;
        $lista = $fuera ? $vendedor['fuera'] : $vendedor['clientes'];

        if ($lista === []) {
            return [null, '', false];
        }

        $cliente = $lista[0];

        for ($intento = 0; $intento < 8; $intento++) {
            $cliente = $lista[$this->azar->getInt(0, count($lista) - 1)];

            if (! isset($atendidos[$cliente['id']])) {
                break;
            }
        }

        $atendidos[$cliente['id']] = true;

        return [$cliente['id'], $vendedor['perfil'] === 'normal' ? $cliente['tipo'] : $vendedor['perfil'], $fuera];
    }

    /**
     * @param array{user: User, bodega: int, perfil: string, meta: float, ticket: float, clientes: list<array{id: int, tipo: string}>, fuera: list<array{id: int, tipo: string}>} $vendedor
     */
    private function tipoPara(array $vendedor, Randomizer $azar): string
    {
        if ($vendedor['perfil'] !== 'normal' || $vendedor['clientes'] === []) {
            return $vendedor['perfil'] === 'normal' ? 'pulperia' : $vendedor['perfil'];
        }

        return $vendedor['clientes'][$azar->getInt(0, count($vendedor['clientes']) - 1)]['tipo'];
    }

    /**
     * Lo que va a comprar el cliente (sin ISV): alrededor de la mediana de su
     * tipo, entre 0.3 y 3.5 veces.
     */
    private function ticket(string $tipo, Randomizer $azar): float
    {
        $normal = sqrt(-2 * log(1 - $azar->nextFloat())) * cos(2 * M_PI * $azar->nextFloat());
        $mediana = self::TIPOS[$tipo]['ticket'];

        return max(0.3 * $mediana, min(3.5 * $mediana, $mediana * exp(0.5 * $normal)));
    }

    /**
     * Productos hasta llegar al ticket: por caja (según el tipo de cliente y
     * si la caja cabe en lo que va a gastar) o por unidad. Con $bodegaId
     * solo lo que hay sin apartar; sin ella, como si hubiera de todo.
     *
     * @return list<array{producto: int, presentacion: int, contenido: int, cantidad: int, valor: float}>
     */
    private function armarLineas(string $tipo, float $ticket, Randomizer $azar, ?int $bodegaId): array
    {
        $t = self::TIPOS[$tipo];
        $lineas = [];
        $usados = [];
        $suma = 0.0;

        for ($intento = 0; $intento < 60 && count($lineas) < $t['lineas'] && $suma < $ticket; $intento++) {
            $id = $this->elegir($azar);

            if (isset($usados[$id])) {
                continue;
            }

            $p = $this->productos[$id];
            $falta = $ticket - $suma;

            if ($p['cj'] !== null && $p['precio_cj'] <= max($falta, $ticket * 0.5) * 1.2 && $azar->nextFloat() < $t['caja']) {
                $cantidad = 1 + (int) floor(($azar->nextFloat() ** 2) * $t['cajas']);
                $cantidad = max(1, min($cantidad, (int) ceil($falta / $p['precio_cj'])));
                [$presentacion, $contenido, $precio] = [$p['cj'], $p['contenido'], $p['precio_cj']];
            } else {
                [$minimo, $maximo] = match (true) {
                    $p['precio'] < 25  => [3, 12],
                    $p['precio'] < 80  => [2, 6],
                    $p['precio'] < 250 => [1, 4],
                    default            => [1, 2],
                };

                if ($tipo === 'mayorista') {
                    [$minimo, $maximo] = [$minimo * 2, $maximo * 3];
                }

                $cantidad = max(1, min($azar->getInt($minimo, $maximo), (int) ceil($falta / max(0.01, $p['precio']))));
                [$presentacion, $contenido, $precio] = [$p['un'], 1, $p['precio']];
            }

            // Con promoción vigente, al súper y al mayorista el vendedor le completa las cajas que la ganan.
            $promo = $this->promos[$id] ?? null;

            if ($promo !== null && $presentacion === $promo['caja'] && $tipo !== 'pulperia' && $this->hoy !== null && $this->promosDesde !== null && $this->hoy->gte($this->promosDesde) && $azar->nextFloat() < 0.5) {
                $cantidad = max($cantidad, $promo['cajas']);
            }

            if ($bodegaId !== null && ! $this->alcanza($bodegaId, $p, $presentacion, $cantidad)) {
                continue;
            }

            $usados[$id] = true;
            $lineas[] = ['producto' => $id, 'presentacion' => $presentacion, 'contenido' => $contenido, 'cantidad' => $cantidad, 'valor' => $cantidad * $precio];
            $suma += $cantidad * $precio;
        }

        return $lineas;
    }

    private function elegir(Randomizer $azar): int
    {
        $buscado = $azar->nextFloat() * $this->acumulado[count($this->acumulado) - 1];
        $bajo = 0;
        $alto = count($this->acumulado) - 1;

        while ($bajo < $alto) {
            $medio = intdiv($bajo + $alto, 2);

            if ($this->acumulado[$medio] < $buscado) {
                $bajo = $medio + 1;
            } else {
                $alto = $medio;
            }
        }

        return $this->ids[$bajo];
    }

    /**
     * ¿Hay sin apartar? Las sueltas salen de las sueltas y de abrir cajas;
     * las cajas, de las cajas que no hay que abrir para las sueltas.
     *
     * @param array{id: int, proveedor: int, gravado: bool, un: int, precio: float, cj: int|null, contenido: int, precio_cj: float} $p
     */
    private function alcanza(int $bodegaId, array $p, int $presentacionId, int $cantidad): bool
    {
        $sueltas = ($this->hay[$bodegaId][$p['un']] ?? 0) - ($this->apartado[$bodegaId][$p['un']] ?? 0);
        $cajas = $p['cj'] !== null ? ($this->hay[$bodegaId][$p['cj']] ?? 0) - ($this->apartado[$bodegaId][$p['cj']] ?? 0) : 0;
        $libres = $cajas - ($sueltas < 0 ? (int) ceil(-$sueltas / $p['contenido']) : 0);

        return $presentacionId === $p['un']
            ? $sueltas + max(0, $libres) * $p['contenido'] >= $cantidad
            : $libres >= $cantidad;
    }

    /**
     * @param array{user: User, bodega: int, perfil: string, meta: float, ticket: float, clientes: list<array{id: int, tipo: string}>, fuera: list<array{id: int, tipo: string}>} $vendedor
     * @param list<array{producto: int, presentacion: int, contenido: int, cantidad: int, valor: float}> $lineas
     */
    private function pedido(array $vendedor, int $clienteId, bool $fuera, CarbonImmutable $dia, CarbonImmutable $hora, array $lineas): int
    {
        $this->viajar($hora);

        $pedido = Pedido::query()->create([
            'bodega_id'         => $vendedor['bodega'],
            'cliente_id'        => $clienteId,
            'vendedor_id'       => $vendedor['user']->id,
            'fecha'             => $dia->toDateString(),
            'notas'             => $this->azar->nextFloat() < 0.06 ? $this->uno(self::NOTAS) : null,
            'fuera_de_ruta'     => $fuera,
            'motivo_fuera_ruta' => $fuera ? $this->uno(self::MOTIVOS_FUERA) : null,
        ]);

        foreach ($lineas as $linea) {
            $pedido->items()->create(['producto_id' => $linea['producto'], 'presentacion_id' => $linea['presentacion'], 'cantidad' => $linea['cantidad']]);
            $this->apartado[$vendedor['bodega']][$linea['presentacion']] = ($this->apartado[$vendedor['bodega']][$linea['presentacion']] ?? 0) + $linea['cantidad'];
        }

        $this->recalcular->ejecutar($pedido, preciosVigentes: true);

        return $pedido->id;
    }

    /**
     * Unos pocos (7 de cada 1000) los cancela el cliente antes de que salgan.
     *
     * @param list<int> $ids
     *
     * @return list<int> los que siguen
     */
    private function anularAlgunos(CarbonImmutable $dia, array $ids): array
    {
        $siguen = [];

        foreach ($ids as $id) {
            if ($this->azar->nextFloat() >= 0.007) {
                $siguen[] = $id;

                continue;
            }

            $pedido = Pedido::query()->with('items')->findOrFail($id);
            $this->viajar($dia->setTime(15, $this->azar->getInt(0, 50)));
            $this->anular->ejecutar($pedido, 'EL CLIENTE CANCELÓ EL PEDIDO');
            $this->soltar($pedido);
        }

        return $siguen;
    }

    /**
     * En la tarde, bodega valida cada pedido (abre las cajas que hagan falta
     * para las sueltas) e imprime las facturas. Algunos traen devolución
     * unos días después.
     *
     * @param list<int> $ids
     */
    private function despachar(CarbonImmutable $dia, array $ids): void
    {
        $porBodega = Pedido::query()->whereKey($ids)->with('items')->orderBy('id')->get()->groupBy('bodega_id');

        foreach ($porBodega as $bodegaId => $pedidos) {
            $bodegaId = (int) $bodegaId;
            $encargado = $this->encargados[$bodegaId] ?? $this->admin();
            $hora = $dia->setTime(16, 0);
            $validados = [];

            foreach ($pedidos as $pedido) {
                $this->viajar($hora);
                $hora = $hora->addSeconds(50);

                try {
                    $this->validar->ejecutar($pedido, [], $encargado, abrir: $this->abrir($bodegaId, $pedido));
                } catch (ReglaNegocioException) {
                    $this->anular->ejecutar($pedido, 'NO HUBO EXISTENCIA');
                    $this->soltar($pedido);

                    continue;
                }

                $this->soltar($pedido);
                $this->refrescar($bodegaId, array_values(PedidoItem::query()->where('pedido_id', $pedido->id)->distinct()->pluck('producto_id')->map(fn (mixed $id): int => (int) $id)->all()));
                $validados[] = $pedido->id;
            }

            foreach (array_chunk($validados, ImprimirFacturas::MAXIMO) as $lote) {
                $this->viajar($hora->addMinutes(5));
                $this->imprimir->ejecutar($lote, $encargado, ModoImpresion::Originales);
            }

            $sinImprimir = Pedido::query()->whereKey($validados)->where('estado', '!=', EstadoPedido::Impreso->value)->count();

            if ($sinImprimir > 0) {
                throw new ReglaNegocioException("No se imprimieron {$sinImprimir} pedidos del {$dia->format('d/m/Y')}: revise que {$encargado->name} pueda imprimir los de su bodega.");
            }

            foreach ($validados as $id) {
                if ($this->azar->nextFloat() < 0.014) {
                    $cuando = $dia->addDays($this->azar->getInt(1, 5));
                    $cuando = $cuando->isSunday() ? $cuando->addDay() : $cuando;
                    $this->porDevolver[$cuando->toDateString()][] = $id;
                }
            }

            $this->porCorregir = [...$this->porCorregir, ...$validados];
        }
    }

    /**
     * Las cajas que hay que abrir para las sueltas del pedido: la caja del
     * producto, las que falten (sin tocar las que salen enteras).
     *
     * @return array<int, int>
     */
    private function abrir(int $bodegaId, Pedido $pedido): array
    {
        $sueltas = [];
        $enteras = [];

        foreach ($pedido->items as $item) {
            $p = $this->productos[$item->producto_id] ?? null;

            if ($item->bonificacion || $p === null || $p['cj'] === null) {
                continue;
            }

            if ($item->presentacion_id === $p['un']) {
                $sueltas[$item->producto_id] = ($sueltas[$item->producto_id] ?? 0) + $item->cantidad;
            } elseif ($item->presentacion_id === $p['cj']) {
                $enteras[$item->producto_id] = ($enteras[$item->producto_id] ?? 0) + $item->cantidad;
            }
        }

        $abrir = [];

        foreach ($sueltas as $productoId => $cantidad) {
            $p = $this->productos[$productoId];
            $falta = $cantidad - max(0, $this->hay[$bodegaId][$p['un']] ?? 0);

            if ($falta <= 0 || $p['cj'] === null) {
                continue;
            }

            $cajas = min((int) ceil($falta / $p['contenido']), ($this->hay[$bodegaId][$p['cj']] ?? 0) - ($enteras[$productoId] ?? 0));

            if ($cajas > 0) {
                $abrir[$p['cj']] = $cajas;
            }
        }

        return $abrir;
    }

    /**
     * 4 de cada 1000 pedidos impresos se corrigen esa noche (se cargó de más
     * una línea): la factura queda anulada y sale otra.
     */
    private function corregirAlguno(CarbonImmutable $dia): void
    {
        $impresos = $this->porCorregir;
        $this->porCorregir = [];

        foreach ($impresos as $id) {
            if ($this->azar->nextFloat() >= 0.004) {
                continue;
            }

            $pedido = Pedido::query()->with('items')->findOrFail($id);
            $lineas = $pedido->items->filter(fn (PedidoItem $item): bool => ! $item->bonificacion && $item->cantidadFacturable() > 0)->values();
            $mayores = $lineas->filter(fn (PedidoItem $item): bool => $item->cantidadFacturable() >= 2)->values();

            if ($lineas->count() < 2 || $mayores->isEmpty()) {
                continue;
            }

            $linea = $mayores->get($this->azar->getInt(0, $mayores->count() - 1));

            if (! $linea instanceof PedidoItem) {
                continue;
            }

            $sale = $linea->cantidadFacturable();
            $encargado = $this->encargados[$pedido->bodega_id] ?? $this->admin();
            $this->viajar($dia->setTime(19, $this->azar->getInt(0, 40)));

            try {
                $this->corregir->ejecutar($pedido, [$linea->id => $sale - max(1, intdiv($sale, 3))], $encargado, 'EL CLIENTE NO RECIBIÓ TODO');
                $this->imprimir->ejecutar([$pedido->id], $encargado, ModoImpresion::Originales);
            } catch (ReglaNegocioException) {
                continue;
            }

            $this->refrescar($pedido->bodega_id, [$linea->producto_id]);
        }
    }

    /**
     * Lo que el pedido tenía apartado deja de estarlo (salió o se anuló).
     */
    private function soltar(Pedido $pedido): void
    {
        foreach ($pedido->items as $item) {
            if (! $item->bonificacion) {
                $this->apartado[$pedido->bodega_id][$item->presentacion_id] = ($this->apartado[$pedido->bodega_id][$item->presentacion_id] ?? 0) - $item->cantidad;
            }
        }
    }

    /**
     * Vuelve a leer la existencia de esos productos (las cajas abiertas, los
     * regalos y las devoluciones la mueven).
     *
     * @param list<int> $productoIds
     */
    private function refrescar(int $bodegaId, array $productoIds): void
    {
        foreach (Existencia::query()->where('bodega_id', $bodegaId)->whereIn('producto_id', $productoIds)->get(['presentacion_id', 'cantidad']) as $existencia) {
            $this->hay[$bodegaId][$existencia->presentacion_id] = $existencia->cantidad;
        }
    }

    /**
     * 0 = domingo o feriado, 0.6 = sábado, 1 = entre semana.
     */
    private function pesoDia(CarbonImmutable $dia): float
    {
        return match (true) {
            $dia->isSunday(), in_array($dia->format('m-d'), self::FERIADOS, true) => 0.0,
            $dia->isSaturday()                                                    => 0.6,
            default                                                               => 1.0,
        };
    }

    private function pesoMes(CarbonImmutable $dia): float
    {
        $clave = $dia->format('Y-m');

        if (! isset($this->pesosMes[$clave])) {
            $suma = 0.0;

            for ($d = $dia->startOfMonth(); $d->month === $dia->month; $d = $d->addDay()) {
                $suma += $this->pesoDia($d);
            }

            $this->pesosMes[$clave] = $suma;
        }

        return $this->pesosMes[$clave];
    }

    private function crecimiento(CarbonImmutable $dia): float
    {
        $mes = ($dia->year * 12 + $dia->month) - ($this->desde->year * 12 + $this->desde->month);

        return self::CRECIMIENTO[max(0, min($mes, count(self::CRECIMIENTO) - 1))];
    }

    /**
     * "Semana del 01/09 al 05/09: 312 facturas, venta L 1,402,334 sin ISV, compras L 1,203,334."
     */
    private function semana(CarbonImmutable $dia): string
    {
        $lunes = $dia->subDays($dia->dayOfWeekIso - 1);
        $facturas = Factura::query()->whereNull('anulada_at')->whereBetween('fecha_emision', [$lunes, $dia->endOfDay()]);
        $venta = (float) (clone $facturas)->sum(DB::raw('importe_exento + importe_gravado'));
        $compras = (float) Compra::query()->whereBetween('fecha', [$lunes->toDateString(), $dia->toDateString()])->sum('total');
        $texto = "Semana del {$lunes->format('d/m')} al {$dia->format('d/m')}: ".(clone $facturas)->count().' facturas, venta L '.number_format($venta, 0).' sin ISV, compras L '.number_format($compras, 0).'.';

        if ($dia->eq($this->hasta)) {
            $texto .= ' Hoy quedan '.Pedido::query()->where('estado', EstadoPedido::Pendiente->value)->count().' pedidos pendientes.';
        }

        return $texto;
    }

    private function viajar(CarbonImmutable $cuando): void
    {
        Carbon::setTestNow($cuando);
        CarbonImmutable::setTestNow($cuando);
    }

    private function admin(): User
    {
        return $this->admin ?? throw new LogicException('Primero hay que preparar la demostración (preparar()).');
    }

    /**
     * @template T
     *
     * @param array<int, T> $lista
     *
     * @return T
     */
    private function uno(array $lista): mixed
    {
        $lista = array_values($lista);

        return $lista[$this->azar->getInt(0, count($lista) - 1)];
    }
}

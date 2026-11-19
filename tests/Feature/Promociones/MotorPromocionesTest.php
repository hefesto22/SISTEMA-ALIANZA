<?php

declare(strict_types=1);

use App\Domain\Promociones\LineaPromocion;
use App\Domain\Promociones\MotorPromociones;
use App\Models\Producto;
use App\Models\Promocion;

/**
 * El motor solo calcula: recibe las líneas que se cobran y las promociones,
 * y dice qué regalos se agregan (gratis), qué línea lleva cuánto de
 * descuento (a mitad o con un %) y qué le falta al pedido. La condición
 * cuenta la presentación exacta.
 */
beforeEach(function (): void {
    // Toffee: L 30 la UN, CJ12 a L 360. Nutrine: L 25.30 la UN, CJ24 a L 607.20.
    $this->toffee = Producto::factory()->precio(20.0, 30.0)->create(['descripcion' => 'BUTTER TOFFEE 12X100G']);
    $this->nutrine = Producto::factory()->precio(20.0, 25.30)->conCaja(24)->create(['descripcion' => 'BABY NUTRINE 24X50G']);
    $this->motor = new MotorPromociones;
});

/**
 * Línea del pedido en la presentación que trae $contenido (1 = UN, 12 = CJ12…), con su precio.
 */
function lineaPromo(int $clave, Producto $producto, int $cantidad, int $contenido = 1): LineaPromocion
{
    $presentacion = presentacion($producto, $contenido);

    return new LineaPromocion($clave, $producto->id, $presentacion->id, $presentacion->contenido, $cantidad, $presentacion->precio);
}

test('gratis es bonificación: el regalo se agrega aparte y no tiene que venir en el pedido', function (): void {
    $promo = Promocion::factory()->para($this->toffee, $this->nutrine)->create();

    $soloToffee = $this->motor->calcular([lineaPromo(1, $this->toffee, 3)], [$promo]);
    $conNutrine = $this->motor->calcular([lineaPromo(1, $this->toffee, 1), lineaPromo(2, $this->nutrine, 1)], [$promo]);

    expect($soloToffee->regalos)->toHaveCount(1)
        ->and($soloToffee->regalos[0]->promocion->id)->toBe($promo->id)
        ->and($soloToffee->regalos[0]->presentacion->id)->toBe(presentacion($this->nutrine)->id)
        ->and($soloToffee->regalos[0]->cantidad)->toBe(3)
        ->and($soloToffee->regalos[0]->texto())->toContain('Regalo: 3 unidades de BABY NUTRINE 24X50G')
        ->and($soloToffee->descuentos)->toBe([])
        ->and($soloToffee->faltantes)->toBe([])
        // El nutrine que sí compra se cobra: el regalo va aparte.
        ->and($conNutrine->descuentoDe(2))->toBeNull()
        ->and($conNutrine->regalos[0]->cantidad)->toBe(1);
});

test('la condición cuenta la presentación exacta: 12 sueltas o 2 fardos de 6 no llenan una CJ12', function (): void {
    $toffee = Producto::factory()->precio(20.0, 30.0)->conPresentacion('FD', 6)->create();
    $promo = Promocion::factory()->para($toffee, $this->nutrine, enCaja: true)->create();

    $caja = $this->motor->calcular([lineaPromo(1, $toffee, 1, 12)], [$promo]);
    $sueltas = $this->motor->calcular([lineaPromo(1, $toffee, 12)], [$promo]);
    $fardos = $this->motor->calcular([lineaPromo(1, $toffee, 2, 6)], [$promo]);

    expect($promo->condiciones->sole()->presentacion_id)->toBe(presentacion($toffee, 12)->id)
        ->and($caja->regalos[0]->cantidad)->toBe(1)
        ->and($sueltas->regalos)->toBe([])
        ->and($fardos->regalos)->toBe([])
        ->and($sueltas->faltantes)->toBe([]);
});

test('el regalo en caja: por 2 CJ12 de toffee, una CJ24 de nutrine', function (): void {
    $promo = Promocion::factory()->para($this->toffee, $this->nutrine, cantidad: 2, enCaja: true, regaloEnCaja: true)->create();

    $dos = $this->motor->calcular([lineaPromo(1, $this->toffee, 2, 12)], [$promo]);
    $cinco = $this->motor->calcular([lineaPromo(1, $this->toffee, 5, 12)], [$promo]);

    expect($dos->regalos[0]->presentacion->contenido)->toBe(24)
        ->and($dos->regalos[0]->cantidad)->toBe(1)
        ->and($dos->regalos[0]->texto())->toContain('1 caja de 24 de BABY NUTRINE 24X50G')
        ->and($cinco->regalos[0]->cantidad)->toBe(2)
        ->and($promo->resumen())->toBe('Compra 2 cajas de 12 de BUTTER TOFFEE 12X100G → 1 caja de 24 de BABY NUTRINE 24X50G gratis');
});

test('mismo producto: compre 2 y lleve 1 gratis, con las 2 pagadas se agrega 1', function (): void {
    $promo = Promocion::factory()->para($this->toffee, $this->toffee, cantidad: 2, regalo: 1)->create();

    $siete = $this->motor->calcular([lineaPromo(1, $this->toffee, 7)], [$promo]);
    $una = $this->motor->calcular([lineaPromo(1, $this->toffee, 1)], [$promo]);

    // 7 pagadas = 3 grupos de 2: 3 de regalo, aparte. Ninguna de las pagadas se descuenta.
    expect($siete->regalos[0]->cantidad)->toBe(3)
        ->and($siete->descuentos)->toBe([])
        ->and($una->regalos)->toBe([]);
});

test('combo: si compra toffee y nutrine, el regalo sale', function (): void {
    $baby = Producto::factory()->precio(10.0, 18.0)->create(['descripcion' => 'BABY CREMA 12X100ML']);
    $promo = Promocion::factory()->para($this->toffee, $baby, cantidad: 2)->yCompra($this->nutrine)->create();

    $una = $this->motor->calcular([lineaPromo(1, $this->toffee, 4), lineaPromo(2, $this->nutrine, 1)], [$promo]);
    $dos = $this->motor->calcular([lineaPromo(1, $this->toffee, 4), lineaPromo(2, $this->nutrine, 2)], [$promo]);
    $sinNutrine = $this->motor->calcular([lineaPromo(1, $this->toffee, 4)], [$promo]);

    // Cuenta lo que alcance de los dos: 4 toffee dan para 2, pero 1 nutrine solo para 1.
    expect($una->regalos[0]->cantidad)->toBe(1)
        ->and($dos->regalos[0]->cantidad)->toBe(2)
        ->and($sinNutrine->regalos)->toBe([])
        ->and($promo->resumen())->toBe('Compra 2 unidades de BUTTER TOFFEE 12X100G + 1 unidad de BABY NUTRINE 24X50G → 1 unidad de BABY CREMA 12X100ML gratis');
});

test('mitad de precio: se descuenta de lo que ya trae el pedido, no se agrega solo', function (): void {
    $promo = Promocion::factory()->para($this->toffee, $this->nutrine, porcentaje: 50)->create();

    $resultado = $this->motor->calcular([lineaPromo(1, $this->toffee, 2), lineaPromo(2, $this->nutrine, 3)], [$promo]);

    // 2 toffee → 2 de los 3 nutrine a mitad: 25.30 × 2 × 50% = 25.30
    expect($resultado->descuentoDe(2)?->monto)->toBe(25.3)
        ->and($resultado->descuentoDe(2)?->unidades)->toBe(2)
        ->and($resultado->descuentoDe(2)?->promocion->id)->toBe($promo->id)
        ->and($resultado->descuentoDe(1))->toBeNull()
        ->and($resultado->regalos)->toBe([])
        ->and($resultado->faltantes)->toBe([])
        ->and($resultado->total())->toBe(25.3);
});

test('con descuento, si falta lo que se descuenta, avisa al vendedor', function (): void {
    $promo = Promocion::factory()->para($this->toffee, $this->nutrine, porcentaje: 50)->create();

    $sinRegalo = $this->motor->calcular([lineaPromo(1, $this->toffee, 3)], [$promo]);
    $conPoco = $this->motor->calcular([lineaPromo(1, $this->toffee, 3), lineaPromo(2, $this->nutrine, 1)], [$promo]);

    expect($sinRegalo->descuentos)->toBe([])
        ->and($sinRegalo->faltantes[0]->cantidad)->toBe(3)
        ->and($sinRegalo->faltantes[0]->texto())->toContain('3 unidades de BABY NUTRINE')->toContain('a mitad de precio')
        // trae 1 nutrine: ese va a mitad y faltan 2
        ->and($conPoco->descuentoDe(2)?->monto)->toBe(12.65)
        ->and($conPoco->faltantes[0]->cantidad)->toBe(2);
});

test('compre 2 y la 3a a mitad: tienen que venir las 3; si ya trae las 2 del siguiente, avisa', function (): void {
    $promo = Promocion::factory()->para($this->toffee, $this->toffee, cantidad: 2, regalo: 1, porcentaje: 50)->create();

    $siete = $this->motor->calcular([lineaPromo(1, $this->toffee, 7)], [$promo]);
    $ocho = $this->motor->calcular([lineaPromo(1, $this->toffee, 8)], [$promo]);

    // 7 = dos grupos de 3 (+1 suelto): 2 a mitad = L 30
    expect($siete->descuentoDe(1)?->monto)->toBe(30.0)
        ->and($siete->faltantes)->toBe([])
        // con 8 ya lleva las 2 del tercer grupo: le falta 1
        ->and($ocho->descuentoDe(1)?->monto)->toBe(30.0)
        ->and($ocho->faltantes)->toHaveCount(1)
        ->and($ocho->faltantes[0]->cantidad)->toBe(1)
        ->and($ocho->faltantes[0]->texto())->toContain('Agregue 1 unidad más de BUTTER TOFFEE');
});

test('una línea recibe un solo descuento, en el orden en que se aplican', function (): void {
    $mitad = Promocion::factory()->para($this->toffee, $this->nutrine, porcentaje: 50)->create();
    $treinta = Promocion::factory()->para($this->toffee, $this->nutrine, porcentaje: 30)->create();

    $resultado = $this->motor->calcular([lineaPromo(1, $this->toffee, 1), lineaPromo(2, $this->nutrine, 1)], [$mitad, $treinta]);

    expect($resultado->descuentoDe(2)?->promocion->id)->toBe($mitad->id)
        ->and($resultado->descuentoDe(2)?->monto)->toBe(12.65)
        ->and($resultado->porPromocion())->toHaveCount(1);
});

test('sin promociones la venta queda normal', function (): void {
    $resultado = $this->motor->calcular([lineaPromo(1, $this->toffee, 5)], []);

    expect($resultado->descuentos)->toBe([])
        ->and($resultado->regalos)->toBe([])
        ->and($resultado->faltantes)->toBe([])
        ->and($resultado->total())->toBe(0.0);
});

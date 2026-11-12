<?php

declare(strict_types=1);

use App\Domain\Promociones\LineaPromocion;
use App\Domain\Promociones\MotorPromociones;
use App\Models\Producto;
use App\Models\Promocion;

/**
 * El motor solo calcula: recibe líneas y promociones y dice qué línea
 * lleva cuánto de descuento, y qué le falta al pedido para aplicar.
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

    return new LineaPromocion($clave, $producto->id, $presentacion->contenido, $cantidad, $presentacion->precio);
}

test('mitad de precio: por cada toffee, un nutrine a mitad', function (): void {
    $promo = Promocion::factory()->para($this->toffee, $this->nutrine, porcentaje: 50)->create();

    $resultado = $this->motor->calcular([
        lineaPromo(1, $this->toffee, 2),
        lineaPromo(2, $this->nutrine, 3),
    ], [$promo]);

    // 2 toffee → 2 de los 3 nutrine a mitad: 25.30 × 2 × 50% = 25.30
    expect($resultado->descuentoDe(2)?->monto)->toBe(25.3)
        ->and($resultado->descuentoDe(2)?->unidades)->toBe(2)
        ->and($resultado->descuentoDe(2)?->promocion->id)->toBe($promo->id)
        ->and($resultado->descuentoDe(1))->toBeNull()
        ->and($resultado->faltantes)->toBe([])
        ->and($resultado->total())->toBe(25.3);
});

test('gratis deja la línea del regalo en cero', function (): void {
    $promo = Promocion::factory()->para($this->toffee, $this->nutrine)->create();

    $resultado = $this->motor->calcular([
        lineaPromo(1, $this->toffee, 1),
        lineaPromo(2, $this->nutrine, 1),
    ], [$promo]);

    expect($resultado->descuentoDe(2)?->monto)->toBe(25.3);
});

test('la condición por caja cuenta las unidades sueltas', function (): void {
    $promo = Promocion::factory()->para($this->toffee, $this->nutrine, enCaja: true)->create();

    $completa = $this->motor->calcular([
        lineaPromo(1, $this->toffee, 6),
        lineaPromo(2, $this->toffee, 6),
        lineaPromo(3, $this->nutrine, 1),
    ], [$promo]);

    $incompleta = $this->motor->calcular([
        lineaPromo(1, $this->toffee, 11),
        lineaPromo(3, $this->nutrine, 1),
    ], [$promo]);

    expect($completa->descuentoDe(3)?->monto)->toBe(25.3)
        ->and($incompleta->descuentos)->toBe([])
        ->and($incompleta->faltantes)->toBe([]);
});

test('si el regalo viene dentro de una caja, se descuenta solo la unidad', function (): void {
    $promo = Promocion::factory()->para($this->toffee, $this->nutrine)->create();

    $resultado = $this->motor->calcular([
        lineaPromo(1, $this->toffee, 1),
        lineaPromo(2, $this->nutrine, 1, 24),
    ], [$promo]);

    // CJ24 × 25.30 = 607.20; sale gratis 1 unidad = 25.30
    expect($resultado->descuentoDe(2)?->monto)->toBe(25.3)
        ->and($resultado->descuentoDe(2)?->unidades)->toBe(1);
});

test('la condición en una caja se llena con otra presentación del mismo producto', function (): void {
    // El toffee también viene en fardo de 6: 2 FD6 son las 12 unidades de una CJ12.
    $toffee = Producto::factory()->precio(20.0, 30.0)->conPresentacion('FD', 6)->create();
    $promo = Promocion::factory()->para($toffee, $this->nutrine, enCaja: true)->create();

    $resultado = $this->motor->calcular([
        lineaPromo(1, $toffee, 2, 6),
        lineaPromo(2, $this->nutrine, 1),
    ], [$promo]);

    expect($promo->condiciones->sole()->presentacion_id)->toBe(presentacion($toffee, 12)->id)
        ->and($resultado->descuentoDe(2)?->monto)->toBe(25.3);
});

test('el regalo en caja: por 2 CJ12 de toffee, una CJ24 de nutrine gratis', function (): void {
    $promo = Promocion::factory()->para($this->toffee, $this->nutrine, cantidad: 2, enCaja: true, regaloEnCaja: true)->create();

    $completo = $this->motor->calcular([
        lineaPromo(1, $this->toffee, 2, 12),
        lineaPromo(2, $this->nutrine, 1, 24),
    ], [$promo]);

    $sinRegalo = $this->motor->calcular([lineaPromo(1, $this->toffee, 2, 12)], [$promo]);

    // La CJ24 completa: 24 × 25.30 = 607.20.
    expect($completo->descuentoDe(2)?->monto)->toBe(607.2)
        ->and($completo->descuentoDe(2)?->unidades)->toBe(24)
        ->and($sinRegalo->faltantes[0]->texto())->toContain('1 caja de 24 de BABY NUTRINE 24X50G')
        ->and($promo->resumen())->toBe('Compra 2 cajas de 12 de BUTTER TOFFEE 12X100G → 1 caja de 24 de BABY NUTRINE 24X50G gratis');
});

test('mismo producto: compre 2 y lleve 1 gratis', function (): void {
    $promo = Promocion::factory()->para($this->toffee, $this->toffee, cantidad: 2, regalo: 1)->create();

    $siete = $this->motor->calcular([lineaPromo(1, $this->toffee, 7)], [$promo]);
    $ocho = $this->motor->calcular([lineaPromo(1, $this->toffee, 8)], [$promo]);

    // 7 = dos grupos de 3 (+1 suelto): 2 gratis = L 60
    expect($siete->descuentoDe(1)?->monto)->toBe(60.0)
        ->and($siete->faltantes)->toBe([])
        // con 8 ya lleva las 2 de la condición: le falta 1 para el siguiente regalo
        ->and($ocho->descuentoDe(1)?->monto)->toBe(60.0)
        ->and($ocho->faltantes)->toHaveCount(1)
        ->and($ocho->faltantes[0]->cantidad)->toBe(1)
        ->and($ocho->faltantes[0]->texto())->toContain('Agregue 1 unidad más de BUTTER TOFFEE');
});

test('combo: si compra toffee y nutrine, el regalo sale gratis', function (): void {
    $baby = Producto::factory()->precio(10.0, 18.0)->create(['descripcion' => 'BABY CREMA 12X100ML']);
    $promo = Promocion::factory()->para($this->toffee, $baby, cantidad: 2)->yCompra($this->nutrine)->create();

    $una = $this->motor->calcular([
        lineaPromo(1, $this->toffee, 4),
        lineaPromo(2, $this->nutrine, 1),
        lineaPromo(3, $baby, 3),
    ], [$promo]);

    $dos = $this->motor->calcular([
        lineaPromo(1, $this->toffee, 4),
        lineaPromo(2, $this->nutrine, 2),
        lineaPromo(3, $baby, 3),
    ], [$promo]);

    $sinNutrine = $this->motor->calcular([
        lineaPromo(1, $this->toffee, 4),
        lineaPromo(3, $baby, 3),
    ], [$promo]);

    // Cuenta lo que alcance de los dos: 4 toffee dan para 2, pero 1 nutrine solo para 1.
    expect($una->descuentoDe(3)?->monto)->toBe(18.0)
        ->and($una->descuentoDe(3)?->unidades)->toBe(1)
        ->and($dos->descuentoDe(3)?->monto)->toBe(36.0)
        ->and($sinNutrine->descuentos)->toBe([])
        ->and($sinNutrine->faltantes)->toBe([])
        ->and($promo->resumen())->toBe('Compra 2 unidades de BUTTER TOFFEE 12X100G + 1 unidad de BABY NUTRINE 24X50G → 1 unidad de BABY CREMA 12X100ML gratis');
});

test('combo con el regalo dentro de lo que compra', function (): void {
    // Compra 2 toffee + 1 nutrine y lleva 1 toffee más gratis.
    $promo = Promocion::factory()->para($this->toffee, $this->toffee, cantidad: 2)->yCompra($this->nutrine)->create();

    $completo = $this->motor->calcular([
        lineaPromo(1, $this->toffee, 3),
        lineaPromo(2, $this->nutrine, 1),
    ], [$promo]);

    $sinRegalo = $this->motor->calcular([
        lineaPromo(1, $this->toffee, 2),
        lineaPromo(2, $this->nutrine, 1),
    ], [$promo]);

    expect($completo->descuentoDe(1)?->monto)->toBe(30.0)
        ->and($completo->faltantes)->toBe([])
        ->and($sinRegalo->descuentos)->toBe([])
        ->and($sinRegalo->faltantes[0]->cantidad)->toBe(1)
        ->and($sinRegalo->faltantes[0]->texto())->toContain('Agregue 1 unidad más de BUTTER TOFFEE');
});

test('si falta el producto de regalo, avisa al vendedor', function (): void {
    $promo = Promocion::factory()->para($this->toffee, $this->nutrine, porcentaje: 50)->create();

    $sinRegalo = $this->motor->calcular([lineaPromo(1, $this->toffee, 3)], [$promo]);

    $conPoco = $this->motor->calcular([
        lineaPromo(1, $this->toffee, 3),
        lineaPromo(2, $this->nutrine, 1),
    ], [$promo]);

    expect($sinRegalo->descuentos)->toBe([])
        ->and($sinRegalo->faltantes[0]->cantidad)->toBe(3)
        ->and($sinRegalo->faltantes[0]->texto())->toContain('3 unidades de BABY NUTRINE')->toContain('a mitad de precio')
        // trae 1 nutrine: ese va a mitad y faltan 2
        ->and($conPoco->descuentoDe(2)?->monto)->toBe(12.65)
        ->and($conPoco->faltantes[0]->cantidad)->toBe(2);
});

test('una línea recibe una sola promoción, en el orden en que se aplican', function (): void {
    $gratis = Promocion::factory()->para($this->toffee, $this->nutrine)->create();
    $mitad = Promocion::factory()->para($this->toffee, $this->nutrine, porcentaje: 50)->create();

    $resultado = $this->motor->calcular([
        lineaPromo(1, $this->toffee, 1),
        lineaPromo(2, $this->nutrine, 1),
    ], [$gratis, $mitad]);

    expect($resultado->descuentoDe(2)?->promocion->id)->toBe($gratis->id)
        ->and($resultado->descuentoDe(2)?->monto)->toBe(25.3)
        ->and($resultado->porPromocion())->toHaveCount(1);
});

test('sin promociones la venta queda normal', function (): void {
    $resultado = $this->motor->calcular([lineaPromo(1, $this->toffee, 5)], []);

    expect($resultado->descuentos)->toBe([])
        ->and($resultado->faltantes)->toBe([])
        ->and($resultado->total())->toBe(0.0);
});

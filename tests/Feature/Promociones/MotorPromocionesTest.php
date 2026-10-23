<?php

declare(strict_types=1);

use App\Domain\Promociones\LineaPromocion;
use App\Domain\Promociones\MotorPromociones;
use App\Enums\UnidadVenta;
use App\Models\Producto;
use App\Models\Promocion;

/**
 * El motor solo calcula: recibe líneas y promociones y dice qué línea
 * lleva cuánto de descuento, y qué le falta al pedido para aplicar.
 */
beforeEach(function (): void {
    // Toffee: L 30 la unidad, caja de 12. Nutrine: L 25.30 la unidad, caja de 24.
    $this->toffee = Producto::factory()->precio(20.0, 30.0)->create(['unidades_por_caja' => 12, 'descripcion' => 'BUTTER TOFFEE 12X100G']);
    $this->nutrine = Producto::factory()->precio(20.0, 25.30)->create(['unidades_por_caja' => 24, 'descripcion' => 'BABY NUTRINE 24X50G']);
    $this->motor = new MotorPromociones;
});

function lineaPromo(int $clave, Producto $producto, int $cantidad, UnidadVenta $unidad = UnidadVenta::Unidad): LineaPromocion
{
    return new LineaPromocion($clave, $producto->id, $producto->unidades_por_caja, $unidad, $cantidad, $producto->precioPara($unidad));
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
    $promo = Promocion::factory()->para($this->toffee, $this->nutrine)->create(['condicion_unidad' => UnidadVenta::Caja]);

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
        lineaPromo(2, $this->nutrine, 1, UnidadVenta::Caja),
    ], [$promo]);

    // Caja de 24 × 25.30 = 607.20; sale gratis 1 unidad = 25.30
    expect($resultado->descuentoDe(2)?->monto)->toBe(25.3)
        ->and($resultado->descuentoDe(2)?->unidades)->toBe(1);
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

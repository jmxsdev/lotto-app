<?php

namespace Lotto\Kernel\Autorizacion\Domain;

use Lotto\Kernel\Jerarquia\Domain\CatalogoNiveles;

final readonly class PerfilProducto
{
    public function __construct(
        public string $codigo,
        public CatalogoNiveles $niveles,
        public CatalogoCapacidades $capacidades,
        public MatrizRolCapacidad $matriz,
        public bool $superMasterBypass = true,
    ) {
        if ($codigo === '') {
            throw new \InvalidArgumentException('El código de producto no puede estar vacío.');
        }
    }
}

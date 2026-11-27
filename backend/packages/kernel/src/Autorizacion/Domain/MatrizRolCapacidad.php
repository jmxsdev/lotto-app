<?php

namespace Lotto\Kernel\Autorizacion\Domain;

use Lotto\Kernel\Identidad\Domain\Rol;

final class MatrizRolCapacidad
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $matriz = [];

    /**
     * @param  array<string, array<int, string>>  $matriz
     */
    public function __construct(
        private readonly CatalogoCapacidades $catalogo,
        array $matriz,
    ) {
        foreach ($matriz as $rolCodigo => $capacidades) {
            try {
                $rol = Rol::desde((string) $rolCodigo);
            } catch (\ValueError) {
                throw new \InvalidArgumentException("Rol desconocido en la matriz: {$rolCodigo}");
            }

            $codigos = [];

            foreach ($capacidades as $capacidad) {
                if (! $this->catalogo->tiene($capacidad)) {
                    throw new \InvalidArgumentException(
                        "Capacidad desconocida en el rol {$rol->value}: {$capacidad}",
                    );
                }

                $codigos[] = $capacidad;
            }

            $this->matriz[$rol->value] = array_values(array_unique($codigos));
        }
    }

    /**
     * @param  array<string, array<int, string>>  $matriz
     */
    public static function desde(CatalogoCapacidades $catalogo, array $matriz): self
    {
        return new self($catalogo, $matriz);
    }

    /**
     * @return array<int, string>
     */
    public function capacidadesDe(Rol|string $rol): array
    {
        $codigo = $rol instanceof Rol ? $rol->value : $rol;

        return $this->matriz[$codigo] ?? [];
    }

    public function tiene(Rol|string $rol, string $capacidad): bool
    {
        return in_array($capacidad, $this->capacidadesDe($rol), true);
    }

    /**
     * @return array<int, string>
     */
    public function roles(): array
    {
        return array_keys($this->matriz);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function matriz(): array
    {
        return $this->matriz;
    }
}

<?php

namespace Lotto\Kernel\Jerarquia\Domain;

use Lotto\Kernel\Identidad\Domain\Nivel;

final class CatalogoNiveles implements \Countable
{
    /**
     * @param  array<int, Nivel>  $niveles
     */
    private function __construct(private readonly array $niveles) {}

    public static function seisNiveles(): self
    {
        return new self(Nivel::cases());
    }

    /**
     * @return array<int, Nivel>
     */
    public function todos(): array
    {
        return $this->niveles;
    }

    /**
     * @return array<int, string>
     */
    public function codigos(): array
    {
        return array_map(fn (Nivel $nivel): string => $nivel->value, $this->niveles);
    }

    public function porCodigo(string $codigo): Nivel
    {
        foreach ($this->niveles as $nivel) {
            if ($nivel->value === $codigo) {
                return $nivel;
            }
        }

        throw new \InvalidArgumentException("Nivel desconocido en el catálogo: {$codigo}");
    }

    /**
     * @return array<int, Nivel>
     */
    public function raices(): array
    {
        return array_values(array_filter(
            $this->niveles,
            fn (Nivel $nivel): bool => $nivel->padre() === null,
        ));
    }

    /**
     * @return array<int, Nivel>
     */
    public function hijosDe(Nivel $nivel): array
    {
        return array_values(array_filter(
            $this->niveles,
            fn (Nivel $candidato): bool => $candidato->padre() === $nivel,
        ));
    }

    public function count(): int
    {
        return count($this->niveles);
    }
}

<?php

namespace Lotto\Kernel\Autorizacion\Domain;

final class CatalogoCapacidades
{
    /**
     * @var array<string, Capacidad>
     */
    private array $capacidades = [];

    public function __construct(Capacidad ...$capacidades)
    {
        foreach ($capacidades as $capacidad) {
            if (isset($this->capacidades[$capacidad->codigo])) {
                throw new \InvalidArgumentException("Capacidad duplicada: {$capacidad->codigo}");
            }

            $this->capacidades[$capacidad->codigo] = $capacidad;
        }
    }

    /**
     * @param  array<int, string>  $codigos
     */
    public static function desde(array $codigos): self
    {
        return new self(...array_map(
            fn (string $codigo): Capacidad => new Capacidad($codigo),
            array_values($codigos),
        ));
    }

    /**
     * @return array<int, Capacidad>
     */
    public function todas(): array
    {
        return array_values($this->capacidades);
    }

    /**
     * @return array<int, string>
     */
    public function codigos(): array
    {
        return array_keys($this->capacidades);
    }

    public function tiene(string $codigo): bool
    {
        return isset($this->capacidades[$codigo]);
    }

    public function obtener(string $codigo): Capacidad
    {
        return $this->capacidades[$codigo]
            ?? throw new \InvalidArgumentException("Capacidad desconocida: {$codigo}");
    }

    public function count(): int
    {
        return count($this->capacidades);
    }
}

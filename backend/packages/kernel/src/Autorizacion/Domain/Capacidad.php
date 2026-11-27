<?php

namespace Lotto\Kernel\Autorizacion\Domain;

final readonly class Capacidad
{
    public function __construct(public string $codigo)
    {
        if ($codigo === '' || trim($codigo) !== $codigo) {
            throw new \InvalidArgumentException(
                'El código de capacidad no puede estar vacío ni tener espacios extremos.',
            );
        }
    }

    public static function desde(string $codigo): self
    {
        return new self($codigo);
    }

    public function es(string $codigo): bool
    {
        return $this->codigo === $codigo;
    }

    public function __toString(): string
    {
        return $this->codigo;
    }
}

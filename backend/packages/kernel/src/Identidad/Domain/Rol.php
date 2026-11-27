<?php

namespace Lotto\Kernel\Identidad\Domain;

enum Rol: string
{
    case SuperMaster = 'super_master';
    case Master = 'master';
    case Banca = 'banca';
    case Grupo = 'grupo';
    case Agencia = 'agencia';
    case Taquilla = 'taquilla';

    public function nivel(): Nivel
    {
        return Nivel::from($this->value);
    }

    public function codigo(): string
    {
        return $this->value;
    }

    public static function desde(string $codigo): self
    {
        return self::from($codigo);
    }
}

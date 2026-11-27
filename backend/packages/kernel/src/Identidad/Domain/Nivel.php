<?php

namespace Lotto\Kernel\Identidad\Domain;

/**
 * Tipo de nodo de la jerarquía. En el Modelo B todos los niveles son
 * entidades; `tablaEntidad()` da su tabla. El catálogo de niveles y la
 * relación de hijos viven en `CatalogoNiveles`.
 */
enum Nivel: string
{
    case SuperMaster = 'super_master';
    case Master = 'master';
    case Banca = 'banca';
    case Grupo = 'grupo';
    case Agencia = 'agencia';
    case Taquilla = 'taquilla';

    public function rango(): int
    {
        return match ($this) {
            self::SuperMaster => 0,
            self::Master => 1,
            self::Banca => 2,
            self::Grupo => 3,
            self::Agencia => 4,
            self::Taquilla => 5,
        };
    }

    /**
     * Poder jerárquico: mayor número = mayor jerarquía.
     *
     * Es el inverso de `rango()`, que expresa profundidad en el árbol.
     */
    public function jerarquia(): int
    {
        return 5 - $this->rango();
    }

    public function padre(): ?self
    {
        return match ($this) {
            self::SuperMaster => null,
            self::Master => self::SuperMaster,
            self::Banca => self::Master,
            self::Grupo => self::Banca,
            self::Agencia => self::Grupo,
            self::Taquilla => self::Agencia,
        };
    }

    /**
     * Tabla de entidad del nivel. Todos los niveles son entidades (Modelo B).
     */
    public function tablaEntidad(): string
    {
        return match ($this) {
            self::SuperMaster => 'super_masters',
            self::Master => 'masters',
            self::Banca => 'bancas',
            self::Grupo => 'grupos',
            self::Agencia => 'agencias',
            self::Taquilla => 'taquillas',
        };
    }

    public function codigo(): string
    {
        return $this->value;
    }
}

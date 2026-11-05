<?php

namespace App\Audit;

/**
 * Immutable denormalized hierarchy scope of an audit event.
 *
 * A `null` value means "not scoped to that level"; `null` on every level means
 * a global event (for example exchange rates or releases).
 */
final readonly class AuditScope
{
    public function __construct(
        public ?int $bancaId = null,
        public ?int $grupoId = null,
        public ?int $agenciaId = null,
        public ?int $taquillaId = null,
    ) {}

    /**
     * Merge scopes; non-null values from later scopes win.
     */
    public function merge(self ...$others): self
    {
        $merged = $this;

        foreach ($others as $other) {
            $merged = new self(
                bancaId: $other->bancaId ?? $merged->bancaId,
                grupoId: $other->grupoId ?? $merged->grupoId,
                agenciaId: $other->agenciaId ?? $merged->agenciaId,
                taquillaId: $other->taquillaId ?? $merged->taquillaId,
            );
        }

        return $merged;
    }

    public function isEmpty(): bool
    {
        return $this->bancaId === null
            && $this->grupoId === null
            && $this->agenciaId === null
            && $this->taquillaId === null;
    }

    /**
     * @return array{banca_id: int|null, grupo_id: int|null, agencia_id: int|null, taquilla_id: int|null}
     */
    public function toArray(): array
    {
        return [
            'banca_id' => $this->bancaId,
            'grupo_id' => $this->grupoId,
            'agencia_id' => $this->agenciaId,
            'taquilla_id' => $this->taquillaId,
        ];
    }
}

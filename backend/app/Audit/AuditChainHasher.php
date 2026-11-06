<?php

namespace App\Audit;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonException;
use RuntimeException;

/**
 * Canonical JSON encoding and optional hash chain for audit rows.
 *
 * Pure functions: no database access and no mutable state. Keys are sorted
 * recursively so the same logical row always yields the same bytes regardless
 * of insertion order; the hash is `sha256(prev_hash . "\n" . canonical_json)`.
 */
final class AuditChainHasher
{
    /**
     * @param  array<string, mixed>  $row
     */
    public function canonicalJson(array $row): string
    {
        try {
            return json_encode(
                $this->canonicalize($row),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the audit row as canonical JSON.', 0, $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function hash(?string $prevHash, array $row): string
    {
        return hash('sha256', ($prevHash ?? '')."\n".$this->canonicalJson($row));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function verify(?string $prevHash, array $row, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->hash($prevHash, $row));
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! $this->isAssociative($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function isAssociative(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }
}

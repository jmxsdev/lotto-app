<?php

namespace App\Audit\Casts;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores `occurred_at` as a UTC DATETIME string and returns DateTimeImmutable.
 *
 * Works with both MySQL DATETIME and SQLite TEXT columns.
 *
 * @implements CastsAttributes<DateTimeImmutable, DateTimeInterface|string>
 */
final class UtcDateTime implements CastsAttributes
{
    public const STORAGE_FORMAT = 'Y-m-d H:i:s';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::toUtc($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::toUtc($value)->format(self::STORAGE_FORMAT);
    }

    private static function toUtc(mixed $value): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone($utc);
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($utc);
        }

        return (new DateTimeImmutable((string) $value, $utc))->setTimezone($utc);
    }
}

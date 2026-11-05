<?php

namespace Tests\Unit\Audit;

use App\Audit\Casts\UtcDateTime;
use App\Models\AuditEvent;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Tests\TestCase;

class UtcDateTimeTest extends TestCase
{
    private UtcDateTime $cast;

    private AuditEvent $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cast = new UtcDateTime;
        $this->model = new AuditEvent;
    }

    public function test_set_convierte_datetime_a_utc_y_guarda_formato_de_base_de_datos(): void
    {
        $caracas = new DateTimeImmutable('2026-11-05 10:00:00', new DateTimeZone('America/Caracas'));

        $this->assertSame(
            '2026-11-05 14:00:00',
            $this->cast->set($this->model, 'occurred_at', $caracas, []),
        );
    }

    public function test_set_acepta_string_iso_8601_con_zulu(): void
    {
        $this->assertSame(
            '2026-11-05 15:04:05',
            $this->cast->set($this->model, 'occurred_at', '2026-11-05T15:04:05Z', []),
        );
    }

    public function test_set_acepta_string_utc_plano(): void
    {
        $this->assertSame(
            '2026-11-05 15:04:05',
            $this->cast->set($this->model, 'occurred_at', '2026-11-05 15:04:05', []),
        );
    }

    public function test_get_devuelve_datetime_immutable_en_utc(): void
    {
        $value = $this->cast->get($this->model, 'occurred_at', '2026-11-05 15:04:05', []);

        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame('UTC', $value->getTimezone()->getName());
        $this->assertSame('2026-11-05T15:04:05+00:00', $value->format(DateTimeInterface::ATOM));
    }

    public function test_get_convierte_un_offset_a_utc(): void
    {
        $caracas = new DateTimeImmutable('2026-11-05 10:00:00', new DateTimeZone('America/Caracas'));

        $value = $this->cast->get($this->model, 'occurred_at', $caracas, []);

        $this->assertSame('2026-11-05 14:00:00', $value->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $value->getTimezone()->getName());
    }

    public function test_nulos_y_vacios_se_preservan(): void
    {
        $this->assertNull($this->cast->get($this->model, 'occurred_at', null, []));
        $this->assertNull($this->cast->get($this->model, 'occurred_at', '', []));
        $this->assertNull($this->cast->set($this->model, 'occurred_at', null, []));
        $this->assertNull($this->cast->set($this->model, 'occurred_at', '', []));
    }
}

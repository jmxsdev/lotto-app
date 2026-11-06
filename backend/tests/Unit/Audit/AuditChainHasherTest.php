<?php

namespace Tests\Unit\Audit;

use App\Audit\AuditChainHasher;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

class AuditChainHasherTest extends TestCase
{
    private AuditChainHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hasher = new AuditChainHasher;
    }

    public function test_canonical_json_ordena_las_claves_recursivamente(): void
    {
        $rowA = ['b' => 2, 'a' => ['d' => 4, 'c' => 3]];
        $rowB = ['a' => ['c' => 3, 'd' => 4], 'b' => 2];

        $this->assertSame('{"a":{"c":3,"d":4},"b":2}', $this->hasher->canonicalJson($rowA));
        $this->assertSame($this->hasher->canonicalJson($rowA), $this->hasher->canonicalJson($rowB));
    }

    public function test_canonical_json_no_tiene_espacios(): void
    {
        $json = $this->hasher->canonicalJson(['a' => 1, 'b' => ['c' => 2]]);

        $this->assertStringNotContainsString(': ', $json);
        $this->assertStringNotContainsString(', ', $json);
    }

    public function test_canonical_json_conserva_unicode_y_slashes(): void
    {
        $json = $this->hasher->canonicalJson([
            'url' => 'https://example.com/a/b',
            'text' => 'ñandú',
        ]);

        $this->assertSame('{"text":"ñandú","url":"https://example.com/a/b"}', $json);
        $this->assertStringNotContainsString('\\/', $json);
        $this->assertStringNotContainsString('\\u00f1', $json);
    }

    public function test_canonical_json_respeta_el_orden_de_las_listas(): void
    {
        $json = $this->hasher->canonicalJson([
            'list' => [3, 1, 2],
            'nested' => [['y' => 2, 'x' => 1]],
        ]);

        $this->assertSame('{"list":[3,1,2],"nested":[{"x":1,"y":2}]}', $json);
    }

    public function test_canonical_json_normaliza_fechas_a_iso_utc(): void
    {
        $json = $this->hasher->canonicalJson([
            'occurred_at' => new DateTimeImmutable('2026-11-05 10:30:00', new DateTimeZone('America/Caracas')),
        ]);

        $this->assertSame('{"occurred_at":"2026-11-05T14:30:00Z"}', $json);
    }

    public function test_el_hash_depende_del_prev_hash(): void
    {
        $row = ['action' => 'banca.updated', 'id' => 5];
        $prev = str_repeat('a', 64);
        $other = str_repeat('b', 64);

        $this->assertNotSame($this->hasher->hash($prev, $row), $this->hasher->hash($other, $row));
        $this->assertSame($this->hasher->hash($prev, $row), $this->hasher->hash($prev, $row));
    }

    public function test_el_hash_es_sha256_del_prev_mas_salto_de_linea_mas_canonical_json(): void
    {
        $prev = str_repeat('a', 64);
        $row = ['b' => 2, 'a' => 1];

        $this->assertSame(
            hash('sha256', $prev."\n".$this->hasher->canonicalJson($row)),
            $this->hasher->hash($prev, $row),
        );
    }

    public function test_el_hash_con_prev_null_usa_cadena_vacia(): void
    {
        $row = ['id' => 5];

        $this->assertSame(
            hash('sha256', "\n".$this->hasher->canonicalJson($row)),
            $this->hasher->hash(null, $row),
        );
    }

    public function test_verify_acepta_el_hash_correcto_y_rechaza_manipulaciones(): void
    {
        $prev = str_repeat('a', 64);
        $row = ['id' => 1, 'action' => 'user.deleted'];
        $hash = $this->hasher->hash($prev, $row);

        $this->assertTrue($this->hasher->verify($prev, $row, $hash));
        $this->assertFalse($this->hasher->verify($prev, ['id' => 2] + $row, $hash));
        $this->assertFalse($this->hasher->verify(str_repeat('b', 64), $row, $hash));
        $this->assertFalse($this->hasher->verify($prev, $row, str_repeat('0', 64)));
    }
}

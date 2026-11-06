<?php

namespace Tests\Unit\Audit;

use App\Audit\AuditRedactor;
use Tests\TestCase;

class AuditRedactorTest extends TestCase
{
    private AuditRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redactor = new AuditRedactor;
    }

    public function test_redacta_password_token_y_clave_cierre(): void
    {
        $result = $this->redactor->redact([
            'password' => 'super-secret',
            'password_confirmation' => 'super-secret',
            'token' => 'abc123',
            'clave_cierre' => '9876',
            'name' => 'Banca Norte',
        ]);

        $this->assertSame(AuditRedactor::REDACTED, $result['password']);
        $this->assertSame(AuditRedactor::REDACTED, $result['password_confirmation']);
        $this->assertSame(AuditRedactor::REDACTED, $result['token']);
        $this->assertSame(AuditRedactor::REDACTED, $result['clave_cierre']);
        $this->assertSame('Banca Norte', $result['name']);
    }

    public function test_redacta_claves_sensibles_adicionales(): void
    {
        $result = $this->redactor->redact([
            'clave_actual' => 'a',
            'clave_nueva' => 'b',
            'refresh_token' => 'c',
            'api_key' => 'd',
            'authorization' => 'Bearer e',
        ]);

        foreach ($result as $value) {
            $this->assertSame(AuditRedactor::REDACTED, $value);
        }
    }

    public function test_redacta_en_anidados_y_preserva_estructura(): void
    {
        $result = $this->redactor->redact([
            'user' => ['email' => 'jdoe@example.com', 'password' => 'x'],
            'items' => [
                ['token' => 't'],
                ['ok' => 'v'],
            ],
        ]);

        $this->assertSame('j***@example.com', $result['user']['email']);
        $this->assertSame(AuditRedactor::REDACTED, $result['user']['password']);
        $this->assertSame(AuditRedactor::REDACTED, $result['items'][0]['token']);
        $this->assertSame('v', $result['items'][1]['ok']);
    }

    public function test_enmascara_email(): void
    {
        $this->assertSame('j***@example.com', $this->redactor->maskEmail('jdoe@example.com'));
        $this->assertSame('a***@b.co', $this->redactor->maskEmail('a@b.co'));
        $this->assertNull($this->redactor->maskEmail(null));
        $this->assertNull($this->redactor->maskEmail('   '));

        $masked = $this->redactor->maskEmail('jdoe@example.com');

        $this->assertSame($masked, $this->redactor->maskEmail($masked));
    }

    public function test_enmascara_mac_con_dos_separadores(): void
    {
        $this->assertSame('AA:BB:CC:**:**:**', $this->redactor->maskMac('AA:BB:CC:DD:EE:FF'));
        $this->assertSame('AA-BB-CC-**-**-**', $this->redactor->maskMac('AA-BB-CC-DD-EE-FF'));
        $this->assertNull($this->redactor->maskMac(null));
        $this->assertNull($this->redactor->maskMac('   '));

        $masked = $this->redactor->maskMac('AA:BB:CC:DD:EE:FF');

        $this->assertSame($masked, $this->redactor->maskMac($masked));
    }

    public function test_hash_fingerprint_es_determinista(): void
    {
        $expected = hash('sha256', 'device-1');

        $this->assertSame($expected, $this->redactor->hashFingerprint('device-1'));
        $this->assertSame($expected, $this->redactor->hashFingerprint('device-1'));
        $this->assertNotSame($expected, $this->redactor->hashFingerprint('device-2'));
        $this->assertSame($expected, $this->redactor->hashFingerprint($expected));
        $this->assertNull($this->redactor->hashFingerprint(null));
    }

    public function test_mascara_codigo_de_activacion(): void
    {
        $this->assertSame('****1234', $this->redactor->redact(['activation_code' => 'ABCD1234'])['activation_code']);
        $this->assertSame('****', $this->redactor->redact(['activation_code' => 'ABCD'])['activation_code']);
    }

    public function test_redact_reason_limpia_secretos_controles_y_longitud(): void
    {
        $text = "  token=abc123   Bearer xyz.987\npassword=supersecret ".str_repeat('z', 600).'  ';

        $result = $this->redactor->redactReason($text);

        $this->assertIsString($result);
        $this->assertStringNotContainsString('abc123', $result);
        $this->assertStringNotContainsString('xyz.987', $result);
        $this->assertStringNotContainsString('supersecret', $result);
        $this->assertStringNotContainsString("\n", $result);
        $this->assertLessThanOrEqual(500, mb_strlen($result));
    }

    public function test_redact_reason_elimina_hex_largos(): void
    {
        $hex = str_repeat('a', 40);

        $result = $this->redactor->redactReason('hash '.$hex);

        $this->assertStringNotContainsString($hex, (string) $result);
        $this->assertNull($this->redactor->redactReason(null));
        $this->assertNull($this->redactor->redactReason('   '));
    }

    public function test_redacta_las_claves_especiales_del_payload(): void
    {
        $result = $this->redactor->redact([
            'email' => 'jdoe@example.com',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'device-1',
            'activation_code' => 'ABCD1234',
        ]);

        $this->assertSame('j***@example.com', $result['email']);
        $this->assertSame('AA:BB:CC:**:**:**', $result['mac_address']);
        $this->assertSame(hash('sha256', 'device-1'), $result['device_fingerprint']);
        $this->assertSame('****1234', $result['activation_code']);
    }

    public function test_no_toca_claves_no_sensibles(): void
    {
        $payload = [
            'name' => 'Banca Norte',
            'code' => 'B-01',
            'active' => true,
            'amount' => 100.5,
        ];

        $this->assertSame($payload, $this->redactor->redact($payload));
    }

    public function test_redact_es_idempotente(): void
    {
        $payload = [
            'email' => 'jdoe@example.com',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'device-1',
            'activation_code' => 'ABCD1234',
            'password' => 'secret',
            'reason_text' => 'token=abc',
        ];

        $once = $this->redactor->redact($payload);
        $twice = $this->redactor->redact($once);

        $this->assertSame($once, $twice);
    }

    public function test_redact_value_respeta_la_clave(): void
    {
        $this->assertSame('Banca', $this->redactor->redactValue('Banca', 'name'));
        $this->assertSame(AuditRedactor::REDACTED, $this->redactor->redactValue('x', 'password'));
        $this->assertSame('j***@example.com', $this->redactor->redactValue('jdoe@example.com', 'email'));
    }
}

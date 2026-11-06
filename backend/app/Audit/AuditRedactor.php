<?php

namespace App\Audit;

/**
 * Deterministic PII and secret redaction for audit payloads.
 *
 * The subject payload keeps its shape: sensitive keys are replaced at the leaf
 * while every other key and list position is preserved. The same input always
 * produces the same output, and applying the redactor twice is a no-op, which
 * makes it safe to use both on write and on read.
 */
final class AuditRedactor
{
    public const REDACTED = '[REDACTED]';

    public const MASK = self::REDACTED;

    public const MAX_DEPTH = 5;

    public const MAX_KEYS = 100;

    public const MAX_STRING = 2000;

    /**
     * Keys that must never reach the audit trail, on top of
     * `config('audit.pii.keys')`.
     *
     * @var array<int, string>
     */
    private const DEFAULT_SECRET_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'clave_actual',
        'clave_nueva',
        'clave_cierre',
        'token',
        'access_token',
        'refresh_token',
        'remember_token',
        'secret',
        'api_key',
        'authorization',
    ];

    /**
     * @var array<int, string>|null
     */
    private ?array $secretKeysCache = null;

    /**
     * @param  array<string|int, mixed>  $payload
     * @return array<string|int, mixed>
     */
    public function redact(array $payload): array
    {
        return $this->redactArray($payload, 0);
    }

    public function redactValue(mixed $value, ?string $key = null): mixed
    {
        return $this->redactEntry($value, $key, 0);
    }

    public function maskEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = trim($email);

        if ($email === '') {
            return null;
        }

        $at = strrpos($email, '@');

        if ($at === false) {
            return $email;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        if ($local === '' || str_contains($local, '*')) {
            return $email;
        }

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    public function maskMac(?string $mac): ?string
    {
        if ($mac === null) {
            return null;
        }

        $mac = trim($mac);

        if ($mac === '') {
            return null;
        }

        $separator = str_contains($mac, ':') ? ':' : (str_contains($mac, '-') ? '-' : null);

        if ($separator === null) {
            return $mac;
        }

        $parts = explode($separator, $mac);

        if (in_array('**', $parts, true)) {
            return $mac;
        }

        $visible = count($parts) >= 4 ? 3 : max(0, count($parts) - 1);

        foreach ($parts as $index => $part) {
            if ($index >= $visible) {
                $parts[$index] = '**';
            }
        }

        return implode($separator, $parts);
    }

    public function hashFingerprint(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $value) === 1) {
            return strtolower($value);
        }

        return hash('sha256', $value);
    }

    public function redactReason(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $max = max(0, (int) config('audit.reasons.text_max', 500));

        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);

        if ($text === null) {
            return null;
        }

        $text = preg_replace('/\s+/u', ' ', $text);

        if ($text === null) {
            return null;
        }

        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $text = preg_replace('/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/iu', 'Bearer [REDACTED]', $text);
        $text = $text !== null
            ? preg_replace('/\b(password|passwd|clave|token|secret|api[_-]?key|authorization)\s*[:=]\s*\S+/iu', '$1=[REDACTED]', $text)
            : null;
        $text = $text !== null
            ? preg_replace('/\b[a-f0-9]{32,}\b/iu', '[REDACTED]', $text)
            : null;

        if ($text === null) {
            return null;
        }

        $text = trim($text);

        return $text !== '' ? mb_substr($text, 0, $max) : null;
    }

    /**
     * @param  array<string|int, mixed>  $payload
     * @return array<string|int, mixed>
     */
    private function redactArray(array $payload, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $result = [];
        $count = 0;

        foreach ($payload as $key => $value) {
            if ($count >= self::MAX_KEYS) {
                break;
            }

            $result[$key] = $this->redactEntry($value, is_string($key) ? $key : null, $depth + 1);
            $count++;
        }

        return $result;
    }

    private function redactEntry(mixed $value, ?string $key, int $depth): mixed
    {
        $normalized = $key !== null ? strtolower(trim($key)) : null;

        if ($normalized !== null && is_string($value)) {
            if ($normalized === 'email' || $normalized === 'actor_email') {
                return $this->maskEmail($value) ?? $value;
            }

            if ($normalized === 'actor_label' && str_contains($value, '@')) {
                return $this->maskEmail($value) ?? $value;
            }

            if ($normalized === 'mac_address' || $normalized === 'mac' || $normalized === 'device_mac') {
                return $this->maskMac($value) ?? $value;
            }

            if ($normalized === 'device_fingerprint' || $normalized === 'fingerprint') {
                return $this->hashFingerprint($value) ?? $value;
            }

            if ($normalized === 'activation_code') {
                return $this->maskActivationCode($value) ?? $value;
            }

            if ($normalized === 'reason_text') {
                return $this->redactReason($value);
            }
        }

        if ($normalized !== null && in_array($normalized, $this->secretKeys(), true)) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            return $this->redactArray($value, $depth);
        }

        return is_string($value) ? mb_substr($value, 0, self::MAX_STRING) : $value;
    }

    private function maskActivationCode(string $code): ?string
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        if (str_starts_with($code, '****')) {
            return $code;
        }

        if (mb_strlen($code) <= 4) {
            return '****';
        }

        return '****'.mb_substr($code, -4);
    }

    /**
     * @return array<int, string>
     */
    private function secretKeys(): array
    {
        if ($this->secretKeysCache !== null) {
            return $this->secretKeysCache;
        }

        $configured = (array) config('audit.pii.keys', []);
        $configured = array_map(static fn (mixed $key): string => strtolower((string) $key), $configured);

        return $this->secretKeysCache = array_values(array_unique(
            array_merge(self::DEFAULT_SECRET_KEYS, $configured),
        ));
    }
}

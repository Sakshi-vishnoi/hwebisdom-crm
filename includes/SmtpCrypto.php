<?php

declare(strict_types=1);

/**
 * SmtpCrypto
 *
 * AES-256-GCM encryption and decryption helper for per-user SMTP passwords.
 * Ensures credentials are never stored in plain text and are decrypted only in memory during sending.
 */
class SmtpCrypto
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12; // 96 bits recommended for GCM
    private const TAG_LENGTH = 16; // 128 bits authentication tag
    public const MASKED_PLACEHOLDER = '••••••••';

    /**
     * Retrieve the 32-byte binary key from environment.
     *
     * @throws RuntimeException if the key is missing or invalid.
     */
    public static function getKey(): string
    {
        $raw = getenv('SMTP_ENCRYPTION_KEY');
        if (!$raw && isset($_ENV['SMTP_ENCRYPTION_KEY'])) {
            $raw = $_ENV['SMTP_ENCRYPTION_KEY'];
        }
        if (!$raw && isset($_SERVER['SMTP_ENCRYPTION_KEY'])) {
            $raw = $_SERVER['SMTP_ENCRYPTION_KEY'];
        }

        $raw = trim((string)$raw);
        if ($raw === '') {
            throw new RuntimeException('SMTP_ENCRYPTION_KEY is not defined in .env. Generate one using: php -r "echo bin2hex(random_bytes(32));"');
        }

        // If provided as 64 hex characters, convert to 32 bytes
        if (strlen($raw) === 64 && ctype_xdigit($raw)) {
            $binary = hex2bin($raw);
            if ($binary !== false) {
                return $binary;
            }
        }

        // If provided as 32-byte raw binary string
        if (strlen($raw) === 32) {
            return $raw;
        }

        // If provided as base64 encoded string
        $b64 = base64_decode($raw, true);
        if ($b64 !== false && strlen($b64) === 32) {
            return $b64;
        }

        // Fallback: derive 32 bytes via SHA-256 hash if custom passphrase is provided
        return hash('sha256', $raw, true);
    }

    /**
     * Encrypt a plain-text password using AES-256-GCM.
     * Stored format: base64( 12-byte IV + 16-byte Auth Tag + Ciphertext )
     *
     * @param string $plainText
     * @return string
     * @throws RuntimeException
     */
    public static function encrypt(string $plainText): string
    {
        if ($plainText === '') {
            return '';
        }

        $key = self::getKey();
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plainText,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '', // aad
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Failed to encrypt SMTP credentials.');
        }

        // Pack IV + Tag + Ciphertext into binary and base64-encode
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt an AES-256-GCM encrypted payload back into memory.
     *
     * @param string $payload Base64-encoded encrypted string
     * @return string Plain-text password
     * @throws RuntimeException
     */
    public static function decrypt(string $payload): string
    {
        $payload = trim($payload);
        if ($payload === '') {
            return '';
        }

        $key = self::getKey();
        $binary = base64_decode($payload, true);

        $minLen = self::IV_LENGTH + self::TAG_LENGTH;
        if ($binary === false || strlen($binary) < $minLen) {
            throw new RuntimeException('Corrupted or invalid encrypted payload.');
        }

        $iv = substr($binary, 0, self::IV_LENGTH);
        $tag = substr($binary, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($binary, $minLen);

        $plain = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plain === false) {
            throw new RuntimeException('Failed to decrypt SMTP credentials (invalid key or tampered payload).');
        }

        return $plain;
    }

    /**
     * Returns true if the password field is a masked placeholder (meaning "keep existing password").
     */
    public static function isMasked(string $input): bool
    {
        $trimmed = trim($input);
        return $trimmed === self::MASKED_PLACEHOLDER || $trimmed === '********' || $trimmed === '';
    }

    /**
     * Sanitizes email headers (From, To, Subject) against CRLF injection attacks.
     */
    public static function sanitizeHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n", "%0a", "%0d", "%0A", "%0D"], '', $value));
    }

    /**
     * Redacts potential sensitive secrets (passwords, base64 auth tokens) from error strings or logs.
     */
    public static function redactSensitive(string $message): string
    {
        // Redact base64 auth sequences or password tokens
        $patterns = [
            '/AUTH\s+LOGIN\s+[A-Za-z0-9+\/=]+/i' => 'AUTH LOGIN [REDACTED]',
            '/password[\s:=]+[^\s,;]+/i' => 'password: [REDACTED]',
            '/pass[\s:=]+[^\s,;]+/i' => 'pass: [REDACTED]',
        ];
        return preg_replace(array_keys($patterns), array_values($patterns), $message);
    }
}

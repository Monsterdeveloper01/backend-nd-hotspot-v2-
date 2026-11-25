<?php

namespace App\Services;

class ApiEncryptionService
{
    /**
     * Get the API secret key from environment
     */
    public static function getKey(): string
    {
        $secret = config('app.api_secret_key') ?: env('API_SECRET_KEY');
        if (empty($secret)) {
            throw new \RuntimeException('API_SECRET_KEY is not set in .env');
        }
        return substr(hash('sha256', $secret, true), 0, 32);
    }

    /**
     * Encrypt array / string data into AES-256-CBC base64 payload
     */
    public static function encrypt(mixed $data): string
    {
        $key = self::getKey();
        $iv = openssl_random_pseudo_bytes(16);
        $json = is_string($data) ? $data : json_encode($data);
        $encrypted = openssl_encrypt($json, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt base64 payload into original data
     */
    public static function decrypt(string $encoded): mixed
    {
        try {
            $key = self::getKey();
            $raw = base64_decode($encoded);
            if (strlen($raw) < 17) return null;
            $iv = substr($raw, 0, 16);
            $ciphertext = substr($raw, 16);
            $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            return json_decode($decrypted, true) ?? $decrypted;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Return encrypted JSON response with HTTP 200 (prevents browser 404 console errors)
     */
    public static function respond(mixed $data, int $status = 200)
    {
        return response()->json([
            'payload' => self::encrypt($data)
        ], $status);
    }
}

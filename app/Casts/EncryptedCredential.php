<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Carrier login secrets (passwords, session tokens), encrypted at rest with the app key.
 *
 * Reading a value that was stored before encryption existed returns it unchanged, so
 * integrations keep working until the backfill migration has run. Writing an already
 * encrypted value keeps it as is, so callers that encrypt themselves are not double-encrypted.
 */
class EncryptedCredential implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::decrypt($value) ?? $value;
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::decrypt($value) !== null ? $value : Crypt::encryptString((string) $value);
    }

    /** Plain value of an encrypted string, or null when it is not one. */
    public static function decrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
}

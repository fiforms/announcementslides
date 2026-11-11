<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Like `encrypted:array`, but a value that cannot be decrypted (the database
 * was restored onto an install with a different APP_KEY) reads as null, i.e.
 * "not configured", instead of throwing. The admin re-enters the value.
 */
class TolerantEncryptedArray implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        try {
            return json_decode(Crypt::decryptString($value), true);
        } catch (DecryptException) {
            return null;
        }
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::encryptString(json_encode($value));
    }
}

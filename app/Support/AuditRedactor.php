<?php

namespace App\Support;

class AuditRedactor
{
    private const SENSITIVE = '/password|secret|token|credential|authorization|cookie|client_secret|refresh_token|access_token/i';

    public static function clean(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            $clean[$key] = is_string($key) && preg_match(self::SENSITIVE, $key)
                ? '[PROTEGIDO]'
                : self::clean($item);
        }

        return $clean;
    }
}

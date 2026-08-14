<?php

namespace App\Support\Tenancy;

use InvalidArgumentException;

final readonly class GlobalOperationScope implements DataScope
{
    public function __construct(public string $operation)
    {
        if (trim($operation) === '') {
            throw new InvalidArgumentException('El alcance global requiere una operación explícita.');
        }
    }

    public function type(): string
    {
        return 'global_operation';
    }
}

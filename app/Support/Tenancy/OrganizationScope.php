<?php

namespace App\Support\Tenancy;

use InvalidArgumentException;

final readonly class OrganizationScope implements DataScope
{
    public function __construct(public int $organizationId)
    {
        if ($organizationId < 1) {
            throw new InvalidArgumentException('El alcance de organización requiere un identificador válido.');
        }
    }

    public function type(): string
    {
        return 'organization';
    }
}

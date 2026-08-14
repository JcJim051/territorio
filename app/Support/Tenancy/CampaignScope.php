<?php

namespace App\Support\Tenancy;

use InvalidArgumentException;

final readonly class CampaignScope implements DataScope
{
    public function __construct(public int $campaignId)
    {
        if ($campaignId < 1) {
            throw new InvalidArgumentException('El alcance de campaña requiere un identificador válido.');
        }
    }

    public function type(): string
    {
        return 'campaign';
    }
}

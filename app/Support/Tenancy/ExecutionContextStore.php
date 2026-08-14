<?php

namespace App\Support\Tenancy;

final class ExecutionContextStore
{
    private ?AuthorizedExecutionContext $context = null;

    public function activate(AuthorizedExecutionContext $context): void
    {
        if ($this->context !== null) {
            throw new UnauthorizedExecutionContext('No se permite anidar contextos de ejecución.');
        }

        $this->context = $context;
    }

    public function clear(): void
    {
        $this->context = null;
    }

    public function current(): AuthorizedExecutionContext
    {
        return $this->context
            ?? throw new UnauthorizedExecutionContext('La operación requiere un contexto autorizado.');
    }

    public function campaignId(): int
    {
        return $this->current()->campaignId();
    }

    public function assertCampaign(int $campaignId): void
    {
        if ($campaignId < 1 || $this->campaignId() !== $campaignId) {
            throw new UnauthorizedExecutionContext('La referencia no pertenece a la campaña autorizada.');
        }
    }

    public function hasContext(): bool
    {
        return $this->context !== null;
    }
}

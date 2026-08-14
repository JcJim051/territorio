<?php

namespace App\Support\Tenancy;

use Closure;

final readonly class AuthorizedContextRunner
{
    public function __construct(private ExecutionContextStore $store)
    {
    }

    public function run(AuthorizedExecutionContext $context, Closure $operation): mixed
    {
        $this->store->activate($context);

        try {
            return $operation();
        } finally {
            $this->store->clear();
        }
    }

    public function runOutbox(AuthorizedExecutionContext $context, Closure $operation): mixed
    {
        if (! $this->store->hasContext()) {
            return $this->run($context, $operation);
        }

        $owner = $this->store->current();
        if (
            $owner->campaignId() !== $context->campaignId()
            || $owner->authorization->evidenceType !== 'campaign_membership'
            || $context->authorization->evidenceType !== 'outbox_event'
        ) {
            throw new UnauthorizedExecutionContext('El contexto activo no puede componer esta ejecución de outbox.');
        }

        return $operation();
    }
}

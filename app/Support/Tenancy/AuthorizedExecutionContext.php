<?php

namespace App\Support\Tenancy;

final readonly class AuthorizedExecutionContext
{
    public function __construct(
        public DataScope $scope,
        public AuthorizationDecision $authorization,
    ) {
        if (! $scope instanceof CampaignScope || $scope->campaignId !== $authorization->campaignId) {
            throw new UnauthorizedExecutionContext('Este incremento solo permite contextos de campaña con evidencia coincidente.');
        }
    }

    public function campaignId(): int
    {
        /** @var CampaignScope $scope */
        $scope = $this->scope;

        return $scope->campaignId;
    }
}

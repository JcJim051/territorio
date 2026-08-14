<?php

namespace App\Support\Tenancy;

use App\Models\CalendarChangeReview;
use App\Models\CalendarConnection;
use App\Models\CampaignMembership;
use App\Models\OutboxEvent;

final class CampaignContextResolver
{
    public function fromMembership(CampaignMembership $membership): AuthorizedExecutionContext
    {
        return $this->context(AuthorizationDecision::fromActiveMembership($membership));
    }

    public function fromCalendarConnection(int $campaignId, int $connectionId): AuthorizedExecutionContext
    {
        $connection = CalendarConnection::query()
            ->with('campaign')
            ->where('campaign_id', $campaignId)
            ->whereKey($connectionId)
            ->first()
            ?? throw new UnauthorizedExecutionContext('La conexión no pertenece a la campaña indicada.');

        return $this->context(AuthorizationDecision::fromCalendarConnection($connection));
    }

    public function fromLegacyCalendarConnection(int $connectionId): AuthorizedExecutionContext
    {
        $connection = CalendarConnection::query()
            ->with('campaign')
            ->whereKey($connectionId)
            ->first()
            ?? throw new UnauthorizedExecutionContext('La conexión durable del trabajo legado ya no existe.');

        return $this->context(AuthorizationDecision::fromCalendarConnection($connection));
    }

    public function fromCalendarReview(int $campaignId, int $reviewId): AuthorizedExecutionContext
    {
        $review = CalendarChangeReview::query()
            ->with(['connection.campaign', 'event', 'meeting'])
            ->where('campaign_id', $campaignId)
            ->whereKey($reviewId)
            ->first()
            ?? throw new UnauthorizedExecutionContext('La revisión no pertenece a la campaña indicada.');

        return $this->context(AuthorizationDecision::fromCalendarReview($review));
    }

    public function fromLegacyCalendarReview(int $reviewId): AuthorizedExecutionContext
    {
        $review = CalendarChangeReview::query()
            ->with(['connection.campaign', 'event', 'meeting'])
            ->whereKey($reviewId)
            ->first()
            ?? throw new UnauthorizedExecutionContext('La revisión durable del trabajo legado ya no existe.');

        return $this->context(AuthorizationDecision::fromCalendarReview($review));
    }

    public function fromOutboxEvent(int $campaignId, int $eventId): AuthorizedExecutionContext
    {
        $event = OutboxEvent::query()
            ->with('campaign')
            ->where('campaign_id', $campaignId)
            ->whereKey($eventId)
            ->first()
            ?? throw new UnauthorizedExecutionContext('El evento outbox no pertenece a la campaña indicada.');

        return $this->context(AuthorizationDecision::fromOutboxEvent($event));
    }

    private function context(AuthorizationDecision $decision): AuthorizedExecutionContext
    {
        return new AuthorizedExecutionContext(new CampaignScope($decision->campaignId), $decision);
    }
}

<?php

namespace App\Support\Tenancy;

use App\Models\CalendarChangeReview;
use App\Models\CalendarConnection;
use App\Models\CampaignMembership;
use App\Models\Meeting;
use App\Models\OutboxEvent;
use Carbon\CarbonImmutable;

final readonly class AuthorizationDecision
{
    private function __construct(
        public int $campaignId,
        public string $evidenceType,
        public string $evidenceId,
        public CarbonImmutable $authorizedAt,
    ) {
    }

    public static function fromActiveMembership(CampaignMembership $membership): self
    {
        $membership->loadMissing(['campaign', 'user']);

        if (
            ! $membership->is_active
            || ! $membership->campaign
            || $membership->campaign->status !== 'active'
            || ! $membership->user
            || ! $membership->user->is_active
            || (int) $membership->campaign_id !== (int) $membership->campaign->id
        ) {
            throw new UnauthorizedExecutionContext('La membresía no autoriza un contexto activo de campaña.');
        }

        return self::verified((int) $membership->campaign_id, 'campaign_membership', (string) $membership->id);
    }

    public static function fromCalendarConnection(CalendarConnection $connection): self
    {
        $connection->loadMissing('campaign');

        if (
            ! $connection->campaign
            || $connection->campaign->status !== 'active'
            || (int) $connection->campaign_id !== (int) $connection->campaign->id
        ) {
            throw new UnauthorizedExecutionContext('La conexión no autoriza un contexto activo de campaña.');
        }

        return self::verified((int) $connection->campaign_id, 'calendar_connection', (string) $connection->id);
    }

    public static function fromCalendarReview(CalendarChangeReview $review): self
    {
        $review->loadMissing(['connection.campaign', 'event', 'meeting']);
        $campaignId = (int) $review->campaign_id;

        if (
            ! $review->connection
            || (int) $review->connection->campaign_id !== $campaignId
            || ! $review->connection->campaign
            || $review->connection->campaign->status !== 'active'
            || ($review->event && (int) $review->event->campaign_id !== $campaignId)
            || ($review->meeting && (int) $review->meeting->campaign_id !== $campaignId)
        ) {
            throw new UnauthorizedExecutionContext('La revisión de calendario contiene referencias de otra campaña.');
        }

        return self::verified($campaignId, 'calendar_change_review', (string) $review->id);
    }

    public static function fromOutboxEvent(OutboxEvent $event): self
    {
        $campaignId = (int) $event->campaign_id;
        $payloadCampaignId = (int) ($event->payload['campaign_id'] ?? 0);
        $payloadMeetingId = (int) ($event->payload['meeting_id'] ?? 0);

        if (
            $campaignId < 1
            || $payloadCampaignId !== $campaignId
            || $event->aggregate_type !== Meeting::class
            || $payloadMeetingId < 1
            || (string) $payloadMeetingId !== (string) $event->aggregate_id
            || ! $event->campaign
            || $event->campaign->status !== 'active'
        ) {
            throw new UnauthorizedExecutionContext('El evento outbox no contiene evidencia durable coherente.');
        }

        return self::verified($campaignId, 'outbox_event', (string) $event->event_id);
    }

    private static function verified(int $campaignId, string $evidenceType, string $evidenceId): self
    {
        return new self($campaignId, $evidenceType, $evidenceId, CarbonImmutable::now());
    }
}

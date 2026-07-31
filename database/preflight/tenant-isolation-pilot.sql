-- Purpose: detect cross-campaign relationships for the TERR-ADR-0001 pilot.
-- Scope: membership-role, attendance-meeting-person, external-event-connection-meeting.
-- Safety: read-only, aggregate counts only, no remediation.

BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;

SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';

WITH pilot_checks AS (
    SELECT
        'membership_role_campaign'::text AS check_name,
        count(*)::bigint AS violations
    FROM campaign_memberships AS membership
    JOIN campaign_roles AS role
      ON role.id = membership.campaign_role_id
    WHERE role.campaign_id IS DISTINCT FROM membership.campaign_id

    UNION ALL

    SELECT
        'attendance_meeting_campaign',
        count(*)::bigint
    FROM attendances AS attendance
    JOIN meetings AS meeting
      ON meeting.id = attendance.meeting_id
    WHERE meeting.campaign_id IS DISTINCT FROM attendance.campaign_id

    UNION ALL

    SELECT
        'attendance_person_campaign',
        count(*)::bigint
    FROM attendances AS attendance
    JOIN persons AS person
      ON person.id = attendance.person_id
    WHERE person.campaign_id IS DISTINCT FROM attendance.campaign_id

    UNION ALL

    SELECT
        'external_event_connection_campaign',
        count(*)::bigint
    FROM external_calendar_events AS external_event
    JOIN calendar_connections AS connection
      ON connection.id = external_event.calendar_connection_id
    WHERE connection.campaign_id IS DISTINCT FROM external_event.campaign_id

    UNION ALL

    SELECT
        'external_event_meeting_campaign',
        count(*)::bigint
    FROM external_calendar_events AS external_event
    JOIN meetings AS meeting
      ON meeting.id = external_event.meeting_id
    WHERE meeting.campaign_id IS DISTINCT FROM external_event.campaign_id
)
SELECT check_name, violations
FROM pilot_checks
ORDER BY check_name;

ROLLBACK;

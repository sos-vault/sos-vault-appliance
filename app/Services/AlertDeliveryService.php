<?php

namespace App\Services;

use App\Events\SendUserEmail;
use App\Models\Alert;
use App\Models\AlertTrigger;
use App\Models\SupportCase;
use App\Notifications\AlertTriggeredNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Dispatches a triggered Alert across its up-to-4 configured delivery
 * channels (Notification, Email, Event/SIEM, Contact Point) and reports
 * which ones actually fired, so AlertEvaluationService can persist it onto
 * the AlertTrigger for troubleshooting.
 */
class AlertDeliveryService
{
    public function __construct(
        private readonly SlackAlertService $slack,
        private readonly VaultRecipientResolver $recipientResolver,
    ) {}

    /** @return array<string, bool> channel => delivered */
    public function deliver(Alert $alert, AlertTrigger $trigger, SupportCase $case): array
    {
        $delivered = [];

        if ($alert->notify_enabled) {
            $delivered['notification'] = $this->deliverNotification($alert, $trigger, $case);
        }

        if ($alert->email_enabled) {
            $delivered['email'] = $this->deliverEmail($alert);
        }

        if ($alert->event_enabled) {
            $delivered['event'] = $this->deliverEvent($alert, $case);
        }

        if ($alert->contact_point_enabled) {
            $delivered['contact_point'] = $this->deliverContactPoint($alert);
        }

        return $delivered;
    }

    /**
     * Alerts are vault-wide, so an in-app notification goes to every member of
     * the vault's group (or the sole owner for a personal vault), not just the
     * alert's creator — there's no recipient field for this channel, and the
     * feature is framed as collaborative ("regardless of who created it").
     */
    private function deliverNotification(Alert $alert, AlertTrigger $trigger, SupportCase $case): bool
    {
        try {
            $recipients = $this->recipientResolver->forCase($case);
            if ($recipients->isEmpty()) {
                return false;
            }

            $link = "/sosViewer/{$case->vault_id}/{$case->file_id}/{$case->id}/{$trigger->dir_id}";
            Notification::send($recipients, new AlertTriggeredNotification($alert, $trigger, $link));

            return true;
        } catch (\Throwable $e) {
            Log::warning('AlertDeliveryService: notification delivery failed: '.$e->getMessage());

            return false;
        }
    }

    /** Reuses the app's existing SendUserEmail pipeline / "notification" blade. */
    private function deliverEmail(Alert $alert): bool
    {
        $addresses = $alert->emailAddressList();
        if ($addresses === []) {
            return false;
        }

        $body = collect($alert->deliveryPayload())
            ->map(fn ($v, $k) => ucfirst(str_replace('_', ' ', $k)).': '.$v)
            ->implode('<br>');

        foreach ($addresses as $address) {
            SendUserEmail::dispatch([
                'title' => "[{$alert->severity}] {$alert->name}",
                'name' => 'there',
                'to' => $address,
                'subject' => "sos-vault Alert: {$alert->name}",
                'type' => 'notification',
                'body' => $body,
            ]);
        }

        return true;
    }

    private function deliverEvent(Alert $alert, SupportCase $case): bool
    {
        try {
            $type = Alert::EVENT_TYPES[$alert->type] ?? null;
            if (! $type) {
                return false;
            }

            addEvent(
                (object) $alert->deliveryPayload(),
                $type,
                'SUCCESS',
                'NORMAL',
                $case->id,
                $case->vault_id,
                $alert->user_id,
                $alert->user_id,
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning('AlertDeliveryService: event delivery failed: '.$e->getMessage());

            return false;
        }
    }

    private function deliverContactPoint(Alert $alert): bool
    {
        if ($alert->contact_point_destination !== 'slack') {
            // Only Slack is implemented; the other destinations are disabled in the UI.
            return false;
        }

        if (! $this->slack->licensed()) {
            Log::info('AlertDeliveryService: Slack contact point skipped — unlicensed appliance.');

            return false;
        }

        $webhookUrl = $alert->contact_point_webhook_url;
        if ($webhookUrl === '') {
            return false;
        }

        return $this->slack->send($webhookUrl, $alert->deliveryPayload());
    }
}

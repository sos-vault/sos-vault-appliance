<?php

namespace App\Services\Compliance;

use App\Events\SendUserEmail;
use App\Models\AnalysisRun;
use App\Models\ComplianceFinding;
use App\Models\SupportCase;
use App\Notifications\ComplianceFindingsNotification;
use App\Services\VaultRecipientResolver;
use Illuminate\Support\Facades\Notification;

/**
 * Notifies vault members when an analysis run produced HIGH/CRITICAL fail
 * findings. Mirrors AlertDeliveryService's notification/email/event channel
 * shapes, but is a single "notify if there's something worth surfacing"
 * entry point rather than a per-rule delivery matrix.
 */
class ComplianceFindingsDeliveryService
{
    public function __construct(private readonly VaultRecipientResolver $recipientResolver) {}

    /** @return array<string, bool> */
    public function notifyIfNeeded(AnalysisRun $run, SupportCase $case): array
    {
        $findings = ComplianceFinding::where('analysis_run_id', $run->id)
            ->where('status', 'fail')
            ->whereIn('severity', ['HIGH', 'CRITICAL'])
            ->get();

        if ($findings->isEmpty()) {
            return [];
        }

        $link = "/sosTool/{$case->vault_id}/{$case->file_id}/Compliance/{$case->id}";

        $notified = false;
        $recipients = $this->recipientResolver->forCase($case);
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ComplianceFindingsNotification($run, $case, $findings, $link));
            $notified = true;
        }

        $emailed = false;
        foreach ($recipients as $recipient) {
            if (! $recipient->email) {
                continue;
            }

            SendUserEmail::dispatch([
                'title' => 'sos-vault Compliance Findings',
                'name' => $recipient->name ?? 'there',
                'to' => $recipient->email,
                'subject' => "sos-vault Compliance: {$findings->count()} finding(s) failed for case {$case->case}",
                'type' => 'notification',
                'body' => "{$findings->count()} HIGH/CRITICAL compliance finding(s) failed for case {$case->case}. View: {$link}",
            ]);
            $emailed = true;
        }

        addEvent(
            (object) ['run_id' => $run->id, 'case' => $case->case, 'count' => $findings->count()],
            'COMPLIANCE_FINDING',
            'SUCCESS',
            'NORMAL',
            $case->id,
            $case->vault_id,
            $case->owner,
            $case->owner,
        );

        return ['notification' => $notified, 'email' => $emailed, 'event' => true];
    }
}

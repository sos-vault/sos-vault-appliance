<?php

namespace App\Listeners;

use App\Events\SendUserEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

class SendEmailListener implements ShouldQueue
{
    /** Tracks recipient+subject pairs already sent within this job to suppress duplicates. */
    private array $sentEmails = [];

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(SendUserEmail $event): void
    {
        /*
        // data looks like this:
        $data = [
            "title"    => $title,
            "name"     => $user->name,
            "username" => $user->username,
            "uid"      => $user->id,
            "from"     => $request->emailfrom,
            "email"    => $user->email,
            "to"       => $to,
            "cc"       => $cc,
            "plans"    => $user->role->display_name,
            "daysleft" => $user->daysLeftOnTrial(),
            "enddate"  => $user->trialEndDate(),
            "deletedate" => $user->trialDeleteDate(),
            "since"    => $user->created_at,
            "body"     => $message,
            "subjec"   => "sos-vault Notification",
            "type"     => "notification", //welcome, passwordReset, complain, response
            "attachments"   => $attachments",
            "action"   => $action,
        ];
        */

        $data = $event->data;
        if (! $data) {
            Log::error('no data found to send email to user. Aborting...');

            return;
        }

        if (isset($data['from'])) {
            $from = $data['from'];
        } else {
            $from = 'support@sos-vault.com';
        }
        $toemail = $data['to'];
        $subject = $data['subject'];
        $type = $data['type'];

        $dedupKey = $toemail.'|'.$subject;
        if (in_array($dedupKey, $this->sentEmails, true)) {
            Log::warning("Duplicate email suppressed (to: {$toemail}, subject: {$subject})");

            return;
        }
        $this->sentEmails[] = $dedupKey;

        $ccemail = [];
        if (isset($data['cc']) && is_array($data['cc']) && ! empty($data['cc'])) {
            $ccemail = $data['cc'];
        }

        $bccemail = isset($data['bcc']) && ! empty($data['bcc']) ? $data['bcc'] : null;

        $attachments = [];
        if (isset($data['attachments'])) {
            // $data['attachments'] shall be an array!
            $attachments = $data['attachments'];
        }

        $blade = 'emails.userNotification';
        switch ($type) {
            case 'notification':
                $blade = 'emails.userNotification';
                break;
            case 'internal':
                $blade = 'emails.internal';
                break;
            case 'resetPassword':
                $blade = 'emails.resetPassword';
                break;
            case 'setPassword':
                $blade = 'emails.setPassword';
                break;
            case 'verifyEmail':
                $blade = 'emails.verifyEmail';
                break;
            case 'welcomeEmail':
                $blade = 'emails.welcomeEmail';
                break;
            case 'endOfTrial':
                $blade = 'emails.endOfTrial';
                break;
            case 'accountSuspended':
                $blade = 'emails.accountSuspended';
                break;
            case 'accountReactivated':
                $blade = 'emails.accountReactivated';
                break;
            case 'paymentReceived':
                $blade = 'emails.paymentReceived';
                break;
            case 'response':
                $blade = 'emails.response';
                $body = (string) ($data['body'] ?? '');
                // Only convert markdown when the body is plain text, not already HTML.
                if ($body !== '' && ! str_starts_with(ltrim($body), '<')) {
                    $body = Str::markdown($body);
                }
                // The body is admin-authored and rendered unescaped in the blade
                // ({!! $body !!}), so strip any script/event-handler vectors while
                // keeping safe formatting before it reaches a recipient's inbox.
                if ($body !== '') {
                    $sanitizer = new HtmlSanitizer(
                        (new HtmlSanitizerConfig)->allowSafeElements()->allowRelativeLinks()
                    );
                    $body = $sanitizer->sanitize($body);
                }
                $data['body'] = $body;
                break;
        }

        $send = function () use ($blade, $data, $from, $toemail, $subject, $ccemail, $bccemail, $attachments): void {
            // Mail config (host, port, credentials, from address) is loaded from
            // the settings table into config('mail.*') by BillingSettingsServiceProvider.
            Mail::send($blade, $data, function ($mail) use ($from, $toemail, $subject, $ccemail, $bccemail, $attachments) {
                $mail->from($from)->to($toemail)->subject($subject);

                if (is_array($ccemail) && ! empty($ccemail)) {
                    $mail->cc($ccemail);
                }

                if (! empty($bccemail)) {
                    $mail->bcc($bccemail);
                }

                if (is_array($attachments) && ! empty($attachments)) {
                    foreach ($attachments as $file) {
                        $mail->attach($file, [
                            'as' => basename($file),
                            'mime' => mime_content_type($file),
                        ]);
                    }
                }
            });
        };

        try {
            $send();
        } catch (UnexpectedResponseException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, '550') && ! str_contains($msg, 'Relaying denied') && ! str_contains($msg, '5.7.')) {
                // Soft 550 (e.g. Mailtrap free-plan rate limit): wait 1 s and retry once.
                sleep(1);
                try {
                    $send();
                } catch (\Throwable $retry) {
                    Log::error('mail send error after retry: '.$retry->getMessage());
                }
            } else {
                Log::error('mail send error: '.$msg);
            }
        } catch (\Throwable $e) {
            Log::error('mail send error: '.$e->getMessage());
        } finally {
            // Clean up temporary attachment files written by ManageEmails.
            foreach ($attachments as $file) {
                if (is_string($file) && str_contains($file, 'email_attachments') && file_exists($file)) {
                    @unlink($file);
                }
            }
        }

    }
}

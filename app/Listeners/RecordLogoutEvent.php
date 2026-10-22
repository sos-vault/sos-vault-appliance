<?php

namespace App\Listeners;

use App\Models\Sysevent;
use Carbon\Carbon;
use Illuminate\Auth\Events\Logout;

class RecordLogoutEvent
{
    public function handle(Logout $event): void
    {
        // Drop Mil's open-case pointer so a case opened in this session can't leak
        // into the next login on the same browser cookie. Wave's logout only calls
        // Auth::logout() and does not invalidate the session, so the key would
        // otherwise survive and answer /case questions with the previous user's data.
        // (Mirrors ChatWidget::OPEN_CASE_SESSION_KEY.)
        session()->forget('mil_open_case');

        $user = $event->user;

        if (! $user) {
            return;
        }

        $via = request()->is('api/*') ? 'API' : 'UI';

        // Calculate session duration by matching the current session to the most recent LOGIN event.
        $sessionId = session()->getId();

        // Guard against double-firing caused by the listener being registered through
        // two mechanisms. If a LOGOUT for this exact session was already written
        // within the last 5 seconds, skip.
        $alreadyRecorded = Sysevent::where('owner', $user->id)
            ->where('type', 'LOGOUT')
            ->where('created_at', '>=', now()->subSeconds(5))
            ->where('payload', 'like', '%'.$sessionId.'%')
            ->exists();

        if ($alreadyRecorded) {
            return;
        }
        $duration = 0;

        $loginEvent = Sysevent::where('type', 'LOGIN')
            ->where('owner', $user->id)
            ->orderBy('created_at', 'DESC')
            ->limit(1)
            ->first();

        if ($loginEvent) {
            $loginPayload = json_decode(stripslashes($loginEvent->payload));
            if ($loginPayload && isset($loginPayload->session) && $sessionId === $loginPayload->session) {
                $now = Carbon::now();
                $ini = Carbon::parse($loginEvent->created_at);
                $diff = explode(':', $ini->diff($now)->format('%H:%i:%s'));
                $duration = sprintf('%02d:%02d:%02d', $diff[0], $diff[1], $diff[2]);
            }
        }

        $payload = (object) [
            'message' => 'logout success',
            'email' => $user->email,
            'via' => $via,
            'session' => $sessionId,
            'duration' => $duration,
        ];

        addEvent($payload, 'LOGOUT', 'SUCCESS', 'ACTIVITY', 0, 0, $user->id, $user->id);
    }
}

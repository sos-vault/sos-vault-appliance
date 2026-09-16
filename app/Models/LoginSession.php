<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Sushi\Sushi;

class LoginSession extends Model
{
    use Sushi;

    protected static array $parameters = [];

    public function sushiShouldCache(): bool
    {
        return false;
    }

    public function getRows(): array
    {
        // User sessions
        $events = Sysevent::where('owner', auth()->user()->id)
            ->where('type', 'LOGOUT')
            ->where('status', 'SUCCESS')
            ->orderBy('created_at', 'DESC')
            ->limit(50)
            ->get();

        $records = [];
        foreach ($events as $event) {
            if (isset($event)) {
                $session = json_decode(stripslashes($event->payload), true);
                if (isset($session)) {
                    $session['type'] = (string) $event->type;
                    $session['status'] = (string) $event->status;
                    $session['date'] = (string) $event->created_at;
                    $session['name'] = auth()->user()->name;
                    $session['ip'] = $event->ip;
                    $session['location'] = "{$event->city} {$event->iso_code}";

                    $records[] = $session;
                }
            }
        }

        return collect($records)->toArray();
    }
}

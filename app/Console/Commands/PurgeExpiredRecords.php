<?php

namespace App\Console\Commands;

use App\Models\Annotation;
use App\Models\ContentsRequest;
use App\Models\Sysevent;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeExpiredRecords extends Command
{
    protected $signature = 'db:purge-expired';

    protected $description = 'Expire and delete old contents requests, annotations, and sysevents';

    public function handle(): int
    {
        $older = 30;
        $eventmessage = '';

        // expire contents request records
        $status = ['VALID', 'LOCKED'];
        ContentsRequest::whereIn('status', $status)
            ->where('expire', '<', Carbon::now()->toDateString())
            ->update(['status' => 'EXPIRED']);

        // delete contents requests marked for deletion that are older than $older days...
        $requests = ContentsRequest::where('status', 'EXPIRED')
            ->where('updated_at', '<', Carbon::now()->subDays($older)->toDateString())
            ->get();

        if ($requests->isNotEmpty()) {
            $message = sprintf("Removing %s expired contents requests older than {$older} days...", count($requests));
            $eventmessage .= "{$message} ";
            Log::info($message);
            $requests->each->delete();
        }

        // expire annotations
        $status = ['PRIVATE', 'SHARED'];
        Annotation::whereIn('status', $status)
            ->where('expire', '<', Carbon::now()->toDateString())
            ->update(['status' => 'EXPIRED']);

        // delete annotations marked for deletion that are older than $older days...
        $annotations = Annotation::where('status', 'EXPIRED')
            ->where('updated_at', '<', Carbon::now()->subDays($older)->toDateString())
            ->get();

        if ($annotations->isNotEmpty()) {
            $message = sprintf("Removing %s expired annotations older then {$older} days...", count($annotations));
            $eventmessage .= "{$message} ";
            Log::info($message);
            $annotations->each->delete();
        }

        // delete sysevents marked for deletion that are older than $older days...
        $events = Sysevent::where('status', 'DELETED')
            ->where('updated_at', '<', Carbon::now()->subDays($older)->toDateString())
            ->get();

        if ($events->isNotEmpty()) {
            $message = sprintf("Removing %s expired sysevents records older then {$older} days...", count($events));
            $eventmessage .= "{$message} ";
            Log::info($message);
            $events->each->delete();
        }

        if ($eventmessage) {
            $cid = 0;
            $vid = 0;
            $uid = 0;
            $gid = 0;

            $payload = (object) [
                'message' => $message,
            ];
            addEvent($payload, 'SCHEDULER', 'SUCCESS', 'ACTIVITY', $cid, $vid, $uid, $gid);
        }

        return self::SUCCESS;
    }
}

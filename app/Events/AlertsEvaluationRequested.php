<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Fired right after DataTools::summaryData() finishes unpacking a case, so
// Alert rules are evaluated in the background and never block the upload
// request. Mirrors FixSosHtmlRequested's shape exactly.
class AlertsEvaluationRequested
{
    use Dispatchable, SerializesModels;

    public $userId;

    public $vid;

    public $did;

    public $cid;

    public function __construct($userId, $vid, $did, $cid)
    {
        $this->userId = $userId;
        $this->vid = $vid;
        $this->did = $did;
        $this->cid = $cid;
    }
}

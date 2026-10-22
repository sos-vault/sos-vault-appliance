<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Fired right after DataTools::summaryData() finishes unpacking a case, so
// compliance analysis runs in the background and never blocks the upload
// request. Mirrors AlertsEvaluationRequested's shape exactly.
class ComplianceAnalysisRequested
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

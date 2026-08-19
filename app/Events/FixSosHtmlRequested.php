<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Fired when a case is opened in the sos Browser so an older report's
// sos_reports/sos.html (unpacked before the link-rewrite existed) gets fixed in
// the background. Idempotent — the FixSosHtml listener is a no-op once the file
// carries the DataTools::SOS_HTML_FIXED_MARKER.
class FixSosHtmlRequested
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

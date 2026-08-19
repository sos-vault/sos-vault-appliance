<?php

// namespace App\Helpers;
use App\Events\SendUserEmail;
use App\Jobs\ForwardEventToSiem;
use App\Models\LocalLicense;
use App\Models\SupportCase;
use App\Models\Sysevent;
use App\Models\User;
use App\Models\Vault;
use App\Notifications\ActionNotification;
use App\Providers\VaultTools;
use App\Services\LicensingPassphraseService;
use App\Services\SettingsEncryptionService;
use App\Services\SiemForwarder;
use App\Services\SiemSettingsService;
use App\Services\TelegramService;
use App\Services\VaultAccess;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Wave\Plan;
use Wave\Setting;

function addEvent($payload, $type, $status, $class, $cid, $vid, $owner, $group)
{
    /*
    addEvent($payload, "EXPAND", "SUCCESS", "ACTIVITY", $cid, $this->VAULT->id, $this->uid, $this->gid);
    PAYLOAD:
        Any JSON object

    STATUS:
        'VALID',
        'LOCKED',
        'INVALID',
        'EXPIRED',
        'DELETED',
        'FAILED',
        'SUCCESS'   // default

    CLASS:
        'NORMAL',   // default
        'REPORT',   // include in a report
        'AUDIT',
        'ACTIVITY',  // event is part of activity and is NOT used in a report

    TYPE:
        // Vault lifecycle
        'EXPAND':                  "Disk expand class=ACTIVITY",
        'SHRINK':                  "Disk shrink class=ACTIVITY",
        'ADJUST':                  "Disk adjust (after a plan upgrade/downgrade) class=ACTIVITY",
        'ADD_VAULT':               "Create a new vault class=ACTIVITY",
        'DEL_VAULT':               "Delete a vault class=ACTIVITY",
        'OPEN':                    "Vault open class=ACTIVITY",
        'CLOSE':                   "Vault close class=ACTIVITY",
        'VAULT_EXPAND':            "Vault disk expansion via billing (wave) class=ACTIVITY",
        'VAULT_SHRINK':            "Vault disk shrink via billing (wave) class=ACTIVITY",
        'VAULT_SHRINK_SCHEDULED':  "Vault disk shrink scheduled via billing (wave) class=ACTIVITY",

        // SOS report operations
        'UPLOAD':         "Upload a sos report class=NORMAL",
        'UNPACK':         "Unpack a sos report class=NORMAL",
        'REPACK':         "Repack an unpacked sos report directory back into an archive class=ACTIVITY",
        'DEL_FILE':       "Delete a sos report class=ACTIVITY",
        'DEL_DIR':        "Delete a directory class=ACTIVITY",
        'DOWLOAD':        "Download a file class=NORMAL",
        'OPEN_FILE':      "Open a file class=NORMAL",
        'OPEN_TOOL':      "Open a tool class=NORMAL",
        'SHARE_FILE':     "Share a file class=NORMAL",
        'UNSHARE_FILE':   "Unshare a file class=NORMAL",

        // Annotations
        'ADD_NOTE':       "Add an annotation to a document class=NORMAL",
        'CHG_NOTE':       "Change an annotation to a document class=NORMAL",

        // Cases
        'ADD_CASE':       "Add a new case class=NORMAL",
        'CHG_CASE':       "Change a case class=NORMAL",
        'DEL_CASE':       "Delete a case class=ACTIVITY",

        // AI reports
        'GEN_REPORT':     "Generate an AI report class=ACTIVITY",
        'DEL_REPORT':     "Delete an AI report class=ACTIVITY",

        // Users
        'LOGIN':          "User login class=ACTIVITY",
        'LOGOUT':         "User logout class=ACTIVITY",
        'PASS_RESET':     "Password reset class=ACTIVITY",
        'ADD_USER':       "Add a new user class=ACTIVITY",
        'CHG_USER':       "Change user settings class=ACTIVITY",
        'DEL_USER':       "Delete a user class=ACTIVITY",
        'CHG_PASS':       "Change password class=ACTIVITY",
        'ENABLE_2FA':     "Enable two-factor authentication class=ACTIVITY",
        'DISABLE_2FA':    "Disable two-factor authentication class=ACTIVITY",

        // API keys
        'ADD_KEY':        "Add an API Key class=ACTIVITY",
        'CHG_KEY':        "Change an API Key class=ACTIVITY",
        'DEL_KEY':        "Delete an API Key class=ACTIVITY",

        // Billing
        'CANCELATION':    "Subscription cancellation class=ACTIVITY",
        'PAYMENT':        "Payment processed class=ACTIVITY",
        'CHECKOUT':       "Checkout session class=ACTIVITY",
        'TRANSACTIONS':   "Transaction recorded class=ACTIVITY",
        'INVOICE':        "Invoice generated class=ACTIVITY",
        'SWITCHPLAN':     "Plan switched class=ACTIVITY",
        'BUY_TOKENS':     "AI tokens purchased class=ACTIVITY",

        // ITSM integrations (Jira, ServiceNow, etc.)
        'ADD_ITSM':       "Add IT Service Manager credentials (Service Now, JIRA Service Desk) class=ACTIVITY",
        'CHG_ITSM':       "Change IT Service Manager credentials (Service Now, JIRA Service Desk) class=ACTIVITY",
        'DEL_ITSM':       "Delete IT Service Manager credentials (Service Now, JIRA Service Desk) class=ACTIVITY",
        'ITSM_REQ':       "IT Service Manager attachment list request class=NORMAL",
        'ITSM_DOWNLD':    "IT Service Manager attachment download class=NORMAL",

        // SIEM integration (Syslog/ECS event forwarding)
        'ADD_SIEM':       "Configure SIEM event forwarding class=ACTIVITY",
        'CHG_SIEM':       "Change SIEM event forwarding settings class=ACTIVITY",
        'DEL_SIEM':       "Disable/clear SIEM event forwarding class=ACTIVITY",

        // Mil AI assistant usage telemetry (payload: duration_ms, quality, provider, tool, fid, question)
        'BOT_CASE':       "Mil open-sosreport analysis query class=ACTIVITY",
        'BOT_LINUX':      "Mil general Linux query class=ACTIVITY",
        'BOT_SOS':        "Mil sos command query class=ACTIVITY",
        'BOT_SOS-VAULT':  "Mil sos-vault app-usage query class=ACTIVITY",
        'BOT_GENERIC':    "Mil generic/opener query (no LLM call) class=ACTIVITY",

        // System / scheduler
        'SCHEDULER':      "Scheduled background task class=ACTIVITY",

        // Pending / not yet implemented
        'ADD_REPORT':     "Add a new AI report class=ACTIVITY",
        'SHARE_VAULT':    "Share whole vault class=ACTIVITY",
        'UNSHARE_VAULT':  "Unshare whole vault class=ACTIVITY",
    */

    if (! $type) {
        return;
    }
    if (! $status) {
        return;
    }
    if (! $class) {
        $class = 'NORMAL';
    }
    if (! $payload) {
        $payload = [];
    }
    $did = 0;
    if ($cid) {
        $case = SupportCase::where('id', $cid)->first();
        if ($case) {
            $vid = $case->vault_id;
            $did = $case->file_id;
        }
    } elseif (! $vid) {
        $vault = Vault::where('owner', $owner)->first();
        if ($vault) {
            $vid = $vault->id;
        }
    }

    if (! $cid) {
        $cid = 0;
    }

    // sysevents.vault_id is NOT NULL (default 0). A vault-less event — e.g. a
    // FAILED ADD_VAULT recorded during an interrupted provision, before any
    // Vault row exists to resolve above — must fall back to the 0 sentinel used
    // elsewhere, not insert an explicit null and crash on the constraint.
    if (! $vid) {
        $vid = 0;
    }

    $ipinfo = [
        'iso_code' => '',
        'country' => '',
        'state' => '',
        'city' => '',
        'timezone' => '',
    ];

    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown IP';
    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
        $tmp = geoip($ip)['attributes'];
        if ($tmp) {
            $ipinfo = $tmp;
        }
    }

    $event = [
        'owner' => $owner,
        'group' => $group,
        'payload' => json_encode($payload),
        'status' => $status,
        'ip' => $ip,
        'iso_code' => $ipinfo['iso_code'],
        'country' => $ipinfo['country'],
        'state' => $ipinfo['state'],
        'city' => $ipinfo['city'],
        'timezone' => $ipinfo['timezone'],
        'vault_id' => $vid,
        'dir_id' => $did,
        'case_id' => $cid,
        'type' => $type,
        'class' => $class,
    ];
    $sysevent = Sysevent::create($event);

    // Forward to an external SIEM if one is configured (queued — never blocks
    // this request). Forwards every event type, unlike the Telegram whitelist.
    sendSyslogEvent($sysevent);

    if ($owner) {
        $event['owner'] = User::where('id', $owner)->first()->name;
    } else {
        $event['owner'] = 'UNKNOW USER';
    }

    $sendAlertsOnly4 = [
        'EXPAND',
        'SHRINK',
        'OPEN',
        'CLOSE',
        'LOGIN',
        'LOGOUT',
        'ADD_NOTE',
        'UPLOAD',
        'DOWLOAD',
        'UNPACK',
        'ADD_KEY',
        'ADD_VAULT',
        'DEL_VAULT',
        'ADD_USER',
        'CANCELATION',
        'PAYMENT',
        'CHECKOUT',
        'TRANSACTIONS',
        'INVOICE',
        'SWITCHPLAN',
        'ITSM_REQ',
        'ITSM_DOWNLD',
        'GEN_REPORT',
        'DEL_REPORT',
    ];

    if (in_array($type, $sendAlertsOnly4)) {
        $stringEvent = implode(',', $event);
        try {
            app(TelegramService::class)->sendTelegramMessage($stringEvent);
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }
    }

    return $sysevent;
}

function getSvaultKey($description)
{

    // get the id
    $id = '';
    $key = '';

    $cmd = '/bin/keyctl show @u';
    exec($cmd, $out, $ret);
    $found = preg_grep("/$description/", $out);
    if (! $found) {
        Log::error("No $description key found on keyring!!");
    } else {
        $parts = preg_split("/\s+/", trim(array_pop($found)));
        $id = $parts[0];
    }

    if ($id) {
        $cmd = '/bin/keyctl link @u @s';
        exec($cmd, $out, $ret);

        // get the goodies
        $out = null;
        $cmd = "/bin/keyctl pipe $id";
        exec($cmd, $out, $ret);
        $key = substr(rtrim($out[0]), 0, 32);

        if (! $key) {
            Log::error("Could not read $description key!!");
        }
    }

    return $key;
}

function alertBadge($message, $type)
{
    // will trigger popToast in the browser
    // Session::flash('message', $message);
    // Session::flash('message_type', $type);

    Filament\Notifications\Notification::make()
        ->title($message)
        ->icon('phosphor-bell-ringing-duotone')
        ->iconColor($type)
        ->send();
}

function getColorArray($compo_state)
{
    $colors = [];
    switch ($compo_state) {
        case 'ok':
        case 'success':
        case 'primary':
        case 'green':
            $colors = [
                '#3a3a14',
                '#43492a',
                '#7b9041',
                '#c1ec89',
                '#b5d37d',
                '#a8e194',
                '#bcf2d3',
                '#d5ece5',
                '#e4eff9',
                '#f2fff3',
            ];
            break;
        case 'fail':
        case 'alert':
        case 'red':
        case 'error':
        case 'danger':
            $colors = [
                '#b71c1c',
                '#c62828',
                '#d32f2f',
                '#e53935',
                '#e53935',
                '#f44336',
                '#ef5350',
                '#e57373',
                '#ef9a9a',
                '#ffcdd2',
            ];
            break;
        case 'warning':
        case 'yellow':
        case 'amber':
            $colors = [
                '#ff6f00',
                '#ff8f00',
                '#ffa000',
                '#ffb300',
                '#ffc107',
                '#ffca28',
                '#ffd54f',
                '#ffe082',
                '#ffecb3',
                '#fff8e1',
            ];
            break;
        case 'info':
        case 'blue':
        case 'normal':
            $colors = [
                '#006064',
                '#00838f',
                '#0097a7',
                '#00acc1',
                '#00bcd4',
                '#26c6da',
                '#4dd0e1',
                '#80deea',
                '#b2ebf2',
                '#e0f7fa',
            ];
            break;
        case 'gray':
        case 'grey':
        case 'neutral':
            $colors = [
                '#808080',
                '#808080',
                '#808080',
                '#808080',
                '#808080',
                '#808080',
                '#A0A0A0',
                '#BFBFBF',
                '#D9D9D9',
                '#F0F0F0',
                '#0A0A0A',
                '#1A1A1A',
                '#2F2F2F',
                '#4B4B4B',
                '#666666',
            ];
            break;
    }

    return $colors;
}

function getIcon($compo_state)
{
    $icon = '';
    switch ($compo_state) {
        case 'ok':
        case 'success':
        case 'green':
            $icon = 'fas fa-circle-check';
            break;
        case 'fail':
        case 'alert':
        case 'red':
        case 'danger':
        case 'error':
            $icon = 'fas fa-circle-exclamation';
            break;
        case 'warning':
        case 'yellow':
        case 'amber':
            $icon = 'fas fa-triangle-exclamation';
            break;
        case 'info':
        case 'blue':
        case 'normal':
            $icon = 'fas fa-circle-info';
            break;
    }

    return $icon;
}

function getColor($compo_state)
{
    $color = '';
    switch ($compo_state) {
        case 'ok':
        case 'success':
        case 'green':
            $color = 'wave-700';
            break;
        case 'fail':
        case 'alert':
        case 'red':
        case 'error':
        case 'danger':
            $color = 'red-600';
            break;
        case 'warning':
        case 'yellow':
        case 'amber':
            $color = 'amber-600';
            break;
        case 'info':
        case 'blue':
        case 'normal':
            $color = 'teal-600';
            break;
        case 'gray':
        case 'grey':
        case 'neutral':
            $color = 'gray-600';
            break;
    }

    return $color;
}

function notifyUser($user, $message, $type, $media = 'ui')
{
    // send notification to via UI or via email
    $lines = [];
    $lines[] = $message;

    $data = (object) [
        'email' => (object) [
            'lines' => $lines,
            'url' => '/dashboard',
            'user' => $user,
        ],
        'type' => $type,
        'toarray' => [
            'icon' => "/storage/{$user->avatar}",
            'status' => $type,
            'body' => $message,
            'link' => '/dashboard',
            'user' => [
                'name' => $user->name,
            ],
        ],
    ];
    if ($media == 'ui' || $media == 'both') {
        $user->notify(new ActionNotification($user, $data));
    }
    if ($media == 'email' || $media == 'both') {
        // this works but the blades are very difficult to customize...
        // $user->notify(new ActionNotification($user, $data));

        $title = 'Notification';
        $toemail = $user->email;
        $subject = 'sos-vault Notification';

        $data = [
            'title' => $title,
            'name' => $user->name,
            'username' => $user->username,
            'uid' => $user->id,
            'email' => $user->email,
            'to' => $user->email,
            'plans' => $user->role->display_name,
            'daysleft' => $user->daysLeftOnTrial(),
            'since' => $user->created_at,
            'body' => $message,
            'subject' => $subject,
            'type' => 'notification',
        ];

        SendUserEmail::dispatch($data);
    }
}

/**
 * Resolve the correct user for VaultTools initialisation.
 *
 * In normal operation the current user owns the vault, so auth()->user() is
 * returned unchanged.  In shared / public-case (sme) mode the vault belongs to
 * a different user (e.g. the admin who created the demo report); returning that
 * owner allows VaultTools to mount and read the vault without a "Wrong vault"
 * error.
 *
 * Elevation to the owner happens ONLY when the caller is entitled to the vault
 * (App\Services\VaultAccess). Without this check any authenticated user could
 * read any tenant's vault by passing another vault's id (IDOR). When the caller
 * is not entitled, the current user is returned unchanged so VaultTools' own
 * owner match fails and the read is denied downstream ("wrong vault"). Pass the
 * case id ($cid) so public / same-group cases resolve, and $did/$fid so a file
 * or directory shared via the "Share" button resolves — all scoped to $vid.
 */
function resolveVaultUser(int|string $vid, int|string|null $cid = null, int|string|null $did = null, int|string|null $fid = null): User
{
    $current = auth()->user();

    if (! VaultAccess::allows($current, $vid, $cid, $did, $fid)) {
        return $current;
    }

    $ownerId = Vault::where('id', $vid)->value('owner');

    if ($ownerId && (int) $ownerId !== auth()->id()) {
        $owner = User::find($ownerId);
        if ($owner) {
            return $owner;
        }
    }

    return $current;
}

function notifyError($message): void
{
    Log::error($message);
    Filament\Notifications\Notification::make()
        ->title($message)
        ->icon('phosphor-bell-ringing-duotone')
        ->iconColor('danger')
        ->persistent()
        ->send();
}

/**
 * Hand the open sosreport case to Mil (the AI chat widget) by stashing it in the
 * session under the key ChatWidget reads on mount (`mil_open_case`).
 *
 * Every tool page (Summary, Top, Compare, file viewer, …) mounts its own copy of
 * the ChatWidget from the app layout, and the layout renders the page slot BEFORE
 * the widget — so a tool page that calls this in mount() guarantees its own case
 * is injected into that page's Mil, without depending on the Browse SOS Report tab
 * having written the session first (which loses the race on the auto-opened Summary
 * tab, leaving Mil blind there). The Browse SOS Report page additionally dispatches
 * `chat-set-case` for a live same-window update; tool pages open in their own
 * navigation, so the session write is what carries the case for them.
 */
function rememberMilOpenCase(?int $did, ?int $cid, ?string $tool = null, ?int $fid = null): void
{
    if (! empty($did) && ! empty($cid)) {
        session(['mil_open_case' => [
            'did' => (int) $did,
            'cid' => (int) $cid,
            // Which tool page the case was handed off from (Summary, Top, File
            // Viewer, …) and, where applicable, the open file id. Mil records
            // these on its BOT_* usage events so per-tool usage can be measured.
            'tool' => $tool !== null && $tool !== '' ? $tool : null,
            'fid' => ! empty($fid) ? (int) $fid : null,
        ]]);
    }
}

/**
 * Build the JS to render a Filament toast purely client-side
 * (window.FilamentNotification) from a serialized notification array — the
 * shape `Notification::send()` pushes into the `filament.notifications` session.
 *
 * Used with `$this->js(...)` to deliver a drained toast WITHOUT a Livewire
 * round-trip on the shared notifications component. Delivering via the
 * `notificationSent` Livewire event races when several siblings re-render in
 * one cycle after `refreshComponents`, corrupting that component's snapshot
 * (an int where a notification array is expected) and 500-ing every page that
 * renders the toast — including login. See the guard in bootstrap/app.php.
 *
 * @param  array<string, mixed>  $notification
 */
function filamentToastJs(array $notification): string
{
    // Keep slash-escaping on (default) so a "</script>" in a title/filename
    // can never break out of an enclosing script context.
    $enc = fn ($value): string => json_encode($value, JSON_UNESCAPED_UNICODE);

    $js = 'new FilamentNotification()';
    $js .= '.title('.$enc($notification['title'] ?? '').')';

    if (! empty($notification['body'])) {
        $js .= '.body('.$enc($notification['body']).')';
    }

    if (! empty($notification['icon'])) {
        $js .= '.icon('.$enc($notification['icon']).')';
    }

    if (! empty($notification['status'])) {
        $js .= '.status('.$enc($notification['status']).')';
    }

    $color = $notification['iconColor'] ?? $notification['color'] ?? $notification['status'] ?? null;
    if (! empty($color)) {
        $js .= '.iconColor('.$enc($color).')';
    }

    $duration = $notification['duration'] ?? null;
    if ($duration === 'persistent') {
        $js .= '.persistent()';
    } elseif (is_numeric($duration)) {
        $js .= '.duration('.((int) $duration).')';
    }

    return $js.'.send();';
}

/**
 * Return the Plan for a user, cached for 1 hour.
 * Returns null for admin (no plan lookup needed) or when not found.
 */
function getPlanForUser($user): ?Plan
{
    $roleName = $user->role->name;

    return Cache::remember("plan_for_role:{$roleName}", 3600, fn () => Plan::where('status', 'available')
        ->where('type', 'service')
        ->whereEnglishName($roleName)
        ->first());
}

function checkAccess($user, $feature): bool
{
    if ($user->role->name == 'admin') {
        return true;
    }
    $plan = getPlanForUser($user);

    return $plan ? $plan->hasFeature($feature) : false;
}

function getPlanDiskSize($user): string
{
    if ($user->role->name == 'admin') {
        return '1 GB';
    }

    return getPlanForUser($user)?->getDiskSize() ?? '1 GB';
}

function getPlanDiskSizeMB($user): int|string
{
    if ($user->role->name == 'admin') {
        return '1000';
    }
    $plan = getPlanForUser($user);

    if (! $plan) {
        return 1024;
    }

    $exp = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $gbsize = explode(' ', $plan->getDiskSize());
    $index = array_search(strtoupper($gbsize[1]), $exp);
    $bsize = floatval($gbsize[0]) * pow(1024, $index);
    $mbsize = intval($bsize / pow(1024, 2));

    return $mbsize;
}

function getPlanTokens($user): string
{
    if ($user->role->name == 'admin') {
        return '50 M';
    }

    return getPlanForUser($user)?->getTokenAmount() ?? '0';
}

function getFeatureDescription($user, $feature): string
{
    if ($user->role->name == 'admin') {
        return '';
    }

    return getPlanForUser($user)?->getFeatureDescription($feature) ?? '';
}

function abuse_ips(): array
{
    return Cache::get('abuse_ips', function () {
        $path = config('abuseip.storage.path');

        return file_exists($path) ? (json_decode(file_get_contents($path), true) ?? []) : [];
    });
}

function is_abused_ip(string|int $ip): bool
{
    if (is_string($ip) && config('abuseip.storage.compress')) {
        $ip = is_numeric($ip) ? (int) $ip : ip2long($ip);
    }

    return in_array($ip, abuse_ips(), true);
}

function linuxIcon($os_version)
{
    $icon = 'simpleicon-linux';
    if (isset($os_version)) {
        switch (strtolower($os_version)) {
            case 'opensuse':
            case 'opensuse-leap':
                $icon = 'simpleicon-opensuse';
                break;
            case 'rhel':
            case 'redhatenterpriseserver':
                $icon = 'simpleicon-redhat';
                break;
            case 'centos':
                $icon = 'simpleicon-centos';
                break;
            case 'fedora':
                $icon = 'simpleicon-fedora';
                break;
            case 'debian':
                $icon = 'simpleicon-debian';
                break;
            case 'ubuntu':
                $icon = 'simpleicon-ubuntu';
                break;
            case 'kubuntu':
                $icon = 'simpleicon-kubuntu';
                break;
            case 'mint':
            case 'linuxmint':
                $icon = 'simpleicon-linuxmint';
                break;
            case 'rocky':
            case 'rockylinux':
                $icon = 'simpleicon-rockylinux';
                break;
            case 'alma':
            case 'almalinux':
                $icon = 'simpleicon-almalinux';
                break;
            case 'manjaro':
            case 'manjarolinux':
                $icon = 'simpleicon-manjaro';
                break;
            case 'proxmox':
                $icon = 'simpleicon-proxmox';
                break;
            case 'arch':
            case 'archlinux':
                $icon = 'simpleicon-archlinux';
                break;
            case 'alpine':
                $icon = 'simpleicon-alpinelinux';
                break;
            case 'zorin':
                $icon = 'simpleicon-zorin';
                break;
            case 'popos':
            case 'pop':
                $icon = 'simpleicon-popos';
                break;
            case 'kali':
            case 'kalilinux':
                $icon = 'simpleicon-kalilinux';
                break;
            case 'mx':
            case 'mxlinux':
                $icon = 'simpleicon-mxlinux';
                break;
            case 'zorin':
            case 'zorinlinux':
                $icon = 'simpleicon-zorin';
                break;
            case 'oracle':
            case 'ol':
                $icon = 'icon-oracle';
                break;
            case 'elementary':
                $icon = 'simpleicon-elementary';
                break;
            default:
                $icon = 'simpleicon-linux';
                break;
        }
    }

    return $icon;
}

/**
 * Setting key under which the master GPG (licensing) passphrase is stored,
 * encrypted with the svault0 keyring key.
 */
const LICENSING_PASSPHRASE_SETTING_KEY = 'licensing.master_gpg_passphrase';

/**
 * Encrypt a plaintext licensing passphrase with the svault0 key.
 *
 * Returns the ciphertext (Laravel Encrypter format), or null when the
 * svault0 key is unavailable or the input is empty.
 */
function encryptLicensingPassphrase(string $plain): ?string
{
    return app(LicensingPassphraseService::class)->encrypt($plain);
}

/**
 * Resolve the master GPG (licensing) passphrase from the settings table.
 *
 * The value is stored encrypted with the svault0 keyring key. Returns an
 * empty string when no passphrase has been configured (i.e. the master key
 * has no passphrase).
 */
function getMasterGpgPassphrase(): string
{
    return app(LicensingPassphraseService::class)->decrypt(Setting::get(LICENSING_PASSPHRASE_SETTING_KEY));
}

/**
 * Whether a licensing passphrase is currently stored in settings.
 *
 * Used by the admin UI to indicate "set" vs "not set" without ever
 * exposing the value itself to the browser.
 */
function hasMasterGpgPassphrase(): bool
{
    return (bool) Setting::get(LICENSING_PASSPHRASE_SETTING_KEY);
}

/**
 * The siem.* setting keys, all stored encrypted at rest with the svault0 key.
 * host/port/protocol/format are decrypted to fill the admin form; the two
 * certificates are write-only (never sent back to the browser).
 */
const SIEM_SETTING_KEYS = [
    'siem.enabled',
    'siem.host',
    'siem.port',
    'siem.protocol',
    'siem.format',
    'siem.ca_cert',
    'siem.server_cert',
];

/**
 * Encrypt a SIEM setting value with the svault0 key. Returns the ciphertext,
 * or null when the svault0 key is unavailable or the input is empty.
 */
function encryptSiemSetting(string $plain): ?string
{
    return app(SiemSettingsService::class)->encrypt($plain);
}

/**
 * Decrypt a stored SIEM setting value. Returns '' when unset or the key is
 * unavailable.
 */
function decryptSiemSetting(?string $cipher): string
{
    return app(SiemSettingsService::class)->decrypt($cipher);
}

/**
 * The decrypted SIEM configuration. Keys: enabled(bool), host, port(int),
 * protocol, format, ca_cert, server_cert.
 *
 * Read fresh each call so a long-lived queue worker always reflects the current
 * settings (no process-wide memoization). This is cheap: Setting::get() is
 * served from the wave_settings cache, and when SIEM is unconfigured each
 * decrypt() short-circuits on the empty ciphertext without touching the keyring.
 */
function siemConfig(): array
{
    $svc = app(SiemSettingsService::class);
    $get = fn (string $key): string => $svc->decrypt(Setting::get($key));

    return [
        'enabled' => $get('siem.enabled') === '1',
        'host' => $get('siem.host'),
        'port' => (int) $get('siem.port'),
        'protocol' => $get('siem.protocol') ?: 'tcp',
        'format' => $get('siem.format') ?: 'ecs',
        'ca_cert' => $get('siem.ca_cert'),
        'server_cert' => $get('siem.server_cert'),
    ];
}

/**
 * Whether a SIEM certificate (ca|server) is currently stored. Lets the admin UI
 * show SET / NOT SET without exposing the PEM to the browser.
 */
function hasSiemCert(string $which): bool
{
    $key = $which === 'server' ? 'siem.server_cert' : 'siem.ca_cert';

    return (bool) Setting::get($key);
}

/**
 * Settings-table keys holding secrets that must be encrypted at rest with
 * the svault0 key: cloud AI provider API keys, the ServiceNow (ITSM)
 * password, and the AWS/S3 secret access key.
 */
const ENCRYPTED_SETTING_KEYS = [
    'ai.openai_api_key',
    'ai.anthropic_api_key',
    'ai.ollama_api_key',
    'servicenow.password',
    'aws.secret_access_key',
];

/**
 * Encrypt a settings-table secret value with the svault0 key. Returns null
 * when the svault0 key is unavailable or the input is empty.
 */
function encryptSetting(string $plain): ?string
{
    return app(SettingsEncryptionService::class)->encrypt($plain);
}

/**
 * Decrypt a stored settings-table secret value, falling back to the raw
 * stored value when it isn't valid ciphertext (an install upgrading in
 * place still has plaintext rows from before encryption was added here).
 */
function decryptSetting(?string $cipher): string
{
    return app(SettingsEncryptionService::class)->decryptOrRaw($cipher);
}

/**
 * Forward a freshly recorded event to the configured SIEM.
 *
 * Called from addEvent() right after the sysevents row is written. When a SIEM
 * is configured it dispatches ForwardEventToSiem so the socket work happens in
 * the queue worker — an unreachable/slow SIEM never stalls the web request.
 */
function sendSyslogEvent(Sysevent $event): void
{
    try {
        if (app(SiemForwarder::class)->isEnabled()) {
            ForwardEventToSiem::dispatch($event);
        }
    } catch (Exception $e) {
        Log::error('sendSyslogEvent dispatch failed: '.$e->getMessage());
    }
}

function isAppliance(): bool
{
    return config('product.type') === 'appliance';
}

function isSaas(): bool
{
    return config('product.type') === 'saas';
}

/**
 * Build the payload consumed by App\Livewire\VaultBadge. Centralises the
 * lookup so the dashboard, the vault page, and the badge's own refresh
 * listener share one implementation. Returns null when the caller has no
 * usable vault (no vault row, vault closed, or usage probe failed) so the
 * caller can render an empty board instead of crashing.
 */
function buildVaultBadgeData(?User $user = null): ?array
{
    $user ??= auth()->user();
    if (! $user) {
        return null;
    }

    $vtools = new VaultTools($user);
    $vid = $vtools->getVaultId();
    if (! $vid) {
        return null;
    }

    $vtools = new VaultTools($user, $vid);
    if ($vtools->getVaultId() != $vid || ! $vtools->isOpen()) {
        return null;
    }

    $usage = $vtools->vaultUsage();
    if (empty($usage) || ! isset($usage['Size'])) {
        return null;
    }

    $dates = $vtools->getVaultDates();
    $cases = SupportCase::where('vault_id', $vid)
        ->where('status', 'OPEN')
        ->count();

    return [
        'state' => $vtools->isOpen() ? 'Open' : 'Closed',
        'shared' => false,
        'size' => $usage['Size'],
        'usage' => $usage['Used'],
        'isage' => $usage['Inodes'],
        'pusage' => $usage['Use%'],
        'pisage' => $usage['IUse%'],
        'cases' => $cases,
        'pfiles' => count($vtools->getFiles()),
        'udirs' => count($vtools->getDirs()),
        'created_at' => $dates['creation'],
        'last_open' => $dates['last_open'],
        'last_close' => $dates['last_close'],
    ];
}

/**
 * Resolve who is allowed to download repacked archives from the vault page.
 *
 *   SaaS:
 *     - admin (including admin currently impersonating another user)        → yes
 *     - Free / Minimal / Basic                                              → yes
 *     - Team / Enterprise                                                   → only team manager
 *   Standalone (appliance):
 *     - admin (including admin currently impersonating another user)        → yes
 *     - anyone else                                                         → no
 */
function canDownloadVaultFile(?User $user = null): bool
{
    $user ??= auth()->user();
    if (! $user) {
        return false;
    }

    if ($user->hasRole('admin')) {
        return true;
    }

    $impersonate = app('impersonate');
    if ($impersonate->isImpersonating()) {
        $impersonator = User::find($impersonate->getImpersonatorId());
        if ($impersonator?->hasRole('admin')) {
            return true;
        }
    }

    if (isAppliance()) {
        return false;
    }

    if ($user->hasRole(['Free', 'Minimal', 'Basic'])) {
        return true;
    }

    if ($user->hasRole(['Team', 'Enterprise'])) {
        return $user->isTeamManager();
    }

    return false;
}

/**
 * Open-core gate: appliance build AND a current ACTIVE license exists.
 * False on SaaS, false on appliance without a license, false when the license
 * has expired (LocalLicense::current() already excludes expired rows).
 */
function applianceLicensed(): bool
{
    return isAppliance() && LocalLicense::current() !== null;
}

/**
 * Inverse of applianceLicensed() but scoped to appliance only. False on SaaS.
 * Use this to gate banners / messages aimed at the unlicensed open-core
 * baseline. Use applianceLicensed() to gate features that should hide.
 */
function applianceUnlicensed(): bool
{
    return isAppliance() && LocalLicense::current() === null;
}

/**
 * Build the canonical Paddle bundle key for a set of license features.
 *
 * The same set of features must always produce the same key regardless of
 * the input order — features are deduplicated, sorted, and joined with '+'.
 * The 'srms' core feature is implicit; if missing, it is added.
 *
 * @param  array<int, string>  $features
 */
function bundleKey(array $features): string
{
    $clean = array_values(array_unique(array_filter($features, 'is_string')));
    if (! in_array('srms', $clean, true)) {
        $clean[] = 'srms';
    }
    sort($clean);

    return implode('+', $clean);
}

/**
 * Canonical mapping: feature bundle → Self-hosted Plan slug.
 *
 * The plans table is the single source of truth for Paddle price IDs. Each
 * sellable bundle corresponds to exactly one plan row; the plan carries both
 * monthly_price_id and yearly_price_id. Today only the full bundle is
 * sellable and resolves to slug 'standalone'. When we later sell additional
 * bundles, seat a new Plan row (slug + price IDs) and add an entry here.
 *
 * Returns null when the bundle has no corresponding plan — callers treat
 * that as a checkout-blocking error.
 *
 * @param  array<int, string>  $features
 */
function licensePlanSlug(array $features): ?string
{
    return match (bundleKey($features)) {
        'ai_analysis+jira+srms+telegram' => 'standalone',
        default => null,
    };
}

/**
 * Resolve the Paddle price ID for a feature bundle + billing cycle.
 *
 * Reads from the plans table (never from .env). Returns an empty string when
 * no plan or no cycle-matching price_id is on file — callers must handle
 * that as a checkout-blocking error.
 *
 * @param  array<int, string>  $features
 * @param  string  $cycle  'month' or 'year'
 */
function licensePriceId(array $features, string $cycle = 'year'): string
{
    $slug = licensePlanSlug($features);
    if (! $slug) {
        return '';
    }

    $plan = Plan::where('slug', $slug)->first();
    if (! $plan) {
        return '';
    }

    $column = $cycle === 'month' ? 'monthly_price_id' : 'yearly_price_id';

    return (string) ($plan->{$column} ?? '');
}

/**
 * Resolve the Paddle price ID for the per-seat add-on ("Extra seat" plan,
 * slug 'extra-seat') for a billing cycle. A self-hosted purchase is billed as
 * the base Self-hosted price (quantity 1) plus this seat price at quantity =
 * additional users. Returns '' when no plan / cycle price is on file.
 *
 * @param  string  $cycle  'month' or 'year'
 */
function seatPriceId(string $cycle = 'year'): string
{
    $plan = Plan::where('slug', 'extra-seat')->first();
    if (! $plan) {
        return '';
    }

    $column = $cycle === 'month' ? 'monthly_price_id' : 'yearly_price_id';

    return (string) ($plan->{$column} ?? '');
}

/**
 * Build a public/storage asset URL with a cache-busting `?v=` query string
 * derived from the file's mtime, so browsers pick up a swapped-in file
 * (e.g. an updated logo) without relying on the old URL being manually
 * hard-refreshed. Falls back to an unversioned URL if the file is missing.
 *
 * @param  string  $path  path relative to storage/app/public, e.g. 'themes/March2025/sos-vault_logo.png'
 */
function versionedStorageAsset(string $path): string
{
    $absolute = storage_path('app/public/'.$path);
    $url = asset('storage/'.$path);

    if (! is_file($absolute)) {
        return $url;
    }

    return $url.'?v='.filemtime($absolute);
}

/**
 * Same cache-busting as versionedStorageAsset(), but for files served
 * directly out of public/ (e.g. the Wave default favicons at
 * public/wave/favicon*.png) rather than the storage/app/public symlink.
 * Passes admin-configured absolute/external URLs through unversioned,
 * since asset() already returns those unchanged and there's no local
 * file to derive an mtime from.
 *
 * @param  string  $path  path relative to public/, e.g. '/wave/favicon.png'
 */
function versionedPublicAsset(string $path): string
{
    $absolute = public_path(ltrim($path, '/'));
    $url = asset($path);

    if (! is_file($absolute)) {
        return $url;
    }

    return $url.'?v='.filemtime($absolute);
}

<?php

namespace App\Providers;

use App\Models\Annotation;
use App\Models\ApiKey;
use App\Models\Bookmark;
use App\Models\ContentsRequest;
use App\Models\FileList;
use App\Models\Group;
use App\Models\Report;
use App\Models\SupportCase;
use App\Models\Sysevent;
use App\Models\User;
use App\Models\UserToken;
use App\Models\Vault;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Wave\PaddleSubscription;
use Wave\Plan;
use Wave\Subscription as WaveSubscription;

class VaultTools extends ServiceProvider
{
    public $user;

    /**
     * Appliance group context. When non-null, this VaultTools instance is
     * operating on a group-owned vault (vaults.owner IS NULL). $this->user
     * remains null in that case, and addEvent's "group" slot uses this
     * group's primary key instead of the user's id.
     */
    public ?Group $group = null;

    /**
     * Application user id of whoever initiated the action — used as the
     * "owner" slot in addEvent for appliance group operations where
     * $this->user is null. Defaults to auth()->id() at construction time.
     */
    protected ?int $actorId = null;

    protected $vault;

    protected $vname;

    protected $mountp;

    public $device;

    protected $headers;

    protected $diskSize;

    protected $uid;

    protected $gid;

    protected $bs;

    protected $VAULT;

    protected $jsonContents;

    protected $DEBUG;

    protected $CRYPTDEBUG;

    protected $debugopt;

    protected $PERFMON;

    protected $encrypter;

    protected $moptions;

    protected $trajectoryIDS = [];

    protected $vaultsDisabled;

    // Error state set by decrypt() / xtract() and read by callers
    public ?string $emessage = null;

    public ?string $etype = null;

    // 'decrypt' when the failure occurred inside decrypt(), 'extract' when inside xtract()
    // Used by AuthController to decide whether to retry with a different passphrase
    public ?string $ePhase = null;

    // files larger than 1MB are considered too big we serve them in 1MB chunks
    public $tooBig = 1048576;

    public $chunkSize = 1048576;

    // Per-request memoization for getFilePathById / getFileContentsById.
    // Why: Filebrowser and FileTable hit these 4-5x per page render — same
    // (vid,did,fid,offset) every time. Each miss re-runs cat|wc -l on the
    // full file, file(1), and the isLinuxLog regex sweep on a 1MB chunk.
    // AppServiceProvider::boot() flushes between requests so stale data
    // can't leak across the FPM worker.
    private static array $filePathCache = [];

    private static array $fileContentsCache = [];

    public static function flushFileCache(): void
    {
        self::$filePathCache = [];
        self::$fileContentsCache = [];
    }

    /**
     * Resolve the (uid, gid) pair used as the addEvent "owner" / "group" slots.
     *
     * SaaS (user context): both default to the user's id, matching legacy
     * behavior so existing event consumers don't break.
     *
     * Appliance (group context): owner = actor admin id, group = appliance
     * group id. That way appliance vault events are attributable to the
     * admin who triggered them and grouped by the team they affected.
     */
    protected function eventActorIds(): array
    {
        if ($this->group) {
            return [$this->actorId ?? 0, $this->group->id];
        }
        if ($this->user) {
            return [$this->user->id, $this->user->id];
        }

        return [$this->actorId ?? 0, 0];
    }

    /**
     * Provision a brand-new group vault on the appliance.
     *
     * Performs the same LUKS create flow as the user-vault path (dd → luksFormat
     * → luksAddKey → luksHeaderBackup → luksOpen → second dd → mkfs → mount),
     * but with no user context. The resulting Vault row has owner=NULL and is
     * linked to $group via groups.vault_id. Returns the persisted Vault on
     * success, null on failure (with the partial device file removed).
     *
     * Events: ADD_VAULT SUCCESS/FAILED + ADD_GROUP SUCCESS — both attributed
     * to the admin who triggered the action (auth()->id() unless overridden).
     */
    public static function createGroupVault(Group $group, int $sizeMb, ?int $actorId = null): ?Vault
    {
        $actor = $actorId ?? (auth()->id() ?? 0);
        $cid = 0;

        if ($group->vault_id) {
            Log::error("createGroupVault: group {$group->id} already has vault {$group->vault_id}");

            return null;
        }

        $vaultRoot = config('filesystems.disks.vault.root');
        $headers = "{$vaultRoot}/.headers";
        $vname = 'g'.hash('md5', "group_{$group->id}", false);
        $device = "{$vaultRoot}/.{$vname}.img";
        $vaultsDisabled = (config('app.vaultsDisabled') == 'TRUE');

        $failPayload = (object) [
            'description' => 'Appliance group vault',
            'plan_id' => 0,
            'message' => '',
            'group' => $group->name,
        ];

        $encryptedKey = '';
        $bs = 100; // 100 MB block size, mirrors createVault()

        if (! $vaultsDisabled) {
            if (file_exists($device)) {
                Log::error("createGroupVault: device {$device} already exists");
                $failPayload->message = 'device file already exists';
                addEvent($failPayload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, null, $actor, $group->id);

                return null;
            }

            $count = intval(($sizeMb + 100) / $bs);
            $debugopt = '';
            $tempBootstrap = self::writeKeyFile(getSvaultKey('svault1'));

            $cleanup = function () use ($device, $tempBootstrap) {
                file_exists($tempBootstrap) && @unlink($tempBootstrap);
                file_exists($device) && @unlink($device);
            };

            // Allocate the backing file with fallocate — it reserves real
            // blocks instantly with NO writes. The old dd zero-fill wrote the
            // full vault size (10+ GB for a personal vault), filling page cache
            // and — under VirtualBox — inflating the host-backed VM RSS until
            // the host OOM killer terminated the whole VM mid-provision. Fall
            // back to dd where fallocate is unsupported (rare on ext4/xfs).
            $out = null;
            $allocMb = $count * $bs;
            exec("/usr/bin/fallocate -l {$allocMb}M {$device} 2>&1", $out, $ret);
            if ($ret) {
                $out = null;
                exec("/bin/dd if=/dev/zero of={$device} bs={$bs}M count={$count} 2>&1", $out, $ret);
            }
            if ($ret) {
                Log::error('createGroupVault: dd failed: '.var_export($out, true));
                $failPayload->message = 'initial dd failed';
                $cleanup();
                addEvent($failPayload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, null, $actor, $group->id);

                return null;
            }

            // luksFormat. Cap the Argon2id KDF memory at 256 MB (--pbkdf-memory
            // is in kB). The default auto-tunes to ~1 GB of mlock'd,
            // non-swappable RAM, which — stacked on the bs dd buffer and the
            // resident llama model — OOM-froze small VMs mid-provision. The
            // vault key is a random 32-char string (Str::password(32)), so the
            // lower memory cost sacrifices no meaningful brute-force resistance.
            $out = null;
            $cmd = "/bin/sudo /sbin/cryptsetup {$debugopt} -v --batch-mode --label='{$vname}' --key-file={$tempBootstrap} --type luks2 --pbkdf-memory 262144 luksFormat {$device} 2>&1";
            exec($cmd, $out, $ret);
            if ($ret) {
                Log::error('createGroupVault: luksFormat failed: '.var_export($out, true));
                $failPayload->message = 'luksFormat failed';
                $cleanup();
                addEvent($failPayload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, null, $actor, $group->id);

                return null;
            }

            // luksAddKey — add a per-vault working key
            $workingPass = Str::password(32);
            $tempWorking = self::writeKeyFile($workingPass);
            $out = null;
            $cmd = "/bin/cat {$tempWorking}|/bin/sudo /sbin/cryptsetup {$debugopt} -v --batch-mode --key-file={$tempBootstrap} luksAddKey {$device} 2>&1";
            exec($cmd, $out, $ret);
            file_exists($tempBootstrap) && @unlink($tempBootstrap);
            if ($ret) {
                Log::error('createGroupVault: luksAddKey failed: '.var_export($out, true));
                $failPayload->message = 'luksAddKey failed';
                file_exists($tempWorking) && @unlink($tempWorking);
                file_exists($device) && @unlink($device);
                addEvent($failPayload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, null, $actor, $group->id);

                return null;
            }

            // luksHeaderBackup
            ! is_dir($headers) && mkdir($headers, 0700, true);
            $out = null;
            $cmd = "/sbin/cryptsetup {$debugopt} -v --key-file={$tempWorking} luksHeaderBackup {$device} --header-backup-file {$headers}/.{$vname}.data 2>&1";
            exec($cmd, $out, $ret);
            if ($ret) {
                Log::error('createGroupVault: luksHeaderBackup failed: '.var_export($out, true));
            }

            // chown the backing file to the web user
            $uid = posix_getuid();
            $gid = posix_getgid();
            $out = null;
            exec("/bin/sudo /bin/chown {$uid}:{$gid} {$device}", $out, $ret);

            // luksOpen → mkfs → mount
            $out = null;
            $cmd = "/bin/sudo /sbin/cryptsetup {$debugopt} -v --batch-mode --key-file={$tempWorking} luksOpen {$device} {$vname} 2>&1";
            exec($cmd, $out, $ret);
            if ($ret) {
                Log::error('createGroupVault: luksOpen failed: '.var_export($out, true));
                $failPayload->message = 'luksOpen failed';
                file_exists($tempWorking) && @unlink($tempWorking);
                file_exists($device) && @unlink($device);
                file_exists("{$headers}/.{$vname}.data") && @unlink("{$headers}/.{$vname}.data");
                addEvent($failPayload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, null, $actor, $group->id);

                return null;
            }

            // No mapper pre-zero. dd'ing /dev/zero across the whole decrypted
            // mapper only randomized free space — a nicety, not a requirement:
            // the device is LUKS-encrypted under a random master key and
            // mkfs.ext4 needs no pre-zeroed device. But it wrote the entire
            // vault size through dm-crypt, the second big page-cache balloon
            // that (under VirtualBox) got the VM host-OOM-killed, and it made
            // first login hang for minutes. mkfs writes its own structures.

            // mkfs.ext4 — see buildMkfsCommand() for the -T ext4,news rationale.
            $out = null;
            exec(self::buildMkfsCommand($vname, $vname).' 2>&1', $out, $ret);
            if ($ret) {
                Log::error('createGroupVault: mkfs.ext4 failed: '.var_export($out, true));
                exec("/bin/sudo /sbin/cryptsetup luksClose {$vname}");
                $failPayload->message = 'mkfs.ext4 failed';
                file_exists($tempWorking) && @unlink($tempWorking);
                file_exists($device) && @unlink($device);
                file_exists("{$headers}/.{$vname}.data") && @unlink("{$headers}/.{$vname}.data");
                addEvent($failPayload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, null, $actor, $group->id);

                return null;
            }

            // mount
            $mountp = "{$vaultRoot}/{$vname}";
            ! is_dir($mountp) && mkdir($mountp, 0700, true);
            $out = null;
            exec("/bin/sudo /bin/mount -o rw,noexec,nosuid,nodev /dev/mapper/{$vname} {$mountp} 2>&1", $out, $ret);
            if ($ret) {
                Log::error('createGroupVault: mount failed: '.var_export($out, true));
            } else {
                // The freshly-mkfs'd ext4 root is owned by root:root — chown it to
                // the app uid (as the legacy createVault does) so the web user can
                // write .contents.json and case files into the mounted vault.
                exec("/bin/sudo /bin/chown {$uid}:{$gid} {$mountp}");
                exec("/bin/sudo /bin/chmod 700 {$mountp}");
            }

            file_exists($tempWorking) && @unlink($tempWorking);

            $encrypter = new Encrypter(
                key: getSvaultKey('svault0'),
                cipher: config('app.cipher'),
            );
            $encryptedKey = $encrypter->encrypt($workingPass);
        }

        $now = str_replace('T', ' ', preg_replace("/\+..*/", '', date('c')));

        $vault = Vault::create([
            'user_vault' => $vname,
            'device' => $device,
            'header_file' => "{$vname}.data",
            'key' => $encryptedKey,
            'status' => 'OPEN',
            'owner' => null,
            'group' => $group->id,
            'perms' => '750',
            'shared_status' => 'GROUP',
            'description' => 'Appliance group vault',
            'subscription_id' => 0,
            'plan_id' => '0',
            'role_id' => 0,
            'current_size' => $sizeMb,
            'plan_size' => $sizeMb,
            'last_open' => $now,
        ]);

        $group->update(['vault_id' => $vault->id]);

        $payload = (object) [
            'message' => 'appliance group vault created',
            'group' => $group->name,
            'size_mb' => $sizeMb,
        ];
        addEvent($payload, 'ADD_VAULT', 'SUCCESS', 'ACTIVITY', $cid, $vault->id, $actor, $group->id);

        return $vault;
    }

    /**
     * Provision a PERSONAL LUKS vault owned by $user.
     *
     * Used on the appliance so the admin always has an encrypted personal vault
     * (sosreports are private and must be encrypted at rest), regardless of
     * licence — encryption of the admin's own workspace is a baseline guarantee,
     * not a paid feature. Mirrors createGroupVault()'s LUKS flow (dd → luksFormat
     * → luksAddKey → luksHeaderBackup → luksOpen → pre-zero → mkfs → mount) but
     * the resulting Vault row has owner=$user->id, shared_status='PRIVATE', and
     * is NOT linked to any group (so it stays independent of a Default Team the
     * admin may own — unlike the SaaS createVault() instance path).
     *
     * The vname/device deliberately match the constructor's user resolution
     * (hash('md5', username), no 'g' prefix) so a later `new VaultTools($user)`
     * finds it. Idempotent: returns the existing personal vault if one is
     * already present. Returns null on provisioning failure.
     */
    public static function createPersonalVault(User $user, int $sizeMb, ?int $actorId = null): ?Vault
    {
        $actor = $actorId ?? (auth()->id() ?? $user->id);
        $cid = 0;

        $existing = Vault::where('owner', $user->id)->first();
        if ($existing) {
            Log::info("createPersonalVault: user {$user->id} already has vault {$existing->id}");

            return $existing;
        }

        $vaultRoot = config('filesystems.disks.vault.root');
        $headers = "{$vaultRoot}/.headers";
        $vname = hash('md5', $user->username, false);
        $device = "{$vaultRoot}/.{$vname}.img";
        $vaultsDisabled = (config('app.vaultsDisabled') == 'TRUE');

        $failPayload = (object) [
            'description' => 'Personal vault',
            'plan_id' => 0,
            'message' => '',
            'user' => $user->username,
        ];

        $encryptedKey = '';
        $bs = 100; // 100 MB block size, mirrors createGroupVault()

        // First login provisions the admin's encrypted personal vault inline,
        // which runs the full LUKS flow (dd → luksFormat → mkfs) and can take
        // several seconds. Log it so the slow first request is explained.
        Log::info("createPersonalVault: provisioning {$sizeMb}MB encrypted personal vault for user {$user->id} ({$user->username}) — this can take a few seconds");
        $startedAt = microtime(true);

        if (! $vaultsDisabled) {
            // Self-heal an interrupted provision. Reaching here guarantees the
            // user has NO Vault row (the early return above handles the
            // has-vault case), so an existing backing device is an orphan left
            // by a prior attempt that crashed after dd/luksFormat but before the
            // DB row was written — e.g. the appliance was rebooted mid-provision
            // on first admin login. Such an orphan is unrecoverable (the
            // per-vault working key lived ONLY in the missing Vault row), so
            // tearing it down loses no usable data. This runs ONLY when no Vault
            // row exists; a fully provisioned vault always has one and is never
            // touched here.
            if (file_exists($device)) {
                Log::warning("createPersonalVault: orphaned device {$device} with no Vault row — clearing interrupted provision and retrying");
                $mountp = "{$vaultRoot}/{$vname}";
                exec("/bin/sudo /bin/umount {$mountp} 2>/dev/null");
                exec("/bin/sudo /sbin/cryptsetup luksClose {$vname} 2>/dev/null");
                @unlink($device);
                @unlink("{$headers}/.{$vname}.data");
            }

            $count = intval(($sizeMb + 100) / $bs);
            $debugopt = '';
            $tempBootstrap = self::writeKeyFile(getSvaultKey('svault1'));

            $cleanup = function () use ($device, $tempBootstrap) {
                file_exists($tempBootstrap) && @unlink($tempBootstrap);
                file_exists($device) && @unlink($device);
            };

            // Allocate the backing file with fallocate — it reserves real
            // blocks instantly with NO writes. The old dd zero-fill wrote the
            // full vault size (10+ GB for a personal vault), filling page cache
            // and — under VirtualBox — inflating the host-backed VM RSS until
            // the host OOM killer terminated the whole VM mid-provision. Fall
            // back to dd where fallocate is unsupported (rare on ext4/xfs).
            $out = null;
            $allocMb = $count * $bs;
            exec("/usr/bin/fallocate -l {$allocMb}M {$device} 2>&1", $out, $ret);
            if ($ret) {
                $out = null;
                exec("/bin/dd if=/dev/zero of={$device} bs={$bs}M count={$count} 2>&1", $out, $ret);
            }
            if ($ret) {
                Log::error('createPersonalVault: dd failed: '.var_export($out, true));
                $failPayload->message = 'initial dd failed';
                $cleanup();
                addEvent($failPayload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, null, $actor, 0);

                return null;
            }

            // luksFormat. Cap the Argon2id KDF memory at 256 MB (--pbkdf-memory
            // is in kB). The default auto-tunes to ~1 GB of mlock'd,
            // non-swappable RAM, which — stacked on the bs dd buffer and the
            // resident llama model — OOM-froze small VMs mid-provision. The
            // vault key is a random 32-char string (Str::password(32)), so the
            // lower memory cost sacrifices no meaningful brute-force resistance.
            $out = null;
            $cmd = "/bin/sudo /sbin/cryptsetup {$debugopt} -v --batch-mode --label='{$vname}' --key-file={$tempBootstrap} --type luks2 --pbkdf-memory 262144 luksFormat {$device} 2>&1";
            exec($cmd, $out, $ret);
            if ($ret) {
                Log::error('createPersonalVault: luksFormat failed: '.var_export($out, true));
                $failPayload->message = 'luksFormat failed';
                $cleanup();
                addEvent($failPayload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, null, $actor, 0);

                return null;
            }

            // luksAddKey — add a per-vault working key
            $workingPass = Str::password(32);
            $tempWorking = self::writeKeyFile($workingPass);
            $out = null;
            $cmd = "/bin/cat {$tempWorking}|/bin/sudo /sbin/cryptsetup {$debugopt} -v --batch-mode --key-file={$tempBootstrap} luksAddKey {$device} 2>&1";
            exec($cmd, $out, $ret);
            file_exists($tempBootstrap) && @unlink($tempBootstrap);
            if ($ret) {
                Log::error('createPersonalVault: luksAddKey failed: '.var_export($out, true));
                $failPayload->message = 'luksAddKey failed';
                file_exists($tempWorking) && @unlink($tempWorking);
                file_exists($device) && @unlink($device);
                addEvent($failPayload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, null, $actor, 0);

                return null;
            }

            // luksHeaderBackup
            ! is_dir($headers) && mkdir($headers, 0700, true);
            $out = null;
            $cmd = "/sbin/cryptsetup {$debugopt} -v --key-file={$tempWorking} luksHeaderBackup {$device} --header-backup-file {$headers}/.{$vname}.data 2>&1";
            exec($cmd, $out, $ret);
            if ($ret) {
                Log::error('createPersonalVault: luksHeaderBackup failed: '.var_export($out, true));
            }

            // chown the backing file to the web user
            $uid = posix_getuid();
            $gid = posix_getgid();
            $out = null;
            exec("/bin/sudo /bin/chown {$uid}:{$gid} {$device}", $out, $ret);

            // luksOpen → mkfs → mount
            $out = null;
            $cmd = "/bin/sudo /sbin/cryptsetup {$debugopt} -v --batch-mode --key-file={$tempWorking} luksOpen {$device} {$vname} 2>&1";
            exec($cmd, $out, $ret);
            if ($ret) {
                Log::error('createPersonalVault: luksOpen failed: '.var_export($out, true));
                $failPayload->message = 'luksOpen failed';
                file_exists($tempWorking) && @unlink($tempWorking);
                file_exists($device) && @unlink($device);
                file_exists("{$headers}/.{$vname}.data") && @unlink("{$headers}/.{$vname}.data");
                addEvent($failPayload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, null, $actor, 0);

                return null;
            }

            // No mapper pre-zero. dd'ing /dev/zero across the whole decrypted
            // mapper only randomized free space — a nicety, not a requirement:
            // the device is LUKS-encrypted under a random master key and
            // mkfs.ext4 needs no pre-zeroed device. But it wrote the entire
            // vault size through dm-crypt, the second big page-cache balloon
            // that (under VirtualBox) got the VM host-OOM-killed, and it made
            // first login hang for minutes. mkfs writes its own structures.

            // mkfs.ext4 — see buildMkfsCommand() for the -T ext4,news rationale.
            $out = null;
            exec(self::buildMkfsCommand($vname, $vname).' 2>&1', $out, $ret);
            if ($ret) {
                Log::error('createPersonalVault: mkfs.ext4 failed: '.var_export($out, true));
                exec("/bin/sudo /sbin/cryptsetup luksClose {$vname}");
                $failPayload->message = 'mkfs.ext4 failed';
                file_exists($tempWorking) && @unlink($tempWorking);
                file_exists($device) && @unlink($device);
                file_exists("{$headers}/.{$vname}.data") && @unlink("{$headers}/.{$vname}.data");
                addEvent($failPayload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, null, $actor, 0);

                return null;
            }

            // mount
            $mountp = "{$vaultRoot}/{$vname}";
            ! is_dir($mountp) && mkdir($mountp, 0700, true);
            $out = null;
            exec("/bin/sudo /bin/mount -o rw,noexec,nosuid,nodev /dev/mapper/{$vname} {$mountp} 2>&1", $out, $ret);
            if ($ret) {
                Log::error('createPersonalVault: mount failed: '.var_export($out, true));
            } else {
                // The freshly-mkfs'd ext4 root is owned by root:root — chown it to
                // the app uid (as the legacy createVault does) so the web user can
                // write .contents.json and case files into the mounted vault.
                exec("/bin/sudo /bin/chown {$uid}:{$gid} {$mountp}");
                exec("/bin/sudo /bin/chmod 700 {$mountp}");
            }

            file_exists($tempWorking) && @unlink($tempWorking);

            $encrypter = new Encrypter(
                key: getSvaultKey('svault0'),
                cipher: config('app.cipher'),
            );
            $encryptedKey = $encrypter->encrypt($workingPass);
        }

        $now = str_replace('T', ' ', preg_replace("/\+..*/", '', date('c')));

        $vault = Vault::create([
            'user_vault' => $vname,
            'device' => $device,
            'header_file' => "{$vname}.data",
            'key' => $encryptedKey,
            'status' => 'OPEN',
            'owner' => $user->id,
            'group' => $user->id,
            'perms' => '750',
            'shared_status' => 'PRIVATE',
            'description' => 'Personal vault',
            'subscription_id' => 0,
            'plan_id' => '0',
            'role_id' => $user->role_id ?? 0,
            'current_size' => $sizeMb,
            'plan_size' => $sizeMb,
            'last_open' => $now,
        ]);

        $payload = (object) [
            'message' => 'personal vault created',
            'user' => $user->username,
            'size_mb' => $sizeMb,
        ];
        addEvent($payload, 'ADD_VAULT', 'SUCCESS', 'ACTIVITY', $cid, $vault->id, $actor, 0);

        $elapsed = round(microtime(true) - $startedAt, 1);
        Log::info("createPersonalVault: vault {$vault->id} ready for user {$user->id} ({$user->username}) in {$elapsed}s");

        return $vault;
    }

    /**
     * Destroy an appliance group vault: close LUKS if open, remove device
     * file + header backup, drop the Vault row. Emits DEL_VAULT.
     */
    public static function destroyGroupVault(Vault $vault, ?int $actorId = null): bool
    {
        $actor = $actorId ?? (auth()->id() ?? 0);
        $cid = 0;
        $groupId = (int) $vault->group;
        $vaultId = (int) $vault->id;
        $vname = $vault->user_vault;
        $vaultRoot = config('filesystems.disks.vault.root');
        $headers = "{$vaultRoot}/.headers";
        $device = $vault->device ?: "{$vaultRoot}/.{$vname}.img";
        $vaultsDisabled = (config('app.vaultsDisabled') == 'TRUE');

        if (! $vaultsDisabled) {
            // Best-effort unmount + luksClose. Errors are logged but not fatal —
            // the goal is to remove the row and reclaim disk regardless.
            $mountp = "{$vaultRoot}/{$vname}";
            if (is_dir($mountp)) {
                exec("/bin/sudo /bin/umount /dev/mapper/{$vname} 2>&1");
            }
            exec("/bin/sudo /sbin/cryptsetup luksClose --deferred {$vname} 2>&1");

            foreach ([$device, "{$headers}/.{$vname}.data", "{$headers}/.{$vname}.data.old"] as $f) {
                file_exists($f) && @unlink($f);
            }
            if (is_dir($mountp)) {
                @rmdir($mountp);
            }
        }

        $vault->delete();

        $payload = (object) [
            'description' => 'Appliance group vault',
            'plan_id' => 0,
            'message' => 'group vault destroyed',
        ];
        addEvent($payload, 'DEL_VAULT', 'SUCCESS', 'ACTIVITY', $cid, $vaultId, $actor, $groupId);

        return true;
    }

    /**
     * Write a passphrase to a temp file with 0600 perms. Static helper used
     * by createGroupVault — mirrors the instance getKeyFile() but doesn't
     * need a user context.
     */
    protected static function writeKeyFile(string $key): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'svk');
        file_put_contents($temp, $key);
        chmod($temp, 0600);

        return $temp;
    }

    public function __construct($context, $vid = '', ?int $actorId = null)
    {
        ini_set('memory_limit', '512M');
        $this->vaultsDisabled = (config('app.vaultsDisabled') == 'TRUE');
        $this->DEBUG = 0;
        $this->PERFMON = 0;

        // $context may be a User (SaaS / appliance member login) or a Group
        // (appliance admin operating directly on a group vault). Branch here
        // so the rest of the constructor doesn't have to care.
        if ($context instanceof Group) {
            $this->user = null;
            $this->group = $context;
        } else {
            $this->user = $context;
            $this->group = null;
        }
        $this->actorId = $actorId ?? (auth()->id() ?? $this->user?->id);

        $this->uid = posix_getuid();
        $this->gid = posix_getgid();
        $this->vault = config('filesystems.disks.vault.root');
        $this->headers = "{$this->vault}/.headers";
        $this->CRYPTDEBUG = 0;
        $this->debugopt = $this->CRYPTDEBUG ? '--debug' : '';

        if ($this->group) {
            // Appliance group context — resolve straight to the group's vault.
            $resolveVid = $vid !== '' ? (int) $vid : (int) $this->group->vault_id;
            if ($resolveVid) {
                $this->VAULT = Vault::where('id', '=', $resolveVid)->first();
            }
        } elseif ($vid !== '') {
            if ($this->user && $this->user->group_id && ($grp = $this->user->group) && $grp->vault_id && (int) $grp->vault_id === (int) $vid) {
                // Group member requesting their group's vault — allow by vault ID alone
                $this->VAULT = Vault::where('id', '=', $vid)->first();
            } elseif ($this->user) {
                // Solo user or admin — must own the vault
                $this->VAULT = Vault::where('id', '=', $vid)
                    ->where('owner', $this->user->id)
                    ->first();
            }
        } elseif ($this->user) {
            // Resolve the Vault row regardless of vaultsDisabled — that flag
            // gates LUKS/OS operations, not DB lookups. Tests construct
            // VaultTools($user) and expect getVaultId() to return the
            // correct shared-group or personal vault id.
            if ($this->user->group_id && ($grp = $this->user->group) && $grp->vault_id) {
                $this->VAULT = Vault::where('id', '=', $grp->vault_id)->first();
            } else {
                $this->VAULT = Vault::where('owner', '=', $this->user->id)->first();
            }
        }

        // Name used for the LUKS mapper / disk image: derives from the user's
        // username on SaaS, or from the group id on appliance group vaults.
        $nameSeed = $this->user?->username ?? ($this->group ? "group_{$this->group->id}" : 'unknown');
        $mountPhash = hash('md5', $nameSeed, false);
        if ($this->VAULT) {
            $this->vname = ($this->VAULT->user_vault) ?: $mountPhash;
            $this->device = ($this->VAULT->device) ?: "{$this->vault}/.{$mountPhash}.img";
        } else {
            $this->vname = $mountPhash;
            $this->device = "{$this->vault}/.{$mountPhash}.img";
        }

        $this->mountp = "{$this->vault}/{$this->vname}";

        // Defense in depth: vname/device/mountp are interpolated into privileged
        // shell commands (cryptsetup, mount, mkfs, dd, rm -rf). They are always
        // system-derived (md5 hex + config paths), never user input. Enforce that
        // invariant here so a tampered DB value (vaults.user_vault / .device)
        // could never smuggle shell metacharacters into a root exec — the whole
        // object fails closed before any command runs.
        $this->assertShellSafePaths();

        ! $this->vaultsDisabled && $this->encrypter = new Encrypter(
            key: getSvaultKey('svault0'),
            cipher: config('app.cipher'),
        );

        $this->diskSize = 500;
        $this->bs = 100; // 100MB

        $this->jsonContents = '.contents.json';
        // $this->moptions = "-o rw,noexec,nosuid,nodev,bind";
        $this->moptions = '-o rw,noexec,nosuid,nodev';
    }

    /**
     * Guarantee the LUKS mapper name, backing-device path and mount point are
     * free of shell metacharacters before they are interpolated into the
     * privileged exec() commands throughout this class. Legitimate values are
     * md5 hex (optionally 'g'-prefixed) and config-rooted paths; anything else
     * indicates tampering and must never reach a root shell.
     *
     * @throws \RuntimeException
     */
    private function assertShellSafePaths(): void
    {
        $checks = [
            'vname' => ['/^[A-Za-z0-9_-]+$/', $this->vname],
            'device' => ['#^[A-Za-z0-9_./-]+$#', $this->device],
            'mountp' => ['#^[A-Za-z0-9_./-]+$#', $this->mountp],
        ];

        foreach ($checks as $name => [$pattern, $value]) {
            if ($value !== null && $value !== '' && ! preg_match($pattern, (string) $value)) {
                Log::error("VaultTools: refusing unsafe {$name} for privileged shell use");
                throw new \RuntimeException("VaultTools: unsafe {$name} value");
            }
        }
    }

    /**
     * Build the mkfs.ext4 command that formats a freshly-opened LUKS mapper
     * device for a vault. Centralized so the personal (createVault) and group
     * (createGroupVault) provisioning paths share one definition — in
     * particular the "-T ext4,news" usage type, which sets a dense inode_ratio
     * (4096 vs the ext4 default 16384). sosreports are tiny-file-heavy, so the
     * default ratio would exhaust inodes long before disk space.
     *
     * $vname is a system-derived md5 mapper name (already shell-safe per
     * assertShellSafePaths); $label, when non-empty, is applied via -L. The
     * returned string has no shell redirection — callers append " 2>&1".
     */
    public static function buildMkfsCommand(string $vname, ?string $label = null): string
    {
        $labelOpt = ($label !== null && $label !== '') ? "-L {$label} " : '';

        return "/bin/sudo /sbin/mkfs.ext4 -T ext4,news {$labelOpt}/dev/mapper/{$vname}";
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    public function getVaultId(): string
    {
        if ($this->VAULT) {
            return intval($this->VAULT->id);
        }

        return '';
    }

    public function infoVault(): string
    {
        $message1 = '';
        $message2 = '';
        $message3 = '';
        $message4 = '';

        $message1 = "\t{$this->user->username}'s vault {$this->device}:\n";
        if (! $this->vaultExists()) {
            $message1 .= "\tDOES NOT EXISTS\n";
        } else {
            $size = filesize($this->device);
            $message1 .= "\t\tDOES EXISTS\n";

            if (! $this->isOpen()) {
                $message1 .= "\t\tLUKS status is CLOSED\n";
            } else {
                $message1 .= "\t\tLUKS status is OPEN\n";

                if (! $this->isMounted()) {
                    $message2 .= "\t\tNOT MOUNTED\n";
                } else {
                    $message2 .= "\t\tMOUNTED in {$this->mountp}\n";

                    // FIELDS: Filesystem, Size, Used, Avail, Use%, IUse%, Inodes
                    $usage = $this->vaultUsage();
                    if ($usage) {
                        $message3 .= "\t\tis {$size}B in image SIZE\n";
                        $message3 .= "\t\tis {$usage['Size']}B in fs SIZE\n";
                        $message4 .= "\t\tis {$usage['Use%']} of USAGE\n";
                        $message4 .= "\t\tis {$usage['IUse%']} of INODE USAGE\n";
                    }
                }
            }
        }

        if ($this->VAULT) {
            $message4 .= "\t\tis {$this->VAULT->shared_status}\n";
            $message3 .= "\t\tis {$this->VAULT->current_size}MB in db SIZE\n";
            $message1 .= "\t\t  db status is {$this->VAULT->status}\n";
            $message4 .= "\t\tPERMISSIONS 0{$this->VAULT->perms}\n";
            $message4 .= "\t\twas first created on {$this->VAULT->created_at}\n";
            $message4 .= "\t\twas last  opened  on {$this->VAULT->last_open}\n";
            $message4 .= "\t\twas last  closed  on {$this->VAULT->last_close}\n";
        }

        return "{$message1}{$message2}{$message3}{$message4}";
    }

    public function createVault(): bool
    {
        $this->DEBUG = 1;
        $this->CRYPTDEBUG = 1;
        $return = true;
        $ini = microtime(true);

        if (file_exists($this->device)) {
            Log::info('vault already exists');

            return false;
        }

        if ($this->VAULT && $this->VAULT->id) {
            $this->VAULT->delete();
        }

        // add record to the database
        if ($this->user->role->name === 'Free') {
            $plan_id = '0';
            $description = 'Free Trial';
            $subscription_id = '0';
        } elseif ($this->user->role->name === 'admin') {
            $plan_id = '0';
            $description = 'Elmer Homero';
            $subscription_id = '1';
        } else {
            $plan = Plan::where('role_id', '=', $this->user->role_id)->first();

            if ($plan) {
                $plan_id = $plan->monthly_price_id;
                $description = $plan->description;
            }

            $paddleSub = PaddleSubscription::where('user_id', '=', $this->user->id)->first();
            $waveSub = WaveSubscription::where('billable_id', '=', $this->user->id)->first();
            $subscription_id = $paddleSub?->id ?? $waveSub?->id ?? '0';
        }

        // get plan's disk size (in MB)
        $this->diskSize = getPlanDiskSizeMB($this->user);
        $adjustment = 100;
        $count = intval(($this->diskSize + $adjustment) / $this->bs);

        $out = null;
        if ($this->user->role->name != 'Enterprise') {
            $cmd = "/bin/dd if=/dev/zero of={$this->device} bs={$this->bs}M count={$count} 2>&1";
        } else {
            // Eterprise is 1TB of vault size so for this command to be quick...
            $count = intval($count / 1000);
            $cmd = "/bin/dd if=/dev/zero of={$this->device} bs={$this->bs}G count={$count} 2>&1";
        }
        $this->DEBUG && Log::info($cmd);
        exec($cmd, $out, $ret);
        if ($ret) {
            Log::error('initial dd failed');
            Log::error($cmd);
            Log::error(var_export($out, true));
            file_exists($this->device) && unlink($this->device);

            $payload = (object) [
                'description' => $description,
                'plan_id' => $plan_id,
                'message' => 'VAULT CREATION ERROR. initial dd',
                'user' => $this->user->username,
            ];
            $uid = $this->user->id;
            $gid = $this->user->id;
            $cid = 0;
            addEvent($payload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, null, $uid, $gid);

            Log::error("vault creation for {$this->user->username} failed");

            return false;
        }

        $temp2 = $this->getKeyFile(getSvaultKey('svault1'));

        $out = null;
        $cmd = "/bin/sudo /sbin/cryptsetup $this->debugopt -v --batch-mode --label='{$this->vname}' --key-file={$temp2} --type luks2 luksFormat {$this->device} 2>&1";
        exec($cmd, $out, $ret);
        if ($this->CRYPTDEBUG) {
            Log::info('cryptsetup operation completed, exit='.$ret);
        }
        if ($ret) {
            Log::error('luksFormat failed');
            $this->DEBUG && Log::error($cmd);
            Log::error(var_export($out, true));
            file_exists($temp2) && unlink($temp2);

            $payload = (object) [
                'description' => $description,
                'plan_id' => $plan_id,
                'message' => 'VAULT CREATION ERROR. luksFormat',
                'user' => $this->user->username,
            ];
            $uid = $this->user->id;
            $gid = $this->user->id;
            $cid = 0;
            addEvent($payload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, null, $uid, $gid);

            Log::error("vault creation for {$this->user->username} failed");

            return false;
        }

        // add a second every day use pass to this disk
        $pass = Str::password(32);
        $temp = $this->getKeyFile($pass);

        $out = null;
        $cmd = "/bin/cat {$temp}|/bin/sudo /sbin/cryptsetup $this->debugopt -v --batch-mode --key-file={$temp2} luksAddKey {$this->device} 2>&1";
        exec($cmd, $out, $ret);
        if ($this->CRYPTDEBUG) {
            Log::info('cryptsetup operation completed, exit='.$ret);
        }
        if ($ret) {
            Log::error('luksAddKey failed');
            $this->DEBUG && Log::error($cmd);
            Log::error(var_export($out, true));
            file_exists($temp) && unlink($temp);
            file_exists($temp2) && unlink($temp2);

            $payload = (object) [
                'description' => $description,
                'plan_id' => $plan_id,
                'message' => 'VAULT CREATION ERROR. luksAddKey',
                'user' => $this->user->username,
            ];
            $uid = $this->user->id;
            $gid = $this->user->id;
            $cid = 0;
            addEvent($payload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, null, $uid, $gid);

            Log::error("vault creation for {$this->user->username} failed");

            return false;
        }

        file_exists($temp2) && unlink($temp2);

        $now = str_replace('T', ' ', preg_replace("/\+..*/", '', date('c')));

        $vault = Vault::create([
            'user_vault' => $this->vname,
            'device' => $this->device,
            'header_file' => "{$this->vname}.data",
            'key' => $this->encrypter->encrypt($pass),
            'status' => 'OPEN',
            'owner' => $this->user->id,
            'group' => Group::where('owner_id', $this->user->id)->value('id') ?? $this->user->id,
            'perms' => '750',
            'shared_status' => 'PRIVATE',
            'description' => $description,
            'subscription_id' => $subscription_id,
            'plan_id' => $plan_id,
            'role_id' => $this->user->role_id,
            'current_size' => $this->diskSize,
            'plan_size' => $this->diskSize,
            'last_open' => $now,
        ]);

        if (! $vault) {
            Log::error('Vault record create failed');
            $this->DEBUG && Log::error($cmd);
            Log::error(var_export($out, true));
            file_exists($temp) && unlink($temp);
            file_exists($temp2) && unlink($temp2);

            $payload = (object) [
                'description' => $description,
                'plan_id' => $plan_id,
                'message' => 'VAULT CREATION ERROR. Vault::create',
                'user' => $this->user->username,
            ];
            $uid = $this->user->id;
            $gid = $this->user->id;
            $cid = 0;
            addEvent($payload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, null, $uid, $gid);

            Log::error("vault creation for {$this->user->username} failed");

            return false;
        }

        $this->VAULT = Vault::where('owner', '=', $this->user->id)->first();

        // Link vault to group if this user owns one (Team / Enterprise)
        $grp = Group::where('owner_id', $this->user->id)->first();
        if ($grp && ! $grp->vault_id && $this->VAULT) {
            $grp->update(['vault_id' => $this->VAULT->id]);
        }

        // save the header
        ! is_dir($this->headers) && mkdir($this->headers, 0700, true);

        $out = null;
        $cmd = "/sbin/cryptsetup $this->debugopt -v --key-file={$temp} luksHeaderBackup {$this->device} --header-backup-file {$this->headers}/.{$this->vname}.data 2>&1";
        exec($cmd, $out, $ret);
        if ($this->CRYPTDEBUG) {
            Log::info('cryptsetup operation completed, exit='.$ret);
        }
        if ($ret) {
            Log::error('luksHeaderBackup failed');
            Log::error(var_export($out, true));
        }

        $out = null;
        $cmd = "/bin/sudo /bin/chown {$this->uid}:{$this->gid} {$this->device}";
        exec($cmd, $out, $ret);
        if ($ret) {
            Log::error('initial chown failed');
            Log::error(var_export($out, true));
            file_exists($temp) && unlink($temp);

            $payload = (object) [
                'description' => $description,
                'plan_id' => $plan_id,
                'message' => 'VAULT CREATION ERROR. chown',
                'user' => $this->user->username,
            ];
            $uid = $this->user->id;
            $gid = $this->user->id;
            $cid = 0;
            addEvent($payload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

            Log::error("vault creation for {$this->user->username} failed");

            return false;
        }

        if (! $this->luksOpen($this->device)) {
            Log::error('was not able to open the vault');

            $payload = (object) [
                'description' => $description,
                'plan_id' => $plan_id,
                'message' => 'VAULT CREATION ERROR. luksOpen',
                'user' => $this->user->username,
            ];
            $uid = $this->user->id;
            $gid = $this->user->id;
            $cid = 0;
            addEvent($payload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

            Log::error("vault creation for {$this->user->username} failed");

            return false;
        }

        // only do the next dd if /dev/mapper/vname is a block device file.
        // /dev/mapper entries are symlinks; use realpath() to resolve before filetype().
        $mapperDev = "/dev/mapper/{$this->vname}";
        $mapperReal = realpath($mapperDev);
        if (! $mapperReal || filetype($mapperReal) != 'block') {
            Log::error("/dev/mapper/{$this->vname} is not a block device");

            file_exists("/dev/mapper/{$this->vname}") && @unlink("/dev/mapper/{$this->vname}");

            $payload = (object) [
                'description' => $description,
                'plan_id' => $plan_id,
                'message' => 'VAULT CREATION ERROR. second dd',
                'user' => $this->user->username,
            ];
            $uid = $this->user->id;
            $gid = $this->user->id;
            $cid = 0;
            addEvent($payload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

            Log::error("vault creation for {$this->user->username} failed");

            return false;
        }

        $out = null;
        $bs = ($this->user->role->name == 'Enterprise') ? 100 : 1;
        $cmd = "/bin/sudo /bin/dd if=/dev/zero of=/dev/mapper/{$this->vname} bs={$bs}G 2>&1";
        exec($cmd, $out, $ret);
        // this command always fails with "no space left on device". Is ok.
        if ($this->CRYPTDEBUG) {
            Log::info($cmd);
            Log::info(var_export($out, true));
        }

        $out = null;
        $cmd = self::buildMkfsCommand($this->vname).' 2>&1';
        exec($cmd, $out, $ret);
        if ($this->CRYPTDEBUG) {
            Log::info($cmd);
            Log::info(var_export($out, true));
        }
        if ($ret) {
            Log::error('initial mkfs failed');
            $this->DEBUG && Log::error($cmd);
            Log::error(var_export($out, true));
            file_exists($temp) && unlink($temp);

            $payload = (object) [
                'description' => $description,
                'plan_id' => $plan_id,
                'message' => 'VAULT CREATION ERROR. initial mkfs',
                'user' => $this->user->username,
            ];
            $uid = $this->user->id;
            $gid = $this->user->id;
            $cid = 0;
            addEvent($payload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

            Log::error("vault creation for {$this->user->username} failed");

            return false;
        }

        ! is_dir($this->mountp) && mkdir($this->mountp, 0700, true);

        $out = null;
        $cmd = "/bin/sudo /bin/mount -t ext4 {$this->moptions} /dev/mapper/{$this->vname} {$this->mountp} 2>&1";
        exec($cmd, $out, $ret);
        if ($this->CRYPTDEBUG) {
            Log::info($cmd);
            Log::info(var_export($out, true));
        }
        if ($ret) {
            Log::error('mount failed');
            Log::error(var_export($out, true));
            file_exists($temp) && unlink($temp);

            $payload = (object) [
                'description' => $description,
                'plan_id' => $plan_id,
                'message' => 'VAULT CREATION ERROR. mount',
                'user' => $this->user->username,
            ];
            $uid = $this->user->id;
            $gid = $this->user->id;
            $cid = 0;
            addEvent($payload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

            Log::error("vault creation for {$this->user->username} failed");

            return false;
        }

        $out = null;
        $cmd = "/bin/sudo /bin/chown {$this->uid}:{$this->gid} {$this->mountp}";
        exec($cmd, $out, $ret);
        if ($ret) {
            Log::error('chown failed');
            Log::error(var_export($out, true));
            file_exists($temp) && unlink($temp);

            $payload = (object) [
                'description' => $description,
                'plan_id' => $plan_id,
                'message' => 'VAULT CREATION ERROR. secodn chown',
                'user' => $this->user->username,
            ];
            $uid = $this->user->id;
            $gid = $this->user->id;
            $cid = 0;
            addEvent($payload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

            Log::error("vault creation for {$this->user->username} failed");

            return false;
        }

        $out = null;
        $cmd = "/bin/sudo /bin/chmod 700 {$this->mountp}";
        exec($cmd, $out, $ret);
        if ($ret) {
            Log::error('chmod failed');
            Log::error(var_export($out, true));
            file_exists($temp) && unlink($temp);

            $payload = (object) [
                'description' => $description,
                'plan_id' => $plan_id,
                'message' => 'VAULT CREATION ERROR. chmod',
                'user' => $this->user->username,
            ];
            $uid = $this->user->id;
            $gid = $this->user->id;
            $cid = 0;
            addEvent($payload, 'ADD_VAULT', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

            Log::error("vault creation for {$this->user->username} failed");

            return false;
        }

        file_exists($temp) && unlink($temp);

        $payload = (object) [
            'description' => $description,
            'plan_id' => $plan_id,
            'message' => 'VAULT CREATION SUCCESS',
            'user' => $this->user->username,
        ];
        $uid = $this->user->id;
        $gid = $this->user->id;
        $cid = 0;
        if (auth()->user()) {
            $uid = $this->user->id;
            $gid = $this->user->id;
        }
        addEvent($payload, 'ADD_VAULT', 'SUCCESS', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

        $this->DEBUG && Log::info("vault creation for {$this->user->username} successful");

        $end = microtime(true);
        $this->PERFMON && Log::error(sprintf('%s took %d s', __FUNCTION__, $end - $ini));

        return $return;
    }

    public function openVault(): bool
    {
        $cid = 0;
        // if cannot be oppened, find why and try to fix it
        $return = true;
        $ini = microtime(true);

        if (! $this->VAULT) {
            Log::error('openVault: no vault record — cannot open');

            return false;
        }

        if ($this->VAULT && $this->VAULT->status !== 'CLOSED') {
            if ($this->isOpen() && $this->isMounted()) {
                // the status is wrong

                if ($this->VAULT) {
                    $this->VAULT->update([
                        'status' => 'OPEN',
                    ]);
                }

                $this->DEBUG && Log::info('vault is already open status updated');

                return true;
            } elseif (! $this->isOpen() && $this->isMounted()) {
                Log::error('something is using the mount point. Help');

                return false;
            }
        }

        if ($this->vaultsDisabled) {
            if ($this->VAULT) {
                $this->VAULT->update([
                    'status' => 'OPEN',
                ]);
            }

            return true;
        }

        if (! $this->luksOpen($this->device)) {
            Log::error('was not able to open the vault');

            return false;
        }

        ! is_dir($this->mountp) && mkdir($this->mountp, 0700, true);

        if ($this->isMounted()) {
            // something is using the mount point.
            $this->closeVault(0, true);
            Log::error('mount point alredy in use');

            return false;
        }

        $out = null;
        $cmd = "/bin/sudo /bin/mount -t ext4 {$this->moptions} /dev/mapper/{$this->vname} {$this->mountp} 2>&1";
        exec($cmd, $out, $ret);
        if ($ret) {
            Log::error('mount failed');
            Log::error(var_export($out, true));

            return false;
        }

        $out = null;
        $cmd = "/bin/sudo /bin/chown {$this->uid}:{$this->gid} {$this->mountp}";
        exec($cmd, $out, $ret);
        if ($ret) {
            Log::error('chown failed');
            Log::error(var_export($out, true));

            return false;
        }

        $out = null;
        $cmd = "/bin/sudo /bin/chmod 700 {$this->mountp}";
        exec($cmd, $out, $ret);
        if ($ret) {
            Log::error('chmod failed');
            Log::error(var_export($out, true));

            return false;
        }

        $now = str_replace('T', ' ', preg_replace("/\+..*/", '', date('c')));

        if ($this->VAULT) {
            $this->VAULT->update([
                'status' => 'OPEN',
                'last_open' => $now,
            ]);
        }

        $payload = (object) [
            'message' => 'vault open success',
        ];
        [$uid, $gid] = $this->eventActorIds();
        addEvent($payload, 'OPEN', 'SUCCESS', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

        $end = microtime(true);
        $this->PERFMON && Log::info(sprintf('%s took %d s', __FUNCTION__, $end - $ini));

        return $return;
    }

    public function closeVault($inactivity = 0, bool $force = false): bool
    {
        $cid = 0;
        $return = true;
        $ini = microtime(true);

        if ($this->vaultsDisabled) {
            return true;
        }

        // always_open vaults (e.g. the admin vault that ingests self-hosted
        // reports) skip auto-close on logout/inactivity but must still be
        // closable for explicit admin actions and for resize/destroy paths
        // that need a real luksClose. Callers pass $force=true to bypass.
        if (! $force && $this->VAULT && $this->VAULT->always_open) {
            Log::info('vault is always_open — skipping close');

            return true;
        }

        if ($this->VAULT && $this->VAULT->status !== 'OPEN') {
            if (! $this->isOpen() && $this->isMounted()) {
                Log::error('something is using the mount point. Help');

                return false;
            } elseif (! $this->isOpen() && ! $this->isMounted()) {
                // the status is wrong

                $now = str_replace('T', ' ', preg_replace("/\+..*/", '', date('c')));

                if ($this->VAULT) {
                    $this->VAULT->update([
                        'status' => 'CLOSED',
                        'last_close' => $now,
                    ]);
                }

                Log::info('vault is already closed status updated');

                return true;
            }
        } else {
            if (! $this->isMounted() && ! $this->isOpen()) {
                // this thing is already closed but has wrong status

                if ($this->VAULT) {
                    $this->VAULT->update([
                        'status' => 'CLOSED',
                    ]);
                }

                Log::info('vault is already closed status updated');

                return true;
            }
        }

        if ($this->isMounted()) {
            $out = null;
            $cmd = "/bin/sudo /bin/umount /dev/mapper/{$this->vname} 2>&1";
            exec($cmd, $out, $ret);
            if ($ret) {
                Log::error('umount failed');
                Log::error(var_export($out, true));

                return false;
            }
        }

        $out = null;
        $cmd = "/bin/sudo /sbin/cryptsetup $this->debugopt luksClose --deferred {$this->vname} 2>&1";
        exec($cmd, $out, $ret);
        if ($this->CRYPTDEBUG) {
            Log::info('cryptsetup operation completed, exit='.$ret);
        }
        if ($ret) {
            Log::error('luksClose failed');
            Log::error($cmd);
            Log::error(var_export($out, true));
        }

        if (is_dir($this->mountp)) {
            $out = null;
            $cmd = "/bin/sudo /bin/rm -rf {$this->mountp}";
            exec($cmd, $out, $ret);
            if ($ret) {
                Log::error('removing mount point failed');
                Log::error($cmd);
                Log::error(var_export($out, true));
            }
        }

        $now = str_replace('T', ' ', preg_replace("/\+..*/", '', date('c')));

        if ($this->VAULT) {
            $this->VAULT->update([
                'status' => 'CLOSED',
                'last_close' => $now,
            ]);
        }

        $message = 'vault closed successfully';
        if ($inactivity) {
            $message = "inactive {$message}";
        }

        $payload = (object) [
            'message' => $message,
        ];
        [$uid, $gid] = $this->eventActorIds();
        addEvent($payload, 'CLOSE', 'SUCCESS', 'ACTIVITY', $cid, $this->VAULT?->id ?? 0, $uid, $gid);

        $end = microtime(true);
        $this->PERFMON && Log::info(sprintf('%s took %d s', __FUNCTION__, $end - $ini));

        return $return;
    }

    public function closeDevice(): bool
    {
        // cryptsetup luksOpen Device already exists..
        if ($this->vaultsDisabled) {
            return true;
        }

        $out = null;
        $cmd = "/bin/sudo /sbin/cryptsetup $this->debugopt luksClose --deferred {$this->vname} 2>&1";
        exec($cmd, $out, $ret);
        if ($this->CRYPTDEBUG) {
            Log::info('cryptsetup operation completed, exit='.$ret);
        }
        if ($ret) {
            Log::error('luksClose failed');
            Log::error($cmd);
            Log::error(var_export($out, true));

            return false;
        }

        return true;
    }

    public function expandVault($increment, $payload = null): bool
    {
        $this->DEBUG = 1;
        $cid = 0;

        // increment in MB
        $return = true;
        $ini = microtime(true);

        if ($this->VAULT && $this->VAULT->status !== 'OPEN') {
            Log::error('vault must be open to be expanded');

            return false;
        }

        if (! $this->isOpen()) {
            Log::error('vault must be open to be expanded');

            return false;
        }

        [$uid, $gid] = $this->eventActorIds();

        // make a backup
        copy($this->device, "{$this->device}.bak");
        $this->DEBUG && Log::info('vault backed-up');

        $this->DEBUG && Log::info("increment: {$increment}");

        // Derive seek from the actual device file size — not from the filesystem
        // reported by df. df -h uses rounded human-readable values and reports
        // the filesystem size (inside LUKS), which is smaller than the raw device
        // by the LUKS header overhead. Using a rounded filesystem size for seek
        // can cause dd to start writing before the real end of the file, so only
        // part of the requested increment is added as new space.
        $seek = intval(filesize($this->device) / ($this->bs * 1024 * 1024));
        $this->DEBUG && Log::info("expand seek (from filesize): {$seek}");

        $count = intval($increment / $this->bs);

        // close the vault here (force past always_open so the dd/resize cycle
        // operates on an unmounted device, not a still-mounted one)
        if (! $this->closeVault(0, true)) {
            Log::error('was not able to close the vault');

            return false;
        }

        $out = null;
        $cmd = "/bin/dd if=/dev/zero of={$this->device} bs={$this->bs}M count={$count} seek={$seek} 2>&1";
        $this->DEBUG && Log::info($cmd);
        exec($cmd, $out, $ret);
        if ($ret) {
            $message = 'initial dd failed';
            Log::error($message);
            $payload->message = $message;
            Log::error(var_export($out, true));
            addEvent($payload, 'EXPAND', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

            // restore the backup
            file_exists("{$this->device}.bak") && rename("{$this->device}.bak", $this->device);
            $this->DEBUG && Log::error('backup restored');

            return false;
        } else {
            if (! $this->luksOpen($this->device)) {
                Log::error('was not able to open the vault');

                return false;
            } else {
                $this->DEBUG && Log::info('luksOpen done');

                if (! $this->fscheckVault('-vfp')) {
                    $message = 'pre fscheckVault failed';
                    Log::error($message);
                    $payload->message = $message;
                    file_exists("{$this->device}.bak") && rename("{$this->device}.bak", $this->device);
                    $this->DEBUG && Log::error('backup restored');

                    addEvent($payload, 'EXPAND', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

                    return false;
                } else {
                    $this->DEBUG && Log::info('pre fscheckVault done');

                    $out = null;
                    $cmd = "/bin/sudo /sbin/resize2fs /dev/mapper/{$this->vname} 2>&1";
                    $this->DEBUG && Log::info($cmd);
                    exec($cmd, $out, $ret);
                    if ($ret) {
                        $message = 'resize failed';
                        Log::error($message);
                        $payload->message = $message;
                        Log::error(var_export($out, true));
                        file_exists("{$this->device}.bak") && rename("{$this->device}.bak", $this->device);
                        $this->DEBUG && Log::error('backup restored');

                        addEvent($payload, 'EXPAND', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

                        return false;
                    } else {
                        $this->DEBUG && Log::info('resize done');

                        if (! $this->fscheckVault()) {
                            $message = 'post fscheckVault failed';
                            Log::error($message);
                            $payload->message = $message;
                            file_exists("{$this->device}.bak") && rename("{$this->device}.bak", $this->device);
                            $this->DEBUG && Log::error('backup restored');
                            addEvent($payload, 'EXPAND', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

                            return false;
                        } else {
                            $this->DEBUG && Log::info('post fscheckVault done');

                            $this->closeVault(0, true);
                            $this->DEBUG && Log::info('closeVault done');

                            file_exists("{$this->device}.bak") && unlink("{$this->device}.bak");
                            $this->DEBUG && Log::info('backup removed');

                            $now = str_replace('T', ' ', preg_replace("/\+..*/", '', date('c')));

                            if ($this->VAULT) {
                                $this->VAULT->update([
                                    'current_size' => $this->VAULT->current_size + $increment,
                                    'updated_at' => $now,
                                ]);
                            }

                            $message = "vault expansion ({$increment} MB) successfull";
                            Log::info($message);
                            $payload->message = $message;

                            $end = microtime(true);
                            $this->PERFMON && Log::error(sprintf('%s took %d s', __FUNCTION__, $end - $ini));

                            addEvent($payload, 'EXPAND', 'SUCCESS', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

                            return $return;
                        }
                    }
                }
            }
        }
    }

    public function shrinkVault($decrement, $payload = null): bool
    {
        $this->DEBUG = 1;
        $cid = 0;
        // decrement in MB
        $return = true;
        $ini = microtime(true);

        if ($this->VAULT && $this->VAULT->status !== 'OPEN') {
            Log::error('vault must be open to be shrunk');

            return false;
        }

        if (! $this->isOpen()) {
            Log::error('vault must be open to be shrunk');

            return false;
        }

        [$uid, $gid] = $this->eventActorIds();

        $this->DEBUG && Log::info("decrement (requested): {$decrement}");

        // Raw device size in MB (exact, no rounding).
        $seek = intval(filesize($this->device) / (1024 * 1024));
        $this->DEBUG && Log::info("shrink current size MB (from filesize): {$seek}");

        // Cap the shrink so we never leave the vault smaller than the floor
        // for this context: on SaaS that's the user's plan allocation; on
        // appliance group vaults it's a hard 256 MB.
        $minSizeMb = $this->user ? (int) getPlanDiskSizeMB($this->user) : 256;
        if (($seek - $decrement) < $minSizeMb) {
            $decrement = $seek - $minSizeMb;
        }

        $this->DEBUG && Log::info("decrement (effective): {$decrement}");

        // Number of bs-sized blocks the new raw device will hold.
        $count = ceil(($seek - $decrement) / $this->bs);

        // ----------------------------------------------------------------
        // Step 1: Unmount the filesystem but keep the LUKS container open.
        // resize2fs needs the mapper device to be accessible — it cannot
        // run after luksClose.  closeVault() would also do luksClose which
        // is wrong here, so we unmount manually.
        // ----------------------------------------------------------------
        if ($this->isMounted()) {
            $out = null;
            $cmd = "/bin/sudo /bin/umount /dev/mapper/{$this->vname} 2>&1";
            exec($cmd, $out, $ret);
            if ($ret) {
                $message = 'umount failed before shrink';
                Log::error($message);
                $payload->message = $message;
                Log::error(var_export($out, true));
                addEvent($payload, 'SHRINK', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

                return false;
            }
        }

        // ----------------------------------------------------------------
        // Step 2: e2fsck — verify the filesystem is consistent at its
        // current (full) size before we resize it.
        // ----------------------------------------------------------------
        $out = null;
        $cmd = "/bin/sudo /sbin/e2fsck -fy /dev/mapper/{$this->vname} 2>&1";
        $this->DEBUG && Log::info($cmd);
        exec($cmd, $out, $ret);
        // Exit codes: 0 = clean, 1 = corrected (OK), 2 = corrected+reboot (OK),
        // 4+ = uncorrectable errors.
        if ($ret >= 4) {
            $message = 'pre-shrink e2fsck failed — filesystem errors could not be corrected';
            Log::error($message);
            Log::error(var_export($out, true));
            $payload->message = $message;
            addEvent($payload, 'SHRINK', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);
            // Restore the vault to an accessible state.
            $this->openVault();

            return false;
        }

        // ----------------------------------------------------------------
        // Step 3: Determine the exact target filesystem size in MB.
        // The mapper device (/dev/mapper/vname) is the LUKS data region
        // with no header overhead.  We query its actual byte size so the
        // target accounts for any LUKS2 header size exactly.
        // ----------------------------------------------------------------
        $out = null;
        exec("/sbin/blockdev --getsize64 /dev/mapper/{$this->vname} 2>&1", $out, $ret);
        if ($ret === 0 && ! empty($out) && is_numeric($out[0])) {
            $mapperSizeMB = intval(intval($out[0]) / (1024 * 1024));
        } else {
            // Fallback: assume a 32 MB LUKS2 header.
            $mapperSizeMB = $seek - 32;
        }
        $targetFsMB = max($mapperSizeMB - intval($decrement), 64);
        $this->DEBUG && Log::info("mapper size MB: {$mapperSizeMB}, target fs MB: {$targetFsMB}");

        // ----------------------------------------------------------------
        // Step 4: resize2fs — shrink the FILESYSTEM first, while the raw
        // device is still at its original (full) size.  This is the
        // critical fix: resize2fs must run BEFORE dd truncates the device.
        // ----------------------------------------------------------------
        $out = null;
        $cmd = "/bin/sudo /sbin/resize2fs -f /dev/mapper/{$this->vname} {$targetFsMB}M 2>&1";
        $this->DEBUG && Log::info($cmd);
        exec($cmd, $out, $ret);
        if ($ret) {
            $message = 'resize2fs shrink failed';
            Log::error($message);
            Log::error(var_export($out, true));
            $payload->message = $message;
            addEvent($payload, 'SHRINK', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);
            $this->openVault();

            return false;
        }
        $this->DEBUG && Log::info('resize2fs shrink done');

        // ----------------------------------------------------------------
        // Step 5: e2fsck — verify the filesystem is consistent at the new
        // smaller size before we truncate the raw device.
        // ----------------------------------------------------------------
        $out = null;
        $cmd = "/bin/sudo /sbin/e2fsck -fy /dev/mapper/{$this->vname} 2>&1";
        $this->DEBUG && Log::info($cmd);
        exec($cmd, $out, $ret);
        if ($ret >= 4) {
            $message = 'post-resize2fs e2fsck failed';
            Log::error($message);
            Log::error(var_export($out, true));
            $payload->message = $message;
            addEvent($payload, 'SHRINK', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);
            $this->openVault();

            return false;
        }

        // ----------------------------------------------------------------
        // Step 6: luksClose — filesystem is now small enough; close the
        // LUKS container so we can truncate the underlying raw device file.
        // ----------------------------------------------------------------
        $out = null;
        $cmd = "/bin/sudo /sbin/cryptsetup {$this->debugopt} luksClose --deferred {$this->vname} 2>&1";
        exec($cmd, $out, $ret);
        if ($ret) {
            $message = 'luksClose before dd failed';
            Log::error($message);
            Log::error(var_export($out, true));
            $payload->message = $message;
            addEvent($payload, 'SHRINK', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

            return false;
        }

        // Remove the mount point directory and mark the vault CLOSED.
        is_dir($this->mountp) && exec("/bin/sudo /bin/rm -rf {$this->mountp}");
        $now = str_replace('T', ' ', preg_replace("/\+..*/", '', date('c')));
        if ($this->VAULT) {
            $this->VAULT->update(['status' => 'CLOSED', 'last_close' => $now]);
        }

        // ----------------------------------------------------------------
        // Step 7: dd — physically copy the first $count blocks to a temp
        // file.  The filesystem is already smaller than this, so no data
        // is lost by truncating at this boundary.
        // ----------------------------------------------------------------
        $temp_device = tempnam($this->vault, 'wkng');

        $out = null;
        $cmd = "/bin/dd if={$this->device} of={$temp_device} bs={$this->bs}M count={$count} 2>&1";
        $this->DEBUG && Log::info($cmd);
        exec($cmd, $out, $ret);
        if ($ret) {
            $message = 'dd shrink copy failed';
            Log::error($message);
            $payload->message = $message;
            $this->DEBUG && Log::error($cmd);
            Log::error(var_export($out, true));
            addEvent($payload, 'SHRINK', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);
            file_exists($temp_device) && unlink($temp_device);

            return false;
        }

        // ----------------------------------------------------------------
        // Step 8: luksOpen the new smaller device + final e2fsck.
        // ----------------------------------------------------------------
        if (! $this->luksOpen($temp_device)) {
            $message = 'could not open shrunk vault for verification';
            Log::error($message);
            $payload->message = $message;
            addEvent($payload, 'SHRINK', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);
            file_exists($temp_device) && unlink($temp_device);

            return false;
        }

        $out = null;
        $cmd = "/bin/sudo /sbin/e2fsck -fy /dev/mapper/{$this->vname} 2>&1";
        $this->DEBUG && Log::info($cmd);
        exec($cmd, $out, $ret);
        if ($ret >= 4) {
            $message = 'final e2fsck on shrunk vault failed';
            Log::error($message);
            Log::error(var_export($out, true));
            $payload->message = $message;
            addEvent($payload, 'SHRINK', 'FAILED', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);
            $this->closeDevice();
            file_exists($temp_device) && unlink($temp_device);

            return false;
        }

        $this->closeDevice();

        // ----------------------------------------------------------------
        // Step 9: Replace the original raw device with the smaller temp.
        // ----------------------------------------------------------------
        file_exists($temp_device) && rename($temp_device, $this->device);

        $now = str_replace('T', ' ', preg_replace("/\+..*/", '', date('c')));

        if ($this->VAULT) {
            $this->VAULT->update([
                'current_size' => $this->VAULT->current_size - $decrement,
                'updated_at' => $now,
            ]);
        }

        $message = "vault shrink ({$decrement} MB) successful";
        Log::info($message);
        $payload->message = $message;

        $end = microtime(true);
        $this->PERFMON && Log::error(sprintf('%s took %d s', __FUNCTION__, $end - $ini));

        addEvent($payload, 'SHRINK', 'SUCCESS', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

        return $return;
    }

    public function adjustVault($newrole, $oldrole, $payload): bool
    {
        // when user makes a plan upgrade, this function increases
        // the vault size and the token balance accordingly

        $newplan = Plan::where('role_id', $newrole)
            ->where('status', 'available')
            ->where('type', 'service')
            ->first();

        $oldplan = Plan::where('role_id', $oldrole)
            ->where('status', 'available')
            ->where('type', 'service')
            ->first();

        $oldtokens = explode(' ', $oldplan->getTokenAmount());
        $newtokens = explode(' ', $newplan->getTokenAmount());

        $qty = $newtokens[0] - $oldtokens[0];

        $tokens = UserToken::firstOrNew(['user_id' => $this->user->id]);
        $tokens->save();
        $tokens->refresh();

        $olditokens = $tokens->input_tokens_available;
        $itokens = $tokens->input_tokens_available + ($qty * pow(10, 6));
        $otokens = $tokens->output_tokens_available + ($qty * pow(10, 3));

        $tokens->update([
            'input_tokens_available' => $itokens,
            'output_tokens_available' => $otokens,
            'total_tokens_available' => $itokens + $otokens,
        ]);

        $message = 'Tokens adjusted from '.Number::abbreviate($olditokens);
        $message .= ' to '.Number::abbreviate($itokens);

        $cid = 0;
        $payload->message = $message;
        addEvent($payload, 'ADJUST', 'SUCCESS', 'ACTIVITY', $cid, $this->VAULT?->id ?? 0, $this->user->id, $this->user->id);

        $exp = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

        $gbsize = explode(' ', $oldplan->getDiskSize());
        $index = array_search(strtoupper($gbsize[1]), $exp);
        $bsize = floatval($gbsize[0]) * pow(1024, $index);
        $oldmbsize = intval($bsize / pow(1024, 2));

        $gbsize = explode(' ', $newplan->getDiskSize());
        $index = array_search(strtoupper($gbsize[1]), $exp);
        $bsize = floatval($gbsize[0]) * pow(1024, $index);
        $newmbsize = intval($bsize / pow(1024, 2));

        $increment = $newmbsize - $oldmbsize;
        if ($increment > 0) {
            return $this->expandVault($increment, $payload);
        } elseif ($increment < 0) {
            return $this->shrinkVault(abs($increment), $payload);
        }

        return true;
    }

    public function fscheckVault($option = '-fy'): bool
    {
        $return = true;
        $ini = microtime(true);

        if ($this->VAULT && $this->VAULT->status !== 'CLOSED') {
            Log::error('vault must be closed to be fschecked');

            return false;
        }

        if ($this->isMounted()) {
            Log::error('vault must not be mounted to be fschecked');

            return false;
        }

        if (! $this->isOpen(1)) {    // do a soft isOpen
            Log::error('vault must be physically open to be fschecked');

            return false;
        }

        $out = null;
        $cmd = "/bin/sudo /sbin/e2fsck {$option} /dev/mapper/{$this->vname} 2>&1";
        $this->DEBUG && Log::info($cmd);
        exec($cmd, $out, $ret);
        // e2fsck exit codes are a bitmask:
        //   0 = no errors
        //   1 = errors corrected        ← success for a repair run
        //   2 = corrected, reboot rec.  ← acceptable in this context
        //   4 = errors left uncorrected ← real failure
        //   8 = operational error
        //  16 = syntax / usage error
        // Treat 0–2 as success; anything ≥ 4 is a genuine problem.
        if ($ret >= 4) {
            Log::error('e2fsck failed');
            $this->DEBUG && Log::info($cmd);
            Log::error(var_export($out, true));

            return false;
        }

        $end = microtime(true);
        $this->PERFMON && Log::info(sprintf('%s took %d s', __FUNCTION__, $end - $ini));

        return $return;
    }

    public function addPass4Vault(): bool
    {
        $return = true;
        $ini = microtime(true);

        if ($this->VAULT && $this->VAULT->newkey) {
            Log::error('There is an existing new key already. Aborting...');

            return false;
        }

        $temp = $this->getKeyFile();

        $pass = Str::password(32);

        $passtmp = tempnam('/tmp', 'svk-');
        file_put_contents($passtmp, $pass);

        $out = null;
        $cmd = '/bin/sudo /sbin/cryptsetup '.$this->debugopt.' -v --batch-mode --key-file='.escapeshellarg($temp).' --new-key-file='.escapeshellarg($passtmp).' luksAddKey '.escapeshellarg($this->device).' 2>&1';
        exec($cmd, $out, $ret);
        @unlink($passtmp);
        if ($this->CRYPTDEBUG) {
            Log::info('cryptsetup operation completed, exit='.$ret);
        }
        if ($ret) {
            Log::error('luksAddKey failed');
            Log::error(var_export($out, true));
            file_exists($temp) && unlink($temp);

            return false;
        }

        file_exists($temp) && unlink($temp);

        $now = str_replace('T', ' ', preg_replace("/\+..*/", '', date('c')));

        if ($this->VAULT) {
            $this->VAULT->update([
                'newkey' => $this->encrypter->encrypt($pass),
                'updated_at' => $now,
            ]);
        }

        $end = microtime(true);
        $this->PERFMON && Log::info(sprintf('%s took %d s', __FUNCTION__, $end - $ini));

        return $return;
    }

    public function delPass4Vault(): bool
    {
        $return = true;
        $ini = microtime(true);

        if ($this->VAULT && ! $this->VAULT->newkey) {
            Log::error('cannot remove old passphrase without adding a new one first');

            return false;
        }

        $temp = $this->getKeyFile();

        $out = null;
        $cmd = "/bin/sudo /sbin/cryptsetup $this->debugopt -v --batch-mode --key-file={$temp} luksRemoveKey {$this->device} 2>&1";
        exec($cmd, $out, $ret);
        if ($this->CRYPTDEBUG) {
            Log::info('cryptsetup operation completed, exit='.$ret);
        }
        if ($ret) {
            Log::error('luksRemoveKey failed');
            Log::error(var_export($out, true));
            file_exists($temp) && unlink($temp);

            return false;
        }

        file_exists($temp) && unlink($temp);

        $epass = $this->VAULT->newkey;

        $now = str_replace('T', ' ', preg_replace("/\+..*/", '', date('c')));

        if ($this->VAULT) {
            $this->VAULT->update([
                'key' => $epass,
                'newkey' => null,
                'updated_at' => $now,
            ]);
        }

        $end = microtime(true);
        $this->PERFMON && Log::info(sprintf('%s took %d s', __FUNCTION__, $end - $ini));

        return $return;
    }

    public function headerBackup(): bool
    {
        $return = true;
        $ini = microtime(true);

        $temp = $this->getKeyFile();

        ! is_dir($this->headers) && mkdir($this->headers, 0700, true);

        // save the old header
        if (is_file("{$this->headers}/.{$this->vname}.data")) {
            rename("{$this->headers}/.{$this->vname}.data", "{$this->headers}/.{$this->vname}.data.old");
        }

        $out = null;
        $cmd = "/sbin/cryptsetup $this->debugopt -v --key-file={$temp} luksHeaderBackup {$this->device} --header-backup-file {$this->headers}/.{$this->vname}.data 2>&1";
        exec($cmd, $out, $ret);
        if ($this->CRYPTDEBUG) {
            Log::info('cryptsetup operation completed, exit='.$ret);
        }
        if ($ret) {
            Log::error('luksHeaderBackup failed');
            Log::error(var_export($out, true));
            $return = false;
        }

        file_exists($temp) && unlink($temp);

        $end = microtime(true);
        $this->PERFMON && Log::info(sprintf('%s took %d s', __FUNCTION__, $end - $ini));

        return $return;
    }

    public function headerRestore(): bool
    {
        $return = true;
        $ini = microtime(true);

        if ($this->VAULT && $this->VAULT->status !== 'CLOSED') {
            Log::error('vault must be closed to be restored');

            return false;
        }

        if ($this->isMounted()) {
            Log::error('vault must not be mounted to be restored');

            return false;
        }

        if ($this->isOpen()) {
            Log::error('vault must not be open to be restored');

            return false;
        }

        $temp = $this->getKeyFile();

        $out = null;
        $cmd = "/sbin/cryptsetup $this->debugopt -v --batch-mode --key-file={$temp} luksHeaderRestore {$this->device} --header-backup-file {$this->headers}/.{$this->vname}.data 2>&1";
        exec($cmd, $out, $ret);
        if ($this->CRYPTDEBUG) {
            Log::info('cryptsetup operation completed, exit='.$ret);
        }
        if ($ret) {
            Log::error('luksHeaderBackup failed');
            Log::error(var_export($out, true));
            $return = false;
        }

        file_exists($temp) && unlink($temp);

        $end = microtime(true);
        $this->PERFMON && Log::info(sprintf('%s took %d s', __FUNCTION__, $end - $ini));

        return $return;
    }

    public function destroyVault(): bool
    {
        $return = true;
        $ini = microtime(true);

        if ($this->vaultExists()) {
            if (! $this->closeVault(0, true)) {
                Log::error("user {$this->user->username} vault could not be closed");

                return false;
            }
        }

        // remove associated files
        $files = [
            $this->device,
            "{$this->headers}/.{$this->vname}.data",
            "{$this->headers}/.{$this->vname}.data.old",
        ];

        foreach ($files as $file) {
            file_exists($file) && unlink($file);
        }

        if ($this->user->role->name === 'Free') {
            $plan_id = '0';
            $description = 'Free Trial';
            $subscription_id = '0';
        } elseif ($this->user->role->name === 'admin') {
            $plan_id = '0';
            $description = 'Elmer Homero';
            $subscription_id = '1';
        } else {
            $plan = Plan::where('role_id', '=', $this->user->role_id)->first();

            if ($plan) {
                $plan_id = $plan->monthly_price_id;
                $description = $plan->description;
            }

            $paddleSub = PaddleSubscription::where('user_id', '=', $this->user->id)->first();
            $waveSub = WaveSubscription::where('billable_id', '=', $this->user->id)->first();
            $subscription_id = $paddleSub?->id ?? $waveSub?->id ?? '0';
        }

        $cid = 0;
        $uid = $this->user->id;
        $gid = $this->user->group_id ?? $this->user->id;
        if ($this->user) {
            $uid = $this->user->id;
            $gid = $this->user->group_id ?? $this->user->id;
        }

        $vid = $this->VAULT->id;
        // check if there is a db record that needs to be removed...
        // remove associated Annotations...
        Annotation::where('vault_id', $vid)
            ->where('group', $gid)
            ->get()
            ->each
            ->delete();

        // remove associated ContentRequests...
        ContentsRequest::where('vault_id', $vid)
            ->where('group', $gid)
            ->get()
            ->each
            ->delete();

        // remove associated Bookmarks...
        Bookmark::where('vault_id', $vid)
            ->where('user_id', $uid)
            ->get()
            ->each
            ->delete();

        // remove associated FileLists...
        FileList::where('vault_id', $vid)
            ->where('user_id', $uid)
            ->get()
            ->each
            ->delete();

        // remove associated Reports...
        Report::where('vault_id', $vid)
            ->get()
            ->each
            ->delete();

        // remove associated SuppportCases...
        SupportCase::where('vault_id', $vid)
            ->where('group', $gid)
            ->get()
            ->each
            ->delete();

        // remove associated API keys
        ApiKey::where('user_id', $uid)
            ->get()
            ->each
            ->delete();

        // mark associated sysevents records for deletion in 30 days from now
        Sysevent::where('vault_id', $vid)
            ->where('group', $gid)
            ->update(['status' => 'DELETED']);

        $this->VAULT->delete();

        $payload = (object) [
            'description' => $description,
            'plan_id' => $plan_id,
            'message' => 'VAULT REMOVE',
        ];
        addEvent($payload, 'DEL_VAULT', 'SUCCESS', 'ACTIVITY', $cid, $this->VAULT->id, $uid, $gid);

        $end = microtime(true);
        $this->PERFMON && Log::info(sprintf('%s took %d s', __FUNCTION__, $end - $ini));

        return $return;
    }

    public function vaultUsage(): array
    {
        $return = true;

        if (! $this->VAULT || $this->VAULT->status !== 'OPEN' || ! $this->isMounted()) {
            Log::error('cannot get usage on closed vaults');

            return [];
        }

        $cmd = "/bin/df --sync -h {$this->mountp} --output=source,size,used,avail,pcent,ipcent,itotal";
        exec($cmd, $out, $ret);
        if ($ret || count($out) < 2) {
            Log::error('df failed or returned no data');
            Log::error(var_export($out, true));

            return [];
        }

        // FIELDS: Filesystem, Size, Used, Avail, Use%, IUse%, Inodes
        $data = preg_split('/\s+/', trim(array_pop($out)));
        $fields = preg_split('/\s+/', trim(array_pop($out)));
        $usage = [];
        foreach ($fields as $i => $value) {
            $usage[$value] = $data[$i] ?? null;
        }

        return $usage;
    }

    public function vaultExists(): bool
    {
        if ($this->vaultsDisabled) {
            return true;
        }

        return $this->VAULT && file_exists($this->device) && filesize($this->device);
    }

    public function isMounted(): bool
    {
        if ($this->vaultsDisabled) {
            return true;
        }

        $cmd = "/bin/findmnt -clnt ext4,ext3,ext2|/bin/grep -q {$this->vname}";
        exec($cmd, $out, $ret);

        return ! $ret;
    }

    public function isOpen($soft = 0): bool
    {
        if ($this->vaultsDisabled) {
            return true;
        }

        if ($soft) {
            return file_exists("/dev/mapper/{$this->vname}");
        } else {
            return file_exists("/dev/mapper/{$this->vname}") && $this->isMounted();
        }
    }

    public function isShared(): bool
    {
        if ($this->vaultsDisabled) {
            return true;
        }

        if (isset($this->VAULT)) {
            return $this->VAULT->shared_status;
        }

        return false;
    }

    public function currentSize(): int
    {
        if (isset($this->VAULT)) {
            return $this->VAULT->current_size;
        }

        return 0;
    }

    public function getMountPoint(): string
    {
        return $this->mountp;
    }

    public function getVaultName(): string
    {
        return $this->vname;
    }

    public function getVaultDates(): array
    {
        $creation = $this->VAULT->created_at;
        $last_open = $this->VAULT->last_open;
        $last_close = $this->VAULT->last_close;

        if ($this->VAULT->created_at instanceof Carbon) {
            $creation = $this->VAULT->created_at->Format('Y-m-d H:i:s');
        }

        if ($this->VAULT->last_open instanceof Carbon) {
            $creation = $this->VAULT->last_open->Format('Y-m-d H:i:s');
        }

        if ($this->VAULT->last_close instanceof Carbon) {
            $creation = $this->VAULT->last_close->Format('Y-m-d H:i:s');
        }

        $dates = [
            'creation' => $creation,
            'last_open' => $last_open,
            'last_close' => $last_close,
        ];

        return $dates;
    }

    public function doesItFit($size, $files = 0): bool
    {
        // find if the provided size (in bytes) fits inside the vault
        $return = true;

        if (! $this->VAULT || $this->VAULT->status !== 'OPEN' || ! $this->isMounted()) {
            Log::error('cannot find usage on closed vaults');

            return false;
        }

        $cmd = "/bin/df --sync -B1 {$this->mountp} --output=source,size,used,avail,pcent,ipcent,iused,itotal";
        exec($cmd, $out, $ret);
        if ($ret) {
            $this->etype = 'error';
            $this->emessage = 'Could not find available space in vault.';
            Log::error($this->emessage);
            Log::error($cmd);
            Log::error(var_export($out, true));

            return false;
        }

        // FIELDS: Filesystem, Size, Used, Avail, Use%, IUse%, Inodes
        $data = preg_split("/\s+/", array_pop($out));
        $fields = preg_split("/\s+/", array_pop($out));
        $usage = [];
        foreach ($fields as $i => $value) {
            $usage[$value] = $data[$i];
        }

        $this->DEBUG && Log::info(var_export($usage, true));

        if (($usage['Avail'] - $size) <= 0) {
            $this->etype = 'error';
            $this->emessage = 'Not enough space left in vault.';
            Log::error($this->emessage);

            return false;
        }

        if (($usage['IUsed'] + $files) >= $usage['Inodes']) {
            $this->etype = 'error';
            $this->emessage = 'Not enough inodes left in vault.';
            Log::error($this->emessage);

            return false;
        }

        return true;
    }

    public function getContents($sdir = null)
    {
        ini_set('memory_limit', '768M');
        $return = true;
        $ini = microtime(true);

        $dir = ($sdir && is_dir($sdir)) ? $sdir : $this->mountp;
        $jsonContents = "{$dir}/{$this->jsonContents}";

        if (is_file($jsonContents)) {
            $SupportFiles = json_decode(file_get_contents($jsonContents));

            if (! $SupportFiles) {
                Log::error('Regenerating vault content object');
                $SupportFiles = json_decode($this->support2json($dir));
            }
        } else {
            $SupportFiles = json_decode($this->support2json($dir));
        }

        $end = microtime(true);
        $this->PERFMON && Log::info(sprintf('%s took %d s', __FUNCTION__, $end - $ini));

        return $SupportFiles;
    }

    public function updateContents($sdir = null): bool
    {
        $return = true;
        $ini = microtime(true);

        $dir = ($sdir && is_dir($sdir)) ? $sdir : $this->mountp;

        if (! $dir) {
            return false;
        }

        $return = ($this->support2json($dir) !== null);

        $end = microtime(true);
        $this->PERFMON && Log::info(sprintf('%s took %d s', __FUNCTION__, $end - $ini));

        return $return;
    }

    /**
     * Repack an extracted sosreport directory into a tar(.xz|.gz|.bz2|.zst)(.gpg)
     * archive at the vault root. The original packed filename is reconstructed
     * from the directory name + compression + (optional) passphrase, so the
     * resulting file can be re-uploaded and unpacked through the normal flow.
     *
     * Files listed in json/exclusion_list.json (EXTRAS symlink + sosreport
     * metadata sidecars) are skipped via tar --exclude-from. Tar runs under
     * sudo to handle root-owned files preserved from the source system, then
     * is piped to `gpg --symmetric` when a passphrase is supplied — matching
     * what `sos --encrypt-pass` produces and what VaultTools::decrypt() reads.
     */
    public function repack(string $dirname, ?string $passphrase, string $compression): array
    {
        $result = ['ok' => false, 'file' => null, 'message' => ''];

        if (! $this->isOpen()) {
            $result['message'] = __('vault.dir_vault_closed');

            return $result;
        }

        $mount = $this->getMountPoint();
        $src = "{$mount}/{$dirname}";

        clearstatcache();
        if (! is_dir($src)) {
            $result['message'] = __('vault.dir_not_found');

            return $result;
        }

        $compression = strtolower(trim($compression)) ?: 'xz';
        [$flag, $ext, $factor] = match ($compression) {
            'gz', 'gzip' => ['-z', 'gz', 1.5],
            'bz', 'bz2', 'bzip', 'bzip2' => ['-j', 'bz2', 1.6],
            'zst', 'zstd' => ['--zstd', 'zst', 1.7],
            default => ['-J', 'xz', 1.7],
        };

        $encrypt = ($passphrase !== null && $passphrase !== '');
        $targetBase = "{$dirname}.tar.{$ext}";
        $targetName = $encrypt ? "{$targetBase}.gpg" : $targetBase;
        $targetPath = "{$mount}/{$targetName}";

        // Reject if EITHER the plain or encrypted variant of the archive already
        // exists in the vault — picking up the unpack pipeline would clash.
        clearstatcache();
        if (is_file($targetPath) || is_file("{$mount}/{$targetBase}") || is_file("{$mount}/{$targetBase}.gpg")) {
            $result['message'] = __('vault.repack_conflict');

            return $result;
        }

        // Estimate packed size: dir_size / compression_factor. Reverses the
        // factors xtract() uses to estimate the unpacked size.
        $dirSize = 0;
        $duOut = [];
        $duRet = 0;
        exec('/bin/du -sb '.escapeshellarg($src).' 2>/dev/null', $duOut, $duRet);
        if ($duRet === 0 && isset($duOut[0])) {
            $dirSize = (int) preg_replace('/\s.*/', '', $duOut[0]);
        }
        $estSize = (int) ceil($dirSize / $factor);
        if (! $this->doesItFit($estSize, 1)) {
            $result['message'] = __('vault.repack_no_space');

            return $result;
        }

        $exclusionListPath = base_path('json/exclusion_list.json');
        if (! is_file($exclusionListPath)) {
            Log::error("repack: exclusion list missing at {$exclusionListPath}");
            $result['message'] = __('vault.repack_failed');

            return $result;
        }
        $exclusions = json_decode(file_get_contents($exclusionListPath), true) ?: [];
        $excFile = tempnam(sys_get_temp_dir(), 'sos-exc-');
        file_put_contents($excFile, implode("\n", $exclusions)."\n");

        $tempPass = null;
        $tarErr = tempnam(sys_get_temp_dir(), 'sos-tar-err-');
        $gpgErr = null;

        try {
            if ($encrypt) {
                $tempPass = tempnam('/var/tmp', 'sos-pass-');
                file_put_contents($tempPass, $passphrase);
                chmod($tempPass, 0600);
                $gpgErr = tempnam(sys_get_temp_dir(), 'sos-gpg-err-');

                // Capture each stage's stderr into its own file so post-mortem
                // logging can show which side of the pipeline failed.
                $pipeline = sprintf(
                    '/bin/sudo /bin/tar -C %s --exclude-from=%s %s -cf - %s 2>%s | /bin/gpg --symmetric --batch --pinentry-mode loopback --no-tty --passphrase-file %s --output %s 2>%s',
                    escapeshellarg($mount),
                    escapeshellarg($excFile),
                    $flag,
                    escapeshellarg($dirname),
                    escapeshellarg($tarErr),
                    escapeshellarg($tempPass),
                    escapeshellarg($targetPath),
                    escapeshellarg($gpgErr),
                );
                $cmd = '/bin/bash -c '.escapeshellarg("set -o pipefail; {$pipeline}");
            } else {
                $cmd = sprintf(
                    '/bin/sudo /bin/tar -C %s --exclude-from=%s %s -cf %s %s 2>%s',
                    escapeshellarg($mount),
                    escapeshellarg($excFile),
                    $flag,
                    escapeshellarg($targetPath),
                    escapeshellarg($dirname),
                    escapeshellarg($tarErr),
                );
            }

            $out = [];
            $ret = 0;
            exec($cmd, $out, $ret);

            if ($ret !== 0) {
                $tarOut = is_file($tarErr) ? trim((string) file_get_contents($tarErr)) : '';
                $gpgOut = ($gpgErr && is_file($gpgErr)) ? trim((string) file_get_contents($gpgErr)) : '';
                Log::error("repack failed (exit {$ret}): {$cmd}");
                Log::error('repack tar stderr: '.($tarOut !== '' ? $tarOut : '(empty)'));
                if ($encrypt) {
                    Log::error('repack gpg stderr: '.($gpgOut !== '' ? $gpgOut : '(empty)'));
                }
                if (! empty($out)) {
                    Log::error('repack stdout: '.var_export($out, true));
                }
                if (file_exists($targetPath)) {
                    @unlink($targetPath);
                }
                $result['message'] = __('vault.repack_failed');

                return $result;
            }

            $result['ok'] = true;
            $result['file'] = $targetName;
            $result['message'] = __('vault.repack_success', ['file' => $targetName]);

            return $result;
        } finally {
            if (file_exists($excFile)) {
                @unlink($excFile);
            }
            if ($tempPass && file_exists($tempPass)) {
                @unlink($tempPass);
            }
            if (file_exists($tarErr)) {
                @unlink($tarErr);
            }
            if ($gpgErr && file_exists($gpgErr)) {
                @unlink($gpgErr);
            }
        }
    }

    private function support2json($dir): string
    {
        // find files and directories in the vault and generate the corresponding jsons (-d option)

        $DIR = ($dir && is_dir($dir)) ? $dir : $this->mountp;

        $today = str_replace('T', ' ', preg_replace("/\+..*/", '', date('c')));
        $hierarchy = ['nodes' => []];
        $list = [];
        $fileRegEx = ".*\/[\.].*";
        $findOptions = '%M %n %u %g %s %TY-%Tm-%Td %TT %TZ %i %h/%f %h/%l %Y';

        // lrwxrwxrwx 1 www-data www-data 32 2024-01-18 15:10:44.1870670000 ACDT 27 /vault/jlruedaVault/sosreport-host0-SSUP2026-2024-01-18-jlmavst/date /vault/jlruedaVault/sosreport-host0-SSUP2026-2024-01-18-jlmavst/sos_commands/systemd/timedatectl d

        $depth = ($DIR === $this->mountp) ? ' -maxdepth 1' : '';

        $cmd = sprintf("/bin/find %s%s -regextype egrep ! -regex \"%s\" -printf \"%s\n\" 2>&1", $DIR, $depth, $fileRegEx, $findOptions);

        exec($cmd, $out, $ret);
        if ($ret) {
            Log::error('find failed');
            Log::error($cmd);
            $this->DEBUG && Log::error(var_export($out, true));
            $return = null;
        }

        // convert each line into a node
        foreach ($out as $line) {
            if ($line) {
                if ($DIR == $this->mountp) {
                    if (preg_match("/lost\+found/", $line)) {
                        continue;
                    }
                }
                $node = $this->get_node($line, $DIR);
                if ($node) {
                    $dirs = explode('/', $node['path']);
                    array_pop($dirs);
                    $hierarchy = $this->mkNestedArray($dirs, $hierarchy, $node);
                }
            }
        }

        if ($DIR === $this->mountp) {
            foreach ($hierarchy['nodes'] as $i => $node) {
                // add the checksums to files
                if ($node['type'] === '-') {
                    $out = null;
                    $cmd = "/bin/sha256sum {$DIR}/{$node['name']}|/bin/cut -f1 -d' '";
                    exec($cmd, $out, $ret);
                    if (! $ret) {
                        $hierarchy['nodes'][$i]['sum'] = $out[0];
                    }
                }
                // add size to dirs
                if ($node['type'] === 'd') {
                    $out = null;
                    $cmd = "/bin/du -sb {$DIR}/{$node['name']}|/bin/cut -f1";
                    exec($cmd, $out, $ret);
                    if (! $ret) {
                        $hierarchy['nodes'][$i]['size'] = $out[0];
                    }
                }
            }
        }

        // mount everythig in a root node (javascript performs a recursive selection)
        $final_array = ['nodes' => []];
        $line = "drwxr-xr-x 1 www-data www-data 0 {$today} +0930 99999999 /";
        $node = $this->get_node($line, $DIR);
        array_push($final_array['nodes'], $node);
        $final_array['nodes'][0]['nodes'] = $hierarchy['nodes'];

        $jsonContents = "{$DIR}/{$this->jsonContents}";
        file_put_contents($jsonContents, json_encode($final_array, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // add a unique id
        $identifier = "{$DIR}/.identifier";
        if (! is_file($identifier)) {
            $bytes = random_bytes(20);
            $id = substr(bin2hex($bytes), 0, 16);
            file_put_contents($identifier, "{$id}\n");
        }

        return file_get_contents($jsonContents);
    }

    private function get_node($line, $DIR)
    {

        // lrwxrwxrwx 1 www-data www-data 32 2024-01-18 15:10:44.1870670000 ACDT 27 /vault/jlruedaVault/sosreport-host0-SSUP2026-2024-01-18-jlmavst/date /vault/jlruedaVault/sosreport-host0-SSUP2026-2024-01-18-jlmavst/sos_commands/systemd/timedatectl d

        $file = preg_split("/\s+/", preg_replace(":{$DIR}/:", '', $line), 12);

        if (! isset($file[9])) {
            return;
        }

        $name = basename($file[9]);
        $path = preg_replace("/{$name}$/", '', $file[9]);
        if ($name == basename($DIR)) {
            return;
        }
        if ($path == '/') {
            $path = '';
        }
        $node = [
            'id' => $file[8],
            'name' => $name,
            'path' => $path,
            'type' => $file[0][0],
            'perms' => preg_replace('/^./', '', $file[0]),
            'owner' => $file[2],
            'group' => $file[3],
            'size' => $file[4],
            'date' => $file[5],
            'time' => explode('.', $file[6])[0],
            'tz' => $file[7],
            'sum' => '',
            'realpath' => '',
            'realtype' => '',
        ];

        if ($node['type'] == 'l') {
            $node['realpath'] = $this->relative2absolute($file[10]);
            $node['realtype'] = $file[11];
        }

        if ($node['type'] == 'd' || $node['realtype'] == 'd') {
            $node['nodes'] = [];
        }

        return $node;
    }

    private function relative2absolute($path): string
    {
        $dirs = explode('/', $path);

        again:
        foreach ($dirs as $index => $dir) {
            if ($dir == '.') {
                array_splice($dirs, $index, 1);
                goto again;
            } elseif ($dir == '..') {
                $n = 1;
                while ($dirs[$index + $n] == '..') {
                    $n++;
                }
                array_splice($dirs, ($index - $n), ($n * 2));
                goto again;
            }
        }

        $newpath = implode('/', $dirs);

        return $newpath;
    }

    private function mkNestedArray($path, $tree, $node): array
    {
        if ($path && count($path)) {
            $target = array_shift($path);
            $isInLevel = $this->find_node($target, $tree['nodes']);
            if ($isInLevel > -1) {
                // navigate
                $dir = $tree['nodes'][$isInLevel];
                if ($dir['type'] == 'd') {
                    $tree['nodes'][$isInLevel] = $this->mkNestedArray($path, $dir, $node);
                }
            }
        } else {
            $isInLevel = $this->find_node($node['name'], $tree['nodes']);
            if ($isInLevel < 0) {
                if (! preg_match("/\d{4}-\d{2}-\d{2}/", $node['name'])) {
                    array_unshift($tree['nodes'], $node);
                } else {
                    array_push($tree['nodes'], $node);
                }
            }
        }

        return $tree;
    }

    private function find_node($name, $tree): int
    {
        $i = 0;
        foreach ($tree as $dir) {
            if ($dir['name'] == $name) {
                return $i;
            }
            $i++;
        }

        return -1;
    }

    private function luksOpen($device): bool
    {
        if (! $this->isOpen()) {

            retry:
                $temp = $this->getKeyFile();

            $out = null;
            $cmd = "/bin/sudo /sbin/cryptsetup $this->debugopt --batch-mode --key-file={$temp} luksOpen {$device} {$this->vname} 2>&1 ";
            exec($cmd, $out, $ret);
            if ($this->CRYPTDEBUG) {
                Log::info('cryptsetup operation completed, exit='.$ret);
            }
            if ($ret) {
                if (preg_match("/Device {$this->vname} already exists./", $out[0])) {
                    if (! $this->closeDevice()) {
                        Log::error('luksOpen failed. Trying again...');
                        file_exists($temp) && unlink($temp);

                        return false;
                    }
                    goto retry;
                }
                Log::error('luksOpen failed.');
                file_exists($temp) && unlink($temp);

                return false;
            }
            file_exists($temp) && unlink($temp);

            return true;
        }

        return true;
    }

    private function getKeyFile($pass = null): string
    {
        if (! $pass) {
            $pass = $this->encrypter->decrypt($this->VAULT->key);
        }
        $temp = tempnam('/var/tmp', 'step2');
        file_put_contents($temp, $pass);

        return $temp;
    }

    public function find_node_by_attr($tree, $attr1, $value1, $attr2 = null, $value2 = null)
    {
        $found = null;
        if ($tree && gettype($tree) == 'array') {
            foreach ($tree as $node) {
                if ($attr2 === null && $value2 === null) {
                    if (isset($node->nodes) && $node->{$attr1} != $value1) {
                        array_push($this->trajectoryIDS, $node->id);
                        $found = $this->find_node_by_attr($node->nodes, $attr1, $value1);
                        if ($found) {
                            break;
                        } else {
                            array_pop($this->trajectoryIDS);
                        }
                    } elseif ($node->{$attr1} == $value1) {
                        $node->trajectory = implode('/', $this->trajectoryIDS);
                        $this->trajectoryIDS = [];
                        $found = $node;
                        break;
                    }
                } else {
                    if (isset($node->nodes) && ($node->{$attr1} != $value1 || $node->{$attr2} != $value2)) {
                        array_push($this->trajectoryIDS, $node->id);
                        $found = $this->find_node_by_attr($node->nodes, $attr1, $value1, $attr2, $value2);
                        if ($found) {
                            break;
                        } else {
                            array_pop($this->trajectoryIDS);
                        }
                    } elseif ($node->{$attr1} == $value1 && $node->{$attr2} == $value2) {
                        $node->trajectory = implode('/', $this->trajectoryIDS);
                        $this->trajectoryIDS = [];
                        $found = $node;
                        break;
                    }
                }
            }
        }

        return $found;
    }

    public function getFiles(): array
    {
        // Get the files (sos files) store directly in the vault
        // used in sosFileController.php
        $files = [];
        $tree = $this->getContents();
        if ($tree) {
            foreach ($tree->nodes[0]->nodes as $node) {
                if ($node->type == '-') {
                    $files[] = $node;
                }
            }
        }

        return $files;
    }

    public function getFileById($id)
    {
        // Get the file (packed sos file) store directly in the vault
        $files = [];
        $tree = $this->getContents();
        if ($tree) {
            foreach ($tree->nodes[0]->nodes as $node) {
                if ($node->type == '-' && $node->id == $id) {
                    return $node;
                }
            }
        }

        return null;
    }

    public function getDirById($id)
    {
        // Get the dir (unpacked sos dir) store directly in the vault
        $files = [];
        $tree = $this->getContents();
        if ($tree) {
            if ($id == 99999999) {
                return $tree->nodes[0];
            }
            foreach ($tree->nodes[0]->nodes as $node) {
                if ($node->type == 'd' && $node->id == $id) {
                    return $node;
                }
            }
        } else {
            Log::error('no contents file found');
        }

        return null;
    }

    public function getDirByName($name)
    {
        // Get the dir (unpacked sos dir) store directly in the vault
        $files = [];
        $tree = $this->getContents();
        if ($tree) {
            if ($name == '') {
                return $tree->nodes[0];
            }
            foreach ($tree->nodes[0]->nodes as $node) {
                if ($node->type == 'd' && $node->name == $name) {
                    return $node;
                }
            }
        } else {
            Log::error('no contents file found');
        }

        return null;
    }

    public function getDirs(): array
    {
        // Get all dirs (unpacked sos dirs) store directly in the vault
        // used in sosFileController.php
        $files = [];
        $tree = $this->getContents();
        if ($tree) {
            foreach ($tree->nodes[0]->nodes as $node) {
                if ($node->type === 'd') {
                    $files[] = $node;
                }
            }
        }

        return $files;
    }

    public function getAll(): array
    {
        // Get the files (sos files) and dirs store directly in the vault
        // used in sosFileController.php
        $files = [];
        $tree = $this->getContents();
        if ($tree) {
            foreach ($tree->nodes[0]->nodes as $node) {
                $files[] = $node;
            }
        }

        return $files;
    }

    public function getFilePathById($vid, $did, $fid, $cid = 0)
    {
        $cacheKey = "{$vid}:{$did}:{$fid}:{$cid}";
        if (array_key_exists($cacheKey, self::$filePathCache)) {
            return self::$filePathCache[$cacheKey];
        }

        $result = $this->computeFilePathById($vid, $did, $fid, $cid);
        self::$filePathCache[$cacheKey] = $result;

        return $result;
    }

    private function computeFilePathById($vid, $did, $fid, $cid = 0)
    {
        // only used here and in SosServiceProvider.php
        if (! $this->vaultsDisabled) {
            if ($vid != $this->VAULT->id) {
                // ok this is a share...
                // does the vault is shared?
                // does the dir existsi is vault?
                // does the fir existsi is dir?

                // to retrieve any content vaultid, dirid and fileid is needed
                // serach did in vault (need a dir/valut table for this)
                // when a sosreport package gets extracted, save the vaultid and the did in a table
                return null;
            }
        }

        $dir = $this->getDirById($did);

        if (! $dir) {
            Log::error('directory not found');

            return null;
        }

        $filepath = "{$this->mountp}/{$dir->name}";

        $tree = $this->getContents($filepath);

        if (! $tree) {
            Log::error('directory cannot be read');

            return null;
        }

        $found = $this->search_fileID($fid, $tree);

        if (! $found) {
            Log::error("file $filepath not found");

            return null;
        }

        $filepath .= '/';
        $filepath .= ($found->type === 'l') ? $found->realpath : "{$found->path}{$found->name}";
        $found->filePath = $filepath;

        if (! is_file($filepath)) {
            Log::error("file $filepath does not exist");

            return null;
        }

        $cmd = '/usr/bin/file -b '.escapeshellarg($filepath);
        $out = [];
        exec($cmd, $out, $ret);
        if ($ret) {
            Log::error("file $filepath cannot be opened");

            return null;
        }
        $found->fileType = $out[0];

        $size = $found->size;
        if ($found->type === 'l') {
            $cmd = '/usr/bin/stat --format=%s '.escapeshellarg($filepath);

            // get the real size...
            $out = [];
            exec($cmd, $out, $ret);
            if ($ret) {
                Log::error("symlink $filepath cannot stat");

                return null;
            }
            $size = $out[0];
        }
        $found->size = $size;

        if (preg_match('/symbolic link to.. * /', $found->fileType)) {
            // follow the link...
            $filepath = realpath($filepath);
            if ($filepath === false || ! str_starts_with($filepath, $this->mountp)) {
                return null;
            }

            $cmd = '/usr/bin/file -b '.escapeshellarg($filepath);
            $out = [];
            exec($cmd, $out, $ret);
            if ($ret) {
                Log::error("symlink $filepath cannot be opened");

                return null;
            }
            $found->fileType = $out[0];
            $found->filePath = $filepath;

            // get the real size...
            $cmd = '/usr/bin/stat --format=%s '.escapeshellarg($filepath);
            $out = [];
            exec($cmd, $out, $ret);
            if ($ret) {
                Log::error("symlink $filepath cannot stat");

                return null;
            }
            $found->size = $out[0];
        }

        // get number of lines...
        $lines = 0;
        $cmd = '/bin/cat '.escapeshellarg($filepath).'|/usr/bin/wc -l';
        $out = [];
        exec($cmd, $out, $ret);
        if ($ret) {
            Log::error("symlink $filepath cannot stat");

            return null;
        }
        $lines = $out[0];
        $found->fileLines = $lines;

        // log::info(var_export($found, true));

        return $found;
    }

    public function getFileContentsById($vid, $did, $fid, $offset = 0, $cid = 0)
    {
        $cacheKey = "{$vid}:{$did}:{$fid}:{$offset}:{$cid}";
        if (array_key_exists($cacheKey, self::$fileContentsCache)) {
            return self::$fileContentsCache[$cacheKey];
        }

        $result = $this->computeFileContentsById($vid, $did, $fid, $offset, $cid);
        self::$fileContentsCache[$cacheKey] = $result;

        return $result;
    }

    private function computeFileContentsById($vid, $did, $fid, $offset = 0, $cid = 0)
    {
        $found = $this->getFilePathById($vid, $did, $fid, $cid);
        // log::info(var_export($found, true));
        if (! $found) {
            return [];
        }
        $filepath = $found->filePath;
        $filetype = $found->fileType;

        $mimeTypes = json_decode($this->supportedMimeTypes);

        foreach ($mimeTypes as $type) {
            if (preg_match(":^{$type->message}:", $filetype)) {
                $tempf = tempnam('/tmp', 'sos');
                try {
                    $contents = null;

                    // add file meta data
                    $type->name = basename($filepath);
                    $type->owner = $this->VAULT->owner;
                    $type->group = $this->VAULT->group;
                    $type->perms = $this->VAULT->perms;
                    $type->subscription_id = $this->VAULT->subscription_id;
                    $type->plan_id = $this->VAULT->plan_id;
                    $type->role_id = $this->VAULT->role_id;
                    $type->chunked = false;
                    $type->isSosHtml = false;
                    $type->title = $found->name;
                    $type->path = $found->path;
                    $type->size = $found->size;
                    $type->date = $found->date;
                    $type->time = $found->time;
                    $type->tz = timezone_name_from_abbr($found->tz);

                    // file classification
                    $type->isLogFile = false;
                    $type->isTable = false;
                    $type->separator = '';
                    $type->has_header = false;
                    $type->header_row = 0;
                    $type->headers = '';
                    $type->columns = 0;
                    $type->records = [];
                    $type->ini_time = '';
                    $type->ini_date = '';
                    $type->fin_time = '';
                    $type->fin_date = '';

                    if ($type->message == 'empty') {

                        $type->title = $type->message;
                        $contents = "file {$type->name} is empty";

                    } elseif ($type->allowed == 'no') {

                        $type->title = $type->message;
                        $contents = "Not allowed for file {$type->name}";

                    } elseif ($found->name === 'sos.html' && $found->path === 'sos_reports/'
                        && str_contains($sosRaw = (string) file_get_contents($filepath), DataTools::SOS_HTML_FIXED_MARKER)) {
                        // The fixSosHtml'd sos_reports/sos.html index: serve it whole
                        // and un-escaped so the File Viewer renders it as HTML (never
                        // chunk, never htmlspecialchars). The name+path+marker triple
                        // means this only ever fires for that one fixed file — any
                        // other .html falls through to normal (escaped) handling.
                        $type->isSosHtml = true;
                        $contents = $sosRaw;
                    } elseif ($found->size > $this->tooBig) {
                        $contents = "{$found->name} is too large to retrieve complete.";
                        $type->chunked = true;

                        $fileHandle = fopen($filepath, 'rb');
                        fseek($fileHandle, $offset);
                        $bigContents = htmlspecialchars(fread($fileHandle, $this->chunkSize));
                        fclose($fileHandle);
                        $bigLines = count(explode("\n", $bigContents));

                    } else {
                        switch ($type->mime) {
                            case 'text/html':

                                if (preg_match("/.*\.json$/i", $type->name)) {
                                    $contents = json_encode(json_decode(file_get_contents($filepath)), JSON_PRETTY_PRINT);
                                } elseif (preg_match("/.*\.sqlite$/i", $type->name)) {

                                    $cmd = '/usr/bin/sqlite3 '.escapeshellarg($filepath).' .dump | /usr/bin/tee '.escapeshellarg($tempf);
                                    exec($cmd, $out, $ret);
                                    if (is_file($tempf) && filesize($tempf) > 0) {
                                        $contents = file_get_contents($tempf);
                                    } else {
                                        $contents = "file {$type->name} is empty";
                                    }

                                } elseif (preg_match("/.*\.pcap$/i", $type->name)) {

                                    $cmd = '/usr/sbin/tcpdump -tttt -r '.escapeshellarg($filepath).' | /usr/bin/tee '.escapeshellarg($tempf);
                                    exec($cmd, $out, $ret);
                                    if (is_file($tempf) && filesize($tempf) > 0) {
                                        $contents = file_get_contents($tempf);
                                    } else {
                                        $contents = "file {$type->name} is empty";
                                    }

                                } elseif (preg_match("/.*\.gz$/i", $type->name)) {

                                    $cmd = '/bin/gunzip -c '.escapeshellarg($filepath).' | /usr/bin/tee '.escapeshellarg($tempf);
                                    exec($cmd, $out, $ret);
                                    if (is_file($tempf) && filesize($tempf) > 0) {
                                        $contents = file_get_contents($tempf);
                                    } else {
                                        $contents = "file {$type->name} is empty";
                                    }
                                } else {
                                    $contents = file_get_contents($filepath);
                                }

                                break;
                            default:
                                // $contents = base64_encode(file_get_contents($filepath));
                                $contents = file_get_contents($filepath);
                                break;
                        }
                        $type->contents = $contents;
                    }
                    $lines = count(explode("\n", $contents)) - 1;

                    $type->contents = $type->chunked ? $bigContents : $contents;
                    $type->lines = $type->chunked ? $bigLines : $lines;
                    $type->totalLines = $type->chunked ? $found->fileLines : $type->lines;

                    if ($type->isSosHtml) {
                        // A rendered HTML blob is neither a table nor a log — skip the
                        // scans, and zero the line count so no line-number gutter is
                        // drawn behind the page.
                        $type->lines = 0;
                        $type->totalLines = 0;
                    } else {
                        // determine what type of file is been served. Options are file, table or logfile
                        // check if is a table like file
                        $tableAnanlysis = $this->isTable($type->contents);
                        [
                            $type->isTable,
                            $type->separator,
                            $type->columns,
                            $type->has_header,
                            $type->header_row,
                            $lines
                        ] = array_values($tableAnanlysis);
                        // log::info(var_export($tableAnanlysis, true));

                        // check if is a log file
                        $logAnanlysis = $this->isLinuxLog($type->contents, $type->isTable);
                        [
                            $isTable,
                            $type->isLogFile,
                            $separator,
                            $columns,
                            $headers,
                            $has_header,
                            $header_row,
                            $lines,
                            $type->records,
                            $dates,
                        ] = array_values($logAnanlysis);
                        // log::info(var_export($logAnanlysis, true));

                        if ($type->isTable && $type->columns < 4) {
                            // don't bother with files that have few columns
                            $type->isTable = false;
                            $type->records = [];
                        }

                        if ($type->isLogFile) {
                            $type->isTable = $isTable;
                            $type->separator = $separator;
                            $type->columns = $columns;
                            $type->headers = $headers;
                            $type->has_header = $has_header;
                            $type->header_row = $header_row;

                            if (! empty($type->records)) {
                                $length = count($type->records) - 1;
                                $first = $type->records[0];
                                $last = $type->records[$length];

                                $type->ini_time = $first['time'];
                                $type->fin_time = $last['time'];
                                $type->ini_date = $dates[0];
                                $type->fin_date = array_pop($dates);
                            }
                        }
                    }

                    /*
                    $forLog = json_decode(json_encode($type));
                    $forLog->contents = '';
                    $forLog->records = [];
                    log::info(var_export($forLog, true));
                    */

                    // Keep $type->contents as the actual first-chunk bytes
                    // ($bigContents) for chunked files. Resetting it back to
                    // the "is too large to retrieve complete." placeholder
                    // here used to make the table view parse that placeholder
                    // string into a single bogus row of headers and data.
                    // Raw view already loads chunks directly via fseek/fread,
                    // so it doesn't depend on this field.

                    $payload = (object) [
                        'message' => 'file found',
                        'name' => basename($filepath),
                        'title' => $found->name,
                        'path' => $found->path,
                        'size' => $found->size,
                        'date' => $found->date,
                        'time' => $found->time,
                    ];
                    $uid = 0;
                    $gid = 0;
                    if ($this->user) {
                        $uid = $this->user->id;
                        $gid = $this->user->id;
                    }
                    addEvent($payload, 'OPEN_FILE', 'SUCCESS', 'NORMAL', $cid, $this->VAULT->id, $uid, $gid);

                    return $type;
                } finally {
                    @unlink($tempf);
                }
            }
        }

        return [];
    }

    public function isTable(string $content): array
    {
        // detect if a text file has a table format or not
        $allLines = preg_split('/\R/', $content);

        // Strip blank lines while preserving original line indices for header mapping
        $lines = [];
        $lineMap = []; // filtered index → original file line index
        foreach ($allLines as $originalIdx => $line) {
            if (trim($line) !== '') {
                $lineMap[count($lines)] = $originalIdx;
                $lines[] = $line;
            }
        }

        $totalLines = count($lines);

        if ($totalLines < 2) {
            return $this->baseResult(false);
        }

        $separators = [
            'space' => '/ {1,}/',
            "\t" => "/\t+/",
            ';' => '/;+/',
            // "|"    => "/\|+/",
            ',' => '/,+/',
        ];

        foreach ($separators as $sepName => $pattern) {

            $rows = [];
            foreach ($lines as $line) {
                if (preg_match($pattern, $line)) {
                    $fields = preg_split($pattern, trim($line));
                    if (count($fields) > 1) {
                        $rows[] = $fields;
                    }
                }
            }

            $rowCount = count($rows);
            if ($rowCount < 2) {
                continue;
            }

            // Column consistency check. Use the 25th-percentile column count as
            // the split limit so that files with a variable-length last column
            // (e.g. systemctl list-units, ps, top) are still detected as tables.
            // 75 % of rows have at least this many columns by definition, so the
            // count is stable even when descriptions vary widely in word count.
            $counts = array_map('count', $rows);
            sort($counts);

            $columnCount = $counts[(int) floor($rowCount * 0.25)];

            if ($columnCount < 2) {
                continue;
            }

            // regenerate rows with the correct number of fields in case the last field has spaces
            // also track each row's original file line index for accurate header_row reporting
            $rows = [];
            $rowLineIndices = []; // maps row index → original file line index
            foreach ($lines as $filteredIdx => $line) {
                if (preg_match($pattern, $line)) {
                    $fields = preg_split($pattern, trim($line), $columnCount);
                    $n = count($fields);
                    if ($n == $columnCount) {
                        // at this stage it's more important to keep the table format than the
                        // completeness of the table because the raw file will have everything
                        $rows[] = $fields;
                        $rowLineIndices[] = $lineMap[$filteredIdx];
                    }
                }
            }

            // Filter rows that match dominant column count. Keep only consistent rows
            $consistentRows = array_values(array_filter(
                $rows,
                fn ($r) => count($r) === $columnCount
            ));

            if (count($consistentRows) <= ($totalLines / 2)) {
                continue;
            }

            $headerIndex = $this->detectHeaderRowIndex($rows, $columnCount);
            $hasHeader = $headerIndex !== null;
            $headerOriginLine = $hasHeader ? ($rowLineIndices[$headerIndex] ?? $headerIndex) : null;

            // Secondary header scan: if no header was found inside the consistent
            // rows, look at lines that precede the first data row. Files like
            // `systemctl list-units` have a header with fewer fields than the data
            // rows (no leading ● bullet, description is a single label word), so
            // it was excluded from the second pass but is still a valid header.
            if (! $hasHeader && ! empty($rowLineIndices)) {
                $firstDataOriginalLine = $rowLineIndices[0];
                $headerCandidate = null;

                foreach ($allLines as $origIdx => $line) {
                    if ($origIdx >= $firstDataOriginalLine) {
                        break;
                    }
                    if (trim($line) === '') {
                        continue;
                    }
                    $fields = preg_split($pattern, trim($line));
                    if (count($fields) < 2) {
                        continue;
                    }
                    // All fields must look like column labels (non-numeric text)
                    $looksLikeHeader = array_reduce(
                        $fields,
                        fn (bool $carry, string $f): bool => $carry && rtrim(trim($f), '%') !== '' && ! is_numeric(rtrim(trim($f), '%')),
                        true
                    );
                    if ($looksLikeHeader) {
                        $headerCandidate = $origIdx; // keep the last match before data
                    }
                }

                if ($headerCandidate !== null) {
                    $hasHeader = true;
                    $headerOriginLine = $headerCandidate;
                }
            }

            return [
                'is_table' => true,
                'separator' => $sepName,
                'columns' => $columnCount,
                'has_header' => $hasHeader,
                'header_row_index' => $headerOriginLine,
                'lines_analyzed' => $totalLines,
            ];
        }

        return $this->baseResult(false);
    }

    public function baseResult(bool $isTable): array
    {
        return [
            'is_table' => $isTable,
            'separator' => null,
            'columns' => null,
            'has_header' => false,
            'header_row_index' => null,
            'lines_analyzed' => 0,
        ];
    }

    public function detectHeaderRowIndex(array $rows, int $columns): ?int
    {
        // Keep only rows that match dominant column count
        $validRowIndexes = [];

        foreach ($rows as $i => $row) {
            if (is_array($row) && count($row) === $columns) {
                $validRowIndexes[] = $i;
            }
        }

        if (count($validRowIndexes) < 2) {
            return null;
        }

        // Sampling caps to keep this O(N²) algorithm bounded for huge files.
        // Why: for a 10k-row syslog with no header, scanning every row as a
        // header candidate against every other row pegged a CPU core for 30s+.
        // A real header always lives near the top, and column-type statistics
        // (numeric vs lowercase ratio) converge well within 200 sample rows.
        $candidateIndexes = array_slice($validRowIndexes, 0, 50);
        $sampleIndexes = array_slice($validRowIndexes, 0, 200);

        $bestScore = 0;
        $bestIndex = null;

        foreach ($candidateIndexes as $candidateIndex) {

            $mismatchColumns = 0;

            for ($col = 0; $col < $columns; $col++) {

                $numericCount = 0;
                $lowerCaseCount = 0;
                $total = 0;

                foreach ($sampleIndexes as $rowIndex) {

                    if ($rowIndex === $candidateIndex) {
                        continue;
                    }

                    $value = $this->cleanValue($rows[$rowIndex][$col] ?? '');

                    if ($value === '') {
                        continue;
                    }

                    $total++;

                    if (is_numeric($value)) {
                        $numericCount++;
                    }

                    if (preg_match('/[a-z]/', $value)) {
                        $lowerCaseCount++;
                    }
                }

                if ($total === 0) {
                    continue;
                }

                $dominantIsNumeric = ($numericCount / $total) > 0.6;

                $candidateValue = $this->cleanValue($rows[$candidateIndex][$col] ?? '');
                $candidateIsNumeric = is_numeric($candidateValue);

                if ($dominantIsNumeric !== $candidateIsNumeric) {
                    // Numeric vs non-numeric mismatch — classic header signal.
                    $mismatchColumns++;
                } elseif (! $dominantIsNumeric) {
                    // Case-based signal for all-text columns: header labels like
                    // UNIT, LOAD, ACTIVE, SUB are all-uppercase while data values
                    // like "loaded", "active", "running" are lowercase/mixed-case.
                    $dominantHasLowercase = ($lowerCaseCount / $total) > 0.6;
                    $candidateIsAllUpper = $candidateValue !== ''
                        && preg_match('/[A-Z]/', $candidateValue)
                        && ! preg_match('/[a-z]/', $candidateValue);

                    if ($dominantHasLowercase && $candidateIsAllUpper) {
                        $mismatchColumns++;
                    }
                }
            }

            $score = $mismatchColumns / $columns;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $candidateIndex; // original index in $rows
            }
        }

        return ($bestScore > 0.3) ? $bestIndex : null;
    }

    public function cleanValue(string $value): string
    {
        $value = trim($value);

        return rtrim($value, '%');
    }

    public function isLinuxLog(string $content, $isTable): array
    {
        // detect if a text file has a log file (syslog type) format or not
        $lines = preg_split('/\R/', $content);
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));

        if (count($lines) === 0) {
            return $this->baseLogResult(false, $isTable);
        }

        $parsed = [];
        $dates = [];
        $matchedLines = 0;

        foreach ($lines as $index => $line) {

            $entry = $this->parseLinuxLogLine($line, $index + 1);

            if ($entry !== null) {
                $matchedLines++;
                $parsed[] = $entry;

                if (! in_array($entry['date'], $dates)) {
                    $dates[] = $entry['date'];
                }
            }
        }

        /*
        log::info("lines: " . count($lines));
        log::info("matched lines: $matchedLines");
        log::info("records: " . count($parsed));
        */

        // Require majority of lines to match log format
        if ($matchedLines > (count($lines) / 2)) {
            sort($dates);

            return [
                'is_table' => true,
                'is_logFile' => true,
                'separator' => 'log-format',
                'columns' => '9',
                'headers' => 'line|date|time|host|process|pid|uid|severity|message',
                'has_header' => true,
                'header_row_index' => -1,
                'lines_analyzed' => count($lines),
                'content' => $parsed,
                'dates' => $dates,
            ];
        }

        return $this->baseLogResult(false, $isTable);
    }

    public function baseLogResult(bool $isLog, $isTable): array
    {
        return [
            'is_table' => $isTable,
            'is_logFile' => $isLog,
            'separator' => null,
            'columns' => null,
            'headers' => null,
            'has_header' => false,
            'header_row_index' => null,
            'lines_analyzed' => 0,
            'content' => [],
            'dates' => [],
        ];
    }

    public function parseLinuxLogLine(string $line, int $index): ?array
    {
        $line = trim($line);

        /*
         * ISO format with severity:
         * 2020-08-18 11:37:01 WARNING: message
         */
        if (preg_match(
            '/^(?<date>\d{4}-\d{2}-\d{2})\s+(?<time>\d{2}:\d{2}:\d{2})\s+(?<severity>[A-Z]+):\s*(?<msg>.*)$/',
            $line,
            $m
        )) {
            // log::info("A: $index");
            return [
                'line' => $index,
                'date' => $m['date'],
                'time' => $m['time'],
                'host' => null,
                'process' => null,
                'pid' => null,
                'uid' => null,
                'severity' => $m['severity'],
                'message' => $m['msg'],
            ];
        }

        /*
         * ISO format with process@pid:
         * 2023-09-22 14:47:17 /usr/bin/kdumpctl@673: message
         */
        if (preg_match(
            '/^(?<date>\d{4}-\d{2}-\d{2})\s+(?<time>\d{2}:\d{2}:\d{2})\s+(?<proc>[^\s@]+)(@(?<pid>\d+))?:\s*(?<msg>.*)$/',
            $line,
            $m
        )) {
            // log::info("B: $index");
            return [
                'line' => $index,
                'date' => $m['date'],
                'time' => $m['time'],
                'host' => null,
                'process' => $m['proc'],
                'pid' => $m['pid'] ?? null,
                'uid' => null,
                'severity' => null,
                'message' => $m['msg'],
            ];
        }

        /*
         * ISO format without explicit process/severity:
         * 2023-09-22 14:47:17 message
         */
        if (preg_match(
            '/^(?<date>\d{4}-\d{2}-\d{2})\s+(?<time>\d{2}:\d{2}:\d{2})\s+(?<msg>.*)$/',
            $line,
            $m
        )) {
            // log::info("C: $index");
            return [
                'line' => $index,
                'date' => $m['date'],
                'time' => $m['time'],
                'host' => null,
                'process' => null,
                'pid' => null,
                'uid' => null,
                'severity' => null,
                'message' => $m['msg'],
            ];
        }

        /*
         * ---- Existing patterns below (syslog, kernel, etc.) ----
         */

        // Classic syslog
        if (preg_match(
            '/^(?<month>\w{3})\s+(?<day>\d{1,2})\s+(?<time>\d{2}:\d{2}:\d{2})\s+(?<host>\S+)\s+(?<proc>[^\[:]+)(\[(?<pid>\d+)\])?:\s*((?<severity>(?i:warning|fatal|error|alert|emerg|crit|err|notice|info|debug)):)*\s*(?<msg>.*)$/',
            $line,
            $m
        )) {
            // log::info("D: $index");
            return [
                'line' => $index,
                'date' => "{$m['month']} {$m['day']}",
                'time' => $m['time'],
                'host' => $m['host'],
                'process' => trim($m['proc']),
                'pid' => $m['pid'] ?? null,
                'uid' => null,
                'severity' => $m['severity'] ?? null,
                'message' => $m['msg'],
            ];
        }

        // log::info("E: $index");
        return null;
    }

    private function search_fileID($fileID, $SupportFiles)
    {
        $found = null;
        foreach ($SupportFiles->nodes as $inode) {
            if (isset($inode->nodes) && $inode->nodes) {
                // this is a directory
                $found = $this->search_fileID($fileID, $inode);
                if ($found) {
                    break;
                }
            } elseif ($inode->id == $fileID) {
                return $inode;
                break;
            }
        }

        return $found;
    }

    public function getBookmarks()
    {
        if (! $this->vaultExists()) {
            return null;
        }

        if (! $this->isOpen()) {
            return null;
        }

        if (! $this->isMounted()) {
            return null;
        }

        if (! $this->VAULT) {
            return null;
        }

        if (! isset($this->VAULT->bookmarks)) {
            return $this->bookmarks;
        }

        $bookmarks = json_decode($this->VAULT->bookmarks);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->bookmarks;
        }

        if (! count((array) $bookmarks)) {
            return $this->bookmarks;
        }

        return $bookmarks;
    }

    public function setBookmarks($bookmarks)
    {
        if (! $this->vaultExists()) {
            return null;
        }

        if (! $this->isOpen()) {
            return null;
        }

        if (! $this->isMounted()) {
            return null;
        }

        if (! $this->VAULT) {
            return null;
        }

        if (count($bookmarks)) {
            $i = 0;
            $newbookmarks = [];
            foreach ($bookmarks as $bookmark) {
                if (! isset($bookmark['name']) || ! isset($bookmark['path'])) {
                    Log::info(var_export($bookmark, true));

                    continue;
                }
                if (! preg_match('/fa. fa-folder/', $bookmark['icon'])) {
                    $bookmark['icon'] = $i++ % 2 ? 'fas fa-file' : 'far fa-file';
                }
                $bookmark['title'] = preg_replace('/_..*$/', '', $bookmark['name']);
                array_push($newbookmarks, $bookmark);
            }

            $this->VAULT->update([
                'bookmarks' => json_encode($newbookmarks),
            ]);
        } else {
            $this->VAULT->update([
                'bookmarks' => '',
            ]);
        }

        $this->DEBUG && Log::info('bookmarks updated');

        return true;
    }

    public function getToolOutput($tool, $vid, $did, $cid)
    {
        $dir = $this->getDirById($did);

        if (! $dir) {
            Log::error('directory not found');

            return null;
        }

        $filepath = "{$this->mountp}/{$dir->name}";

        $tree = $this->getContents($filepath);

        if ($tree) {
            $found = $this->find_node_by_attr($tree->nodes, 'name', 'sos.html');
        }

        $date = $found && is_object($found) && $found->date ? $found->date : $dir->date;
        $time = $found && is_object($found) && $found->time ? $found->time : $dir->time;

        $mimeType = 'text/html';
        $type = (object) [
            'name' => $tool,
            'message' => 'ASCII text',
            'mime' => $mimeType,
            'allowed' => 'yes',
            'owner' => $this->VAULT->owner,
            'group' => $this->VAULT->group,
            'perms' => $this->VAULT->perms,
            'subscription_id' => $this->VAULT->subscription_id,
            'plan_id' => $this->VAULT->plan_id,
            'role_id' => $this->VAULT->role_id,
            'chunked' => false,
            'title' => $tool,
            'path' => '',
            'size' => 0,
            'date' => $date,
            'time' => $time,
        ];

        $SosProvider = new SosServiceProvider($this, $vid, $did, $cid);

        $contents = '';
        switch ($tool) {
            case 'Summary':
                $contents = $SosProvider->getSummary();
                break;
            case 'Top':
                $contents = $SosProvider->getTop();
                break;
            case 'Report':
                $contents = $SosProvider->getReport();
                break;
        }
        $type->contents = $contents;
        $type->lines = 0;

        $payload = (object) [
            'message' => 'file found',
            'name' => $tool,
            'title' => $tool,
            'path' => '',
            'size' => 0,
            'date' => $date,
            'time' => $time,
        ];
        $uid = 0;
        $gid = 0;
        if ($this->user) {
            $uid = $this->user->id;
            $gid = $this->user->id;
        }
        addEvent($payload, 'OPEN_FILE', 'SUCCESS', 'NORMAL', $cid, $this->VAULT->id, $uid, $gid);

        return $type;
    }

    public function parseOldFilename($filename)
    {
        // sosreport-cen-tlg-dbl-02-20250523104451.tar.xz (sosreport v3.3 and lower)
        $this->DEBUG && Log::info("entering parseOldFilename: {$filename}");
        $fdata = (object) [
            'secured' => false,
            'sosreport' => '',
            'label' => '',
            'host' => '',
            'case' => '',
            'date' => '',
            'sosid' => '',
            'gpg' => false,
            'compression' => '',
            'tar' => false,
            'obfuscated' => false,
            'path' => '',
            'serial' => 0,
            'customer' => '',
            'version' => '',
            'owner' => '',
            'group' => '',
            'perms' => '',
            'link' => '',
            'file_id' => 0,
            'vault_id' => 0,
        ];
        $compression_methods = ['gz', 'xz', 'bz', 'zip'];

        // sosreport-cen-tlg-dbl-02-20250523104451.tar.xz (sosreport v3.3 and lower)
        $nameparts = explode('-', $filename);

        if (isset($nameparts)) {
            $extensions = explode('.', array_pop($nameparts));

            $fdata->path = implode('-', $nameparts);

            if (isset($extensions[0])) {
                if (preg_match('/(20[0-3][0-9])([0-1][0-9])([0-3][0-9])([0-2][0-9][0-5][0-9][0-5][0-9])/', $extensions[0], $matches)) {
                    $fdata->date = "{$matches[1]}-{$matches[2]}-{$matches[3]}";
                    $fdata->sosid = $matches[4];
                } elseif ($extensions[0] == 'obfuscated') {
                    $fdata->sosid = array_pop($nameparts);
                }
            }

            foreach (array_reverse($extensions) as $ext) {
                switch ($ext) {
                    case 'gpg':
                        $fdata->gpg = true;
                        break;
                    case 'tar':
                        $fdata->tar = true;
                        break;
                    case 'obfuscated':
                        $fdata->obfuscated = true;
                        break;
                    default:
                        if (in_array($ext, $compression_methods)) {
                            $fdata->compression = $ext;
                        }
                        break;
                }
            }

            if ($nameparts[0] === 'secured') {
                $fdata->secured = true;
                array_shift($nameparts);
            }

            $fdata->sosreport = array_shift($nameparts);

            // cen-tlg-dbl-02
            $fdata->case = array_pop($nameparts);
            $fdata->host = implode('-', $nameparts);
        }

        $fdata->owner = $this->user->id;
        $fdata->group = $this->user->group_id ?? $this->user->id;
        $fdata->perms = '750';

        // Log::info(var_export($fdata , true));
        return $fdata;
    }

    public function parseFilename($filename)
    {
        // this function parses the name of sos report tar file. used in sosFileController.php and vaultbrowser.blade.php
        // sosreport-host0-2024-09-21-nhnyjgp.tar.xz.gpg
        // sosreport-host0-SSUP2026-2024-09-21-nhnyjgp.tar.xz.gpg
        // sosreport-host0-rhel91-SSUP2026-2024-09-21-nhnyjgp.tar.xz.gpg
        // secured-sosreport-host0-rhel91-SSUP2026-2024-09-21-nhnyjgp.tar.xz.gpg
        // sosreport-host0-rhel91-SSUP2026-2024-09-21-nhnyjgp-obfuscated.tar.xz.gpg
        // secured-sosreport-host0-SSUP2026-2024-09-21-nhnyjgp-obfuscated.tar.xz.gpg
        // secured-sosreport-host0-2024-09-21-nhnyjgp-obfuscated.tar.xz.gpg
        // secured-sosreport-host1-sar-ISS-7096-2025-04-14-epchzbr-obfuscated.tar.xz.gpg

        $old = intval(preg_match("/-20[0-3][0-9][0-1][0-9][0-3][0-9][0-2][0-9][0-5][0-9][0-5][0-9]\./", $filename));
        if ($old) {
            return $this->parseOldFilename($filename);
        }

        $this->DEBUG && Log::info("entering parseFilename: {$filename}");
        $fdata = (object) [
            'secured' => false,
            'sosreport' => '',
            'label' => '',
            'host' => '',
            'case' => '',
            'date' => '',
            'sosid' => '',
            'gpg' => false,
            'compression' => '',
            'tar' => false,
            'obfuscated' => false,
            'path' => '',
            'serial' => 0,
            'customer' => '',
            'version' => '',
            'owner' => '',
            'group' => '',
            'perms' => '',
            'link' => '',
            'file_id' => 0,
            'vault_id' => 0,
        ];
        $compression_methods = ['gz', 'xz', 'bz', 'zip'];

        $nameparts = explode('-', $filename);
        if (isset($nameparts)) {
            $extensions = explode('.', array_pop($nameparts));

            $fdata->path = implode('-', $nameparts);

            if (isset($extensions[0])) {
                if ($extensions[0] == 'obfuscated') {
                    $fdata->sosid = array_pop($nameparts);
                } else {
                    $fdata->sosid = array_shift($extensions);
                    $fdata->path = "{$fdata->path}-{$fdata->sosid}";
                }
            }

            foreach (array_reverse($extensions) as $ext) {
                switch ($ext) {
                    case 'gpg':
                        $fdata->gpg = true;
                        break;
                    case 'tar':
                        $fdata->tar = true;
                        break;
                    case 'obfuscated':
                        $fdata->obfuscated = true;
                        break;
                    default:
                        if (in_array($ext, $compression_methods)) {
                            $fdata->compression = $ext;
                        }
                        break;
                }
            }

            if ($nameparts[0] === 'secured') {
                $fdata->secured = true;
                array_shift($nameparts);
            }

            $fdata->sosreport = array_shift($nameparts);
            $fdata->host = array_shift($nameparts);

            $day = array_pop($nameparts);
            $month = array_pop($nameparts);
            $year = array_pop($nameparts);
            $fdata->date = "{$year}-{$month}-{$day}";

            // the dificult part is tell the case-id and the label (if there is one) appart:
            switch (count($nameparts)) {
                case 0:
                    $fdata->case = $fdata->sosid;
                    break;
                case 1:
                    $fdata->case = array_pop($nameparts);
                    break;
                case 2:
                    $fdata->case = implode('-', $nameparts);
                    $fdata->label = array_shift($nameparts);
                    break;
                default:
                    $fdata->label = array_shift($nameparts);
                    $fdata->case = implode('-', $nameparts);
                    break;
            }
        }

        $fdata->owner = $this->user->id;
        $fdata->group = $this->user->group_id ?? $this->user->id;
        $fdata->perms = '750';

        // Log::info(var_export($fdata , true));
        return $fdata;
    }

    public function unpack($key, $filename, $fileid)
    {

        $directory = $this->getMountPoint();

        $fdata = $this->parseFilename($filename);

        $message = '';
        $emessage = '';
        $did = '';
        $cid = '';
        $decrypted = false;

        $statusfile = "/tmp/{$fileid}.json";
        $statuslock = "/tmp/{$fileid}.lock";

        if ($statusfile) {
            $statusdata = [
                'phase' => 'Processing',
                'percentage' => 1,
                'filename' => $filename,
            ];
            file_put_contents($statusfile, json_encode($statusdata));
            sleep(1);
        }

        // if the file is not encrypted, try to unpack it...
        if (! empty($key) || ! $fdata->gpg) {
            if (! $this->doDecryptAndExtract($filename, $directory, $key, $did, $cid, $emessage, $statusfile)) {

                $message = 'File decryption failed. Cannot continue. ';

                // let's try with the upload-pass key...
                if (isset($_SERVER['PHP_AUTH_PW']) && ! empty($_SERVER['PHP_AUTH_PW'])) {
                    $key = $_SERVER['PHP_AUTH_PW'];

                    if (! $this->doDecryptAndExtract($filename, $directory, $key, $did, $cid, $emessage, $statusfile)) {
                        if ($emessage) {
                            // Original: $emessage . ' File was kept in vault. '
                            $message = __('notifications.unpack_kept_in_vault', ['reason' => $emessage]);
                        }

                        notifyError($message);

                        Notification::make()
                            ->title($message)
                            ->icon('phosphor-bell-ringing-duotone')
                            ->iconColor('danger')
                            ->send();

                        return;
                    }

                    $decrypted = true;
                } else {
                    if ($emessage) {
                        // Original: $emessage . ' File was kept in vault. '
                        $message = __('notifications.unpack_kept_in_vault', ['reason' => $emessage]);
                    }

                    notifyError($message);

                    return;
                }
            } else {
                $decrypted = true;
            }

        } elseif (isset($_SERVER['PHP_AUTH_PW']) && ! empty($_SERVER['PHP_AUTH_PW'])) {
            $key = $_SERVER['PHP_AUTH_PW'];

            // let's try with the upload-pass key then...
            if (! $this->doDecryptAndExtract($filename, $directory, $key, $did, $cid, $emessage, $statusfile)) {

                // Original: 'File decryption failed. Cannot continue.'
                $message = __('notifications.unpack_decrypt_no_key');

                if ($emessage) {
                    // Original: $emessage . ' File was kept in vault. '
                    $message = __('notifications.unpack_kept_in_vault', ['reason' => $emessage]);
                }

                notifyError($message);

                Notification::make()
                    ->title($message)
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();

                return;
            }
            $decrypted = true;
        }

        if ($decrypted) {
            $vid = $this->getVaultId();
            // Original: 'File extraction complete.'
            $message = __('notifications.unpack_extraction_complete');

            $this->DEBUG && Log::info('file unpacked successfully');

            if (isset($did)) {
                // pre extract data for summary tool
                $this->DEBUG && Log::info('Generiating Summary tool data');

                $dtools = new DataTools($this, $vid, $did);
                $dtools->summaryData($cid);
            }

            $payload = (object) [
                'message' => $message,
                'name' => $filename,
                'id' => $fileid,
                'via' => 'upload',
            ];
            addEvent($payload, 'UNPACK', 'SUCCESS', 'NORMAL', $cid, $vid, auth()->user()->id, auth()->user()->id);

            if ($statusfile) {
                $statusdata = [
                    'phase' => 'Complete',
                    'percentage' => 100,
                    'filename' => $filename,
                ];
                file_put_contents($statusfile, json_encode($statusdata));
                sleep(2);
            }
        }

        $this->DEBUG && Log::info($message);
        Notification::make()
            ->title($message)
            ->icon('phosphor-bell-ringing-duotone')
            ->iconColor('success')
            ->send();
    }

    public function unpackStatus($fid)
    {
        $this->DEBUG && Log::info("unpackStatus fileId: {$fid}");

        $file = "/tmp/{$fid}.json";
        $lockfile = "/tmp/{$fid}.lock";

        if (file_exists($file)) {
            $statusdata = json_decode(file_get_contents($file));
            file_put_contents($lockfile, ' ');

            return json_encode($statusdata);
        }
    }

    public function doDecryptAndExtract($filename, $dir, $pass, &$did, &$cid, &$errorMesg, $statusfile = null, $customer = null, $version = null, $link = null, $caseOverride = null, $selfHostedUserId = null)
    {
        $this->DEBUG = 1;
        $this->ePhase = null;
        $uid = $this->user?->id ?? auth()->id() ?? 0;

        if (substr($dir, -1) != '/') {
            $dir .= '/';
        }

        $this->DEBUG && Log::info("entering doDecryptAndExtract: {$dir}{$filename}, pass: *********************}");

        // save the sha256 checksum...
        $sha256 = '';
        $tree = $this->getContents($dir);
        if ($tree) {
            $found = $this->find_node_by_attr($tree->nodes, 'name', $filename);
            if ($found) {
                if ($found->sum) {
                    $sha256 = $found->sum;
                }
            }
        }

        $fdata = $this->parseFilename($filename);

        //      dir: /vault/c06ba1a7d99f69fc22a718fb4d744fea/
        // filename: secured-sosreport-vmrhel83x64-rhel83-SSUP2026-2024-09-21-wmqrbgi.tar.xz.gpg
        $fdata->path = "{$dir}{$fdata->path}";
        $this->DEBUG && Log::info('parseFilename: '.var_export($fdata, true));

        // serial is used for when there are several sosreports associated to the same case. Default is 1
        $fdata->serial = 1;
        $fdata->customer = $customer;
        $fdata->version = $version;
        $fdata->link = $link;
        $fdata->vault_id = $this->getVaultId();
        $fdata->self_hosted_user_id = $selfHostedUserId;
        $fdata->sha256 = $sha256;

        $file = "{$dir}{$filename}";

        if (! is_file($file)) {
            // Original: "Couldn't find file. Cannot continue."
            $errorMesg = __('notifications.unpack_file_not_found');
            $this->DEBUG && Log::info($errorMesg);

            return false;
        }

        $this->DEBUG && Log::info('file : '.var_export($file, true));

        $attrs = $this->fileDetect($file);
        $this->DEBUG && Log::info('file attributes: '.var_export($attrs, true));

        // decrypt part
        if ($attrs && $fdata->gpg && $attrs->is_gpg) {
            $this->DEBUG && Log::info('gpg signed file detected. Decrypting');

            if ($statusfile) {
                $statusdata = [
                    'phase' => 'Decrypting',
                    'percentage' => 15,
                    'filename' => basename($filename),
                ];
                file_put_contents($statusfile, json_encode($statusdata));
                sleep(2);
            }

            $file = $pass === null
                ? $this->decryptWithMasterKey($file, $dir, $statusfile)
                : $this->decrypt($file, $dir, $pass, $statusfile);
            if (! $file) {
                // Original: 'Decryption failed. Please verify that the passphrase is correct.'
                $errorMesg = __('notifications.unpack_decrypt_failed');
                $this->DEBUG && Log::info($errorMesg);

                return false;
            }
            // Original: 'Decryption complete. Filename after decryption: '.basename($file)
            $message = __('notifications.decrypt_complete', ['filename' => basename($file)]);
            $this->DEBUG && Log::info($message);

            notifyUser($this->user, $message, 'success');

            // need to recreate the dir contents here
            $this->DEBUG && Log::info("Updating vault contents for: {$this->getMountPoint()}");
            $this->updateContents($this->getMountPoint());
        }

        if ($statusfile) {
            $statusdata = [
                'phase' => 'Extracting',
                'percentage' => 33,
                'filename' => basename($file),
            ];
            file_put_contents($statusfile, json_encode($statusdata));
        }

        $attrs = $this->fileDetect($file);
        $this->DEBUG && Log::info('new file attributes: '.var_export($attrs, true));

        // extract part
        if ($attrs && $fdata->tar && ($attrs->is_gzip || $attrs->is_xz || $attrs->is_bzip || $attrs->is_zip)) {
            $this->DEBUG && Log::info('compressed file detected. Extracting');

            if (! $this->xtract($file, $dir, $fdata->path, $statusfile, $attrs)) {

                if ($this->emessage && $this->etype) {
                    // populeated during decryption or extraction
                    $type = $this->etype;
                    $errorMesg = $this->emessage;
                }

                // update the dir
                $this->DEBUG && Log::info("Updating vault contents in error for: {$this->getMountPoint()}");
                $this->updateContents($this->getMountPoint());

                $this->DEBUG && Log::info('Extraction failed');

                return false;
            }

            // Original: 'Extraction complete. Removing original file: '.basename($file)
            $message = __('notifications.extract_complete', ['filename' => basename($file)]);
            $this->DEBUG && Log::info("Extraction complete. Removing original file: {$file}");

            notifyUser($this->user, $message, 'success');

            file_exists($file) && unlink($file);

            $this->DEBUG && Log::info("Updating vault contents for: {$this->getMountPoint()}");
            $this->updateContents($this->getMountPoint());

            $this->DEBUG && Log::info("Updating case dir contents for: {$fdata->path}");
            $this->updateContents($fdata->path);
        }

        $dirs = $this->getDirs();

        foreach ($dirs as $dir) {
            if ($dir->name === basename($fdata->path)) {
                $fdata->file_id = $dir->id;
                $this->did = $dir->id;
                $did = $dir->id;
            }
        }

        if ($statusfile) {
            $statusdata = [
                'phase' => 'Extracting',
                'percentage' => 88,
                'filename' => $dir->name,
            ];
            file_put_contents($statusfile, json_encode($statusdata));
        }

        // if no case info use the sos-id as default value...
        if (! $fdata->case || $fdata->case == '') {
            $fdata->case = $fdata->sosid;
        } else {
            $cases = SupportCase::where('case', $fdata->case)->count();
            if ($cases) {
                $fdata->serial = $cases + 1;
            }
        }

        // Self-hosted customer uploads: override the case name with SELF-HOST{uid}
        // so all reports from that customer group together in the admin vault.
        if ($caseOverride) {
            $fdata->case = $caseOverride;
            $cases = SupportCase::where('case', $caseOverride)->count();
            $fdata->serial = $cases + 1;
        }

        // update case record...
        $this->DEBUG && Log::info('Creating case record for this unpacked file '.var_export($fdata, true));

        $cid = 0;
        $case = SupportCase::create(get_object_vars($fdata));

        if (! $case) {
            // What shall we do here?!!!
            // Original: 'Could not create the associated case!!'
            $message = __('notifications.case_create_failed');

            Log::error($message);

            notifyUser($this->user, $message, 'error');

            addEvent((object) ['message' => 'case creation error', 'case' => $fdata->case ?? '', 'sosid' => $fdata->sosid ?? ''], 'ADD_CASE', 'FAILED', 'NORMAL', $cid, $this->VAULT->id, $uid, $uid);

            $errorMesg = $emessage;

            return false;
        } else {
            $cid = $case->id;

            addEvent((object) ['message' => 'case created', 'case' => $fdata->case ?? '', 'sosid' => $fdata->sosid ?? ''], 'ADD_CASE', 'SUCCESS', 'NORMAL', $cid, $this->VAULT->id, $uid, $uid);

            // Original: "Associated case {$case->case} serial: {$case->serial} created correctly"
            $message = __('notifications.case_created', ['case' => $case->case, 'serial' => $case->serial]);

            $this->DEBUG && Log::info($message);

            notifyUser($this->user, $message, 'success');
        }

        // create some bookmarks and some file lists...
        $dir = $this->getDirById($did);

        if (! $dir) {
            // Original: 'Directory not found after case creation.'
            $message = __('notifications.unpack_dir_not_found');
            Log::error($message);
            $errorMesg = $message;

            return false;
        }

        $filepath = "{$this->mountp}/{$dir->name}";

        $tree = $this->getContents($filepath);

        if (! $tree) {
            // Original: 'Directory cannot be read after case creation.'
            $message = __('notifications.unpack_dir_not_readable');
            Log::error($message);
            $errorMesg = $message;

            return false;
        }

        // Default bookmarks + FileLists are seeded ONCE per vault, on its first
        // case. They are case-independent (shown across every case of the vault),
        // so re-seeding them for each new case would only pile up duplicate rows
        // that the UI already dedups away. Keyed on the vault already holding any
        // of the user's bookmarks/FileLists.
        $vaultAlreadySeeded = Bookmark::where('user_id', $this->user->id)
            ->where('vault_id', $this->getVaultId())
            ->exists()
            || FileList::where('user_id', $this->user->id)
                ->where('vault_id', $this->getVaultId())
                ->exists();

        if (! $vaultAlreadySeeded) {
            // bookmarks
            $files2bookmark = ['df', 'free', 'ps', 'netstat', 'pstree', 'lsof', 'uptime'];

            foreach ($files2bookmark as $file) {
                $found = $this->find_node_by_attr($tree->nodes, 'name', $file);

                if ($found) {
                    $name = $found->name;
                    if ($found->type == 'l') {
                        $name = basename($found->realpath);
                        $found = $this->find_node_by_attr($tree->nodes, 'name', $name);
                    }

                    if ($found) {
                        $icon = $found->type == 'd' ? 'phosphor-folder-duotone' : 'phosphor-file-duotone';

                        $bookmark = Bookmark::create([
                            'user_id' => $this->user->id,
                            'case_id' => $cid,
                            'vault_id' => $this->getVaultId(),
                            'dir_id' => $did,
                            'name' => $found->name,
                            'fullpath' => $found->path,
                            'filetype' => $found->type,
                            'icon' => $icon,
                        ]);
                    }
                }
            }

            // filelists
            $fileLists = [
                'Process' => ['ps', 'pstree', 'lsof'],
                'Disks' => ['mount', 'findmnt', 'df'],
                'Memory' => ['free', 'swapon_--bytes_--show'],
                'CPU' => ['lscpu', 'cpupower_frequency-info'],
                'Network' => ['netstat', 'nstat_-zas', 'ip_route', 'ip_addr'],
                'System' => ['uptime', 'date', 'hostname'.'uname'],
            ];

            foreach ($fileLists as $filelist => $files) {

                $fileList = FileList::create([
                    'user_id' => $this->user->id,
                    'case_id' => $cid,
                    'vault_id' => $this->getVaultId(),
                    'dir_id' => $did,
                    'name' => $filelist,
                    'title' => $filelist,
                    'statis' => 'available',
                    'enabled' => 1,
                    'icon' => 'phosphor-files-duotone',
                ]);

                foreach ($files as $file) {
                    $found = $this->find_node_by_attr($tree->nodes, 'name', $file);

                    if ($found) {
                        $name = $found->name;
                        if ($found->type == 'l') {
                            $name = basename($found->realpath);
                            $found = $this->find_node_by_attr($tree->nodes, 'name', $name);
                        }

                        if ($found) {
                            $icon = $found->type == 'd' ? 'phosphor-folder-duotone' : 'phosphor-file-duotone';

                            $bookmark = Bookmark::create([
                                'user_id' => $this->user->id,
                                'case_id' => $cid,
                                'vault_id' => $this->getVaultId(),
                                'dir_id' => $did,
                                'name' => $found->name,
                                'fullpath' => $found->path,
                                'filetype' => $found->type,
                                'icon' => $icon,
                                'filelist_id' => $fileList->id,
                            ]);
                        }
                    }
                }
            }
        }

        // when the dirDiff functionality is enabled...
        $gitSupport = 0;

        if ($gitSupport) {
            $this->DEBUG && Log::info('Initializin git...');
            // add git support (50MB extra)
            $gitcmds = [];

            $email = $this->user->email;
            $name = $this->user->name;

            array_push($gitcmds, "/bin/find {$fdata->path} -type f -perm 200 -exec chmod 600 {} \;");
            array_push($gitcmds, "/bin/find {$fdata->path} -type f -perm 000 -exec chmod 600 {} \;");
            array_push($gitcmds, '/bin/git init');
            array_push($gitcmds, "/bin/git config --global user.email {$email}");
            array_push($gitcmds, "/bin/git config --global user.name \"{$name}\"");
            array_push($gitcmds, '/bin/git config --global init.defaultBranch master');
            array_push($gitcmds, '/bin/git add --all');
            array_push($gitcmds, '/bin/git commit -m "initial commit"');

            // $ignoreFile = "{$fdata->path}/.gitignore";
            // $contents = implode("\n", ["proc","proc/*","sys","sys/*",""]);
            // file_put_contents($ignoreFile, $contents);

            chdir($fdata->path);
            foreach ($gitcmds as $cmd) {
                exec($cmd, $out, $ret);
                if ($ret) {
                    Log::error("{$cmd}: failed: {$ret} \n".var_export($out, true));
                }
            }
        }

        if ($statusfile) {
            $statusdata = [
                'phase' => 'Extracting',
                'percentage' => 96,
                'filename' => $dir->name,
            ];
            file_put_contents($statusfile, json_encode($statusdata));
            sleep(1);
        }

        return true;
    }

    private function fileDetect($file)
    {
        $this->DEBUG && Log::info("fileDetect: {$file}");
        $attr = (object) [
            'is_gpg' => false,
            'is_gzip' => false,
            'is_xz' => false,
            'is_bzip' => false,
            'is_rar' => false,
            'is_zip' => false,
        ];

        if (! file_exists($file)) {
            return null;
        }

        $cmd = '/bin/file -b '.escapeshellarg($file);
        exec($cmd, $out, $ret);
        if ($ret) {
            return null;
        }
        $this->DEBUG && Log::info(var_export($out, true));
        $type = array_pop($out);

        $attr->is_gpg = preg_match('/^GPG.*/', $type) || preg_match('/^PGP.*/', $type);
        $attr->is_gzip = preg_match('/^gzip/', $type);
        $attr->is_xz = preg_match('/^XZ/', $type);
        $attr->is_bzip = preg_match('/^bzip/', $type);
        $attr->is_rar = preg_match('/^RAR/', $type);
        $attr->is_zip = preg_match('/^Zip/', $type) || preg_match('/^7-Zip/', $type);

        // Extension fallback: older `file` releases don't recognize modern cv25519
        // public-key-encrypted OpenPGP messages and report them as plain "data".
        // Trust the .gpg extension in that case so the decrypt branch still runs.
        if (! $attr->is_gpg && str_ends_with(strtolower($file), '.gpg')) {
            $attr->is_gpg = true;
        }

        return $attr;
    }

    private function decrypt($file, $dir, $pass, $statusfile = null)
    {
        $gpgdir = "{$dir}.gnupg";

        clearstatcache();
        if (! is_dir($gpgdir)) {
            mkdir($gpgdir, 0700, 1);
        }

        $newname = "{$dir}".basename($file, '.gpg');

        // Attempt to decrypt the passphrase stored encrypted by the Encrypter.
        // Falls back to using $pass as plain text when it was never encrypted
        // (e.g. direct API / test calls that pass the raw GPG passphrase).
        try {
            $actualpass = new Encrypter(key: getSvaultKey('svault0'), cipher: config('app.cipher'))->decrypt($pass);
        } catch (DecryptException $e) {
            $actualpass = $pass;
        }

        if (! $actualpass) {
            // Original: 'decrypt-pass is empty'
            $message = __('notifications.unpack_decrypt_pass_empty');
            Log::error($message);
            $this->etype = 'error';
            $this->ePhase = 'decrypt';
            $this->emessage = $message;

            return null;
        }

        $temp = tempnam('/var/tmp', 'asdasd');
        file_put_contents($temp, $actualpass);

        // Every interpolated path is escapeshellarg'd — $file/$newname derive from
        // the uploaded filename, which is untrusted (mirrors decryptWithMasterKey).
        $tempArg = escapeshellarg($temp);
        $gpgdirArg = escapeshellarg($gpgdir);
        $outArg = escapeshellarg("{$newname}x");
        $fileArg = escapeshellarg($file);

        $cmd = "/bin/gpg --decrypt --pinentry-mode loopback --no-tty --batch --passphrase-file {$tempArg} --homedir {$gpgdirArg} --output {$outArg} {$fileArg} 2>&1";

        $out = null;
        exec($cmd, $out, $ret);
        if ($ret) {
            if (! (file_exists("{$newname}x") && filesize("{$newname}x") > 0)) {
                // lets try with --ignore-mdc-error option for older versions of gpg
                $cmd = "/bin/gpg --decrypt --ignore-mdc-error --pinentry-mode loopback --no-tty --batch --passphrase-file {$tempArg} --homedir {$gpgdirArg} --output {$outArg} {$fileArg} 2>&1";
                $out = null;
                exec($cmd, $out, $ret);
                if ($ret) {
                    file_exists($temp) && unlink($temp);
                    $this->DEBUG && Log::error("$cmd: ".var_export($out, true));
                    $this->etype = 'error';
                    $this->ePhase = 'decrypt';
                    // Original: 'File could not be decrypted.'
                    $this->emessage = __('notifications.unpack_file_decrypt_failed');

                    return null;
                }
            }
        }

        if ($statusfile) {
            $statusdata = [
                'phase' => 'Decrypting',
                'percentage' => 30,
                'filename' => basename($newname),
            ];
            file_put_contents($statusfile, json_encode($statusdata));
            sleep(2);
        }

        file_exists($temp) && unlink($temp);
        file_exists("{$newname}x") && rename("{$newname}x", $newname);
        file_exists($newname) && file_exists($file) && unlink($file);

        return $newname;
    }

    /**
     * Decrypt a sosreport encrypted with the SaaS public key using the master
     * private key in GPG_HOME_BUILD. Used for self-hosted customer uploads.
     */
    private function decryptWithMasterKey($file, $dir, $statusfile = null)
    {
        $gpgHome = config('product.master_gpg_home');
        $passphrase = getMasterGpgPassphrase();

        if (! $gpgHome || ! is_dir($gpgHome)) {
            $this->etype = 'error';
            $this->ePhase = 'decrypt';
            $this->emessage = "Master GPG keyring not found at: {$gpgHome}";
            Log::error($this->emessage);

            return null;
        }

        $newname = "{$dir}".basename($file, '.gpg');
        $tempPass = null;

        $cmd = '/bin/gpg --decrypt --pinentry-mode loopback --no-tty --batch --homedir '.escapeshellarg($gpgHome);

        if ($passphrase !== '') {
            $tempPass = tempnam('/var/tmp', 'svgmk');
            file_put_contents($tempPass, $passphrase);
            $cmd .= ' --passphrase-file '.escapeshellarg($tempPass);
        }

        $cmd .= ' --output '.escapeshellarg("{$newname}x").' '.escapeshellarg($file).' 2>&1';

        $out = null;
        exec($cmd, $out, $ret);

        if ($tempPass && file_exists($tempPass)) {
            unlink($tempPass);
        }

        if ($ret || ! file_exists("{$newname}x") || filesize("{$newname}x") === 0) {
            $this->DEBUG && Log::error('master-key decrypt failed: '.var_export($out, true));
            $this->etype = 'error';
            $this->ePhase = 'decrypt';
            $this->emessage = __('notifications.unpack_file_decrypt_failed');

            return null;
        }

        if ($statusfile) {
            file_put_contents($statusfile, json_encode([
                'phase' => 'Decrypting',
                'percentage' => 30,
                'filename' => basename($newname),
            ]));
        }

        rename("{$newname}x", $newname);
        file_exists($newname) && file_exists($file) && unlink($file);

        return $newname;
    }

    private function xtract($file, $dir, $expectednewdir, $statusfile, $attrs)
    {
        $this->DEBUG && Log::info("entering xtract: {$file}. Expected {$expectednewdir}");

        //      dir: /vault/c06ba1a7d99f69fc22a718fb4d744fea/
        //     file: /vault/c06ba1a7d99f69fc22a718fb4d744fea/secured-sosreport-vmrhel83x64-rhel83-SSUP2026-2024-09-21-wmqrbgi.tar.xz.
        // Expected: /vault/c06ba1a7d99f69fc22a718fb4d744fea/sosreport-vmrhel83x64-rhel83-SSUP2026-2024-09-21-wmqrbgi

        // Abort early if a previously-extracted directory already exists at the
        // expected target. tar would otherwise silently merge into it, mixing
        // old and new content. Triggered after a repack-without-delete + unpack.
        clearstatcache();
        if (is_dir($expectednewdir)) {
            $this->etype = 'error';
            $this->ePhase = 'extract';
            $this->emessage = __('vault.unpack_conflict');
            Log::error($this->emessage." ({$expectednewdir})");

            return false;
        }

        $factor = 1.1;
        if ($attrs) {
            if ($attrs->is_gzip) {
                // GZ (gzip) usually lands around 50–65% reduction (output is 35–50% of original).
                $factor = 1.5;
            }
            if ($attrs->is_xz) {
                // XZ typically achieves compression ratios of 60–72% on text files
                // (meaning the output is 28–40% of the original size),
                $factor = 1.7;
            }
            if ($attrs->is_bzip) {
                // bzip2 typically achieves compression ratios similar to or slightly better than gzip,
                // usually landing the output at around 30–45% of the original size on text.
                $factor = 1.6;
            }
            if ($attrs->is_zip) {
                // similar to gzip
                $factor = 1.5;
            }
        }
        // find the size of the extracted data
        $out = null;
        $cmd = '/bin/tar -xOf '.escapeshellarg($file).' |/bin/wc -c';
        exec($cmd, $out, $ret);
        if ($ret) {
            Log::error("$cmd: ".var_export($out, true));
            $this->etype = 'error';
            $this->ePhase = 'extract';
            // Original: 'Could not calculate the expected size.'
            $this->emessage = __('notifications.unpack_size_calc_failed');
            Log::error($this->emessage);

            return false;
        }
        $expectedSize = $out[0];
        $this->DEBUG && Log::info("xtract: expected size: $expectedSize");

        if ($statusfile) {
            $statusdata = [
                'phase' => 'Extracting',
                'percentage' => 40,
                'filename' => basename($file),
            ];
            file_put_contents($statusfile, json_encode($statusdata));
        }

        // find the number of files of the extracted data
        $out = null;
        $cmd = '/bin/tar -tf '.escapeshellarg($file).' |/bin/wc -l';
        exec($cmd, $out, $ret);
        if ($ret) {
            Log::error("$cmd: ".var_export($out, true));
            $this->etype = 'error';
            $this->ePhase = 'extract';
            // Original: 'Could not calculate the expected file count.'
            $this->emessage = __('notifications.unpack_count_calc_failed');
            Log::error($this->emessage);

            return false;
        }
        $expectedFiles = $out[0];
        $this->DEBUG && Log::info("xtract: expected inodes: $expectedFiles");

        if ($statusfile) {
            $statusdata = [
                'phase' => 'Extracting',
                'percentage' => 48,
                'filename' => basename($file),
            ];
            file_put_contents($statusfile, json_encode($statusdata));
        }

        // check is there is enough space in users vault
        if (! $this->doesItFit($expectedSize * $factor, $expectedFiles)) {
            $this->etype = 'error';
            $this->ePhase = 'extract';
            // Original: 'Not enough space to extract the file.'
            $this->emessage = __('notifications.unpack_no_space');
            Log::error($this->emessage);

            return false;
        }

        // find the name of the directory that will be created
        $out = null;
        $cmd = '/bin/tar -tf '.escapeshellarg($file).' 2>/dev/null|/bin/head -1';
        exec($cmd, $out, $ret);
        if ($ret) {
            $this->etype = 'error';
            $this->ePhase = 'extract';
            // Original: 'Could not determine new extraction directroy.'
            $this->emessage = __('notifications.unpack_dir_unknown');
            Log::error($this->emessage);

            return false;
        }
        $newdir = $dir.preg_replace("/\/.*$/", '', $out[0]);
        $this->DEBUG && Log::info("tar will extract to {$newdir}");

        if ($statusfile) {
            $statusdata = [
                'phase' => 'Extracting',
                'percentage' => 58,
                'filename' => basename($file),
            ];
            file_put_contents($statusfile, json_encode($statusdata));
        }

        // extract. do not extract dev because the vault is mounted nodev
        $out = null;
        $cmd = '/bin/tar -C '.escapeshellarg($dir).' --exclude="dev/*" -xf '.escapeshellarg($file).' 2>&1';
        exec($cmd, $out, $ret);
        if ($ret) {
            Log::error("$cmd: ".var_export($out, true));
            $this->etype = 'error';
            $this->ePhase = 'extract';
            // Original: 'Extraction command failed.'
            $this->emessage = __('notifications.unpack_extract_failed');
            Log::error($this->emessage);

            return false;
        }

        if ($statusfile) {
            $statusdata = [
                'phase' => 'Extracting',
                'percentage' => 74,
                'filename' => basename($file),
            ];
            file_put_contents($statusfile, json_encode($statusdata));
        }

        clearstatcache();
        if (! is_dir($newdir)) {
            // tar extarcated the contents to a different directory. This will never happen
            $this->etype = 'error';
            $this->ePhase = 'extract';
            // Original: 'Extraction to an unknown directory.'
            $this->emessage = __('notifications.unpack_extract_unknown_dir');
            Log::error($this->emessage);

            return false;
        }

        if ($newdir != $expectednewdir) {

            clearstatcache();
            if (is_dir($expectednewdir)) {
                // expected directory already exists. Aborting. This shall not happen
                $this->etype = 'error';
                $this->ePhase = 'extract';
                // Original: 'Expected directory already exists. Aborting.'
                $this->emessage = __('notifications.unpack_dir_exists');
                Log::error($this->emessage);

                // no trash in disk...
                $out = null;
                $cmd = "/bin/rm -rf {$newdir}";
                exec($cmd, $out, $ret);

                return false;
            }

            // rename if needed
            $this->DEBUG && Log::info('Extraction dir is different. Correcting');
            rename($newdir, $expectednewdir);
            $newdir = $expectednewdir;
        }

        // always make a top-level symlink to etc/sos/extras.d
        $extras_dir = "{$newdir}/sos_commands/sos_extras";
        $cmd = "/bin/ln -s '{$extras_dir}' '{$newdir}/EXTRAS'";
        exec($cmd, $out, $ret);
        if ($ret) {
            Log::error("$cmd: failed: ".var_export($out, true));
        }

        return true;
    }

    public function getTools()
    {
        $tools = [];
        if (checkAccess($this->user, 'Basic Tools')) {
            $tools = array_merge($tools, $this->basictools);
        } else {
            $temp = $this->basictools;
            foreach ($temp as $tool) {
                $tool['enabled'] = false;
                $tools[] = $tool;
            }
        }

        if (checkAccess($this->user, 'Advanced Tools')) {
            $tools = array_merge($tools, $this->advancedtools);
        } else {
            $temp = $this->advancedtools;
            foreach ($temp as $tool) {
                $tool['enabled'] = false;
                $tools[] = $tool;
            }
        }

        if (checkAccess($this->user, 'Special Tools')) {
            $tools = array_merge($tools, $this->specialtools);
        } else {
            $temp = $this->specialtools;
            foreach ($temp as $tool) {
                $tool['enabled'] = false;
                $tools[] = $tool;
            }
        }

        // log::info(var_export($tools, true));

        return $tools;
    }

    private $basictools = [
        ['name' => 'Summary',    'id' => '10', 'enabled' => true, 'title' => 'Summary',     'status' => 'available', 'icon' => 'fas fa-stethoscope'],
        ['name' => 'Top',        'id' => '20', 'enabled' => true, 'title' => 'Top',         'status' => 'available', 'icon' => 'fas fa-weight-scale'],
    ];

    private $advancedtools = [
        ['name' => 'Report',     'id' => '60', 'enabled' => true, 'title' => 'Report',       'status' => 'available', 'icon' => 'far fa-file'],
        ['name' => 'Chronoview', 'id' => '70', 'enabled' => false, 'title' => 'Chronoview',  'status' => 'development', 'development', 'icon' => 'nf nf-md-calendar_clock mb-4'],
        ['name' => 'Security',   'id' => '80', 'enabled' => false, 'title' => 'Security',    'status' => 'development', 'development', 'icon' => 'fas fa-shield-halved'],
    ];

    private $specialtools = [
        ['name' => 'File_Compare',   'id' => '110', 'enabled' => false, 'title' => 'File Compare',  'status' => 'development', 'icon' => 'nf nf-md-file_compare mb-4'],
        ['name' => 'Report_Comapre', 'id' => '120', 'enabled' => false, 'title' => 'Report Compare',    'status' => 'development', 'icon' => 'nf nf-dev-git_compare mb-'],
    ];

    private $bookmarks = [
        // ubuntu
        ['name' => 'syslog',   'path' => 'var/log/', 'title' => 'syslog',   'icon' => 'fas fa-file'],
        ['name' => 'boot.log', 'path' => 'var/log/', 'title' => 'boot.log', 'icon' => 'far fa-file'],
        ['name' => 'dmesg',    'path' => 'var/log/', 'title' => 'dmesg',    'icon' => 'fas fa-file'],
        ['name' => 'kern.log', 'path' => 'var/log/', 'title' => 'kern.log', 'icon' => 'far fa-file'],
        ['name' => 'ufw.log',  'path' => 'var/log/', 'title' => 'ufw.log',  'icon' => 'fas fa-file'],
        ['name' => 'dpkg.log', 'path' => 'var/log/', 'title' => 'dpkg.log', 'icon' => 'far fa-file'],
        ['name' => 'lastlog',  'path' => 'var/log/', 'title' => 'lastlog',  'icon' => 'fas fa-file'],

        // RHLE
        ['name' => 'messages', 'path' => 'var/log/', 'title' => 'messages', 'icon' => 'fas fa-file'],
        ['name' => 'secure',   'path' => 'var/log/', 'title' => 'secure', 'icon' => 'fas fa-file'],
        ['name' => 'kdump.log', 'path' => 'var/log/', 'title' => 'kdump.log', 'icon' => 'fas fa-file'],
    ];

    protected $supportedMimeTypes = '[
        {
            "message": "CSV ASCII text",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "BSD makefile script, ASCII text",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "New Line Delimited JSON text data",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "JSON text data",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "Apache Avro version 101",
            "mime": "text/html",
            "allowed": "no"
        },
        {
            "message": "ASCII text.*",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "a /usr/bin/php script, ASCII text executable",
            "mime": "text/html",
            "allowed": "no"
        },
        {
            "message": "Berkeley DB.*",
            "mime": "text/html",
            "allowed": "no"
        },
        {
            "message": "Bourne-Again shell script, ASCII text executable",
            "mime": "text/html",
            "allowed": "no"
        },
        {
            "message": "C source, ASCII text",
            "mime": "text/html",
            "allowed": "no"
        },
        {
            "message": "data",
            "mime": "application/octet-stream",
            "allowed": "no"
        },
        {
            "message": "empty",
            "mime": "text/html",
            "allowed": "no"
        },
        {
            "message": "exported SGML document, ASCII text",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "GPG key public ring, created.*",
            "mime": "text/html",
            "allowed": "no"
        },
        {
            "message": "gzip compressed data.*",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "HTML document, ASCII text.*",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "ISO-8859 text.*",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "Java KeyStore",
            "mime": "text/html",
            "allowed": "no"
        },
        {
            "message": "magic text file for file.* cmd, ASCII text",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "MS Windows icon resource - 1 icon, 41x60, 16 colors",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "NetStumbler log file, 1919501934 stations found",
            "mime": "text/html",
            "allowed": "no"
        },
        {
            "message": "OpenSSH.*",
            "mime": "text/html",
            "allowed": "no"
        },
        {
            "message": "Pascal source, ASCII text",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "PDF document, version 1.4",
            "mime": "application/pdf",
            "allowed": "yes"
        },
        {
            "message": "PEM.*",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "Perl script text executable",
            "mime": "text/html",
            "allowed": "no"
        },
        {
            "message": "PHP script, ASCII text",
            "mime": "text/html",
            "allowed": "no"
        },
        {
            "message": "PNG image data.*",
            "mime": "image/png",
            "allowed": "yes"
        },
        {
            "message": "POSIX shell script.*",
            "mime": "text/html",
            "allowed": "no"
        },
        {
            "message": "Python script, ASCII text executable",
            "mime": "text/html",
            "allowed": "no"
        },
        {
            "message": "RRDTool DB version 0003 64bit aligned little-endian 64bit long.*",
            "mime": "text/html",
            "allowed": "no"
        },
        {
            "message": "SQLite 3.x database, last written using SQLite version 3022000",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "tcpdump capture file.*",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "troff or preprocessor input, ASCII text",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "unified diff output, ASCII text",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "UTF-8 Unicode text.*",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "Unicode text. UTF-8 text.*",
            "mime": "text/html",
            "allowed": "yes"
        },
        {
            "message": "Vim swap file.*",
            "mime": "text/html",
            "allowed": "no"
        },
        {
            "message": "XML 1.0 document.*",
            "mime": "text/xml",
            "allowed": "yes"
        },
        {
            "message": "CSV text",
            "mime": "text/html",
            "allowed": "yes"
        }
    ]';
}

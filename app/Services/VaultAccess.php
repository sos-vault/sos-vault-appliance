<?php

namespace App\Services;

use App\Models\Annotation;
use App\Models\ContentsRequest;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;

/**
 * Central authorization decision for "may this user read this vault?".
 *
 * Historically vault reads were gated only by resolveVaultUser() silently
 * elevating to the vault owner, with no check that the caller was entitled to
 * the vault — any authenticated user could read any tenant's sosreport files by
 * supplying another vault's id (IDOR). This service is the single place that
 * decides entitlement; resolveVaultUser() consults it before elevating.
 *
 * Entitlement mirrors how the app already surfaces content to other users:
 *   - the vault owner and members of the owning group (group vaults);
 *   - admins (operator triage);
 *   - a public / same-group case, scoped to that case's vault;
 *   - a file or directory explicitly shared via the "Share" button, scoped to
 *     the exact file/dir (the ContentsRequest capability that sosShared /
 *     sosSharedDir hand out). "Shared" means status SHARED or LOCKED, matching
 *     the app's own definition (FileContent::withParameters, tool-controls);
 *     expiry is enforced because PurgeExpiredRecords flips status to EXPIRED.
 */
class VaultAccess
{
    /**
     * Whether $user may MANAGE (write to) vault $vid — create/revoke shares,
     * write annotations, flip a case public, etc. This is stricter than read
     * access: only the vault owner, a member of the owning group, or an admin.
     * A share/public-case *recipient* can read but must never be able to write
     * or re-share content they don't own (otherwise they could mint a share and
     * escalate their own read access).
     */
    public static function canManage(?User $user, int|string|null $vid): bool
    {
        $vid = (int) $vid;
        if (! $user || $vid <= 0) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        if (Vault::where('id', $vid)->where('owner', $user->id)->exists()) {
            return true;
        }

        if ($user->group_id) {
            $group = $user->group;
            if ($group && (int) $group->vault_id === $vid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $user may read vault $vid. $cid enables case-scoped grants
     * (public / same-group case), and $did/$fid enable share-scoped grants
     * (a file or directory shared to other users). Case- and share-scoped
     * grants are always tied to $vid so a share in one vault can never unlock
     * another.
     */
    public static function allows(
        ?User $user,
        int|string|null $vid,
        int|string|null $cid = null,
        int|string|null $did = null,
        int|string|null $fid = null,
    ): bool {
        $vid = (int) $vid;
        if (! $user || $vid <= 0) {
            return false;
        }

        // 1-3. Owner / group member / admin can always read what they manage.
        if (self::canManage($user, $vid)) {
            return true;
        }

        // 4. Case-scoped access — only when the case lives in this vault.
        if ($cid) {
            $case = SupportCase::find($cid);
            if ($case && (int) $case->vault_id === $vid) {
                if ($case->is_public) {
                    return true;
                }
                if ($user->group_id && (int) $case->group === (int) $user->group_id) {
                    return true;
                }
            }
        }

        // 5. Share-scoped access — a file or directory the owner shared with
        //    other users of the system (the sosShared / sosSharedDir flow).
        if ($did !== null) {
            $did = (int) $did;

            // Exact file share.
            if ($fid !== null && (int) $fid > 0 && self::hasActiveShare($vid, $did, (int) $fid)) {
                return true;
            }

            // Directory share (file_id 0) — grants files under that directory.
            if (self::hasActiveShare($vid, $did, 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An active share for (vid, did, fid) mirrors exactly what the sosShared /
     * sosSharedDir controllers accept: a ContentsRequest that is not EXPIRED
     * whose Annotation is not PRIVATE.
     *
     * The "is shared" signal is the Annotation status (SHARED, set by
     * shareFile/shareDir; PRIVATE, set by unshare) — NOT the ContentsRequest
     * status, which the annotation-save path (saveAnnotations -> setRows with a
     * 'locked' key) overwrites with the lock flag. Keying off ContentsRequest
     * status would let saving a note silently invalidate the share.
     */
    private static function hasActiveShare(int $vid, int $did, int $fid): bool
    {
        $hasLiveRequest = ContentsRequest::where('vault_id', $vid)
            ->where('dir_id', $did)
            ->where('file_id', $fid)
            ->where('status', '!=', 'EXPIRED')
            ->exists();

        if (! $hasLiveRequest) {
            return false;
        }

        return ! Annotation::where('vault_id', $vid)
            ->where('dir_id', $did)
            ->where('file_id', $fid)
            ->where('status', 'PRIVATE')
            ->exists();
    }

    /**
     * Whether a shared document is LOCKED — i.e. only its owner/manager may add
     * annotations. Matches the app's own definition (FileContent maps
     * `locked` from ContentsRequest.status === 'LOCKED').
     */
    public static function isDocumentLocked(int|string|null $vid, int|string|null $did, int|string|null $fid): bool
    {
        return ContentsRequest::where('vault_id', (int) $vid)
            ->where('dir_id', (int) $did)
            ->where('file_id', (int) $fid)
            ->where('status', 'LOCKED')
            ->exists();
    }
}

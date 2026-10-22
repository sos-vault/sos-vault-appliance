<?php

namespace App\Services;

use App\Models\Group;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves the members who should receive a vault-wide in-app notification
 * for a given case: every member of the vault's group (or the sole owner for
 * a personal vault) — there's no per-recipient field on this channel.
 */
class VaultRecipientResolver
{
    public function forCase(SupportCase $case): Collection
    {
        $group = Group::where('vault_id', $case->vault_id)->first();
        if ($group) {
            $recipients = $group->members;
            if ($group->owner) {
                $recipients = $recipients->push($group->owner);
            }

            return $recipients->unique('id');
        }

        $vault = $case->vault;
        $owner = $vault ? User::find($vault->owner) : null;

        return $owner ? collect([$owner]) : collect();
    }
}

<?php

/**
 * Team & Enterprise Plan — Management Tests
 *
 * Covers:
 *  - Group model CRUD and isFull() logic
 *  - Member creation, limit enforcement, and deletion
 *  - Shared vault lookup for team members
 *  - checkAccess() feature gates: ITSM Integration, Direct Upload, Special Tools
 *  - /settings/team access control (manager vs. member vs. other plan)
 *  - User::isTeamManager() helper
 */

use App\Models\Group;
use App\Models\PlanFeature;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Wave\Plan;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a verified user assigned to the given Spatie role.
 */
function teamUser(string $roleName): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
    ]);
    $user->syncRoles([$roleName]);

    return $user;
}

/**
 * Ensure a Plan with the given English name has the given PlanFeature (bool type).
 */
function ensurePlanFeature(string $planName, string $featureName, bool $enabled): void
{
    $plan = Plan::where('status', 'available')
        ->where('type', 'service')
        ->whereEnglishName($planName)
        ->first();

    if (! $plan) {
        $role = Role::where('name', $planName)->first();
        $plan = Plan::create([
            'name' => $planName,
            'slug' => strtolower($planName),
            'status' => 'available',
            'type' => 'service',
            'features' => '{}',
            'role_id' => $role->id,
        ]);
    }

    $existing = $plan->planFeatures()
        ->whereRaw("json_extract(name, '$.en') = ?", [$featureName])
        ->first();

    if ($existing) {
        $existing->update(['enabled' => $enabled]);
    } else {
        PlanFeature::create([
            'plan_id' => $plan->id,
            'name' => $featureName,
            'type' => 'bool',
            'enabled' => $enabled,
            'sort_order' => 99,
            'status' => 'ready',
        ]);
    }
}

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

// ---------------------------------------------------------------------------
// Group model — CRUD and isFull()
// ---------------------------------------------------------------------------

describe('Group model', function () {

    it('creates a group with correct owner and max_members', function () {
        $manager = teamUser('Team');
        $group = Group::create([
            'name' => 'Alpha Team',
            'owner_id' => $manager->id,
            'max_members' => 8,
        ]);

        expect($group->owner_id)->toBe($manager->id)
            ->and($group->max_members)->toBe(8)
            ->and($group->name)->toBe('Alpha Team');
    });

    it('isFull() returns false when under the member limit', function () {
        $manager = teamUser('Team');
        $group = Group::factory()->create(['owner_id' => $manager->id, 'max_members' => 8]);

        User::factory(3)->create(['group_id' => $group->id]);

        expect($group->isFull())->toBeFalse();
    });

    it('isFull() returns true when the member limit is reached', function () {
        $manager = teamUser('Team');
        $group = Group::factory()->create(['owner_id' => $manager->id, 'max_members' => 2]);

        // 1 member fills the 1 available slot (max_members - 1)
        User::factory()->create(['group_id' => $group->id]);

        expect($group->isFull())->toBeTrue();
    });

});

// ---------------------------------------------------------------------------
// Member management
// ---------------------------------------------------------------------------

describe('Member management', function () {

    it('adding a member sets group_id and increments members count', function () {
        $manager = teamUser('Team');
        $group = Group::factory()->create(['owner_id' => $manager->id, 'max_members' => 8]);
        $member = User::factory()->create(['group_id' => $group->id]);

        expect($group->members()->count())->toBe(1)
            ->and($member->group_id)->toBe($group->id);
    });

    it('deleting a member removes the user record', function () {
        $manager = teamUser('Team');
        $group = Group::factory()->create(['owner_id' => $manager->id, 'max_members' => 8]);
        $member = User::factory()->create(['group_id' => $group->id]);
        $memberId = $member->id;

        $member->delete();

        expect(User::find($memberId))->toBeNull();
    });

    it('enterprise group allows up to 19 members (max_members=20)', function () {
        $manager = teamUser('Enterprise');
        $group = Group::factory()->create(['owner_id' => $manager->id, 'max_members' => 20]);

        User::factory(19)->create(['group_id' => $group->id]);

        expect($group->isFull())->toBeTrue()
            ->and($group->members()->count())->toBe(19);
    });

});

// ---------------------------------------------------------------------------
// Shared vault lookup
// ---------------------------------------------------------------------------

describe('Shared vault', function () {

    it('a team member resolves to the group vault', function () {
        $manager = teamUser('Team');
        $vault = Vault::factory()->forUser($manager->id)->create();
        $group = Group::factory()->create([
            'owner_id' => $manager->id,
            'vault_id' => $vault->id,
            'max_members' => 8,
        ]);
        $member = User::factory()->create(['group_id' => $group->id]);

        // Mirror the VaultTools lookup logic without triggering OS calls.
        $resolved = null;
        if ($member->group_id && ($grp = $member->group) && $grp->vault_id) {
            $resolved = Vault::where('id', $grp->vault_id)->first();
        }

        expect($resolved)->not->toBeNull()
            ->and($resolved->id)->toBe($vault->id);
    });

    it('a user without a group resolves to their own vault', function () {
        $user = teamUser('Basic');
        $vault = Vault::factory()->forUser($user->id)->create();

        $resolved = null;
        if ($user->group_id && ($grp = $user->group) && $grp->vault_id) {
            $resolved = Vault::where('id', $grp->vault_id)->first();
        } else {
            $resolved = Vault::where('owner', $user->id)->first();
        }

        expect($resolved)->not->toBeNull()
            ->and($resolved->id)->toBe($vault->id);
    });

});

// ---------------------------------------------------------------------------
// checkAccess() — ITSM Integration
// ---------------------------------------------------------------------------

describe('checkAccess() — ITSM Integration', function () {

    it('returns true for Basic plan', function () {
        ensurePlanFeature('Basic', 'ITSM Integration', true);
        expect(checkAccess(teamUser('Basic'), 'ITSM Integration'))->toBeTrue();
    });

    it('returns false for Minimal plan', function () {
        ensurePlanFeature('Minimal', 'ITSM Integration', false);
        expect(checkAccess(teamUser('Minimal'), 'ITSM Integration'))->toBeFalse();
    });

    it('returns true for Team plan', function () {
        ensurePlanFeature('Team', 'ITSM Integration', true);
        expect(checkAccess(teamUser('Team'), 'ITSM Integration'))->toBeTrue();
    });

    it('returns true for Enterprise plan', function () {
        ensurePlanFeature('Enterprise', 'ITSM Integration', true);
        expect(checkAccess(teamUser('Enterprise'), 'ITSM Integration'))->toBeTrue();
    });

});

// ---------------------------------------------------------------------------
// checkAccess() — Direct Upload
// ---------------------------------------------------------------------------

describe('checkAccess() — Direct Upload', function () {

    it('returns false for Minimal plan', function () {
        ensurePlanFeature('Minimal', 'Direct Upload', false);
        expect(checkAccess(teamUser('Minimal'), 'Direct Upload'))->toBeFalse();
    });

    it('returns true for Basic plan', function () {
        ensurePlanFeature('Basic', 'Direct Upload', true);
        expect(checkAccess(teamUser('Basic'), 'Direct Upload'))->toBeTrue();
    });

    it('returns true for Team plan', function () {
        ensurePlanFeature('Team', 'Direct Upload', true);
        expect(checkAccess(teamUser('Team'), 'Direct Upload'))->toBeTrue();
    });

});

// ---------------------------------------------------------------------------
// checkAccess() — Special Tools
// ---------------------------------------------------------------------------

describe('checkAccess() — Special Tools', function () {

    it('returns false for Basic plan', function () {
        ensurePlanFeature('Basic', 'Special Tools', false);
        expect(checkAccess(teamUser('Basic'), 'Special Tools'))->toBeFalse();
    });

    it('returns false for Team plan', function () {
        ensurePlanFeature('Team', 'Special Tools', false);
        expect(checkAccess(teamUser('Team'), 'Special Tools'))->toBeFalse();
    });

    it('returns true for Enterprise plan', function () {
        ensurePlanFeature('Enterprise', 'Special Tools', true);
        expect(checkAccess(teamUser('Enterprise'), 'Special Tools'))->toBeTrue();
    });

});

// ---------------------------------------------------------------------------
// /settings/team access control
// ---------------------------------------------------------------------------

describe('/settings/team access', function () {

    it('redirects a Basic plan user', function () {
        actingAs(teamUser('Basic'));
        get(route('settings.team'))->assertRedirect(route('settings.profile'));
    });

    it('redirects a Team member who is not the group owner', function () {
        $manager = teamUser('Team');
        $group = Group::factory()->create(['owner_id' => $manager->id, 'max_members' => 8]);
        $member = User::factory()->create(['group_id' => $group->id]);
        $member->syncRoles(['Team']);

        actingAs($member);
        get(route('settings.team'))->assertRedirect(route('settings.profile'));
    });

    it('renders 200 for the Team manager', function () {
        $manager = teamUser('Team');
        Group::factory()->create(['owner_id' => $manager->id, 'max_members' => 8]);

        actingAs($manager);
        get(route('settings.team'))->assertOk();
    });

    it('renders 200 for an Enterprise manager', function () {
        $manager = teamUser('Enterprise');
        Group::factory()->create(['owner_id' => $manager->id, 'max_members' => 20]);

        actingAs($manager);
        get(route('settings.team'))->assertOk();
    });

});

// ---------------------------------------------------------------------------
// User::isTeamManager()
// ---------------------------------------------------------------------------

describe('User::isTeamManager()', function () {

    it('returns true when the user owns a group', function () {
        $manager = teamUser('Team');
        Group::factory()->create(['owner_id' => $manager->id]);
        expect($manager->isTeamManager())->toBeTrue();
    });

    it('returns false when the user is only a member', function () {
        $manager = teamUser('Team');
        $group = Group::factory()->create(['owner_id' => $manager->id]);
        $member = User::factory()->create(['group_id' => $group->id]);
        expect($member->isTeamManager())->toBeFalse();
    });

});

// ---------------------------------------------------------------------------
// Plan helper functions
// ---------------------------------------------------------------------------

describe('Plan helper functions null guards', function () {

    it('getPlanDiskSizeMB returns safe default when plan is not found', function () {
        $user = teamUser('Minimal');
        // In a fresh test DB there is no Minimal plan row — should not throw
        expect(getPlanDiskSizeMB($user))->toBe(1024);
    });

    it('getPlanDiskSize returns fallback string when plan is not found', function () {
        $user = teamUser('Basic');
        expect(getPlanDiskSize($user))->toBe('1 GB');
    });

    it('getPlanTokens returns zero string when plan is not found', function () {
        $user = teamUser('Team');
        expect(getPlanTokens($user))->toBe('0');
    });

    it('getFeatureDescription returns empty string when plan is not found', function () {
        $user = teamUser('Enterprise');
        expect(getFeatureDescription($user, 'ITSM Integration'))->toBe('');
    });

    it('admin bypasses plan lookup for disk size', function () {
        $admin = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
        $admin->syncRoles(['admin']);
        expect(getPlanDiskSizeMB($admin))->toBe('1000');
    });

});

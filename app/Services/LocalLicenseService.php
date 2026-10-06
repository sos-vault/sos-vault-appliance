<?php

namespace App\Services;

use App\Models\Group;
use App\Models\LocalLicense;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LocalLicenseService
{
    public function __construct(
        private readonly LicenseGeneratorService $licenseGenerator,
        private readonly MachineTokenService $machineTokens,
    ) {}

    /**
     * Verify a clearsigned .lic payload and persist it as the installed license.
     *
     * Validates: GPG signature against the local public keyring, payload shape,
     * presence of the live host's machine_token in the payload's machine_tokens
     * array. Throws RuntimeException with a human-readable reason on any failure.
     *
     * On success, returns the freshly inserted LocalLicense row. The previous
     * installed license (if any) is preserved as history — LocalLicense::current()
     * always returns the latest active row.
     */
    public function install(string $signedContent, ?int $uploadedBy = null): LocalLicense
    {
        $payload = $this->licenseGenerator->verify($signedContent);
        if ($payload === false) {
            throw new RuntimeException('Signature verification failed. The license file is invalid or was not signed by the SOS-Vault build keyring.');
        }

        $required = ['license_id', 'customer_id', 'machine_tokens', 'seats', 'features', 'status', 'issued_at', 'expires_at'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new RuntimeException("License payload is missing required field: {$key}");
            }
        }

        $tokens = $payload['machine_tokens'];
        if (! is_array($tokens) || $tokens === []) {
            throw new RuntimeException('License payload contains no machine tokens.');
        }

        // Derive every token this host can produce — one independent token per
        // captured identifier (machine-id, DMI system UUID, board / system
        // serial). Require at least one to match the license payload. When the
        // host has hardware identifiers beyond the machine-id, require at least
        // one of THOSE to match — that's the teeth against the "copy
        // /etc/machine-id to another box" attack: the machine-id is a plain file
        // that travels, but the DMI tokens won't line up on different hardware.
        $hostTokens = $this->machineTokens->currentHostTokens();
        $matches = array_values(array_intersect($hostTokens, $tokens));

        if ($matches === []) {
            throw new RuntimeException('License is not valid for this server. None of the host\'s machine tokens match the license.');
        }

        $machineIdToken = $this->machineTokens->machineIdToken;
        if ($this->machineTokens->bindingStrength === 'strong' && $machineIdToken !== null) {
            $strongerMatches = array_values(array_filter($matches, fn ($t) => $t !== $machineIdToken));
            if ($strongerMatches === []) {
                throw new RuntimeException('License is not valid for this server. The license matches only the machine-id, which is insufficient on a host with hardware identifiers — the license appears to have been issued for a different machine.');
            }
        } elseif ($this->machineTokens->bindingStrength !== 'strong') {
            Log::warning('LocalLicenseService::install: weak hardware binding — only one host identifier available, installing with single-token match.');
        }

        $license = LocalLicense::create([
            'uuid' => $payload['license_id'],
            'customer_id' => (int) $payload['customer_id'],
            'machine_tokens' => $tokens,
            'seats' => (int) $payload['seats'],
            'features' => is_array($payload['features']) ? $payload['features'] : [],
            'status' => $payload['status'] ?: 'ACTIVE',
            'signed_license' => $signedContent,
            'issued_at' => Carbon::parse($payload['issued_at']),
            'expires_at' => Carbon::parse($payload['expires_at']),
            'uploaded_by' => $uploadedBy,
        ]);

        $this->reconcileGroupSeats($license);

        addEvent(
            [
                'uuid' => $license->uuid,
                'seats' => $license->seats,
                'features' => $license->features,
                'expires_at' => $license->expires_at?->toIso8601String(),
            ],
            'LICENSE_INSTALLED',
            'SUCCESS',
            'ACTIVITY',
            0,
            0,
            $uploadedBy ?? 0,
            0
        );

        return $license;
    }

    /**
     * Raise the appliance team's member cap to the seats AVAILABLE to members.
     *
     * The "Default Team" group is seeded at install time — BEFORE any license
     * exists — so its max_members froze at the no-license fallback (8). Once a
     * license is installed the only ceiling on a group should be the available
     * member seats, so reconcile the sole appliance group up (or down) to that.
     *
     * max_members is the seats AVAILABLE to members, NOT the raw license seat
     * count: one seat is always reserved for the always-included admin operator
     * (mirrors ApplianceLicenseWidget / ManageLicense — a "10-user" license has
     * raw seats=11 and presents 10 to the operator). So target = seats - 1.
     *
     * Only the single-group case is touched: when an operator has created
     * multiple teams they allocate seats per-group via the seat-budgeted Max
     * Members form, and auto-redistributing could exceed the license total.
     */
    private function reconcileGroupSeats(LocalLicense $license): void
    {
        if (! isAppliance()) {
            return;
        }

        $reservedAdminSeats = 1;
        $availableSeats = max(2, (int) $license->seats - $reservedAdminSeats);

        $groups = Group::query()->get();
        if ($groups->count() !== 1) {
            return;
        }

        $group = $groups->first();
        if ((int) $group->max_members !== $availableSeats) {
            $group->update(['max_members' => $availableSeats]);
        }
    }

    /**
     * Number of remaining seats under the currently installed license.
     * Returns 0 when no license is installed (all user creation blocked on appliance).
     */
    public function seatsRemaining(): int
    {
        $license = LocalLicense::current();
        if (! $license) {
            return 0;
        }

        $used = User::query()->count();

        return max(0, $license->seats - $used);
    }
}

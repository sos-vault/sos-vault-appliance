<?php

namespace App\Services;

use RuntimeException;

/**
 * Open-core license-request flow. Derives the live host's machine tokens (the
 * same identifiers used to bind an installed license) and encodes them into a
 * single, copy-pasteable key the operator pastes into sos-vault.com to verify
 * their server and unlock license purchase.
 *
 * This replaces the former sosreport round-trip: no heavy hardware dump, no GPG
 * encryption, no file on disk. The key is NOT secret — it carries only the same
 * hardware identifiers an sosreport exposed in cleartext, and the trust anchor
 * remains the issuer-signed license matched to the live host at install time
 * (see LocalLicenseService::install()), so the security level is unchanged.
 */
class LicenseRequestService
{
    public function __construct(private readonly MachineTokenService $machineTokens) {}

    /**
     * Generate the license-request key for this host.
     *
     * @return string The encoded machine key (prefix "SOSV1.").
     *
     * @throws RuntimeException when not running on an appliance install or the
     *                          host's machine tokens cannot be derived.
     */
    public function generate(): string
    {
        if (! isAppliance()) {
            throw new RuntimeException('License request generation only runs on appliance installs.');
        }

        $tokens = $this->machineTokens->currentHostTokens();
        $key = $this->machineTokens->encode($tokens);

        addEvent(
            ['binding' => $this->machineTokens->bindingStrength, 'tokens' => count($tokens)],
            'LICENSE_REQUEST_GENERATED',
            'SUCCESS',
            'ACTIVITY',
            0,
            0,
            auth()->id() ?? 0,
            0
        );

        return $key;
    }
}

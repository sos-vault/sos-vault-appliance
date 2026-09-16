# License-request flow

How to move from the open-core baseline to a licensed appliance.

The flow has six steps: two on your appliance, three on sos-vault.com, and a license file you download and install at the end. **No files are exchanged** — the appliance produces a short text *License Request Key* that you copy and paste into the portal. End-to-end takes about ten minutes once you have a Customer account.

## Step 1: generate a license request key (on the appliance)

1. Sign in to your appliance at `https://<your-host>/admin`.
2. Open **License** in the sidebar.
3. In the **Request a License** section, click **Generate License Request**.
4. The appliance derives this host's machine tokens (the same hardware identifiers used to bind an installed license) and encodes them into a short key prefixed `SOSV1.`. It appears in a copyable **Your License Request Key** field.

The key is safe to share: it contains only this server's hardware fingerprint — no sosreport, no logs, no host data. Nothing is written to disk or sent anywhere; generating it again just re-reads the current hardware.

## Step 2: copy the key

Click **Copy key** (or select the field and copy it). You will paste it into the Customer Portal in the next step.

## Step 3: verify the key at sos-vault.com

1. Visit `https://sos-vault.com` and sign in to your SELF-HOSTED Customer account. If you do not have one, create one first (free).
2. Navigate to **Customer Portal → Verify License Request**.
3. Paste the key into the **License Request Key** field and submit.
4. The portal:
   - Decodes and validates the key (`MachineTokenService::decode()`), rejecting a malformed or unrecognized key.
   - Rejects the key if the same physical server has already been verified under a different account.
   - Records the result as a `passed` `LicenseVerification` row bound to the key's machine tokens — no file upload needed.

Once the key is verified, license purchase unlocks. If the key is invalid or the server is already claimed by another account, the portal tells you why.

## Step 4: purchase a license

1. From the Customer Portal **Licenses** section, click **Buy New License**.
2. Choose the number of seats and billing cycle (monthly or yearly). The Pro feature set is fixed — multi-user, groups, modules, ITSM, full Disk Manager, event log, AI Assistant settings, Appliance Vaults settings, Authentication settings.
3. You will be redirected to Paddle for checkout. Pay with credit card.
4. On `transaction.completed` Paddle delivers a webhook back to sos-vault.com. `LicenseCheckoutService::mintFromTransaction()`:
   - Locates the pending `LicensePurchaseIntent` keyed off `intent_id` from the Paddle custom data.
   - Validates the linked verification is still `passed`.
   - Creates a `License` row bound to the machine tokens from the verification.
   - GPG-signs the license payload using the SaaS license-signing private key.
   - Stores the signed `.lic` in `License.signed_license`.

## Step 5: download the .lic

1. Refresh the Customer Portal **Licenses** page.
2. Find your newly-minted license; status is `ACTIVE`.
3. Click **Download** to save the `.lic` file.

The download is a small GPG clearsigned JSON blob (a few KB). The file extension is `.lic` but it is plain ASCII — you can `cat` it to confirm.

## Step 6: install the .lic on the appliance

1. Return to the appliance's **License** page.
2. In the **Install License** section, drop the `.lic` into the uploader.
3. Click **Install**.

The appliance verifies the signature against its local public keyring, intersects the embedded machine tokens with what `MachineTokenService::currentHostTokens()` derives from the live host, and persists the license on success.

Every gated UI surface reappears on the next page load:

- Groups admin
- Modules install
- Event Log
- Create User action
- AI Assistant settings
- Appliance Vaults settings
- Authentication settings
- ServiceNow / ITSM settings

A `LICENSE_INSTALLED` event is recorded in the audit log.

## What if the license install fails?

The most common reasons:

- **"Signature verification failed."** The `.lic` was tampered with or generated against a different keyring. Re-download from the Customer Portal.
- **"License is not valid for this server."** The machine tokens do not match — this `.lic` was minted for a different host. You probably verified the wrong key in step 3. Check the host's `/etc/machine-id` matches what is in the license payload.
- **"License matches only the machine-id, which is insufficient on a host with hardware identifiers."** Defense-in-depth check: the verification correctly extracted hardware tokens but the appliance only matches the primary `machine-id`. Indicates someone copied `/etc/machine-id` between machines. The license was issued for a different physical host.

All three failure modes raise a `RuntimeException` surfaced as a Filament notification with the exact reason.

## Renewal

Before the license expires, the SaaS Customer Portal sends reminder emails at 30 / 15 / 7 days. Renew via the **Renew** action on the existing license row in the Customer Portal — the renewal extends the term by a full cycle from the OLD `expires_at`, so renewing early never loses you days.

The renewed license is downloaded and installed the same way as the original.

## When the license expires

The appliance reverts to the open-core baseline on the next request. Extra users and groups remain in the database but cannot sign in. See [`FEATURES.md`](FEATURES.md) for the full expiry behaviour.

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ChangelogsTableSeeder extends Seeder
{
    /**
     * Product release notes shown on the /changelog page. These are the
     * canonical sos-vault entries (not the Wave boilerplate) and are seeded on
     * both SaaS (DatabaseSeeder) and the appliance (ApplianceAdminSeeder), which
     * boots from a clean DB. No PII — pure product content.
     *
     * @return void
     */
    public function run()
    {
        \DB::table('changelogs')->delete();

        \DB::table('changelogs')->insert([
            [
                'id' => 1,
                'title' => 'sos-vault 1.0.0 Released',
                'description' => 'We have just released the first official version of sos-vault. Click here to learn more!',
                'body' => '<p>sos-vault is a secure sos-report management system and analysis tool for uploading, storing, unpacking, browsing, and analysing Linux sos reports. It is designed for Linux administrators, support engineers, SREs, and DevOps teams. </p>
<p>Make sure to stay up-to-date on our latest releases as we will be releasing many more features down the road :)</p>
<p>Thanks!.</p>',
                'created_at' => '2025-04-05 23:19:00',
                'updated_at' => '2025-04-05 00:38:02',
            ],
            [
                'id' => 2,
                'title' => 'sos-vault 2.0.0 Released',
                'description' => "sos-vault V2 has been released.\nwith a more robust, responsive and powerful interface. Be sure to read up on what's new!",
                'body' => '<p>This new version of sos-vault includes new powerful analysis tools:</p><ul><li>Complete user interface re-design for a more robust, responsive and powerful interaction</li><li>New file viewer with more functionality</li><li>New sos-report Compare tool</li><li>New file Compare tool</li><li>New file-lists functionality</li><li>A new issue report writer engine</li><li>Much more fetures for you to discover</li></ul><p>Also be sure to check some new atricles we wrote for you so you can take full advantage of all the sos-report and sos-vault features and tools <a target="_blank" class="fi-color-secondary" href="/blog">here</a></p>',
                'created_at' => '2026-04-15 23:19:00',
                'updated_at' => '2026-04-15 00:38:02',
            ],
            [
                'id' => 3,
                'title' => 'sos-vault 2.1.0 — Self-Hosted Edition',
                'description' => "Run sos-vault entirely on your own hardware.\nThe new Self-Hosted appliance is open core, air-gap friendly, and licensed per machine.",
                'body' => '<p>sos-vault is now available as a fully self-hosted appliance you install on your own infrastructure — nothing leaves your network.</p><ul><li><strong>Open core</strong> — the complete source tree is published under AGPLv3; a free single-admin baseline works forever, with multi-user, groups, modules, ITSM, encrypted vaults, and the event log unlocked by a per-machine license</li><li><strong>Air-gap ready</strong> — the full sosreport import, decrypt, browse, and analysis pipeline runs offline; licensing is verified locally with no phone-home</li><li><strong>Local AI Assistant</strong> — a bundled on-appliance model answers product, <code>sos</code>, and Linux questions without sending data to any third party</li><li><strong>Customer Portal</strong> — request and purchase per-machine licenses, then download and install the signed <code>.lic</code> on your appliance</li></ul><p>Read the setup, administration, and architecture guides <a target="_blank" class="fi-color-secondary" href="/blog">here</a>.</p>',
                'created_at' => '2026-07-15 12:00:00',
                'updated_at' => '2026-07-15 12:00:00',
            ],
            [
                'id' => 4,
                'title' => 'sos-vault 2.1.1 Released',
                'description' => "New: View Fleet lets you track every host across your uploaded sosreports.\nPlus a handful of security and reliability fixes.",
                'body' => '<p><strong>New feature</strong></p><ul><li><strong>View Fleet</strong> — a new fleet-wide view that groups your sosreports by host, so you can see each machine\'s upload history at a glance and drill into any host\'s timeline</li></ul><p><strong>Bug fixes</strong></p><ul><li>AI provider, ServiceNow (ITSM), and AWS credentials configured in Manage Settings are now encrypted at rest</li><li>Updated dependencies flagged by our Composer security audit</li><li>sosreport uploads whose filename can\'t be reliably parsed are now rejected with a clear error instead of being silently accepted</li><li>Documentation updates</li></ul>',
                'created_at' => '2026-08-19 12:00:00',
                'updated_at' => '2026-08-19 12:00:00',
            ],
        ]);
    }
}

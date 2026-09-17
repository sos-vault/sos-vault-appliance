<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Wave\Category;
use Wave\Post;
use Wave\User;

class SosVaultFeatureDocsSeeder extends Seeder
{
    public function run(): void
    {
        $category = Category::where('slug', 'sos-vault')->first();

        if (! $category) {
            return;
        }

        $authorId = User::query()->orderBy('id')->value('id')
            ?? DB::table('users')->orderBy('id')->value('id')
            ?? 1;

        foreach ($this->posts() as $post) {
            Post::updateOrCreate(
                ['slug' => $post['slug']],
                array_merge([
                    'image' => 'posts/transparent.png',
                ], $post, [
                    'author_id' => $authorId,
                    'category_id' => $category->id,
                    'status' => 'PUBLISHED',
                    'featured' => 0,
                ]),
            );
        }
    }

    private function posts(): array
    {
        return [
            [
                'title' => '11. Compliance & Exposure Assessment',
                'slug' => 'sos-vault-compliance-exposure-assessment',
                'seo_title' => 'CIS, STIG & Docker Bench Compliance in sos-vault',
                'meta_description' => 'How sos-vault automatically assesses CIS, STIG, and Docker Bench compliance plus network, software, identity, and systemd exposure on every uploaded sosreport.',
                'meta_keywords' => 'CIS, STIG, DISA, Docker Bench, compliance, exposure, hardening, sos-vault',
                'excerpt' => 'Every sosreport upload is automatically checked against CIS, STIG, and Docker Bench benchmarks, plus a network/software/identity/systemd exposure assessment — no configuration or extra input required.',
                'image' => 'posts/September2026/compliance-dashboard.png',
                'body' => $this->bodyComplianceExposure(),
            ],
            [
                'title' => '12. Automated Alerts',
                'slug' => 'sos-vault-automated-alerts',
                'seo_title' => 'Automated Alert Rules in sos-vault',
                'meta_description' => 'How sos-vault evaluates file-pattern, metric-threshold, and reference-case-diff alert rules automatically on every sosreport upload, with in-app, email, and event-log/Slack delivery.',
                'meta_keywords' => 'alerts, notifications, monitoring, thresholds, sos-vault',
                'excerpt' => 'Create alert rules once and sos-vault evaluates them automatically on every new sosreport upload — file-pattern matches, metric thresholds, or reference-case diffs — and delivers matches in-app, by email, or to your SIEM.',
                'image' => 'posts/September2026/alerts-dashboard.png',
                'body' => $this->bodyAlerts(),
            ],
        ];
    }

    private function bodyComplianceExposure(): string
    {
        return <<<'HTML'
<p>The <strong>Compliance</strong> tool assesses every uploaded sosreport
against CIS, STIG, and Docker Bench benchmarks, plus a network, software,
identity, and systemd exposure check &mdash; automatically, with no extra
input from you. It runs natively against the data already captured in the
report; nothing is executed against the source system, and no OpenSCAP or
external scanner is required.</p>

<h2>What it checks</h2>
<ul>
  <li><strong>CIS</strong> &mdash; SSH hardening, PAM/password policy,
      sudoers configuration, firewall default policy, kernel (sysctl)
      hardening, SELinux enforcement, auditd, and unnecessary services.</li>
  <li><strong>STIG</strong> &mdash; the same fact domains evaluated against
      official DISA STIG rule text (title, description, severity, and fix
      text are sourced directly from the published benchmark for your
      report's operating system).</li>
  <li><strong>Docker Bench</strong> &mdash; daemon configuration, logging,
      security options, and auditd coverage of the Docker daemon &mdash;
      shown only when the report includes Docker plugin data.</li>
  <li><strong>Exposure</strong> &mdash; network/firewall exposure, risky
      installed software, identity exposure (root login, empty passwords,
      unrestricted sudo), and unnecessary network-facing systemd units.</li>
</ul>

<h2>What data you need to provide</h2>
<p>None. Compliance analysis starts automatically as soon as a sosreport
finishes unpacking &mdash; there is nothing to configure and no extra file to
upload. Reports that predate this feature, or an existing case you want to
re-check after a rule-pack update, can be analyzed on demand from the tool's
empty state.</p>

<h2>Reading the results</h2>
<p>Open a case and select <strong>Compliance</strong> from the tool ribbon.
Four score pills summarize the pass rate for CIS, STIG, Docker Bench, and
Exposure. Below them, a filterable table lists every check: which ruleset it
belongs to, its category, severity, and pass/fail/not-applicable/error
status. Checks that don't apply to a report (for example, Docker Bench on a
report with no Docker plugin data) are marked <em>not applicable</em> rather
than silently omitted. Selecting a finding opens its full description,
remediation guidance, and reference link.</p>

<h2>Notifications</h2>
<p>If a run produces any <strong>HIGH</strong> or <strong>CRITICAL</strong>
failing findings, the vault's members are notified in-app and by email, and
the event is recorded in the audit log (and forwarded to your SIEM if one is
configured) &mdash; the same delivery channels used by Alert Rules.</p>

<h2>Licensing</h2>
<p>Compliance &amp; Exposure Assessment is unlicensed &mdash; it is available
on every plan and on every appliance install, licensed or not.</p>
HTML;
    }

    private function bodyAlerts(): string
    {
        return <<<'HTML'
<p>The <strong>Alerts</strong> tool lets you define rules once, then evaluates
them automatically on every new sosreport uploaded to the vault &mdash; no
need to re-check a case by hand every time.</p>

<h2>Alert types</h2>
<ul>
  <li><strong>File pattern match</strong> &mdash; a regular expression
      checked against a specific file (for example, a failed systemd unit
      in <code>sos_commands/systemd/systemctl_list-units</code>), triggering
      when the pattern is found or when it's missing.</li>
  <li><strong>Metric threshold</strong> &mdash; CPU, memory, swap, disk,
      inodes, process count, open files, or connection count crossing a
      threshold you set.</li>
  <li><strong>Reference case diff</strong> &mdash; a file that has changed
      compared to a reference case you choose, useful for catching drift in
      configuration files between reports.</li>
</ul>

<h2>Creating a rule</h2>
<p>Open the vault-wide <strong>Alerts</strong> page from the sidebar to
manage every rule in one place, or use <strong>Add an Alert</strong> directly
from a file in the sos Viewer to pre-fill the file path for a new
file-pattern rule. Each rule has a name, description, and severity, and
applies to every case any vault member uploads going forward.</p>

<h2>Delivery</h2>
<p>A matching rule can notify you in-app, by email, and in the event log
(forwarded to your SIEM if one is configured) on every plan and every
appliance install. Slack delivery is available on the licensed self-hosted
tier and on SaaS Team and Enterprise plans.</p>

<h2>What data you need to provide</h2>
<p>Just the rule itself &mdash; once created, evaluation is automatic on
every future upload. Existing cases uploaded before a rule was created are
not retroactively evaluated.</p>
HTML;
    }
}

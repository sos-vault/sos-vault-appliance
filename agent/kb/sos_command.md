# The `sos` Command & Report Structure — Reference

## What `sos` is
`sos` (formerly `sosreport`) collects system diagnostics from a Linux host into a compressed,
optionally encrypted tar archive: logs, configs, hardware, kernel info, network settings,
performance metrics, and installed packages. It runs locally on the target host.

**Point-in-time snapshot.** CPU, memory, and process states reflect the moment of collection,
not live state.

## Common questions (answer from these — use the exact option names)
- **How do I limit the size of the journal (e.g. to 10 MB)?** Use `--journal-size 10` (value in
  MB). Any question about the **journal** size is answered with `--journal-size` — NOT
  `--log-size`. `--log-size` does not control the journal.
- **How do I limit the size of other collected logs (e.g. to 10 MB)?** Use `--log-size 10` (value
  in MB; default 25). `--log-size` caps non-journal log files only; for the systemd journal use
  `--journal-size` (see above). There is **no** `maxsize` option.
- **How do I upload the report to sos-vault?** Add
  `--upload-url "https://sos-vault.com/api/upload" --upload-user "you@email.com" --upload-pass "<upload-token>" --upload-method post`
  to your `sos report` command.
- **How do I obfuscate sensitive data (IPs/hostnames)?** Add `--clean`.
- **How do I tag the report with a case/ticket number?** `--case-id "CAS-1234"`.
- **How do I encrypt the archive?** `--encrypt-pass "decrypt-pass"` (GPG symmetric).
- **How do I skip a slow/sensitive plugin?** `--skip-plugins name1,name2`.
- **How do I run only one/specific plugin(s)?** `--only-plugins name1,name2` (runs *only* those,
  skipping everything else). The flag is `--only-plugins` — there is no `--plugin`/`--run-plugin`.
- **How do I add/enable a plugin that is off by default?** `--enable-plugins name1,name2`. This
  turns on plugins disabled by default; it does **not** install new plugins. (Do not confuse with
  the `sos_extras` plugin, which collects custom *files*, not plugins.)
- **How do I set a plugin option?** `-k plugin.option=value` (e.g. `-k logs.all_logs=on`); repeat
  `-k` for multiple options. `--list-plugins` shows each plugin's available options.

## Running `sos`
Basic: `sudo sos report` (prompts for a case ID, saves to `/var/tmp/`).

Recommended for production / upload to sos-vault:
```bash
sudo sos report -q --clean --batch \
  --case-id "CAS-1234" \
  --encrypt-pass "decrypt-pass" \
  --upload-url "https://sos-vault.com/api/upload" \
  --upload-user "user@email.com" \
  --upload-pass "upload-api-token" \
  --upload-method post
```
- `-q`/`--quiet` — suppress progress · `--batch` — no prompts · `--clean` — obfuscate
  IPs/hostnames/MACs · `--case-id` — embed the ticket number in the filename ·
  `--encrypt-pass` — GPG symmetric encryption · `--upload-*` — upload on completion.

Other useful options: `--label`, `--tmp-dir DIR`, `--skip-plugins LIST`, `--only-plugins LIST`,
`--enable-plugins LIST`, `-k plugin.option=value` (set a plugin option),
`--profile network|storage|...`, `--preset NAME` (a saved bundle of options — `--list-presets`
shows them; ideal for repeatable, tuned runs), `--log-size MB` (default 25),
`--journal-size MB` (journal only), `--all-logs`, `--no-report`,
`--encrypt-key KEYID`, `--list-plugins`.

**Tuning collection speed / archive size** (from fastest to most granular): pick a `--profile`
or `--preset`; cap logs with `--log-size`/`--journal-size`; drop noisy plugins with
`--skip-plugins` (e.g. `auditd,apt`); or restrict to exactly what you need with `--only-plugins`.
Plugins run in parallel, so skipping a slow one (e.g. `hardware`) also shortens wall-clock time.

**`sos collect`** runs `sos report` across multiple nodes over SSH and retrieves all archives:
```bash
sudo sos collect --nodes node1,node2,node3 --case-id "CAS-1234" --encrypt-pass "decrypt-pass"
```
(`--password` or `--ssh-key` for auth; `--batch`/`--clean`/`--skip-plugins` etc. also apply.)

## Filename format
`sosreport-HOSTNAME-CASEID-DATE-HASH.tar.xz[.gpg]`, e.g.
`sosreport-web01-CAS-1234-2026-03-15-abcde12.tar.xz.gpg`. The name gains markers as options are
used:
- **Encrypted** (`--encrypt-pass`): the archive is prefixed **`secured-`** and gets a `.gpg`
  suffix — e.g. `secured-sosreport-host0-CAS-1234-…-obfuscated.tar.xz.gpg`. Without encryption
  there is no `secured-` prefix and no `.gpg`.
- **Obfuscated** (`--clean`): **`-obfuscated`** is appended before the extension and the hostname
  is masked (e.g. `host0`), along with IPs/MACs inside the report.
- Without `--case-id` the CASEID segment is a random id. Compression may be `.tar.xz` (default)
  or `.tar.gz`.

sos prints a **SHA-256** of the finished archive; sos-vault recomputes it after upload so
integrity is verified end-to-end. sos-vault parses this name at unpack time to seed the case —
always pass `--case-id`.

## Config file & presets (avoid long command lines)
**`/etc/sos/sos.conf`** — system-wide defaults, so a production run needs no flags. Three
sections:
- `[global]` — run-wide: `batch = true`, `threads = 4`, `verbosity`, `log-size`, `journal-size`,
  `compression-type = xz`, `tmp-dir`, `skip-plugins`.
- `[report]` — report options: `clean = true`, `encrypt-pass`, `keywords = /path/keywords.txt`
  (a custom obfuscation dictionary), `upload-url` / `upload-user` / `upload-pass` /
  `upload-method`.
- `[plugin_options]` — per-plugin knobs, e.g. `networking.traceroute = yes`.

With sos.conf populated, `sudo sos report --case-id "CAS-1234"` is enough. (Older path:
`/etc/sos.conf`.)

**Presets** — named, reusable option bundles as JSON in **`/etc/sos/presets.d/<name>.json`**; the
`args` map mirrors the flags (`batch`, `clean`, `log-size`, `only-plugins`, `encrypt-pass`,
`upload-*`, …). Run with `sudo sos report --preset <name> --case-id "CAS-1234"`; list with
`--list-presets`. Ideal for automated incident response (e.g. Grafana alert → Ansible →
`sos report --preset diskProblem`).

## `sos_extras` plugin
Collects custom files/commands defined under `/etc/sos/extras.d/` (one entry file per app).
Collected files appear at their original paths in the archive; command output goes under
`sos_commands/sos_extras/<name>/`. sos-vault surfaces these in an `EXTRAS` directory.

## `--clean` obfuscation
Replaces IPv4/IPv6 with consistent fake addresses, hostnames with `obfuscatedhost` variants,
and MACs with masked variants. The mapping is saved under `var/tmp/sos-clean-archive-*/`.
Use `--skip-plugins mysql,pgsql,...` to exclude plugins that gather sensitive configs.

## Extracted archive layout
Top level (partial copy of the host plus sos-specific dirs):
```
etc/  proc/  sys/  run/  var/log/  boot/  usr/  opt/  lib->usr/lib
sos_commands/   # per-plugin command output (one subdir per active plugin)
sos_logs/       # sos's own run log (sos.log) — collection errors land here
sos_reports/    # the report index: sos.json (+ manifest.json, html/txt)
sos_strings/    # up to ~30 days of journal dump (can be large)
EXTRAS/         # present only when sos_extras was used (sos-vault convention)
installed-rpms | installed-debs    hostname  uname  date  version.txt
```
- `sos_reports/sos.json` — the **index** of the whole report. Each entry has an `href` giving
  the file/command-output location inside the archive. Use it to locate anything.
- `sos_reports/manifest.json` — how the run went: timestamps, commands+params per plugin,
  files copied, obfuscation details.
- `sos_commands/<plugin>/` — files are named after the exact command run (with options), and
  the file content is that command's output. E.g. `sos_commands/process/ps_-elfL`,
  `sos_commands/networking/ip_route`, `sos_commands/kernel/sysctl_-a`.
- **Root-level symlinks** — friendly shortcuts to the most-used command outputs: `df` →
  `sos_commands/filesys/df_-al_-x_autofs`, `ps` → `sos_commands/process/ps_auxwwwm`, `free` →
  `sos_commands/memory/free`, `ip_addr`/`ip_route`/`netstat` → `sos_commands/networking/…`,
  `dmidecode`/`lspci` → `sos_commands/hardware|pci/…`, `lsmod`/`uname` → `sos_commands/kernel/…`,
  `date` → `.../timedatectl`, `mount` → `.../mount_-l`, plus `uptime`, `hostname`, `last`,
  `installed-rpms`/`installed-debs`. Useful entry points when you know the tool but not the path.

## Key plugins (what they collect)
`kernel` (dmesg, lsmod, sysctl, /proc/sys) · `memory` (meminfo, slabinfo, swap) · `block`
(iostat, lsblk, smartctl, diskstats) · `filesys` (df, findmnt, mounts, fstab) · `lvm2`
(pvs/vgs/lvs) · `networking` (ip addr/route, ss, netstat, /proc/net) · `firewalld`/`iptables`/
`nftables` · `hardware` (dmidecode, lshw, lspci, lsusb) · `systemd` (list-units/timers) ·
`logs` (journalctl, /var/log) · `process` (ps, /proc/[pid]) · `selinux`/`auditd` ·
`yum`/`dnf`/`apt` · `podman`/`docker` · `openshift`/`kubernetes` · `pacemaker` · `ceph`.
List all: `sos report --list-plugins`. Disable: `--skip-plugins podman,docker,ceph`.

## Common issues
| Symptom | Likely cause | Fix |
|---|---|---|
| Collection hangs | plugin timeout (e.g. hardware probe) | `--skip-plugins hardware` |
| Archive too large | logs / core dumps | `--log-size 10` or `--skip-plugins coredump` |
| Permission denied | not root | `sudo sos report` |
| Upload fails | wrong URL or token | check `--upload-url` / `--upload-pass` |
| `.gpg` won't unpack in sos-vault | wrong decrypt password | check Settings → Keys |

## Further reading (in-app Documentation)
sos-vault ships a **Documentation** section (left sidebar) with a `sos command` category. For a
deep-dive, point the user there using these **on-box relative paths** — never an external URL:
- Plugins reference table — `/blog/sos-command/15-sos-report-available-plugins`
- Plugin descriptions — `/blog/sos-command/10-sos-report-plugins`
- Include your own files (`sos_extras`) — `/blog/sos-command/14-including-your-own-files-in-the-sos-report`
- Automatic upload — `/blog/sos-command/13-automatic-upload-of-resulting-sos-report-archive-file`
- Customizing with `sos.conf` — `/blog/sos-command/17-customizing-the-sos-report-command`
- Presets (Grafana/Ansible automation) — `/blog/sos-command/18-using-sos-report-presets`
- Multi-node `sos collect` — `/blog/sos-command/12-sos-report-in-a-cluster`
- Archive / data-pack layout — `/blog/sos-command/07-sos-report-data-pack-layout`

Cite the exact path, or just say "see **Documentation → sos command**". Do **not** invent other
URLs, and never emit an external `sos-vault.com` link — these pages are served locally.

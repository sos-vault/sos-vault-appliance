# sos-vault — Usage & Navigation Reference

sos-vault is a secure sosreport management and analysis tool: upload, store, decrypt,
unpack, browse, and analyse Linux sosreports. Current version: **v2.0.0**.

## Common questions (answer from these — do not invent steps)
- **How do I upload a sosreport?** In the left sidebar choose **Upload SOS File → Upload It**
  and pick the archive from your device. For automated/CLI uploads, add the `--upload-*` flags
  to your `sos report` command using the upload token from **Settings → Keys**. To pull one
  from a Jira/JSM ticket, use **Upload SOS File → ITSM Provider**.
- **How do I open / analyse a report?** **Browse SOS Report** → open the case → use the
  **Tools** menu (Summary, Top, Search, Compare). sos-vault uses a **multi-tab workspace** —
  each tool/file opens in a new browser tab — so the browser must **allow pop-ups** for the
  sos-vault domain. The first time you open a report the browser may block the tabs (a
  blocked-pop-ups icon appears by the address bar); click it, choose **Allow**, and reload.
- **Where do I get my upload token / decrypt key?** **Settings → Keys**.
- **How do I share a file?** Open the file in **Browse SOS Report**; in the File Viewer open the
  **File Tools** tab and choose **Share Document** (link expires in 7 days).
- **How do I compare two files from different cases?** In **Browse SOS Report**, locate the file
  in the file manager. Every file row has three action icons: **Bookmark**, **Add to File List**,
  and **Compare**. Click the **Compare** icon to open the **File Compare** tool, then pick the
  other case/report to diff the *same* file against. (Both reports must contain that file; if no
  other report has it, there's nothing to compare.) To instead compare two whole reports, use
  **Tools → Compare**.
- **How do I get a verification report for a case?** Use the **Report** tool (Tools →
  **Report**) / the vault toolbar — it bundles the case's checksums, signatures, and a JSON
  summary into a printable/downloadable report to attach to a ticket or audit.
- **How do I add an extra analyser / module?** Optional modules (e.g. `german-support`,
  add-on analysers) are installed by an administrator; once enabled they appear in the analyser
  ribbon inside **Browse SOS Report**. You cannot install modules from an analyst account.
- **How do I free up space?** **Vault Management** — delete packed/unpacked files; storage can
  be expanded/shrunk from the **Dashboard**.

## Core concepts
- **Vault** — your private, encrypted storage. Opens automatically on login and auto-closes
  shortly after your session goes idle (about 5 minutes by default). Only sosreport archives
  are accepted (e.g. `sosreport*.tar.xz.gpg`, `sosreport*.tar.gz.gpg`).
- **Case** — a logical grouping of one or more unpacked sosreport directories. Created
  automatically at unpack time. The Case ID comes from the `--case-id` used when running `sos`.
- **Directory** — the extracted folder from one unpacked archive. One directory per archive.
  A case may hold several directories (e.g. reports from several hosts), but a directory
  belongs to exactly one case.
- **Tools** — the analysis menu inside Browse SOS Report (Summary, Top, Search, Compare, etc.).

## Standard workflow
1. **Generate** the report on the Linux host: `sudo sos report -q --clean --batch --case-id "CAS-1234" --encrypt-pass "decrypt-pass"`.
2. **Get it into the vault** one of three ways:
   - **Direct upload** from the `sos` command line (add `--upload-url https://sos-vault.com/api/upload --upload-user you@email.com --upload-pass <upload-token> --upload-method post`). Requires an upload key in **Settings → Keys**.
   - **Web upload**: sidebar → **Upload SOS File → Upload It** (file must be on your device).
   - **From a Jira/JSM ticket**: sidebar → **Upload SOS File → ITSM Provider** (configure **Settings → ITSM Provider** first).
3. **Unpack** — automatic if a decrypt key is configured in **Settings → Keys**; otherwise
   use **Vault Management → Unpack** and enter the passphrase. After unpacking, the archive
   is deleted and a new directory + case appear.
4. **Browse & analyse** — **Browse SOS Report** → open the case → navigate the file tree and
   use the **Tools** menu.

## Sidebar menus
- **Dashboard** — vault status, account, sessions, billing, invoices, storage expand/shrink.
- **Upload SOS File** — upload from local file, ITSM (Jira/JSM), or re-unpack an archive.
- **View Cases** — list, edit, or delete cases and their metadata.
- **View Fleet** — one row per system: hostname, machine-id, OS, report count, first/last
  seen. Click a row for that system's report history (chronological, with Browse /
  Summary / Compare shortcuts). See "View Fleet (system history)" below.
- **Vault Management** — manage packed/unpacked files; delete to free space; manual unpack.
- **Browse SOS Report** — open a case and navigate its files (the main analysis interface).
- **Incident Reports**, **Notifications**, **Announcements**, **Documentation**, **Changelog**.
- **User menu** (bottom user icon) — account **Settings** (Keys, ITSM Provider, Security/2FA).

## View Fleet (system history)
Host-centric view of all uploaded reports (`/fleet`). Systems are grouped by the
**machine-id** read from the report's `etc/machine-id` at unpack time; reports without
one (very old reports, or not yet backfilled) group by the **filename-derived host**
instead. The real hostname (from uname) is shown when available.
- Clicking a system opens its **report timeline** (`/fleet/{machine-id}`) — all of that
  host's reports in date order, each with Browse / Summary / Compare shortcuts. Use
  **Compare** between two reports of the same host for change tracking / config drift.
- **Backfill**: reports unpacked before this feature carry no identity. It self-heals
  when a case is opened in the browser (vault must be open), or run
  `php artisan fleet:backfill-identity` (options: `--case=ID`, `--force`, `--dry-run`)
  to backfill in bulk. Closed (locked) vaults cannot be read and are skipped.
- **Caveats**: reports made with `sos report --clean` have an obfuscated hostname (e.g.
  `host0`) — it is stored as-is; if the obfuscation mapping isn't preserved between runs
  on the client, one host may split into several fleet rows. Cloned VMs sharing a
  machine-id collapse into a single row. machine_id/hostname live in the app database
  (outside the encrypted vault) so the fleet list works without opening vaults.

## Browse SOS Report (the sos Browser)
The main navigation tool: traverse, search, open, and compare files. Two parts — the
collapsible **Tools Control** section (top) and the **File Manager** (below).
- **Tools Control tabs**: **Report Info** (was it obfuscated, who owns it), **Case Info**
  (case ID, report series, date, detected OS), **Tools** (launch Summary / Top / Report /
  Compare), **Bookmarks** (files bookmarked for quick access — a default set is auto-created
  at unpack), **File List** (named groups that open several related files at once; hover to
  preview). Below the tabs: **breadcrumbs** (current location), a **regex file search**, and
  the **case selector** (switch reports).
- **File Manager**: the report's file tree as a table (permissions, owner, size, date, name).
  Directories show a folder icon — click the **+ / closed-folder** to expand, the open-folder
  to collapse; breadcrumbs update as you navigate. Click a file icon or name to open it in the
  **File Viewer** (new tab).
- **Each file row has three action icons**: **Bookmark**, **Add to File List**, and **Compare**
  (diff this file against the same file in another report — see File Compare below). Directory
  rows have a bookmark icon.
- **Search File/Dir Regex** at the top filters/locates files by name/path (regex supported);
  results let you jump straight to a file, scrolling and highlighting it in the tree.

## File Viewer
The main tool for reading a single file, opened in its own tab. Two display modes:
**table mode** (structured rows — increase visible rows, search, hide noise columns, sort by
time asc/desc, apply date/time filters) and **raw mode** (original plain text; toggle line
numbers). Control tabs: **File Info** (name, source command, plugin, size, line count),
**Case Info**, and **File Tools** (line numbers, in-file regex search, **text highlighting**,
**notes**, and **Share Document**). Status badges show line count, ownership, and sharing/lock
status — the **lock** indicates whether you may add notes/highlights to a shared file you don't
own. **Add Note** annotations expire in 30 days; **Share** links expire in 7 days; the original
file can be **Downloaded** at any time.

## Tools menu
- **Summary** — opens in a new tab after a successful unpack; the best starting point. A
  dashboard of colour-coded **badges** — read **red first, then yellow**. Each badge drills into
  a full detail table: **Host Info** (hostname, OS, kernel, load, IPs, timezone, uptime),
  **CPU**, **Memory** (RAM + swap), **Disk Info** (usage, inodes, fs type, disk type, IO — like
  `df` plus more, with selectable throughput/utilisation columns), **Process Info** (all running
  processes — total count is bottom-left; search by name, group by user, sort by CPU%/MEM%, see
  open files & thread counts), **Network Info** (top table = TCP/UDP sockets, lower = Unix domain
  sockets), **Error Messages** (every log file with its first ~100 errors and line numbers;
  the *open-log* icon jumps the File Viewer to that line), plus TCP/IP stats, open files by
  process, firewall rules, hardware, installed software, and kernel config.
- **Top** — a `top`-style snapshot: tasks, CPU%, memory%, running/sleeping/zombie counts.
- **Search** — full-text search across all files in the report.
- **Compare** — diff two whole **SOS reports** (directory-tree level) to find files that differ
  between them. **File Compare** — diff **one file** against the same file in another report;
  launched from the **Compare** icon on a file row in Browse SOS Report.
- **Report** — generates a printable/downloadable **verification / issue report**: the case's
  checksums, signatures, and a JSON summary (plus supporting data and activity logs) as a
  structured root-cause PDF, suitable for a support ticket or warranty/audit follow-up.
- **Modules** — admin-installed add-on analysers (e.g. `german-support`) appear in the analyser
  ribbon here once enabled; there is nothing for an analyst to install.

## Where to find specific information (Tools menu → or file path)
- **Memory total/used/free, swap** → Summary → Memory Info. **TCP/IP stack memory** →
  Memory Info (network buffers) or `proc/net/sockstat`. **Process using most memory** →
  Summary → Process Info (sort MEM%) or Top.
- **CPU model/cores** → Summary → CPU Info. **Load average** → Summary → Host Info.
  **Process using most CPU** → Summary → Process Info (sort CPU%) or Top. **Flags** → `proc/cpuinfo`.
- **Hardware (model, DIMMs, BIOS)** → Summary → Hardware or `sos_commands/hardware/dmidecode`.
  **PCI devices** → `sos_commands/hardware/lspci`.
- **Disk space / inodes / filesystems** → Summary → Disk Info. **Mounts** →
  `sos_commands/filesys/` or `proc/mounts`. **LVM** → `sos_commands/lvm2/`. **Disk I/O** →
  `sos_commands/block/iostat` or `proc/diskstats`.
- **Interfaces & IPs** → Summary or `sos_commands/networking/ip_addr`. **Routing** →
  `sos_commands/networking/ip_route`. **Connections** → `sos_commands/networking/ss`.
  **Firewall** → Summary or `sos_commands/networking/iptables`. **DNS** → `etc/resolv.conf`.
- **Processes** → Summary → Process Info or Top. **Failed services** →
  `sos_commands/systemd/systemctl_list-units --failed`. **Process tree** →
  `sos_commands/process/pstree_-lp`.
- **Log errors** → Summary → Error Messages. **System log** → `var/log/messages`. **dmesg** →
  `sos_commands/kernel/dmesg`. **Journal** → `sos_commands/logs/journalctl_--no-pager`.
  **Auth failures** → `var/log/secure` or `var/log/auth.log`. **Audit/SELinux** →
  `var/log/audit/audit.log`.
- **OS version** → Summary → Host Info or `etc/os-release`. **Kernel version** →
  Summary → Kernel or `proc/version`. **Boot params** → `proc/cmdline`. **Modules** →
  `sos_commands/kernel/lsmod`. **Installed packages** → Summary → Packages or
  `installed-rpms` / `installed-debs`. **Repos** → `etc/yum.repos.d/` or `etc/apt/sources.list`.

## Settings
- **Keys** — upload-pass (API upload token) and decrypt-pass (auto-decrypt on arrival; must
  match the `sos --encrypt-pass` value). Get the upload token here for the API.
- **ITSM Provider** — Jira/JSM URL, username + API token, and the customer-name field ID,
  to pull sosreports attached to tickets.
- **Security** — two-factor authentication (TOTP). Mandatory for admins.

## API upload (command line)
```bash
curl -X POST https://<host>/api/upload \
  -H "Authorization: Bearer <upload-api-token>" \
  -F "file=@/var/tmp/sosreport-host-CAS-1234-2026-01-01-abc.tar.xz.gpg"
```
Auto-unpack requires the decrypt key in Settings → Keys. Direct upload from `sos` is ideal
for CI/CD pipelines.

## Limits & behaviours
- Vault auto-closes shortly after the session goes idle (≈5 min default).
- Shared links expire after **7 days**; annotations after **30 days**.
- Events for a deleted directory are retained **30 days**, then removed.
- Deleting a **case** also deletes its directories, annotations, and shares; deleting the
  **last directory** of a case also deletes the case.
- Max upload archive size is **512 MiB**. Per-plan vault storage can be expanded or shrunk
  from the Dashboard. **Shrinking** takes effect at the end of the billing period, cannot be
  cancelled, and requires free space ≥ **1.5×** the shrink amount — delete unused reports first.
- Mil cannot see service/account/billing status; for those, the user should use the app.

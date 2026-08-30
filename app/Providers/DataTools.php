<?php

namespace App\Providers;

use App\Models\SupportCase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

class DataTools extends ServiceProvider
{
    // Sentinel prepended to sos_reports/sos.html once fixSosHtml() has rewritten
    // its links. Doubles as (a) the idempotency guard for re-runs and (b) the
    // signal computeFileContentsById() checks to render this one file as HTML.
    public const SOS_HTML_FIXED_MARKER = '<!-- SOSVAULT-LINKS-FIXED -->';

    protected $cached = 1;

    protected $dir;

    protected $vid;

    protected $did;

    protected $mountp;

    protected $vtools;

    protected $DEBUG;

    protected $tree;

    protected $path;

    protected $uname = null;

    protected $kernel_version = null;

    protected $os_version = null;

    protected $sos_version = null;

    protected $chartsPath = __DIR__.'/../../json';

    public function __construct($vtools, $vid, $did)
    {
        // check permissions here...

        $this->vid = $vid;
        $this->did = $did;
        $this->vtools = $vtools;

        if (! $this->vtools) {
            Log::error('vault not found');

            return null;
        }

        if (! $this->vtools->isOpen()) {
            Log::error('vault si closed');

            return null;
        }

        $this->dir = $this->vtools->getDirById($did);
        if (! $this->dir) {
            // Stale .contents.json cache: the directory's on-disk inode (which is
            // the node id getDirById matches on) has diverged from the cached tree
            // — e.g. after a vault rebuild or re-extraction that didn't refresh the
            // root cache. Regenerate the contents once and retry before giving up,
            // so a desync self-heals instead of 500-ing the tool page on ->nodes.
            $this->vtools->updateContents();
            $this->dir = $this->vtools->getDirById($did);
        }
        if (! $this->dir) {
            Log::error('directory not found');

            return null;
        }

        $this->mountp = $this->vtools->getMountPoint();
        $this->path = "{$this->mountp}/{$this->dir->name}";

        $this->tree = $this->vtools->getContents($this->path);
        if (! $this->tree) {
            Log::error('tree not found');

            return null;
        }

        if ($did != 99999999) {
            $this->uname = $this->unameData();
            $this->kernel_version = $this->kernelVersion();
            $this->os_version = $this->osVersion();
            $this->sos_version = $this->sosVersion();
        }
    }

    public function readFileContents($filepath)
    {
        $fileInfo = explode('/', preg_replace(':^/:', '', $filepath));
        $fileName = array_pop($fileInfo);
        $path = implode('/', $fileInfo).'/';

        $found = $this->vtools->find_node_by_attr($this->tree->nodes, 'name', $fileName, 'path', $path);
        if (! $found) {
            $found = $this->vtools->find_node_by_attr($this->tree->nodes, 'name', $fileName, 'path', '');
            if (! $found) {
                Log::error("{$path}{$fileName} file not found");

                return null;
            }
        }

        $filepath = "{$this->path}/";
        $filepath .= ($found->type === 'l') ? $found->realpath : "{$found->path}{$found->name}";
        if (! is_file($filepath)) {
            Log::error("file $filepath does not exist");

            return null;
        }

        $contents = explode("\n", file_get_contents($filepath));

        return $contents;
    }

    // Resolve a logfile path (as stored in the errors data) to its file tree
    // node id (the "fid" the File Viewer route expects). Mirrors the node lookup
    // in readFileContents().
    public function getFileIdByPath($filepath)
    {
        $fileInfo = explode('/', preg_replace(':^/:', '', $filepath));
        $fileName = array_pop($fileInfo);
        $path = implode('/', $fileInfo).'/';

        $found = $this->vtools->find_node_by_attr($this->tree->nodes, 'name', $fileName, 'path', $path);
        if (! $found) {
            $found = $this->vtools->find_node_by_attr($this->tree->nodes, 'name', $fileName, 'path', '');
        }

        return $found->id ?? null;
    }

    // Rewrite the sos_reports/sos.html index so its ~11k on-disk relative links
    // (all "../<report-relative-path>", since sos.html lives in sos_reports/)
    // become working File-Viewer URLs, then mark it so the viewer renders it as
    // HTML instead of escaped text. Runs once per report at unpack time (from
    // summaryData) and, for older reports, from the queued FixSosHtml listener
    // when the case is opened. Idempotent: the marker short-circuits re-runs.
    public function fixSosHtml($cid = null): bool
    {
        if (empty($this->path)) {
            return false;
        }

        $sosHtml = "{$this->path}/sos_reports/sos.html";
        if (! is_file($sosHtml)) {
            // Not every report ships one; nothing to do.
            return false;
        }

        $contents = file_get_contents($sosHtml);
        if ($contents === false) {
            return false;
        }

        // Already processed — leave it byte-identical.
        if (str_contains($contents, self::SOS_HTML_FIXED_MARKER)) {
            return true;
        }

        // The links need a case id (the viewer resolves vid/did from the case).
        if (empty($cid)) {
            $cid = SupportCase::where('vault_id', $this->vid)
                ->where('file_id', $this->did)
                ->value('id');
        }
        if (empty($cid)) {
            Log::warning("fixSosHtml: no case for vault {$this->vid} dir {$this->did}");

            return false;
        }

        $map = $this->buildPathIdMap();

        $rewritten = preg_replace_callback(
            '/\b(href|src)="\.\.\/([^"?#]+)"/i',
            function ($m) use ($map, $cid) {
                $rel = rtrim(rawurldecode($m[2]), '/');
                $fid = $map[$rel] ?? null;
                if ($fid === null) {
                    // Listed but not present in the tree (excluded/missing) — inert.
                    return $m[0];
                }

                return "{$m[1]}=\"/filebrowser/{$cid}/{$fid}\" target=\"_blank\"";
            },
            $contents
        );

        // Append (never prepend) the marker: a leading HTML comment makes file(1)
        // misclassify the document so getFilePathById's mime match — and thus the
        // whole viewer — would fail to serve it. At the end, the file still begins
        // with its <!DOCTYPE>/<html> and classifies as an HTML document.
        $rewritten = rtrim($rewritten, "\n")."\n".self::SOS_HTML_FIXED_MARKER."\n";

        // Atomic replace so the File Viewer never reads a half-written file.
        $tmp = "{$sosHtml}.".bin2hex(random_bytes(4)).'.tmp';
        if (file_put_contents($tmp, $rewritten) === false) {
            Log::warning("fixSosHtml: failed writing {$tmp}");

            return false;
        }
        if (! rename($tmp, $sosHtml)) {
            @unlink($tmp);
            Log::warning("fixSosHtml: failed replacing {$sosHtml}");

            return false;
        }

        return true;
    }

    // Flatten the report file tree into a [report-relative-path => fid] map so
    // fixSosHtml can resolve each link path to its file-viewer node id (inode) in
    // one pass instead of walking the tree per link.
    private function buildPathIdMap(): array
    {
        $map = [];
        $walk = function ($nodes) use (&$walk, &$map) {
            if (! is_array($nodes)) {
                return;
            }
            foreach ($nodes as $node) {
                if (($node->type ?? '') !== 'd') {
                    $key = ($node->path ?? '').($node->name ?? '');
                    if ($key !== '' && isset($node->id)) {
                        $map[$key] = $node->id;
                    }
                }
                if (isset($node->nodes)) {
                    $walk($node->nodes);
                }
            }
        };

        $walk($this->tree->nodes ?? null);

        return $map;
    }

    private function convertToBytes(string $from): ?int
    {
        $units = ['B', 'K', 'M', 'G', 'T', 'P'];

        sscanf(strtoupper(str_replace(' ', '', $from)), '%f%c', $number, $suffix);

        if ($suffix != 'B') {
            $suffix = preg_replace('/([KMGTP])*B/', "\1", $suffix);
        }

        $exponent = array_flip($units)[$suffix] ?? null;
        if ($exponent === null) {
            return null;
        }

        $converted = $number * (1024 ** $exponent);

        return $converted;
    }

    public function unameData()
    {

        $jsonContents = "{$this->path}/.uname.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $uname = json_decode(file_get_contents($jsonContents), 1);
            if (json_last_error() == JSON_ERROR_NONE) {
                return $uname;
            }
        }

        // get sos_commands/kernel/uname_-a
        $contents = $this->readFileContents('sos_commands/kernel/uname_-a');

        if (! $contents) {
            Log::error('uname contents not found');

            return null;
        }

        $oss = ['Linux', 'FreeBSD,OpenBSD', 'NetBSD', 'SunOS', 'AIX', 'Darwin'];
        $regexp = '/^['.implode('|', $oss).']/i';
        $aver = preg_grep($regexp, $contents);
        $unameData = preg_split("/\s+/", strtolower(array_pop($aver)));

        $uname = [
            'os_name' => array_shift($unameData),
            'hostname' => array_shift($unameData),
            'kernel_release' => array_shift($unameData),
            'kernel_version' => array_shift($unameData),
            'smp' => array_shift($unameData),
            'os_type' => array_pop($unameData),
            'architecture' => array_pop($unameData),
            'date' => rtrim(implode(' ', preg_replace('/x86_64|aarch64i|amd64/', '', $unameData))),
        ];

        file_put_contents($jsonContents, json_encode((object) $uname)."\n");

        return (object) $uname;
    }

    public function kernelVersion()
    {

        $jsonContents = "{$this->path}/.kernelVersion.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $kernel_version = json_decode(file_get_contents($jsonContents), 1);
            if (json_last_error() == JSON_ERROR_NONE) {
                return $kernel_version;
            }
        }

        if (! isset($this->uname)) {
            $this->uname = $this->unameData();
        }

        if (! isset($this->uname) || empty($this->uname)) {
            return null;
        }

        if (gettype($this->uname) == 'array') {
            $version = explode('-', $this->uname['kernel_release']);
        } else {
            $version = explode('-', $this->uname->kernel_release);
        }

        $kernel = explode('.', $version[0]);

        $kernel_version = [
            'kernel' => $kernel[0] ?: '',
            'major' => "{$kernel[1]}",
            'minor' => "{$kernel[2]}",
            'ABI' => $version[1] ?: '',
            'patch' => $version[1] ?: '',
            'flavour' => isset($version[2]) && $version[2] ? $version[2] : '',
        ];

        file_put_contents($jsonContents, json_encode($kernel_version)."\n");

        return $kernel_version;
    }

    public function osVersion()
    {

        $jsonContents = "{$this->path}/.osVersion.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $os_version = json_decode(file_get_contents($jsonContents), 1);
            if (json_last_error() == JSON_ERROR_NONE) {
                return $os_version;
            }
        }

        $pfiles = [
            'etc/os-release',
            'sos_commands/lsbrelease/lsb_release_-a',
        ];

        $contents = '';
        foreach ($pfiles as $file) {
            if (! is_file("{$this->path}/{$file}")) {
                continue;
            }
            $contents = $this->readFileContents($file);
            break;
        }

        if (! $contents) {
            return null;
        }

        foreach ($contents as $line) {
            if (preg_match('/.*=.*/', $line)) {
                $keyVal = explode('=', $line);
            } elseif (preg_match('/.*:.*/', $line)) {
                $keyVal = explode(':', $line);
            } else {
                continue;
            }
            $key = $keyVal[0];
            if ($key && isset($keyVal[1])) {
                switch ($key) {
                    case 'Distributor ID':
                        $index = 'ID';
                        break;
                    case 'Description':
                        $index = 'NAME';
                        break;
                    case 'Release':
                        $index = 'VERSION';
                        break;
                    case 'Codename':
                        $index = 'VERSION_CODENAME';
                        break;
                    default:
                        $index = $key;
                        break;
                }
                $os_version[$index] = str_replace('"', '', preg_replace('/:.*$/', '', trim($keyVal[1])));
            }
        }

        file_put_contents($jsonContents, json_encode($os_version)."\n");

        return $os_version;
    }

    public function sosVersion()
    {

        $jsonContents = "{$this->path}/.sosVersion.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $sos_version = json_decode(file_get_contents($jsonContents));
            if (json_last_error() == JSON_ERROR_NONE) {
                return $sos_version;
            }
        }

        $contents = $this->readFileContents('version.txt');

        if (! $contents) {
            // Older sos versions (and partial/obfuscated captures) omit
            // version.txt. Bail like the sibling *Version() readers rather than
            // array_shift(null) — a missing file must not 500 the tool page.
            Log::error('version.txt contents not found');

            return null;
        }

        $sosreport_version = explode(':', str_replace(' ', '', array_shift($contents)));

        $contents = $this->readFileContents('sos_commands/process/ps_auxwwwm');

        if (! $contents) {
            Log::error('ps contents not found');
        } else {
            $soslines = preg_grep('|sos *report|', $contents);
            $sosps = explode(' ', preg_replace("/\s+/", ' ', array_pop($soslines)));
        }

        $sos_version = (object) [
            'sos_version' => $sosreport_version[1],
            'pid' => isset($sosps[1]) ? $sosps[1] : '',
        ];

        file_put_contents($jsonContents, json_encode($sos_version)."\n");

        return $sos_version;
    }

    public function getHostData()
    {
        $jsonContents = "{$this->path}/.hostData.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $hostinfo = json_decode(file_get_contents($jsonContents));
            if (json_last_error() == JSON_ERROR_NONE) {
                return $hostinfo;
            }
        }

        $ntp = '--';
        $dhcp = '--';
        $smtp = '--';

        isset($this->sos_version) && $sos = $this->sos_version->sos_version;

        $ver = 'Linux';
        if (isset($this->os_version) && isset($this->os_version['NAME']) && isset($this->os_version['VERSION'])) {
            $ver = "{$this->os_version['NAME']} {$this->os_version['VERSION']}";
        }

        $host = '';
        $ker = '';
        if (! empty($this->uname)) {
            if (gettype($this->uname) == 'array') {
                $host = $this->uname['hostname'];
                $ker = $this->uname['kernel_release'].' '.$this->uname['kernel_version'];
            } else {
                $host = $this->uname->hostname;
                $ker = $this->uname->kernel_release.' '.$this->uname->kernel_version;
            }
        }

        $uptime = '';
        $users = '';
        $upcontents = $this->readFileContents('uptime');
        if ($upcontents) {
            $contents = array_filter($upcontents);
            if ($contents) {
                $up = preg_split("/,\s+/", array_pop($contents));

                // uptime
                $upt = array_filter(explode(' ', $up[0]));

                $lastField = array_pop($upt);

                if ($lastField == 'days') {
                    $uptime = array_pop($upt)." {$lastField}";
                    $users = $up[2];
                } else {
                    $uptime = "{$lastField} hrs";
                    $users = $up[1];
                }

                // load average
                $load = explode(',', preg_replace('/..*: /', '', implode(',', array_slice($up, 2))));
            }
        }

        $cores = '';
        $contents = $this->readFileContents('/sos_commands/processor/lscpu');
        if (isset($contents)) {
            $lscpu = [];
            foreach ($contents as $line) {
                if ($line) {
                    $pair = explode(':', preg_replace("/:\s\s*/", ':', $line));
                    if (is_array($pair) && count($pair) == 2) {
                        $lscpu[$pair[0]] = $pair[1];
                    }
                }
            }
            $cores = $lscpu['CPU(s)'];
        }

        $conf = $this->getNICData();
        if (isset($conf)) {
            $nics = array_keys($conf);

            foreach ($nics as $xnic) {
                if ($xnic != 'lo') {
                    $nic = $xnic;
                    $mac = isset($conf[$xnic]['GENERAL.HWADDR']) ? $conf[$xnic]['GENERAL.HWADDR'] : '';
                    $mtu = isset($conf[$xnic]['GENERAL.MTU']) ? $conf[$xnic]['GENERAL.MTU'] : '';
                    $type = isset($conf[$xnic]['GENERAL.TYPE']) ? $conf[$xnic]['GENERAL.TYPE'] : '';
                    $conn = isset($conf[$xnic]['GENERAL.CONNECTION']) ? $conf[$xnic]['GENERAL.CONNECTION'] : '';
                    $ip4 = isset($conf[$xnic]['IP4.ADDRESS']) ? $conf[$xnic]['IP4.ADDRESS'] : '';
                    $gw = isset($conf[$xnic]['IP4.GATEWAY']) ? $conf[$xnic]['IP4.GATEWAY'] : '';
                    $dns = isset($conf[$xnic]['IP4.DNS']) ? $conf[$xnic]['IP4.DNS'] : '';
                    $dom = isset($conf[$xnic]['IP4.DOMAIN']) ? $conf[$xnic]['IP4.DOMAIN'] : '';
                    $speed = isset($conf[$xnic]['GENERAL.SPEED']) ? $conf[$xnic]['GENERAL.SPEED'] : '';
                    $duplex = isset($conf[$xnic]['GENERAL.DUPLEX']) ? $conf[$xnic]['GENERAL.DUPLEX'] : '';
                    $port = isset($conf[$xnic]['GENERAL.PORT']) ? $conf[$xnic]['GENERAL.PORT'] : '';
                    $linked = isset($conf[$xnic]['GENERAL.LINK_DETECTED']) ? $conf[$xnic]['GENERAL.LINK_DETECTED'] : '';
                    $ip6 = isset($conf[$xnic]['IP6.ADDRESS']) ? $conf[$xnic]['IP6.ADDRESS'] : '';

                    // currently summary ui can only fit one nic...
                    break;
                }
            }
        }

        // date and time zone
        $contents = $this->readFileContents('sos_commands/systemd/timedatectl');
        $tdctl = [];
        if (isset($contents)) {
            foreach ($contents as $line) {
                $keyval = explode('|', str_replace(': ', '|', preg_replace("/^\s+/", '', $line)));
                if (is_array($keyval) && count($keyval) > 1) {
                    $tdctl[$keyval[0]] = $keyval[1];
                }
            }
        }

        // boot time
        $contents = $this->readFileContents('proc/stat');
        $boottime = '';
        if ($contents) {
            $lines = preg_grep('/^btime.*/', $contents);
            $line = trim(array_shift($lines));
            $stamp = str_replace('btime ', '', $line);
            $boottime = date('D Y-m-d H:i:s T', $stamp);
        }

        // NTP server
        if (isset($tdctl)) {
            if (isset($tdctl['systemd-timesyncd.service active']) && $tdctl['systemd-timesyncd.service active'] == 'yes') {
                $contents = $this->readFileContents('etc/systemd/timesyncd.conf');
                $lines = preg_grep('/^NTP/', $contents);
                $ntp = preg_replace('/^NTP=/', '', array_shift($lines));
            } elseif (isset($tdctl['NTP service']) && $tdctl['NTP service'] == 'active') {
                $contents = $this->readFileContents('etc/systemd/timesyncd.conf');
                if ($contents) {
                    $lines = preg_grep('/^NTP/', $contents);
                    $ntp = preg_replace('/^NTP=/', '', array_shift($lines));
                }
            }
        }

        if (! isset($ntp) || empty($ntp)) {
            $ntp = '--';
        }

        // runlevel is a single line file
        $pfiles = [
            'sos_commands/services/runlevel',
            'sos_commands/startup/runlevel',
        ];

        $contents = '';
        foreach ($pfiles as $file) {
            if (! is_file("{$this->path}/{$file}")) {
                continue;
            }
            $contents = $this->readFileContents($file);
            break;
        }

        $runlevel = '0';
        if ($contents) {
            $line = array_filter($contents);
            if ($line) {
                $data = explode(' ', array_pop($line));
                if (isset($data[1])) {
                    $runlevel = $data[1];
                }
            }
        }

        $tableOrder1 = [
            'hostname', 'sos version', 'os version', 'kernel', 'runlevel', 'system time', 'universal time', 'boot time',
            'time zone', 'uptime', 'load average', 'nic', 'type', 'mac', 'mtu', 'ip4', 'gateway', 'dns servers', 'dns domain', 'connection', 'speed', 'linked', 'port', 'duplex',
            'dhcp server', 'smtp server', 'ntp server', 'ip6', 'cores', 'users', 'icon', 'machineid',
        ];

        // machineid is a single line file
        $machineid = '';
        $pfiles = [
            'etc/machine-id',
        ];

        $contents = '';
        foreach ($pfiles as $file) {
            if (! is_file("{$this->path}/{$file}")) {
                continue;
            }
            $contents = array_filter($this->readFileContents($file));
            break;
        }

        if ($contents) {
            $line = array_pop($contents);
            if ($line) {
                $data = explode(' ', $line);
                if (isset($data[0])) {
                    $machineid = $data[0];
                }
            }
        }

        // fill the data here
        $hostinfo = (object) [];
        foreach ($tableOrder1 as $key) {
            $value = null;
            switch ($key) {
                case 'cores':
                    if (isset($cores)) {
                        $value = $cores;
                    }
                    break;
                case 'dns domain':
                    if (isset($dom)) {
                        $value = $dom;
                    }
                    break;
                case 'load average':
                    if (isset($load)) {
                        if (isset($cores)) {
                            $value = "{$cores} cores: ".implode(', ', $load);
                        } else {
                            $value = implode(', ', $load);
                        }
                    }
                    break;
                case 'hostname':
                    if (isset($host)) {
                        $value = $host;
                    }
                    break;
                case 'boot time':
                    if (isset($boottime)) {
                        $value = $boottime;
                    }
                    break;
                case 'system time':
                    if (isset($tdctl['Local time'])) {
                        $value = $tdctl['Local time'];
                    }
                    break;
                case 'universal time':
                    if (isset($tdctl['Universal time'])) {
                        $value = $tdctl['Universal time'];
                    }
                    break;
                case 'time zone':
                    if (isset($tdctl['Time zone'])) {
                        $value = $tdctl['Time zone'];
                    }
                    break;
                case 'sos version':
                    if (isset($sos)) {
                        $value = $sos;
                    }
                    break;
                case 'os version':
                    if (isset($ver)) {
                        $value = $ver;
                    }
                    break;
                case 'kernel':
                    if (isset($ker)) {
                        $value = $ker;
                    }
                    break;
                case 'gateway':
                    if (isset($gw)) {
                        $value = $gw;
                    }
                    break;
                case 'dns servers':
                    if (isset($dns)) {
                        $value = $dns;
                    }
                    break;
                case 'smtp server':
                    if (isset($smtp)) {
                        $value = $smtp;
                    }
                    break;
                case 'ntp server':
                    if (isset($ntp)) {
                        $value = $ntp;
                    }
                    break;
                case 'dhcp server':
                    if (isset($dhcp)) {
                        $value = $dhcp;
                    }
                    break;
                case 'nic':
                    if (isset($nic)) {
                        $value = $nic;
                    }
                    break;
                case 'mac':
                    if (isset($mac)) {
                        $value = $mac;
                    }
                    break;
                case 'mtu':
                    if (isset($mtu)) {
                        $value = $mtu;
                    }
                    break;
                case 'ip4':
                    if (isset($ip4)) {
                        $value = $ip4;
                    }
                    break;
                case 'type':
                    if (isset($type)) {
                        $value = $type;
                    }
                    break;
                case 'connection':
                    if (isset($conn)) {
                        $value = $conn;
                    }
                    break;
                case 'speed':
                    if (isset($speed)) {
                        $value = $speed;
                    }
                    break;
                case 'port':
                    if (isset($port)) {
                        $value = $port;
                    }
                    break;
                case 'linked':
                    if (isset($linked)) {
                        $value = $linked;
                    }
                    break;
                case 'duplex':
                    if (isset($duplex)) {
                        $value = $duplex;
                    }
                    break;
                case 'machineid':
                    $value = $machineid;
                    break;
                case 'icon':
                    $value = '';
                    break;
                default:
                    if (isset($$key)) {
                        $value = $$key;
                    }
                    break;
            }
            if (isset($value)) {
                $hostinfo->{$key} = $value;
            }
        }

        file_put_contents($jsonContents, json_encode($hostinfo)."\n");

        return $hostinfo;
    }

    public function getCpuData()
    {
        $jsonContents = "{$this->path}/.cpuData.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $cpuinfo = json_decode(file_get_contents($jsonContents));
            if (json_last_error() == JSON_ERROR_NONE) {
                return $cpuinfo;
            }
        }

        $cpuinfo = (object) [];

        // get sos_commands/processor/lscpu
        $contents = $this->readFileContents('/sos_commands/processor/lscpu');
        if (! $contents) {
            return null;
        }

        $lscpu = [];
        foreach ($contents as $line) {
            if ($line) {
                $pair = explode(':', preg_replace("/:\s\s*/", ':', $line));
                if (is_array($pair) && count($pair) == 2) {
                    $lscpu[$pair[0]] = $pair[1];
                }
            }
        }

        // get proc/stat
        $contents = $this->readFileContents('/proc/stat');
        if (! $contents) {
            Log::error('stat not found');

            return null;
        }

        $cpus = preg_grep('/^cpu.*/', $contents);

        $head = ['cpu', 'user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'total', 'color'];

        // percentage conversion
        $skip = ['cpu', 'total', 'color'];

        foreach ($cpus as $line) {
            $cpu = explode(' ', preg_replace('/   */', ' ', $line));
            $entry = (object) [];
            $total = 0;
            $name = '';
            foreach ($head as $i => $header) {
                if ($header == 'total') {
                    $entry->{$header} = $total;
                    $cpuinfo->{$cpu[0]} = $entry;
                } elseif ($header == 'cpu') {
                    $entry->{$header} = $cpu[$i] == 'cpu' ? 'total' : $cpu[$i];
                } elseif ($header == 'color') {
                    $entry->{$header} = 'green';
                } else {
                    if (isset($cpu[$i])) {
                        $entry->{$header} = intval($cpu[$i]);
                        $total += $entry->{$header};
                    }
                }
            }
        }

        foreach ($cpuinfo as $cpu => $entry) {
            foreach ($entry as $key => $value) {
                if (in_array($key, $skip)) {
                    continue;
                }
                $cpuinfo->{$cpu}->{$key} = Number::format(floatval($value * 100 / $entry->total), precision: 2);
            }
        }

        $cpuinfo->model = '';
        if (isset($lscpu) && isset($lscpu['Model name'])) {
            $cpuinfo->model = $lscpu['Model name'];
        } else {
            $cpuinfo->model = $lscpu['Vendor ID'];
        }

        file_put_contents($jsonContents, json_encode($cpuinfo)."\n");

        return $cpuinfo;
    }

    public function getMemoryData()
    {

        $jsonContents = "{$this->path}/.memoryData.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $meminfo = json_decode(file_get_contents($jsonContents));
            if (json_last_error() == JSON_ERROR_NONE) {
                return $meminfo;
            }
        }

        // get proc/meminfo
        $contents = $this->readFileContents('/proc/meminfo');
        if (! $contents) {
            Log::error('meminfo not found');

            return null;
        }

        $meminfo = [];
        foreach ($contents as $line) {
            if ($line) {
                $pair = explode(':', str_replace(' ', '', str_replace(' kB', '', $line)));
                if (is_array($pair) && count($pair) == 2) {
                    $meminfo[$pair[0]] = floatval($pair[1]);
                }
            }
        }

        $tcpMem = 0;
        $sockstat = $this->getSockstatData();
        if ($sockstat) {
            $tcpMem = $sockstat->TCP->mem + $sockstat->UDP->mem + $sockstat->FRAG->memory;
        }

        $buffer = $meminfo['Buffers'] + $meminfo['Cached'] + $meminfo['SReclaimable'];
        $used = $meminfo['MemTotal'] - ($meminfo['MemFree'] + $buffer);
        $pused = ($used / $meminfo['MemTotal'] * 100);
        $pfree = ($meminfo['MemFree'] / $meminfo['MemTotal'] * 100);
        $pbuff = ($buffer / $meminfo['MemTotal'] * 100);

        $available = 0;
        if (isset($meminfo['MemAvailable'])) {
            $available = $meminfo['MemAvailable'];
        } else {
            $available = $meminfo['MemFree'];
        }

        $head = ['total', 'used', 'pused', 'free', 'pfree', 'shared', 'buff/cache', 'pbuff', 'network', 'available'];
        $mem = [
            $meminfo['MemTotal'],
            $used,
            $pused,
            $meminfo['MemFree'],
            $pfree,
            $meminfo['Shmem'],
            $buffer,
            $pbuff,
            $tcpMem,
            $available,
        ];

        $swap = [];
        if ($meminfo['SwapTotal'] > 0) {
            $swap = [
                $meminfo['SwapTotal'],
                $meminfo['SwapTotal'] - $meminfo['SwapFree'],
                (($meminfo['SwapTotal'] - $meminfo['SwapFree']) / $meminfo['SwapTotal'] * 100),
                $meminfo['SwapFree'],
                ($meminfo['SwapFree'] / $meminfo['SwapTotal'] * 100),
            ];
        }

        // get sos_commands/memory/swapon_--bytes_--show contents
        $contents = $this->readFileContents('/sos_commands/memory/swapon_--bytes_--show');
        if ($contents) {
            // parse the contents and extract the info
            $aver = preg_grep('/NAME/', $contents);
            $head2 = preg_split("/\s+/", strtolower(array_pop($aver)));

            $aver = preg_grep("/^\//", $contents);
            $swap2 = preg_split("/\s+/", array_pop($aver));
        }

        // fill the mem data
        $perc = ['pused', 'pfree', 'pbuff', 'network'];

        $tableData1 = (object) [];
        $tableData1->title = 'memory';
        foreach ($head as $i => $header) {
            if (isset($mem[$i])) {
                $entry = (object) [
                    'value' => '',
                    'color' => '',
                    'icon' => '',
                ];
                if (in_array($header, $perc)) {
                    $entry->value = floatval($mem[$i]);
                } else {
                    $entry->value = floatval($mem[$i]) * 1024;
                }
                $tableData1->{$header} = $entry;
            }
        }

        // fill the swap data
        $tableData2 = (object) [];
        $tableData2->title = 'swap';
        foreach ($head as $i => $header) {
            if (isset($swap[$i])) {
                $entry = (object) [
                    'value' => '',
                    'color' => '',
                    'icon' => '',
                ];
                if (in_array($header, $perc)) {
                    $entry->value = floatval($swap[$i]);
                } else {
                    $entry->value = floatval($swap[$i]) * 1024;
                }
                $tableData2->{$header} = $entry;
            }
        }

        if (isset($head2)) {
            foreach ($head2 as $i => $header) {
                if ($header == 'used') {
                    continue;
                }
                if (isset($swap2[$i])) {
                    $entry = (object) [
                        'value' => '',
                        'color' => '',
                        'icon' => '',
                    ];
                    if ($header == 'size') {
                        $entry->value = floatval($swap2[$i]) * 1024;
                    } else {
                        $entry->value = $swap2[$i];
                    }
                    $tableData2->{$header} = $entry;
                }
            }
        }

        $meminfo = (object) [
            'memory' => $tableData1,
            'swap' => $tableData2,
        ];

        file_put_contents($jsonContents, json_encode($meminfo)."\n");

        return $meminfo;
    }

    public function getDiskData()
    {
        $jsonContents = "{$this->path}/.disksData.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $disks = (array) json_decode(file_get_contents($jsonContents));
            if (json_last_error() == JSON_ERROR_NONE) {
                return $disks;
            }
        }

        $except = [
            'sysfs',
            'proc',
            'udev',
            'devpts',
            'tmpfs',
            'securityfs',
            'cgroup',
            'pstore',
            'mqueue',
            'hugetlbfs',
            'configfs',
            'fusectl',
            'debugfs',
            'sunrpc',
            'binfmt_misc',
            'lxcfs',
            'tracefs',
        ];

        $disks = [];

        $pfiles = [
            'sos_commands/filesys/df_-al_-x_autofs',
            'sos_commands/filesys/df_-al',
        ];

        $contents = '';
        foreach ($pfiles as $file) {
            if (! is_file("{$this->path}/{$file}")) {
                continue;
            }
            $contents = $this->readFileContents($file);
            break;
        }

        if (! $contents) {
            return null;
        }

        // parse the contents and extract the info
        $regexp = ";^(?!/dev/loop\d{1,3})(?!/dev/fuse\d{0,3})[^".implode('|', $except).'];';

        $aver = preg_grep($regexp, $contents);

        $disks1 = [];
        if (is_array($aver)) {
            array_shift($aver);
            foreach ($aver as $disk) {
                $disks1[] = preg_split("/\s+/", $disk);
            }
        }
        if (! $disks1 || ! is_array($disks1) || ! count($disks1)) {
            Log::error('could not parse contents');

            return null;
        }

        // parse the contents and extract the info
        $aver = preg_grep('/^Filesystem/', $contents);
        $head1 = preg_split("/\s+/", array_shift($aver));

        // inodes
        $removeType = false;

        $pfiles = [
            'sos_commands/filesys/df_-ali_-x_autofs',
            'sos_commands/filesys/df_-aliT_-x_autofs',
            'sos_commands/filesys/df_-ali',
        ];

        $contents = '';
        foreach ($pfiles as $file) {
            if (! is_file("{$this->path}/{$file}")) {
                continue;
            }
            $contents = $this->readFileContents($file);
            if ($file == 'sos_commands/filesys/df_-aliT_-x_autofs') {
                $removeType = true;
            }
            break;
        }

        $disks2 = [];
        if ($contents) {
            $aver = preg_grep($regexp, $contents);
            if (is_array($aver)) {
                // remove header line
                array_shift($aver);
                foreach ($aver as $disk) {
                    $fields = preg_split("/\s+/", $disk);
                    if ($removeType) {
                        array_splice($fields, 1, 1);
                    }
                    $disks2[] = $fields;
                }
            }
            if (! $disks2 || ! is_array($disks2) || ! count($disks2)) {
                Log::error('could not parse contents2');

                return null;
            }

            // parse the contents and extract the info
            $aver = preg_grep('/^Filesystem/', $contents);
            $head2 = preg_split("/\s+/", array_shift($aver));
        }

        // fs type
        $findmnt = $this->readFileContents('sos_commands/filesys/findmnt');
        if (! $findmnt) {
            Log::error('findmnt not found');

            return null;
        }

        // disk type
        $lsblk = $this->readFileContents('/sos_commands/block/lsblk_-O_-P');
        $lsblk_info = [];
        if ($lsblk) {
            $tmplsblk = [];
            if ($lsblk) {
                foreach ($lsblk as $index => $line) {
                    if (! empty($line)) {
                        $cols = preg_split("/\s+/", trim($line));
                        if (! empty($cols)) {
                            foreach ($cols as $col) {
                                if (! empty($col)) {
                                    $pair = preg_split('/=/', $col);
                                    if (! empty($pair)) {
                                        isset($pair[0]) && $key = $pair[0];
                                        isset($pair[1]) && $value = str_replace('"', '', $pair[1]);
                                        if (! isset($tmplsblk[$index])) {
                                            $tmplsblk[$index] = [];
                                        }
                                        $tmplsblk[$index][$key] = $value;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $lsblk = [];
            if ($tmplsblk) {
                foreach ($tmplsblk as $index => $ddata) {
                    if (! empty($ddata)) {
                        if (isset($ddata['MOUNTPOINT'])) {
                            $lsblk_info[$ddata['MOUNTPOINT']] = $ddata;
                        }
                    }
                }
            }
        } else {
            Log::error('lsblk -O -P not found');
            $lsblk = $this->readFileContents('/sos_commands/block/lsblk');
            if (! $lsblk) {
                Log::error('lsblk not found');

                return null;
            }
        }

        // disk io
        $diskstats = $this->readFileContents('proc/diskstats');
        if (! $diskstats) {
            Log::error('diskstats not found');
            // return null;
        }

        $iostats = [];
        if (isset($diskstats) && ! empty($diskstats)) {
            $ddata = [];
            foreach ($diskstats as $line) {
                if (! empty($line)) {
                    $fields = preg_split("/\s+/", trim($line));
                    if (! empty($fields)) {
                        // https://docs.kernel.org/admin-guide/iostats.html

                        $cols = count($fields);
                        if ($cols < 14) {
                            continue;
                        } elseif ($cols > 18) {
                            // kernels 5.5 and newer
                            [
                                $major,
                                $minor,
                                $dev,
                                $reads,
                                $readsMerged,
                                $sectorsRead,
                                $readMs,
                                $writes,
                                $writesMerged,
                                $sectorsWritten,
                                $writeMs,
                                $inFlight,
                                $ioMs,
                                $weightedIoMs,
                                $discards,
                                $dmerged,
                                $dsectors,
                                $discardsms,
                                $flush_requests,
                                $flushms,
                            ] = $fields;

                            $iostats["{$major}:{$minor}"] = [
                                'reads' => (int) $reads,
                                'readsMerged' => (int) $readsMerged,
                                'sectorsRead' => (int) $sectorsRead,
                                'readMs' => (int) $readMs,
                                'writes' => (int) $writes,
                                'writesMerged' => (int) $writesMerged,
                                'sectorsWritten' => (int) $sectorsWritten,
                                'writeMs' => (int) $writeMs,
                                'ioMs' => (int) $ioMs,
                                'weightedIoMs' => (int) $weightedIoMs,
                                'discards' => (int) $discards,
                                'discardsMerged' => (int) $dmerged,
                                'sectorsDiscarded' => (int) $dsectors,
                                'discardMs' => (int) $discardsms,
                                'flush_requests' => (int) $flush_requests,
                                'flushMs' => (int) $flushms,
                            ];
                        } elseif ($cols == 18) {
                            // kernels 4.19 and newer
                            [
                                $major,
                                $minor,
                                $dev,
                                $reads,
                                $readsMerged,
                                $sectorsRead,
                                $readMs,
                                $writes,
                                $writesMerged,
                                $sectorsWritten,
                                $writeMs,
                                $inFlight,
                                $ioMs,
                                $weightedIoMs,
                                $discards,
                                $dmerged,
                                $dsectors,
                                $discardsms,
                            ] = $fields;

                            $iostats["{$major}:{$minor}"] = [
                                'reads' => (int) $reads,
                                'readsMerged' => (int) $readsMerged,
                                'sectorsRead' => (int) $sectorsRead,
                                'readMs' => (int) $readMs,
                                'writes' => (int) $writes,
                                'writesMerged' => (int) $writesMerged,
                                'sectorsWritten' => (int) $sectorsWritten,
                                'writeMs' => (int) $writeMs,
                                'ioMs' => (int) $ioMs,
                                'weightedIoMs' => (int) $weightedIoMs,
                                'discards' => (int) $discards,
                                'discardsMerged' => (int) $dmerged,
                                'sectorsDiscarded' => (int) $dsectors,
                                'discardMs' => (int) $discardsms,
                                'flush_requests' => 0,
                                'flushMs' => 0,
                            ];
                        } else {
                            [
                                $major,
                                $minor,
                                $dev,
                                $reads,
                                $readsMerged,
                                $sectorsRead,
                                $readMs,
                                $writes,
                                $writesMerged,
                                $sectorsWritten,
                                $writeMs,
                                $inFlight,
                                $ioMs,
                                $weightedIoMs
                            ] = $fields;

                            $iostats["{$major}:{$minor}"] = [
                                'reads' => (int) $reads,
                                'readsMerged' => (int) $readsMerged,
                                'sectorsRead' => (int) $sectorsRead,
                                'readMs' => (int) $readMs,
                                'writes' => (int) $writes,
                                'writesMerged' => (int) $writesMerged,
                                'sectorsWritten' => (int) $sectorsWritten,
                                'writeMs' => (int) $writeMs,
                                'ioMs' => (int) $ioMs,
                                'weightedIoMs' => (int) $weightedIoMs,
                                'discards' => 0,
                                'discardsMerged' => 0,
                                'sectorsDiscarded' => 0,
                                'discardMs' => 0,
                                'flush_requests' => 0,
                                'flushMs' => 0,
                            ];
                        }
                    }
                }
            }
        }

        // lvm membership
        $lvsfiles = [
            'sos_commands/lvm2/lvs_-a_-o_lv_tags_devices_lv_kernel_read_ahead_lv_read_ahead_stripes_stripesize_--config_global_locking_type_0_metadata_read_only_1_--foreign',
            '/sos_commands/lvm2/lvs_-a_-o_lv_tags_devices_--config_global_locking_type_0',
        ];

        $lvs = '';
        foreach ($lvsfiles as $file) {
            if (! is_file("{$this->path}/{$file}")) {
                continue;
            }
            $lvs = $this->readFileContents($file);
            break;
        }

        if (! $lvs) {
            Log::error('vgdisplay not found');
            // return null;
        }

        // zfs membership

        $uptimeSeconds = 0;
        $data = $this->getHostData();
        if (isset($data) && ! empty($data)) {
            if (isset($data->{'boot time'}) && isset($data->{'universal time'})) {
                $tz = new \DateTimeZone('UTC');
                $bootTime = new \DateTimeImmutable($data->{'boot time'}, $tz);
                $systemTime = new \DateTimeImmutable($data->{'universal time'}, $tz);
                $uptimeSeconds = $systemTime->getTimestamp() - $bootTime->getTimestamp();
                if ($uptimeSeconds < 0) {
                    Log::error('no uptime info found');
                    $uptimeSeconds = 0;
                }
            } else {
                Log::error('no uptime info found');
            }
        }

        // fill the disk data
        if (isset($disks1) && ! empty($disks1)) {
            foreach ($disks1 as $j => $disk) {
                $diskentry = (object) [
                    'label' => '',
                    'point' => '',
                    'size' => '',
                    'available' => '',
                    'used' => '',
                    'pused' => '',
                    'isize' => '',
                    'iused' => '',
                    'ipused' => '',
                    'dtype' => '',
                    'fstype' => '',
                    'color' => '',
                    'name' => '',
                    'pvolumes' => '',
                    'moptions' => '',
                    'r/s' => '',
                    'rkB/s' => '',
                    'rrqm/s' => '',
                    '%rrqm' => '',
                    'r_await' => '',
                    'rareq-sz' => '',
                    'w/s' => '',
                    'wkB/s' => '',
                    'wrqm/s' => '',
                    '%wrqm' => '',
                    'w_await' => '',
                    'wareq-sz' => '',
                    'aqu-sz' => '',
                    'util' => '',
                    'tps' => '',
                    'd/s' => '',
                    'dkB/s' => '',
                    'drqm/s' => '',
                    '%drqm' => '',
                    'd_await' => '',
                    'dareq-sz' => '',
                    'f/s' => '',
                    'f_await' => '',
                    'majmin' => '',
                    'ifree' => '',
                ];

                foreach ($head1 as $i => $header) {
                    if (isset($disk[$i])) {
                        $label = '';
                        $value = $disk[$i];
                        switch ($i) {
                            case 0:
                                $label = 'label';
                                break;
                            case 1:
                                $label = 'size';
                                $value = floatval($disk[$i]) * 1024;
                                break;
                            case 2:
                                $label = 'used';
                                $value = floatval($disk[$i]) * 1024;
                                break;
                            case 3:
                                $label = 'available';
                                $value = floatval($disk[$i]) * 1024;
                                break;
                            case 4:
                                $label = 'pused';
                                $value = str_replace('%', '', $disk[$i]);
                                break;
                            case 5:
                                $label = 'point';
                                break;
                        }

                        if (isset($label)) {
                            $diskentry->{$label} = $value;
                        }
                    }
                }
                $disks[] = $diskentry;
            }
        }

        // fill the inode data
        if (isset($disks2) && ! empty($disks2)) {
            foreach ($disks2 as $j => $disk) {
                $index = -1;
                foreach ($disks as $i => $entry) {
                    if ($entry->label == $disk[0]) {
                        $index = $i;

                    }
                }

                foreach ($head2 as $i => $header) {
                    if (isset($disk[$i])) {
                        $label = '';
                        $value = $disk[$i];
                        switch ($i) {
                            case 1:
                                $label = 'isize';
                                break;
                            case 2:
                                $label = 'iused';
                                break;
                            case 3:
                                $label = 'ifree';
                                break;
                            case 4:
                                $label = 'ipused';
                                $value = str_replace('%', '', $disk[$i]);
                                break;
                        }

                        if ($label != '') {
                            $disks[$index]->{$label} = $value;
                        }
                    }
                }
            }
        }

        // fill the fs type data
        if (isset($disks) && ! empty($disks)) {
            // fill the fstype, disk type, name and majmin
            foreach ($disks as $index => $entry) {
                if (isset($lsblk_info) && ! empty($lsblk_info) && isset($lsblk_info[$entry->point])) {

                    $ddata = $lsblk_info[$entry->point];

                    if (isset($ddata) && ! empty($ddata)) {
                        isset($ddata['FSTYPE']) && ! empty($ddata['FSTYPE']) && $disks[$index]->fstype = $ddata['FSTYPE'];
                        isset($ddata['TYPE']) && ! empty($ddata['TYPE']) && $disks[$index]->dtype = $ddata['TYPE'];
                        isset($ddata['NAME']) && ! empty($ddata['NAME']) && $disks[$index]->name = $ddata['NAME'];
                        isset($ddata['MAJ:MIN']) && ! empty($ddata['MAJ:MIN']) && $disks[$index]->majmin = $ddata['MAJ:MIN'];
                        isset($ddata['MAJ_MIN']) && ! empty($ddata['MAJ_MIN']) && $disks[$index]->majmin = $ddata['MAJ_MIN'];
                    }
                } elseif (isset($lsblk) && ! empty($lsblk)) {
                    $nameparts = explode('/', $entry->label);
                    $name = array_pop($nameparts);
                    $disks[$index]->name = $name;

                    if (! empty($name)) {
                        if (isset($findmnt) && ! empty($findmnt)) {
                            $lines = preg_grep("|{$name}\s+|", $findmnt);
                            if (! empty($lines)) {
                                $ddata = [];
                                foreach ($lines as $line) {
                                    if (! empty($line)) {
                                        $mntinfo = preg_split("/\s+/", trim($line));
                                        if (! empty($mntinfo)) {
                                            $disks[$index]->moptions = array_pop($mntinfo);
                                            $disks[$index]->fstype = array_pop($mntinfo);
                                        }
                                    }
                                }
                            }
                        }
                    }

                    $regex = "|{$entry->point}|";
                    $line = preg_grep($regex, $lsblk);
                    if (isset($line)) {
                        $DATA = preg_split("/\s+/", trim(array_pop($line)));
                        if (isset($DATA) && is_array($DATA) && count($DATA) > 5) {
                            $l = count($DATA);
                            $disks[$index]->dtype = $DATA[$l - 2];
                            $disks[$index]->majmin = $DATA[1];
                        }
                    }
                }

                // color
                if ($entry->pused >= 90 || $entry->ipused >= 90) {
                    $disks[$index]->color = 'danger';
                } elseif ($entry->pused >= 75 || $entry->ipused >= 75) {
                    $disks[$index]->color = 'warning';
                }
            }

            // fill the pvolumes data
            if (isset($lvs) && ! empty($lvs)) {
                foreach ($disks as $index => $entry) {
                    $names = explode('-', $entry->label);
                    $name = array_pop($names);
                    if (! empty($name)) {
                        $lines = preg_grep("|{$name}\s+|", $lvs);
                        if (! empty($lines)) {
                            $ddata = [];
                            foreach ($lines as $line) {
                                if (! empty($line)) {
                                    $lvinfo = preg_split("/\s+/", trim($line));
                                    $ddata[] = preg_replace("/\(\d+\)$/", '', array_slice($lvinfo, -5, 1)[0]);
                                }
                            }
                            if (isset($ddata) && ! empty($ddata)) {
                                $disks[$index]->pvolumes = implode(',', $ddata);
                            }
                        }
                    }
                }
            }

            // fill the moptions field
            foreach ($disks as $index => $entry) {
                $name = $entry->point;
                if (! empty($name)) {
                    if (isset($findmnt) && ! empty($findmnt)) {
                        $lines = preg_grep("|{$name}\s+|", $findmnt);
                        if (! empty($lines)) {
                            $ddata = [];
                            foreach ($lines as $line) {
                                if (! empty($line)) {
                                    $mntinfo = preg_split("/\s+/", trim($line));
                                    if (! empty($mntinfo)) {
                                        $disks[$index]->moptions = array_pop($mntinfo);
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // fill the iostat fields
            if (isset($iostats) && ! empty($iostats) && $uptimeSeconds > 0) {
                foreach ($disks as $index => $entry) {
                    if (! empty($entry)) {

                        if (! isset($iostats[$entry->majmin]) || empty($iostats[$entry->majmin])) {
                            continue;
                        }

                        $stats = $iostats[$entry->majmin];

                        // read stats
                        $rps = $stats['reads'] / $uptimeSeconds;
                        $rkB = $stats['sectorsRead'] * 512 / 1024 / $uptimeSeconds;
                        $rrqms = $stats['readsMerged'] / $uptimeSeconds;
                        $totalReadReq = $stats['readsMerged'] + $stats['reads'];
                        $rrqmPct = $totalReadReq > 0 ? ($stats['readsMerged'] / $totalReadReq) * 100 : 0;
                        $rawait = $stats['reads'] > 0 ? $stats['readMs'] / $stats['reads'] : 0;
                        $rareq = $stats['reads'] > 0 ? ($stats['sectorsRead'] * 512 / 1024) / $stats['reads'] : 0;

                        // write stats
                        $wps = $stats['writes'] / $uptimeSeconds;
                        $wkB = $stats['sectorsWritten'] * 512 / 1024 / $uptimeSeconds;
                        $wrqms = $stats['writesMerged'] / $uptimeSeconds;
                        $totalWriteReq = $stats['writesMerged'] + $stats['writes'];
                        $wrqmPct = $totalWriteReq > 0 ? ($stats['writesMerged'] / $totalWriteReq) * 100 : 0;
                        $wawait = $stats['writes'] > 0 ? $stats['writeMs'] / $stats['writes'] : 0;
                        $wareq = $stats['writes'] > 0 ? ($stats['sectorsWritten'] * 512 / 1024) / $stats['writes'] : 0;
                        $totalIO = ($stats['reads'] + $stats['writes']) / $uptimeSeconds;

                        $avgqu = $stats['weightedIoMs'] / ($uptimeSeconds * 1000);
                        $util = $stats['ioMs'] / ($uptimeSeconds * 10);

                        // read stats
                        $disks[$index]->{'r/s'} = json_encode([
                            'value' => $rps,
                            'units' => 'op/s',
                            'descr' => 'Read requests completed per second',
                        ]);
                        $disks[$index]->{'rkB/s'} = json_encode([
                            'value' => $rkB,
                            'units' => 'kB/s',
                            'descr' => 'Kilobytes read per second',
                        ]);
                        $disks[$index]->{'rrqm/s'} = json_encode([
                            'value' => $rrqms,
                            'units' => 'op/s',
                            'descr' => 'Read requests merged per second by the I/O scheduler',
                        ]);
                        $disks[$index]->{'%rrqm'} = json_encode([
                            'value' => $rrqmPct,
                            'units' => '%',
                            'descr' => 'percentage of read requests that were merged',
                        ]);
                        $disks[$index]->{'r_await'} = json_encode([
                            'value' => $rawait,
                            'units' => 'ms',
                            'descr' => 'Average time spent per read request (queue + service)',
                        ]);
                        $disks[$index]->{'rareq-sz'} = json_encode([
                            'value' => $rareq,
                            'units' => 'kB',
                            'descr' => 'Average size of read requests issued',
                        ]);

                        // write stats
                        $disks[$index]->{'w/s'} = json_encode([
                            'value' => $wps,
                            'units' => 'op/s',
                            'descr' => 'Write requests completed per second',
                        ]);
                        $disks[$index]->{'wkB/s'} = json_encode([
                            'value' => $wkB,
                            'units' => 'kB/s',
                            'descr' => 'Kilobytes written per second',
                        ]);
                        $disks[$index]->{'wrqm/s'} = json_encode([
                            'value' => $wrqms,
                            'units' => 'op/s',
                            'descr' => 'Write requests merged per second by the I/O scheduler',
                        ]);
                        $disks[$index]->{'%wrqm'} = json_encode([
                            'value' => $wrqmPct,
                            'units' => '%',
                            'descr' => 'percentage of write requests that were merged',
                        ]);
                        $disks[$index]->{'w_await'} = json_encode([
                            'value' => $wawait,
                            'units' => 'ms',
                            'descr' => 'Average time spent per write request (queue + service)',
                        ]);
                        $disks[$index]->{'wareq-sz'} = json_encode([
                            'value' => $rareq,
                            'units' => 'kB',
                            'descr' => 'Average size of write requests issued',
                        ]);

                        // global IO stats
                        $disks[$index]->{'aqu-sz'} = json_encode([
                            'value' => $avgqu,
                            'units' => 'op',
                            'descr' => 'Average number of requests waiting in the device queue (queue depth)',
                        ]);
                        $disks[$index]->{'util'} = json_encode([
                            'value' => $util,
                            'units' => '%',
                            'descr' => 'Percentage of time the device was busy handling I/O',
                        ]);

                        // discard and flush stats (kernels 4.19 and newer
                        if (count($stats) > 10) {
                            $dps = $stats['discards'] / $uptimeSeconds;
                            $fps = $stats['flush_requests'] / $uptimeSeconds;
                            $dkB = $stats['sectorsDiscarded'] * 512 / 1024 / $uptimeSeconds;
                            $drqms = $stats['discardsMerged'] / $uptimeSeconds;
                            $totalDiscardReq = $stats['discardsMerged'] + $stats['discards'];
                            $drqmPct = $totalDiscardReq > 0 ? ($stats['discardsMerged'] / $totalDiscardReq) * 100 : 0;
                            $dawait = $stats['discards'] > 0 ? $stats['discardMs'] / $stats['discards'] : 0;
                            $fawait = $stats['flush_requests'] > 0 ? $stats['flushMs'] / $stats['flush_requests'] : 0;
                            $dareq = $stats['discards'] > 0 ? ($stats['sectorsDiscarded'] * 512 / 1024) / $stats['discards'] : 0;
                            $totalIO = ($stats['reads'] + $stats['writes'] + $stats['discards'] + $stats['flush_requests']) / $uptimeSeconds;

                            $disks[$index]->{'d/s'} = json_encode([
                                'value' => $dps,
                                'units' => 'op/s',
                                'descr' => 'Discard (TRIM) requests completed per second',
                            ]);
                            $disks[$index]->{'dkB/s'} = json_encode([
                                'value' => $dkB,
                                'units' => 'kB/s',
                                'descr' => 'Kilobytes discarded per second',
                            ]);
                            $disks[$index]->{'drqm/s'} = json_encode([
                                'value' => $drqms,
                                'units' => 'op/s',
                                'descr' => 'Discard requests merged per second by the I/O scheduler',
                            ]);
                            $disks[$index]->{'%drqm'} = json_encode([
                                'value' => $drqmPct,
                                'units' => 'percentage',
                                'descr' => 'Percentage of discard requests that were merged',
                            ]);
                            $disks[$index]->{'d_await'} = json_encode([
                                'value' => $dawait,
                                'units' => 'ms',
                                'descr' => 'Average time spent per discard request (queue + service)',
                            ]);
                            $disks[$index]->{'dareq-sz'} = json_encode([
                                'value' => $dareq,
                                'units' => 'kB',
                                'descr' => 'Average size of discard requests issued',
                            ]);

                            $disks[$index]->{'f/s'} = json_encode([
                                'value' => $fps,
                                'units' => 'op/s',
                                'descr' => 'Flush (cache flush/FUA) requests completed per second',
                            ]);
                            $disks[$index]->{'f_await'} = json_encode([
                                'value' => $fawait,
                                'units' => 'ms',
                                'descr' => 'Average time spent per flush request',
                            ]);

                        }

                        $await = $totalIO > 0 ? ($stats['readMs'] + $stats['writeMs']) / $totalIO : 0;

                        $disks[$index]->{'tps'} = json_encode([
                            'value' => $totalIO,
                            'units' => 'op/s',
                            'descr' => 'Total I/O requests issued to the device per second (reads + writes + discards + flushes)',
                        ]);

                        /*
                            Add something like this:
                            “High write IOPS with small request sizes, low latency, and moderate utilization. Disk is busy but not saturated. No I/O bottleneck detected.”
                        */

                    }
                }
            }
        }

        // log::info(var_export($disks, true));

        file_put_contents($jsonContents, json_encode($disks)."\n");

        return $disks;
    }

    public function getProcessesData()
    {
        $jsonContents = "{$this->path}/.processesData.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $processes = (array) json_decode(file_get_contents($jsonContents));
            if (json_last_error() == JSON_ERROR_NONE) {
                return $processes;
            }
        }

        $pids1 = [];
        $pids2 = [];
        $processes = [];

        if (is_file("{$this->path}/sos_commands/process/ps_-elfL")) {
            // read ps -ef file
            $file = 'sos_commands/process/ps_-elfL';
            $ffields = [
                'USER' => 'UID',
                'PID' => 'PID',
                'prio' => 'PRI',
                'CMD' => 'CMD',
                'Command' => 'CMD',
                'STAT' => 'S',
                'PPID' => 'PPID',
                'PRI' => 'PRI',
                'NI' => 'NI',
                'WCHAN' => 'WCHAN',
                'STIME' => 'STIME',
                'TTY' => 'TTY',
                'threads' => 'NLWP',
            ];
            $headers = [];
            $contents = $this->readFileContents($file);
            if (isset($contents)) {
                foreach ($contents as $line) {
                    if (! $line) {
                        continue;
                    }

                    $pinfo0 = [];
                    if (preg_match('/^F S..*/', $line)) {
                        $headers = preg_split("/\s{1,10}/", $line);
                    } else {
                        $fields = preg_split("/\s{1,10}/", $line, 17);
                        if (count($fields)) {
                            foreach ($headers as $i => $head) {
                                $pinfo0[$head] = $fields[$i];
                            }
                        }

                        $pinfo = [];
                        foreach ($ffields as $name1 => $name2) {
                            if (isset($name1) && isset($pinfo0[$name2])) {
                                $pinfo[$name1] = $pinfo0[$name2];
                            }
                        }

                        if (isset($pinfo['PID'])) {
                            $pid = $pinfo['PID'];
                        }
                        if (isset($pid)) {
                            if (! in_array($pid, $pids1)) {
                                array_push($pids1, $pid);
                            }
                            if (! isset($processes[$pid])) {
                                $processes[$pid] = [];
                            }
                            $processes[$pid] = array_merge($processes[$pid], $pinfo);
                        }
                    }
                }
            }
        } elseif (is_file("{$this->path}/sos_commands/process/ps_alxwww")) {
            $file = 'sos_commands/process/ps_alxwww';
            $ffields = [
                'USER' => 'UID',
                'PID' => 'PID',
                'prio' => 'PRI',
                'CMD' => 'COMMAND',
                'Command' => 'COMMAND',
                'STAT' => 'STAT',
                'PPID' => 'PPID',
                'PRI' => 'PRI',
                'NI' => 'NI',
                'WCHAN' => 'WCHAN',
                'STIME' => 'TIME',
                'TTY' => 'TTY',
                // "threads" => "NLWP",
            ];
            $headers = [];
            $contents = $this->readFileContents($file);
            if (isset($contents)) {
                foreach ($contents as $line) {
                    if (! $line) {
                        continue;
                    }

                    $pinfo0 = [];
                    if (preg_match('/^F ..*/', $line)) {
                        $headers = preg_split("/\s{1,10}/", $line, 13);
                    } else {
                        $fields = preg_split("/\s{1,10}/", $line, 13);
                        if (count($fields)) {
                            foreach ($headers as $i => $head) {
                                if (isset($head) && isset($fields[$i])) {
                                    $pinfo0[$head] = $fields[$i];
                                }
                            }
                        }

                        $pinfo = [];
                        foreach ($ffields as $name1 => $name2) {
                            if (isset($name1) && isset($pinfo0[$name2])) {
                                $pinfo[$name1] = $pinfo0[$name2];
                            }
                        }

                        if (isset($pinfo['PID'])) {
                            $pid = $pinfo['PID'];
                        }
                        if (isset($pid)) {
                            if (! in_array($pid, $pids1)) {
                                array_push($pids1, $pid);
                            }
                            if (! isset($processes[$pid])) {
                                $processes[$pid] = [];
                            }
                            $processes[$pid] = array_merge($processes[$pid], $pinfo);
                        }
                    }
                }
            }
        }

        $file = 'sos_commands/process/ps_auxwwwm';
        $ffields = [
            'TIME' => 'TIME',
            '%CPU' => '%CPU',
            '%MEM' => '%MEM',
            'VSZ' => 'VSZ',
            'RSS' => 'RSS',
        ];
        $headers = [];
        $contents = $this->readFileContents($file);
        if (isset($contents)) {
            foreach ($contents as $line) {
                if (! $line) {
                    continue;
                }

                $pinfo0 = [];
                if (preg_match('/^USER ..*/', $line)) {
                    $headers = preg_split("/\s{1,10}/", $line);
                } elseif (preg_match("/^\w{3,10}\s+-\s..*/", $line)) {
                    continue;
                } else {
                    $fields = preg_split("/\s{1,10}/", $line, 11);
                    if (count($fields)) {
                        foreach ($headers as $i => $head) {
                            if (isset($head) && isset($fields[$i])) {
                                $pinfo0[$head] = $fields[$i];
                            }
                        }
                    }

                    $pinfo = [];
                    foreach ($ffields as $name1 => $name2) {
                        if (isset($name1) && isset($pinfo0[$name2])) {
                            $pinfo[$name1] = $pinfo0[$name2];
                        }
                    }

                    if (isset($pinfo0['PID'])) {
                        $pid = $pinfo0['PID'];
                    }
                    if (isset($pid)) {
                        if (! in_array($pid, $pids2)) {
                            array_push($pids2, $pid);
                        }
                        if (isset($processes[$pid])) {
                            // precesses include only in ps_auxwwwm will not be included
                            $processes[$pid] = array_merge($processes[$pid], $pinfo);
                        }
                    }
                }
            }

            // get rid of precesses not in ps_auxwwwm
            foreach (array_diff($pids1, $pids2) as $pid) {
                unset($processes[$pid]);
            }
        }

        $file = 'sos_commands/process/pidstat_-p_ALL_-rudvwsRU_--human_-h';
        $ffields = [
            'StkRef' => 'StkRef',
            'CPU' => 'CPU',
            '%usr' => '%usr',
            '%system' => '%system',
            '%guest' => '%guest',
            '%wait' => '%wait',
            'kB_rd/s' => 'kB_rd/s',
            'kB_wr/s' => 'kB_wr/s',
            'kB_ccwr/s' => 'kB_ccwr/s',
            'iodelay' => 'iodelay',
            'policy' => 'policy',
            'minflt/s' => 'minflt/s',
            'majflt/s' => 'majflt/s',
            'cswch/s' => 'cswch/s',
            'nvcswch/s' => 'nvcswch/s',
        ];
        $headers = [];
        $contents = $this->readFileContents($file);
        if (isset($contents)) {
            foreach ($contents as $line) {
                if (! $line) {
                    continue;
                }

                $pinfo0 = [];
                if (preg_match('/^# Time..*/', $line)) {
                    $headers = preg_split("/\s{1,10}/", preg_replace('/^# Time/', 'Time', $line));
                } elseif (preg_match('/^Linux..*/', $line)) {
                    continue;
                } else {
                    $fields = preg_split("/\s{1,10}/", $line, 27);
                    if (count($fields)) {
                        foreach ($headers as $i => $head) {
                            if (isset($head) && isset($fields[$i])) {
                                $pinfo0[$head] = $fields[$i];
                            }
                        }
                    }

                    $pinfo = [];
                    foreach ($ffields as $name1 => $name2) {
                        if (isset($name1) && isset($pinfo0[$name2])) {
                            $pinfo[$name1] = $pinfo0[$name2];
                        }
                    }

                    $pid = $pinfo0['PID'];
                    if ($pid && isset($processes[$pid])) {
                        // precesses include only in pidstat will not be included
                        $processes[$pid] = array_merge($processes[$pid], $pinfo);
                    }
                }
            }
        }

        // find missing fields and add them empty
        $maxfields = 0;
        $allfields = [];

        foreach ($processes as $pid => $pinfo) {
            if (! $pid) {
                continue;
            }

            $file = "proc/$pid/status";

            if (is_file("{$this->path}/{$file}")) {
                $ffields = [
                    'VSZ' => 'VmSize',
                    'RSS' => 'RssAnon',
                    'StkSize' => 'VmStk',
                    'threads' => 'Threads',
                    'fd-nr' => 'FDSize',
                    'SHR' => 'RssShmem',
                    'Umask' => 'Umask',
                ];
                $contents = $this->readFileContents($file);
                if (isset($contents)) {
                    $pinfo = [];
                    foreach ($ffields as $field1 => $field2) {
                        $line = preg_grep("/^{$field2}:\s+/", $contents);
                        $data = preg_split("/:\s+/", array_pop($line));
                        $pinfo[$field1] = 0;
                        if (count($data) > 1) {
                            if (isset($field1) && isset($data[1])) {
                                $pinfo[$field1] = $data[1];
                            }
                        }
                    }

                    if (isset($processes[$pid])) {
                        $processes[$pid] = array_merge($processes[$pid], $pinfo);
                    }
                }
            }

            $file = "proc/$pid/limits";

            if (is_file("{$this->path}/{$file}")) {
                $ffields = [
                    'Max processes' => 'Max processes',
                    'Max open files' => 'Max open files',
                    'Max cpu time' => 'Max cpu time',
                    'Max file locks' => 'Max file locks',
                    'Max pending signals' => 'Max pending signals',
                    'Max realtime timeout' => 'Max realtime timeout',
                    'Max nice priority' => 'Max nice priority',
                    'Max realtime priority' => 'Max realtime priority',
                    'Max locked memory' => 'Max locked memory',
                    'Max msgqueue size' => 'Max msgqueue size',
                    'Max file size' => 'Max file size',
                    'Max data size' => 'Max data size',
                    'Max stack size' => 'Max stack size',
                    'Max core file size' => 'Max core file size',
                    'Max resident set' => 'Max resident set',
                    'Max address space' => 'Max address space',
                ];
                $contents = $this->readFileContents($file);
                if (isset($contents)) {
                    $pinfo = [];
                    foreach ($ffields as $field1 => $field2) {
                        $line = preg_grep("/^{$field2}\s+/", $contents);
                        $data = preg_split("/\s\s+/", array_pop($line), 4);
                        if (count($data) > 3) {
                            switch ($data[0]) {
                                case 'Max processes':
                                case 'Max open files':
                                case 'Max cpu time':
                                case 'Max file locks':
                                case 'Max pending signals':
                                case 'Max realtime timeout':
                                case 'Max nice priority':
                                case 'Max realtime priority':
                                    if ($data[1] != 'unlimited') {
                                        $data[1] = Number::format(floatval($data[1]), precision: 0);
                                    }
                                    if ($data[2] != 'unlimited') {
                                        $data[2] = Number::format(floatval($data[2]), precision: 0);
                                    }
                                    break;
                                default:
                                    if ($data[1] != 'unlimited') {
                                        $data[1] = Number::fileSize(floatval($data[1]), precision: 0);
                                    }
                                    if ($data[2] != 'unlimited') {
                                        $data[2] = Number::fileSize(floatval($data[2]), precision: 0);
                                    }
                                    break;
                            }
                            $name = trim($data[3]);
                            $fname = ! $name ? $field1 : "{$field1} ({$name})";
                            $pinfo[$fname] = "{$data[1]} | {$data[2]}";
                        }
                    }
                    if (isset($processes[$pid])) {
                        $processes[$pid] = array_merge($processes[$pid], $pinfo);
                    }
                }
            }
        }

        if (isset($pid)) {
            $pinfo = $processes[$pid];
            $cur = count(array_keys($pinfo));
            if ($cur > $maxfields) {
                $maxfields = $cur;
                $allfields = array_keys($pinfo);
            }
        }

        $counters = (object) [
            'tasks' => 0,
            'idle' => 0,
            'running' => 0,
            'sleeping' => 0,
            'stopped' => 0,
            'zombie' => 0,
        ];

        // correct values formats and remove units (make sort works)
        foreach ($processes as $pid => $pinfo) {
            if (! $pid || ! $pinfo) {
                continue;
            }
            foreach ($pinfo as $key => $value) {
                $newvalue = null;
                switch ($key) {
                    case 'Command':
                        $newvalue = preg_replace('/ ..*$/', '', $value);
                        break;
                    case 'SHR':
                    case 'VSZ':
                    case 'RSS':
                    case 'kB_rd/s':
                    case 'kB_wr/s':
                    case 'StkSize':
                    case 'StkRef':
                        // remove G M K B and multiply accordingly
                        $newvalue = $this->convertToBytes($value);
                        if (! $newvalue) {
                            $newvalue = $value;
                        }
                        break;

                    case '%CPU':
                    case '%usr':
                    case '%system':
                    case '%wait':
                    case '%guest':
                    case '%MEM':
                        // remove % sign
                        $newvalue = str_replace('%', '', $value);
                        if (! $newvalue) {
                            $newvalue = $value;
                        }
                        break;

                    case 'STAT':
                        // traduce flag to state
                        $counters->tasks++;
                        switch ($value) {
                            case 'S':
                            case 'Ss':
                            case 'SN':
                            case 'S<':
                            case 'S<s':
                            case 'Ssl':
                            case 'Sl':
                                $newvalue = 'sleeping';
                                $counters->sleeping++;
                                break;
                            case 'D':
                            case 'Ds':
                                $newvalue = 'uninterruptible';
                                $counters->sleeping++;
                                break;
                            case 'R':
                                $newvalue = 'running';
                                $counters->running++;
                                break;
                            case 'I':
                                $newvalue = 'idle';
                                $counters->idle++;
                                break;
                            case 'T':
                                $newvalue = 'stopped';
                                $counters->stopped++;
                                break;
                            case 'Z':
                                $newvalue = 'zombie';
                                $counters->zombie++;
                                break;
                        }
                        break;

                    default:
                        $newvalue = $value;
                        break;
                }
                $pinfo[$key] = $newvalue;
            }

            // add missing fields for this record
            $curk = array_keys($pinfo);
            if (count($curk) < $maxfields) {
                $missing = array_diff($allfields, $curk);
                foreach ($missing as $field) {
                    switch ($field) {
                        case 'USER':
                        case 'policy':
                        case 'Command':
                        case 'STAT':
                        case 'WCHAN':
                        case 'TTY':
                            $pinfo[$field] = '';
                            break;
                        case 'TIME':
                        case 'STIME':
                        case 'Time':
                            $pinfo[$field] = '00:00';
                            break;
                        case 'PID':
                        case '%usr':
                        case '%system':
                        case '%guest':
                        case '%wait':
                        case '%CPU':
                        case 'CPU':
                        case 'minflt/s':
                        case 'majflt/s':
                        case 'VSZ':
                        case 'RSS':
                        case '%MEM':
                        case 'StkSize':
                        case 'StkRef':
                        case 'kB_rd/s':
                        case 'kB_wr/s':
                        case 'kB_ccwr/s':
                        case 'iodelay':
                        case 'cswch/s':
                        case 'nvcswch/s':
                        case 'threads':
                        case 'fd-nr':
                        case 'prio':
                        case 'PPID':
                        case 'PRI':
                        case 'NI':
                        case 'SHR':
                        case 'Umask':
                            $pinfo[$field] = 0;
                            break;
                        case 'Max processes':
                        case 'Max open files':
                        case 'Max cpu time':
                        case 'Max file locks':
                        case 'Max pending signals':
                        case 'Max realtime timeout':
                        case 'Max nice priority':
                        case 'Max realtime priority':
                        case 'Max locked memory':
                        case 'Max msgqueue size':
                        case 'Max file size':
                        case 'Max data size':
                        case 'Max stack size':
                        case 'Max core file size':
                        case 'Max resident set':
                        case 'Max address space':
                            $pinfo[$field] = '0 | 0';
                            break;
                    }
                }
            }

            // convert pinfo to an object
            $processes[$pid] = (object) $pinfo;
        }

        // tasks

        if (! (! isset($processes) || empty($processes) || ! $processes)) {
            $counters->sleeping += abs(count($processes) - $counters->tasks);
            $counters->tasks = count($processes) > $counters->tasks ? count($processes) : $counters->tasks;
            $processes['tasks'] = $counters;
        }

        // log::info(var_export($processes,true));

        file_put_contents($jsonContents, json_encode($processes)."\n");

        return $processes;

        /*
            (object) array(
                'USER' => 'obfuscateduser1',
                'PID' => '12998',
                'prio' => '80',
                'CMD' => '/opt/google/chrome/chrome --type=renderer --crashpad-handler-pid=12893 --enable-crash-reporter=, --extension-process --disable-nacl --origin-trial-disabled-features=WebGPU --change-stack-guard-on-fork=enable --lang=en-GB --num-raster-threads=4 --enable-main-frame-before-activation --renderer-client-id=7 --time-ticks-at-unix-epoch=-1705527637171925 --launch-time-ticks=759905543 --shared-files=v8_context_snapshot_data:100 --field-trial-handle=0,i,5726352822791836259,3657316957876426414,262144 --variations-seed-version=20240114-180112.851000',
                'Command' => '/opt/google/chrome/chrome',
                'STAT' => 'sleeping',
                'PPID' => '12907',
                'PRI' => '80',
                'NI' => '0',
                'WCHAN' => 'futex_',
                'STIME' => '08:33',
                'TTY' => '?',
                'TIME' => '0:16',
                '%CPU' => '0.2',
                '%MEM' => '1.4',
                'StkRef' => 98304,
                'CPU' => '4',
                '%usr' => '0.2',
                '%system' => '0.0',
                '%guest' => '0.0',
                '%wait' => '0.0',
                'kB_rd/s' => 597,
                'kB_wr/s' => 492,
                'kB_ccwr/s' => '0.0B',
                'iodelay' => '0',
                'policy' => 'NORMAL',
                'minflt/s' => '41.51',
                'majflt/s' => '0.01',
                'cswch/s' => '1.70',
                'nvcswch/s' => '0.37',
                'VSZ' => 1217236094976,
                'RSS' => 114200576,
                'StkSize' => 163840,
                'threads' => '18',
                'fd-nr' => '128',
                'SHR' => 1310720,
                'Umask' => '0002',
                'Max processes (processes)' => '62,168 | 62,168',
                'Max open files (files)' => '1,024 | 1,048,576',
                'Max cpu time (seconds)' => 'unlimited | unlimited',
                'Max file locks (locks)' => 'unlimited | unlimited',
                'Max pending signals (signals)' => '62,168 | 62,168',
                'Max realtime timeout (us)' => 'unlimited | unlimited',
                'Max nice priority' => '0 | 0',
                'Max realtime priority' => '0 | 0',
                'Max locked memory (bytes)' => '64 MB | 64 MB',
                'Max msgqueue size (bytes)' => '800 KB | 800 KB',
                'Max file size (bytes)' => 'unlimited | unlimited',
                'Max data size (bytes)' => '8 GB | 8 GB',
                'Max stack size (bytes)' => '8 MB | unlimited',
                'Max core file size (bytes)' => '0 B | unlimited',
                'Max resident set (bytes)' => 'unlimited | unlimited',
                'Max address space (bytes)' => 'unlimited | unlimited',
            ),
        */
    }

    public function getNICData()
    {
        $jsonContents = "{$this->path}/.nicData.json";
        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $conf = json_decode(file_get_contents($jsonContents), 1);
            if (json_last_error() == JSON_ERROR_NONE) {
                return $conf;
            }
        }

        $conf = [];

        $dirpath = "{$this->path}/sos_commands/networkmanager";
        if (is_dir($dirpath)) {
            // for newer os that have networkmanager get the network interfaces info
            $fields = [
                'GENERAL.DEVICE',
                'GENERAL.TYPE',
                'GENERAL.HWADDR',
                'GENERAL.MTU',
                'GENERAL.STATE',
                'GENERAL.CONNECTION',
                'IP4.ADDRESS',
                'IP4.GATEWAY',
                'IP4.ROUTE',
                'IP4.DNS',
                'IP4.DOMAIN',
                'IP6.ADDRESS',
                'IP6.GATEWAY',
                'IP6.ROUTE',
            ];

            $contents = $this->readFileContents('/sos_commands/networking/ip_-d_address');
            if (isset($contents)) {
                foreach ($contents as $line) {
                    if (! $line) {
                        continue;
                    }
                    preg_match("/^\d{1,3}:\s+([\w-]{2,20}):\s+<..*$/", $line, $matches);
                    if (count($matches) != 2) {
                        continue;
                    }
                    $interface = $matches[1];

                    $data = $this->readFileContents("sos_commands/networkmanager/nmcli_dev_show_{$interface}");
                    if (! $data) {
                        continue;
                    }

                    $entry = [];
                    foreach ($fields as $field) {
                        $regex = "/^{$field}(\[\d+\])*:\s+/";
                        $fieldInfo = preg_grep($regex, $data);
                        $entry[$field] = implode(', ', preg_replace($regex, '', $fieldInfo));
                    }

                    if (count($entry)) {
                        $conf[$interface] = $entry;
                    }
                }
            }
        } else {
            // for older linux that do not have networkmanager

            $pfiles = [
                'sos_commands/networking/ip_-d_address',
                'sos_commands/networking/ip_address',
            ];

            $contents = '';
            foreach ($pfiles as $file) {
                if (! is_file("{$this->path}/{$file}")) {
                    continue;
                }
                $contents = $this->readFileContents($file);
                break;
            }

            if ($contents) {
                $entry = [];
                $entry['IP6.ADDRESS'] = '--';
                foreach ($contents as $line) {
                    if (! $line) {
                        continue;
                    }
                    if (preg_match("/^\d{1,3}:\s+([\w-]{2,20}):\s+<..*> mtu (\d+) qdisc..* state (UP|DOWN|UNKNOWN)..*$/", $line, $matches)) {
                        $interface = $matches[1];
                        $entry['GENERAL.DEVICE'] = $matches[1];
                        $entry['GENERAL.MTU'] = $matches[2];
                        $entry['GENERAL.STATE'] = $matches[3];
                        $entry['GENERAL.CONNECTION'] = '--';
                    }

                    if (preg_match("/^\s+inet (\d{1,3}(\.\d{1,3}){3}\/\d{1,2}) ..*$/", $line, $matches)) {
                        $entry['IP4.ADDRESS'] = $matches[1];
                    }

                    if (preg_match("/^\s+inet6 (..*) scope link..*$/", $line, $matches)) {
                        $entry['IP6.ADDRESS'] = $matches[1];
                    }

                    if (preg_match("/^\s+link\/(\w+) (([\da-f]{2}:?){6}) brd..*$/", $line, $matches)) {
                        $entry['GENERAL.TYPE'] = $matches[1];
                        $entry['GENERAL.HWADDR'] = $matches[2];
                    }

                    if (count($entry)) {
                        $conf[$interface] = $entry;
                    }
                }
            }

            $contents = $this->readFileContents('/sos_commands/networking/ip_route_show_table_all');
            if (isset($contents)) {
                foreach ($conf as $interface => $entry) {
                    $data = preg_grep("/{$interface}/", $contents);
                    $routeline4 = '';
                    foreach ($data as $line) {
                        if (preg_match("/^default via (\d{1,3}(\.\d{1,3}){3}) dev {$interface} ..*$/", $line, $matches)) {
                            $conf[$interface]['IP4.GATEWAY'] = $matches[1];
                            $routeline4 = "dst = 0.0.0.0/0, nh = {$matches[1]}, my = --, ";

                        }
                        if (preg_match("/^default via (\d{1,3}(\.\d{1,3}){3}) dev {$interface} ..* metric (\d+)$/", $line, $matches)) {
                            $conf[$interface]['IP4.GATEWAY'] = $matches[1];
                            $routeline4 = "dst = 0.0.0.0/0, nh = {$matches[1]}, my = {$matches[2]}, ";

                        }

                        if (preg_match("/^(\d{1,3}(\.\d{1,3}){3}) dev {$interface} ..* metric (\d+)$/", $line, $matches)) {
                            $routeline4 = "dst = {$matches[1]}, nh = 0.0.0.0, my = {$matches[2]}, ";
                        }
                    }
                    $conf[$interface]['IP4.ROUTE'] = $routeline4;
                    $conf[$interface]['IP6.GATEWAY'] = '--';
                    $conf[$interface]['IP4.ROUTE'] = '--';
                }
            }

            $contents = $this->readFileContents('/sos_commands/networking/networkctl_status_-a');
            if (isset($contents) && isset($interface)) {
                $conf[$interface]['IP4.DNS'] = '--';
                $conf[$interface]['IP4.DOMAIN'] = '--';
                foreach ($conf as $interface => $entry) {
                    $data = preg_grep('/DNS:/', $contents);
                    foreach ($data as $line) {
                        if (preg_match('/DNS: (..*)$/', $line, $matches)) {
                            $conf[$interface]['IP4.DNS'] = $matches[1];
                        }
                    }
                    $data = preg_grep('/Search Domains:/', $contents);
                    foreach ($data as $line) {
                        if (preg_match('/Search Domains: (..*)$/', $line, $matches)) {
                            $conf[$interface]['IP4.DOMAIN'] = $matches[1];
                        }
                    }
                }
            }
        }

        foreach ($conf as $interface => $data) {
            // NIC Speed and mode
            if (isset($conf[$interface])) {
                $conf[$interface]['GENERAL.SPEED'] = '';
                $conf[$interface]['GENERAL.DUPLEX'] = '';
                $conf[$interface]['GENERAL.LINK_DETECTED'] = '';
                $conf[$interface]['GENERAL.PORT'] = '';

                $contents = $this->readFileContents("/sos_commands/networking/ethtool_{$interface}");
                if (isset($contents)) {

                    $data = preg_grep('/Speed: /', $contents);
                    foreach ($data as $line) {
                        if (preg_match('/Speed: +(..*)$/', $line, $matches)) {
                            $conf[$interface]['GENERAL.SPEED'] = $matches[1];
                        }
                    }

                    $data = preg_grep('/Duplex: /', $contents);
                    foreach ($data as $line) {
                        if (preg_match('/Duplex: +(..*)$/', $line, $matches)) {
                            $conf[$interface]['GENERAL.DUPLEX'] = $matches[1];
                        }
                    }

                    $data = preg_grep('/Port: /', $contents);
                    foreach ($data as $line) {
                        if (preg_match('/Port: +(..*)$/', $line, $matches)) {
                            $conf[$interface]['GENERAL.PORT'] = $matches[1];
                        }
                    }

                    $data = preg_grep('/Link detected: /', $contents);
                    foreach ($data as $line) {
                        if (preg_match('/Link detected: +(..*)$/', $line, $matches)) {
                            $conf[$interface]['GENERAL.LINK_DETECTED'] = $matches[1];
                        }
                    }
                }
            }
        }

        // log::info(var_export($conf,true));

        file_put_contents($jsonContents, json_encode($conf)."\n");

        return $conf;
    }

    public function getNetworkData()
    {
        $jsonContents = "{$this->path}/.networkData.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $netstats = (array) json_decode(file_get_contents($jsonContents));
            if (json_last_error() == JSON_ERROR_NONE) {
                return $netstats;
            }
        }

        $netstats = [];
        $headers1 = [];
        $headers2 = [];

        $pfiles = [
            'sos_commands/networking/netstat_-W_-neopa',
            'sos_commands/networking/netstat_-neopa',
        ];

        $contents = '';
        foreach ($pfiles as $file) {
            if (! is_file("{$this->path}/{$file}")) {
                continue;
            }
            $contents = $this->readFileContents($file);
            break;
        }

        if (! $contents) {
            return [];
        }

        if (isset($contents)) {
            // the blade can only manage up to 3K connections if there are more than that, then tcp and udp have priority
            // over unix sockets
            $maxConn = 3100;
            $tcp = count(preg_grep("/^(tcp|udp)\s*/", $contents));
            $unix = count(preg_grep("/^unix\s*/", $contents));
            $skipUNIX = $maxConn - $tcp;

            $n = 0;
            foreach ($contents as $line) {
                if (! $line) {
                    continue;
                }
                if (preg_match('/^Active..*/', $line)) {
                    continue;
                } elseif (preg_match("/^Proto\s+Recv-Q..*/", $line)) {
                    $line = str_replace('Local Address', 'Local_Address', $line);
                    $line = str_replace('Foreign Address', 'Foreign_Address', $line);
                    $line = str_replace('Program name', 'Program_name', $line);
                    $headers1 = explode('|', preg_replace("/\s+/", '|', $line));
                } elseif (preg_match("/^Proto\s+RefCnt..*/", $line)) {
                    $line = str_replace('Program name', 'Program_name', $line);
                    $headers2 = explode('|', preg_replace("/\s+/", '|', $line));
                } elseif (preg_match('/^(tcp|udp)/', $line)) {
                    if ($n > $skipUNIX) {
                        $netstats[] = "#INCOMPLETE:Showing only $skipUNIX out of $tcp";
                        break;
                    }
                    $cinfo = explode('|', preg_replace("/\s+/", '|', $line));
                    if ($cinfo[0] == 'udp') {
                        $tmp1 = array_slice($cinfo, 0, 5);
                        $tmp2 = array_slice($cinfo, 5);
                        array_push($tmp1, 'N/A');
                        $cinfo = array_merge($tmp1, $tmp2);
                    }
                    $connection = (object) [];
                    foreach ($headers1 as $i => $header) {
                        if (isset($cinfo[$i])) {
                            if ($header == 'Timer') {
                                $connection->{$header} = implode(' ', array_slice($cinfo, $i));
                            } elseif ($header == 'PID/Program_name') {
                                if ($cinfo[$i] == '-') {
                                    $connection->Program_name = '';
                                    $connection->PID = '';
                                } else {
                                    $command = explode('/', $cinfo[$i], 2);
                                    $connection->Program_name = array_pop($command);
                                    $connection->PID = array_pop($command);
                                }
                            } else {
                                $connection->{$header} = $cinfo[$i];
                            }
                        }
                    }
                    $netstats[] = $connection;
                } elseif (preg_match('/^unix/', $line)) {
                    if ($n > $skipUNIX) {
                        $netstats[] = "#INCOMPLETE:Showing only $skipUNIX out of $unix";
                        break;
                    }
                    $line = str_replace('[ ', '[', $line);
                    $line = str_replace(' ]', ']', $line);
                    $line = str_replace('DGRAM', 'DGRAM -', $line);
                    $cinfo = explode('|', preg_replace("/\s+/", '|', $line));
                    $connection = (object) [];
                    foreach ($headers2 as $i => $header) {
                        if (isset($cinfo[$i])) {
                            if ($header == 'PID/Program_name') {
                                if ($cinfo[$i] == '-') {
                                    $connection->Program_name = '';
                                    $connection->PID = '';
                                } else {
                                    $command = explode('/', $cinfo[$i], 2);
                                    $connection->Program_name = array_pop($command);
                                    $connection->PID = array_pop($command);
                                }
                            } else {
                                $connection->{$header} = $cinfo[$i];
                            }
                        }
                    }
                    $netstats[] = $connection;
                }
                $n++;
            }
        }

        // log::info(var_export($netstats,true));

        file_put_contents($jsonContents, json_encode($netstats)."\n");

        return $netstats;
    }

    public function getOpenFilesData()
    {

        $jsonContents = "{$this->path}/.openFilesData.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $filestats = (array) json_decode(file_get_contents($jsonContents));
            if (json_last_error() == JSON_ERROR_NONE) {
                return $filestats;
            }
        }

        $filestats = (object) [];
        $filenames = (object) [];

        $headers1 = [];
        $pfiles = [
            'sos_commands/process/lsof_M_-n_-l_-c',
            'sos_commands/process/lsof_-b_M_-n_-l_-c',
            'sos_commands/process/lsof_-b_M_-n_-l',
        ];

        $contents = '';
        foreach ($pfiles as $file) {
            if (! is_file("{$this->path}/{$file}")) {
                continue;
            }
            $contents = $this->readFileContents($file);
            break;
        }

        if (! $contents) {
            return null;
        }

        foreach ($contents as $line) {
            if (! $line) {
                continue;
            } elseif (preg_match('/^COMMAND..*/', $line)) {
                $line = str_replace('SIZE/OFF', 'SIZE', $line);
                $headers1 = explode('|', preg_replace("/\s+/", '|', $line));
            } elseif (preg_match("/^lsof: \w+:* ..*/", $line)) {
                continue;
            } elseif (preg_match('/^   */', $line)) {
                continue;
            } else {
                $line = str_replace(' unknown ', ' unknown - 0 - ', $line);
                $line = str_replace('0t0', '0', $line);
                $finfo = explode('|', preg_replace("/\s+/", '|', $line));

                $pid = $finfo[1];

                $fileLine = (object) [];
                foreach ($headers1 as $i => $header) {
                    if (isset($finfo[$i])) {
                        if ($header == 'NAME') {
                            if (! isset($filenames->{$pid})) {
                                $filenames->{$pid} = [];
                            }
                            $filenames->{$pid}[] = $finfo[$i];
                        } else {
                            $fileLine->{$header} = $finfo[$i];
                        }
                    }
                }

                if (isset($fileLine) && ! isset($filestats->{$pid})) {
                    $fileLine->{'LSOF_FILENAMES'} = $filenames->{$pid};
                    $fileLine->{'FILES'} = count($filenames->{$pid});
                    $filestats->{$pid} = $fileLine;
                } else {
                    // update files and count
                    $filestats->{$pid}->LSOF_FILENAMES = $filenames->{$pid};
                    $filestats->{$pid}->FILES = count($filenames->{$pid});
                }
            }
        }

        // convert object to array
        $temp = [];
        foreach ($filestats as $pid => $entry) {
            // have to limit the number of files here otherwise the follwoing error occurs (295 limit):
            // local.ERROR: Allowed memory size of 268435456 bytes exhausted (tried to allocate 29347208 bytes)

            $entry->LSOF_FILENAMES = implode("\n", array_slice($entry->LSOF_FILENAMES, 0, 200));
            $temp[] = $entry;
        }
        $filestats = $temp;

        // log::info(var_export($filestats,true));

        file_put_contents($jsonContents, json_encode($filestats)."\n");

        return $filestats;
    }

    public function getErrorsData()
    {

        $jsonContents = "{$this->path}/.logErrorsData.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $errors = (array) json_decode(file_get_contents($jsonContents));
            if (json_last_error() == JSON_ERROR_NONE) {
                return $errors;
            }
        }

        $cmd = sprintf('/bin/find %s/sos_strings %s/var/log -type f', $this->path, $this->path);
        exec($cmd, $out, $ret);
        if ($ret) {
            Log::error('find failed');
            Log::error($cmd);
            $this->DEBUG && Log::error(var_export($out, true));

            return null;
        }

        $cmd = sprintf("/bin/zgrep -m100 -inE '(error|critic|oom).*[^=]+' %s", implode(' ', $out));
        exec($cmd, $contents, $ret);
        if ($ret) {
            $this->DEBUG && Log::info($cmd);
            // $this->DEBUG && Log::info(var_export($contents,true));
        }

        $errors = [];
        if (isset($contents)) {
            foreach ($contents as $line) {
                if (! $line) {
                    continue;
                }
                $line = str_replace($this->path, '', $line);
                $parts = explode(':', $line);
                $logfile = array_shift($parts);
                $line = implode(':', $parts);
                if (! isset($errors[$logfile])) {
                    $errors[$logfile] = [];
                }
                $errors[$logfile][] = $line;
            }
        }
        // log::info(var_export($errors,true));

        file_put_contents($jsonContents, json_encode($errors)."\n");

        return $errors;
    }

    public function getIpTablesData()
    {

        $jsonContents = "{$this->path}/.iptablesData.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $iptables = (array) json_decode(file_get_contents($jsonContents));
            if (json_last_error() == JSON_ERROR_NONE) {
                return $iptables;
            }
        }

        // get tcp/udp mem info
        $iptables = (object) [];

        $pfiles = [
            'sos_commands/firewall_tables/iptables_-vnxL',
            'sos_commands/networking/iptables_-vnxL',
        ];

        $contents = '';
        foreach ($pfiles as $file) {
            if (! is_file("{$this->path}/{$file}")) {
                continue;
            }
            $contents = $this->readFileContents($file);
            break;
        }

        if (empty($contents)) {
            return null;
        }

        if ($contents) {
            foreach ($contents as $line) {
                if (! $line) {
                    continue;
                }

                $line = trim(preg_replace("/\s+/", ' ', $line));
                if (preg_match('/^Chain..*/', $line)) {
                    $parts = explode('(', $line);
                    $chain = trim(str_replace('Chain', '', $parts[0]));
                    $policy = str_replace('DROP', 'DROP,', str_replace(')', '', $parts[1]));

                    $iptables->{$chain} = (object) [
                        'title' => "Chain {$chain}",
                        'policy' => $policy,
                        'data' => [],
                    ];

                } elseif (preg_match('/^pkts..*/', $line)) {
                    $headers = explode(' ', $line);
                    $headers[] = 'more';
                } else {
                    $ruleValues = explode(' ', $line, 10);
                    $ruleObj = (object) [];

                    foreach ($headers as $i => $header) {
                        $ruleObj->{$header} = isset($ruleValues[$i]) ? $ruleValues[$i] : '';
                    }

                    if (count((array) $ruleObj)) {
                        $iptables->{$chain}->data[] = $ruleObj;
                    }

                }
            }
        }

        // log::info(var_export($iptables,true));

        file_put_contents($jsonContents, json_encode($iptables)."\n");

        return $iptables;
    }

    public function getInventoryData()
    {

        $jsonContents = "{$this->path}/.inventoryData.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $inventory = (array) json_decode(file_get_contents($jsonContents));
            if (json_last_error() == JSON_ERROR_NONE) {
                return $inventory;
            }
        }

        // get CPU info
        $cpuinfo = $this->readFileContents('sos_commands/processor/lscpu');

        $ethinfo = $this->getNICData();

        $inventory = (object) [];

        // get dmi info
        $contents = $this->readFileContents('sos_commands/hardware/dmidecode');
        if (! $contents) {
            Log::error('dmidecode not found');

            return null;
        }

        $empties = array_keys(preg_grep('/^$/', $contents));

        while ($empties) {
            $ini = intval(array_shift($empties)) + 1;

            if (! isset($empties[0])) {
                break;
            }

            $fin = intval($empties[0]);

            $structure = array_slice($contents, $ini, $fin - $ini, 1);

            if (! count($structure)) {
                continue;
            }

            $handleLine = array_shift($structure);
            if (! preg_match('/^Handle..*/', $handleLine)) {
                continue;
            }
            $handle = explode(', ', $handleLine);

            $entry = (object) [];
            $entry->type = str_replace('DMI type ', '', $handle[1]);

            if ($entry->type == 126) {
                continue;
            }

            if ($entry->type == 127) {
                break;
            }

            $entry->name = array_shift($structure);
            if ($entry->type == 4 && $cpuinfo) {
                foreach (array_reverse($cpuinfo) as $line) {
                    array_unshift($structure, preg_replace("/\s+/", ' ', $line));
                }
            }
            $entry->data = array_map(function ($line) {
                return preg_replace("/^\t/", '', $line);
            }, $structure);
            $entry->path = '';
            $entry->icon = '';
            $entry->color = 'blue';

            switch ($entry->type) {
                case 0:
                case 13:
                    /*
                     0   BIOS
                    13   BIOS Language
                    */
                    $entry->icon = 'phosphor-keyboard-duotone';
                    break;
                case 1:
                case 12:
                case 15:
                case 23:
                case 32:
                    /*
                     1   System
                    12   System Configuration Options
                    15   System Event Log
                    23   System Reset
                    32   System Boot
                    */
                    $entry->icon = 'phosphor-laptop-duotone';
                    break;
                case 2:
                case 10:
                case 41:
                    /*
                     2   Baseboard
                    10   On Board Devices
                    41   Onboard Devices Extended Information
                    */
                    $entry->icon = 'phosphor-circuitry-duotone';
                    break;
                case 3:
                    /*
                     3   Chassis
                    */
                    $entry->icon = 'phosphor-computer-tower-duotone';
                    break;
                case 4:
                    /*
                     4   Processor
                    */
                    $entry->icon = 'phosphor-cpu-duotone';
                    break;
                case 5:
                case 6:
                case 16:
                case 17:
                    /*
                     5   Memory Controller
                     6   Memory Module
                    16   Physical Memory Array
                    17   Memory Device
                    */
                    $entry->icon = 'phosphor-memory-duotone';
                    break;
                case 7:
                    /*
                     7   Cache
                    */
                    $entry->icon = 'phosphor-cpu-duotone';
                    break;
                case 8:
                    /*
                     8   Port Connector
                    */
                    $entry->icon = 'phosphor-mouse-duotone';
                    break;
                case 9:
                    /*
                     9   System Slots
                    */
                    $entry->icon = 'phosphor-mouse-duotone';
                    break;
                case 22:
                    /*
                    22   Portable Battery
                    */
                    $entry->icon = 'phosphor-battery-charging-duotone';
                    break;
                case 24:
                    /*
                    24   Hardware Security
                    */
                    $entry->icon = 'phosphor-key-duotone';
                    break;
                case 27:
                    /*
                    27   Cooling Device
                    */
                    $entry->icon = 'phosphor-fan-duotone';
                    break;
                case 30:
                    /*
                    30   Out-of-band Remote Access
                    */
                    $entry->icon = 'phosphor-shield-checkered-duotone';
                    break;
                case 33:
                    /*
                    33   64-bit Memory Error Information
                    */
                    $entry->icon = 'phosphor-memory-duotone';
                    break;
                case 39:
                    /*
                    39   Power Supply
                    */
                    $entry->icon = 'phosphor-plug-duotone';
                    break;
                case 221:
                    $entry->icon = 'phosphor-shield-checkered-duotone';
                    break;

                default:
                    /*
                    11   OEM Strings
                    14   Group Associations
                    18   32-bit Memory Error
                    19   Memory Array Mapped Address
                    20   Memory Device Mapped Address
                    21   Built-in Pointing Device
                    24   Hardware Security
                    25   System Power Controls
                    26   Voltage Probe
                    28   Temperature Probe
                    29   Electrical Current Probe
                    30   Out-of-band Remote Access
                    31   Boot Integrity Services
                    33   64-bit Memory Error
                    34   Management Device
                    35   Management Device Component
                    36   Management Device Threshold Data
                    37   Memory Channel
                    38   IPMI Device
                    40   Additional Information
                    42   Management Controller Host Interface
                    */
                    $entry->icon = 'phosphor-info-duotone';
                    break;
            }

            if (! isset($inventory->{$entry->name})) {
                if ($entry->type == 6) {
                    $inventory->{$entry->name} = $entry;
                    $inventory->{$entry->name}->total = '';
                    $inventory->{$entry->name}->sum = 0;
                    $inventory->{$entry->name}->count = 0;

                    $status = preg_grep('/Enabled Size:/', $entry->data);
                    $enabled = preg_match("/Enabled Size: (\d{1,5} [KkMmGgTtPp]*[Bb]).*/", array_pop($status), $match);
                    if ($enabled) {
                        $inventory->{$entry->name}->sum += $this->convertToBytes($match[1]);
                        $inventory->{$entry->name}->count++;
                    }
                } elseif ($entry->type == 7) {
                    $inventory->{$entry->name} = $entry;
                    $inventory->{$entry->name}->total = '';
                    $inventory->{$entry->name}->sum = 0;
                    $inventory->{$entry->name}->count = 0;

                    $status = preg_grep('/Installed Size:/', $entry->data);
                    $enabled = preg_match("/Installed Size: (\d{1,5} [KkMmGgTtPp]*[Bb]).*/", array_pop($status), $match);
                    if ($enabled && $match[1] != '0 kB') {
                        $inventory->{$entry->name}->sum += $this->convertToBytes($match[1]);
                        $inventory->{$entry->name}->count++;
                    }
                } elseif ($entry->type == 8) {
                    $newdata = [];
                    foreach ($entry->data as $line) {
                        $newdata[] = $line;
                        if (preg_match("/(Port\sType:..*$)/", $line)) {
                            array_push($newdata, '');
                        }
                    }
                    $entry->data = $newdata;
                    $inventory->{$entry->name} = $entry;
                    $inventory->{$entry->name}->total = '';
                    $inventory->{$entry->name}->count = 0;
                    $inventory->{$entry->name}->sum = 0;
                    $inventory->{$entry->name}->count++;
                } elseif ($entry->type == 9) {
                    $designation = explode(': ', $entry->data[0])[1];
                    $newname = "{$entry->name} {$designation}";

                    if (! isset($inventory->{$newname})) {
                        $inventory->{$newname} = $entry;
                        $inventory->{$newname}->total = '';
                        $inventory->{$newname}->sum = 0;
                        $inventory->{$newname}->count = 0;
                    } else {
                        $inventory->{$newname}->count++;
                    }

                    continue;
                } elseif ($entry->type == 17) {
                    $inventory->{$entry->name} = $entry;
                    $inventory->{$entry->name}->total = '';
                    $inventory->{$entry->name}->sum = 0;
                    $inventory->{$entry->name}->count = 0;

                    $status = preg_grep('/Size:/', $entry->data);
                    $enabled = preg_match("/Size: (\d{1,5} [KkMmGgTtPp]*[Bb]).*/", array_pop($status), $match);
                    if ($enabled && $match[1] != '0 kB') {
                        $inventory->{$entry->name}->sum += $this->convertToBytes($match[1]);
                        $inventory->{$entry->name}->count++;
                    }
                } else {
                    $inventory->{$entry->name} = $entry;
                    $inventory->{$entry->name}->total = '';
                    $inventory->{$entry->name}->count = 1;
                    $inventory->{$entry->name}->sum = 0;
                }
            } else {
                if ($entry->type == 6) {
                    $status = preg_grep('/Enabled Size:/', $entry->data);
                    $enabled = preg_match("/Enabled Size: (\d{1,5} [KkMmGgTtPp]*[Bb]).*/", array_pop($status), $match);
                    if ($enabled) {
                        $inventory->{$entry->name}->sum += $this->convertToBytes($match[1]);
                        $inventory->{$entry->name}->count++;
                    }
                } elseif ($entry->type == 7) {
                    $status = preg_grep('/Installed Size:/', $entry->data);
                    $enabled = preg_match("/Installed Size: (\d{1,5} [KkMmGgTtPp]*[Bb]).*/", array_pop($status), $match);
                    if ($enabled && $match[1] != '0 kB') {
                        $inventory->{$entry->name}->sum += $this->convertToBytes($match[1]);
                        $inventory->{$entry->name}->count++;
                    }
                } elseif ($entry->type == 8) {
                    foreach ($entry->data as $line) {
                        array_push($inventory->{$entry->name}->data, $line);
                        if (preg_match("/(Port\sType:..*$)/", $line)) {
                            array_push($inventory->{$entry->name}->data, '');
                        }
                    }
                } elseif ($entry->type == 17) {
                    $status = preg_grep('/Size:/', $entry->data);
                    $enabled = preg_match("/Size: (\d{1,5} [KkMmGgTtPp]*[Bb]).*/", array_pop($status), $match);
                    if ($enabled && $match[1] != '0 kB') {
                        $inventory->{$entry->name}->sum += $this->convertToBytes($match[1]);
                        $inventory->{$entry->name}->count++;
                    }
                } else {
                    $inventory->{$entry->name}->count++;
                }
            }
        }

        // get pci and disks info
        $pfiles = [
            'sos_commands/pci/lspci_-nnvv',
            'sos_commands/pci/lspci_-nvv',
        ];

        $contents = '';
        foreach ($pfiles as $file) {
            if (! is_file("{$this->path}/{$file}")) {
                continue;
            }
            $contents = $this->readFileContents($file);
            break;
        }

        if ($contents) {
            $empties = array_keys(preg_grep('/^$/', $contents));
            array_unshift($empties, 0);

            while ($empties) {
                $ini = intval(array_shift($empties)) + 1;

                if (! isset($empties[0])) {
                    break;
                }

                $fin = intval($empties[0]);

                $structure = array_slice($contents, $ini, $fin - $ini, 1);

                if (! count($structure)) {
                    continue;
                }

                $description = array_shift($structure);

                if (preg_match("/^([^ ]*) (..*) \[(..*)\]: (..*)$/", $description, $matches)) {
                    $path = $matches[1];
                    $name = $matches[2];
                    $type = $matches[3];
                    $descr = $matches[4];

                    $entry = (object) [];
                    $entry->type = $type;
                    $entry->name = "{$name} {$descr}";
                    $entry->data = array_map(function ($line) {
                        return preg_replace("/^\t/", '', $line);
                    }, $structure);
                    $entry->path = $path;
                    $entry->icon = '';
                    $entry->color = 'blue';

                    if ($path) {
                        array_unshift($entry->data, "Bus Info: pci@0000:{$path}");
                        $cpath = "pci-0000:{$path}";
                    }

                    if ($ethinfo) {
                        $ethTypes = ['0200', '0280'];
                        $no = ['Path', 'Model', 'Driver'];
                        if (in_array($entry->type, $ethTypes)) {
                            foreach ($ethinfo as $card) {
                                if (isset($card['Path']) && $card['Path'] == $cpath) {
                                    array_shift($structure);
                                    foreach (array_reverse($card) as $key => $line) {
                                        if (! in_array($key, $no)) {
                                            array_unshift($structure, "$key: $line");
                                        }
                                    }
                                    array_unshift($structure, "Bus Info: pci@0000:{$path}");
                                }
                                $entry->data = array_map(function ($line) {
                                    return preg_replace("/^\t/", '', $line);
                                }, $structure);
                            }
                        }
                    }

                    switch ($entry->type) {
                        case '0200':
                            // "Ethernet controller":
                            $entry->icon = 'phosphor-network-duotone';
                            break;
                        case '1180':
                            // "Signal processing controlle":
                            $entry->icon = 'phosphor-waveform-duotone';
                            break;
                        case '0100':
                            // "SCSI storage controller":
                            $entry->icon = 'phosphor-database-duotone';
                            break;
                        case '0300':
                            // "VGA compatible controller":
                            $entry->icon = 'phosphor-monitor-duotone';
                            break;
                        case '0880':
                            // "System peripheral":
                            $entry->icon = 'phosphor-info-duotone';
                            break;
                        case '0680':
                            // "Bridge":
                            $entry->icon = 'phosphor-info-duotone';
                            break;
                        case '0101':
                            // "IDE Interface":
                            $entry->icon = 'phosphor-info-duotone';
                            break;
                        case '0601':
                            // ISA bridge
                            $entry->icon = 'phosphor-info-duotone';
                            break;
                        case '0604':
                            // PCI bridge
                            $entry->icon = 'phosphor-info-duotone';
                            break;
                        case '0600':
                            // Host bridge
                            $entry->icon = 'phosphor-info-duotone';
                            break;
                        case '0c03':
                            // USB Controller
                            $entry->icon = 'phosphor-usb-duotone';
                            break;
                        case '0104':
                            // RAID Controller
                            $entry->icon = 'phosphor-hard-drives-duotone';
                            break;
                        case '0500':
                            // RAM Memory
                            $entry->icon = 'phosphor-memory-duotone';
                            $entry->type = 88;
                            break;
                        case '0280':
                            // Network Controller
                            $entry->icon = 'phosphor-network-duotone';
                            break;
                        case '0c05':
                            // System Management Bus Controller
                            $entry->icon = 'phosphor-graphics-card-duotone';
                            break;
                        case '0c80':
                            // Serial Bus Controller
                        case '0700':
                            // Serial Controller
                            $entry->icon = 'phosphor-usb-duotone';
                            break;
                        case '0401':
                            // Multimedia Audio Controller
                            $entry->icon = 'phosphor-headset-duotone';
                            break;
                        case '0d40':
                            // Wireless Controller
                            $entry->icon = 'phosphor-wifi-medium-duotone';
                            break;
                        case '0108':
                            // Non-volatile Memory Controller
                            $entry->icon = 'phosphor-memory-duotone';
                            break;
                        default:
                            $entry->icon = 'phosphor-info-duotone';
                            break;
                    }

                    if (! isset($inventory->{$entry->name})) {
                        $inventory->{$entry->name} = $entry;
                        $inventory->{$entry->name}->count = 1;
                        $inventory->{$entry->name}->total = '';
                        $inventory->{$entry->name}->sum = 0;
                    } else {
                        $inventory->{$entry->name}->count++;
                    }
                }
            }
        }

        // get disks info
        $diskTypes = ['disk', 'cdrom', 'namespace'];

        $contents = $this->readFileContents('sos_commands/hardware/lshw');
        if ($contents) {
            foreach ($diskTypes as $dtype) {
                $regex1 = sprintf("/^(\s+)\*-%s\$/", $dtype);
                $disks = preg_grep($regex1, $contents);

                $info = [];
                $indent = 0;
                $number = 0;
                foreach ($disks as $index => $disk) {
                    if (preg_match($regex1, $disk, $match)) {
                        $indent = strlen($match[1]);

                        $regex2 = sprintf("/^\s{%s,}..*/", ++$indent);

                        $temp = array_slice($contents, $index);
                        foreach ($temp as $line) {
                            if (preg_match($regex2, $line)) {
                                $info[] = $line;
                            } else {
                                if (! preg_match($regex1, $line)) {
                                    break;
                                }
                            }
                        }

                        $this->regex3 = sprintf("/^\s{%s}/", $indent);
                        $entry = (object) [];
                        $entry->type = "{$dtype}{$number}";
                        $entry->name = explode(': ', $info[0])[1].$number;
                        $entry->data = array_map(function ($line) {
                            return preg_replace($this->regex3, '', $line);
                        }, $info);
                        switch ($dtype) {
                            case 'disk':
                                $entry->icon = 'phosphor-database-duotone';
                                break;
                            case 'cdrom':
                                $entry->icon = 'phosphor-disc-duotone';
                                break;
                            case 'namespace':
                                $entry->icon = 'phosphor-memory-duotone';
                                break;
                        }
                        $entry->path = explode(': ', $info[4])[1];
                        $entry->color = 'blue';

                        if (! isset($inventory->{$entry->name})) {
                            $inventory->{$entry->name} = $entry;
                            $inventory->{$entry->name}->count = 1;
                            $inventory->{$entry->name}->total = '';
                            $inventory->{$entry->name}->sum = 0;
                        }

                        $number++;
                    }
                }
            }
        }

        // USB devices
        $contents = $this->readFileContents('sos_commands/usb/lsusb');
        if ($contents) {
            $regex1 = "/Bus (\d+) Device (\d+): ID (\w+:\w+) (..*)$/";
            $indent = 0;
            $number = 0;
            $info = [];
            foreach ($contents as $index => $device) {
                if (preg_match($regex1, $device, $match)) {
                    $bus = $match[1];
                    $dev = $match[2];
                    $id = $match[3];
                    $desc = $match[4];
                    $indent = strlen($match[1]);

                    $this->regex3 = sprintf("/^\s{%s}/", $indent);
                    $entry = (object) [];
                    $entry->name = $desc;
                    $entry->path = "usb@{$bus}:{$dev}";
                    $entry->color = 'blue';

                    $entry->data[] = sprintf('%s', $entry->path);
                    $entry->data[] = sprintf('type: %s', $id);

                    if (preg_match('/Camera/i', $desc)) {
                        $entry->icon = 'phosphor-video-camera-duotone';
                        $entry->type = 'camera';
                    } elseif (preg_match('/Mouse/i', $desc)) {
                        $entry->icon = 'phosphor-mouse-duotone';
                        $entry->type = 'mouse';
                    } elseif (preg_match('/Keyboard/i', $desc)) {
                        $entry->icon = 'phosphor-keyboard-duotone';
                        $entry->type = 'keyboard';
                    } elseif (preg_match('/Ethernet/i', $desc)) {
                        $entry->icon = 'phosphor-network-duotone';
                        $entry->type = 'ethernet';
                    } elseif (preg_match('/(Seagate|WD|Western Digital|Lenovo)/i', $desc)) {
                        $entry->icon = 'phosphor-hard-drive-duotone';
                        $entry->type = 'usbdisk';
                    } elseif (preg_match('/Dock/i', $desc)) {
                        $entry->icon = 'phosphor-usb-duotone';
                        $entry->type = 'docking';
                    } else {
                        $entry->icon = 'phosphor-usb-duotone';
                        $entry->type = 'usbdevice';
                    }

                    if (! isset($inventory->{$entry->name})) {
                        $inventory->{$entry->name} = $entry;
                        $inventory->{$entry->name}->count = 1;
                        $inventory->{$entry->name}->total = '';
                        $inventory->{$entry->name}->sum = 0;
                    }
                }
            }
        }

        // Bluetooth devices

        // log::info(var_export($inventory,true));

        file_put_contents($jsonContents, json_encode($inventory)."\n");

        return $inventory;
    }

    public function getSockstatData()
    {
        $jsonContents = "{$this->path}/.sockstat.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $sockstat = (object) json_decode(file_get_contents($jsonContents));
            if (json_last_error() == JSON_ERROR_NONE) {
                return $sockstat;
            }
        }

        // get tcp/udp mem info
        $sockstat = (object) [];

        $contents = $this->readFileContents('/proc/net/sockstat');
        if (! $contents) {

            // find sockstat in all the sos report
            $tree = $this->vtools->getContents($this->path);
            if ($tree) {
                $found = $this->vtools->find_node_by_attr($tree->nodes, 'name', 'sockstat');
            }

            $path = $found && is_object($found) ? $found->path : "/proc/{$this->sos_version->pid}/net/";

            $contents = $this->readFileContents("{$path}sockstat");
            if (! $contents) {
                Log::error('sockstat not found');

                return null;
            }
        }

        if (isset($contents)) {
            foreach ($contents as $line) {
                if ($line) {
                    $parts = explode(':', $line);
                    $proto = $parts[0];
                    $row = explode(' ', trim($parts[1]));
                    $data = (object) [];
                    while (count($row)) {
                        $key = array_shift($row);
                        $val = array_shift($row);
                        if ($key == 'mem' || $key == 'memory') {
                            $val *= 4096;
                        }
                        $data->{$key} = $val;
                    }
                    $sockstat->{$proto} = (object) $data;
                }
            }
        }

        $tcpMem = $sockstat->TCP->mem + $sockstat->UDP->mem + $sockstat->FRAG->memory;

        // add tcp limits
        $contents = $this->readFileContents('proc/sys/net/ipv4/tcp_mem');

        if (! $contents) {
            Log::error('tcp_mem not found');
        } else {
            $values = explode(' ', preg_replace("/\s+/", ' ', $contents[0]));
            $sockstat->TCP->max_mem = intval(array_pop($values)) * 4096;
            $sockstat->TCP->min_mem = intval(array_pop($values)) * 4096;
            $sockstat->TCP->default_mem = intval(array_pop($values)) * 4096;
        }

        // add udp limits
        $contents = $this->readFileContents('proc/sys/net/ipv4/udp_mem');

        if (! $contents) {
            Log::error('udp_mem not found');
        } else {
            $values = explode(' ', preg_replace("/\s+/", ' ', $contents[0]));
            $sockstat->UDP->max_mem = intval(array_pop($values)) * 4096;
            $sockstat->UDP->min_mem = intval(array_pop($values)) * 4096;
            $sockstat->UDP->default_mem = intval(array_pop($values)) * 4096;
        }

        // log::info(var_export($sockstat,true));

        file_put_contents($jsonContents, json_encode($sockstat)."\n");

        return $sockstat;
    }

    public function getPackagesData()
    {
        if (isset($this->os_version['ID'])) {
            switch ($this->os_version['ID']) {
                case 'sles':
                case 'opensuse':
                    // suse linux
                    break;
                case 'rhel':
                case 'RedHatEnterpriseServer':
                case 'almalinux':
                case 'ol':
                case 'centos':
                case 'fedora':
                    return $this->getRHELPackagesData();
                    break;
                case 'debian':
                case 'ubuntu':
                    return $this->getUbuntuPackagesData();
                    break;
            }
        }
    }

    public function getRHELPackagesData()
    {
        $jsonContents = "{$this->path}/.packagesData.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $packages = (array) json_decode(file_get_contents($jsonContents));
            if (json_last_error() == JSON_ERROR_NONE) {
                return $packages;
            }
        }

        $packages = [];

        $pfiles = [
            'sos_commands/rpm/sh_-c_rpm_--nodigest_-qa_--qf_-59_NVRA_INSTALLTIME_date_sort_-V',
            'sos_commands/rpm/sh_-c_rpm_--nodigest_-qa_--qf_NAME_-_VERSION_-_RELEASE_._ARCH_INSTALLTIME_date_awk_-F_printf_-59s_s_n_1_2_sort_-V',
            'sos_commands/rpm/sh_-c_rpm_--nodigest_-qa_--qf_NAME_-_VERSION_-_RELEASE_._ARCH_INSTALLTIME_date_INSTALLTIME_VENDOR_BUILDHOST_SIGPGP_SIGPGP_pgpsig_awk_-F_printf_-59s_s_n_1_2_sort',
        ];

        $contents = '';
        foreach ($pfiles as $file) {
            if (! is_file("{$this->path}/{$file}")) {
                continue;
            }
            $contents = $this->readFileContents($file);
            break;
        }

        if (! $contents) {
            return null;
        }

        if (isset($contents)) {
            foreach ($contents as $line) {
                if (! $line) {
                    continue;
                }
                $parts = preg_split("/\s{2,50}/", $line);

                $name = array_shift($parts);

                if (! $name || strlen($name) > 100) {
                    // some malformed lines associated to Red_Hat_Enterprise_Linux-Release_Notes-6-es-ES-7-2.1.el6.noarch
                    continue;
                }

                $rest = explode("\t", array_shift($parts));

                if (isset($rest) && is_array($rest)) {
                    if (count($rest) == 1) {
                        $date = $rest[0];
                    } else {
                        $date = array_shift($rest);
                    }
                }

                $entry = (object) [
                    'Name' => trim($name),
                    'Date' => trim($date),
                ];

                $packages[] = $entry;
            }
        }

        // log::info(var_export($packages,true));

        file_put_contents($jsonContents, json_encode($packages)."\n");

        return $packages;
    }

    public function getUbuntuPackagesData()
    {

        $jsonContents = "{$this->path}/.packagesData.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $packages = (array) json_decode(file_get_contents($jsonContents));
            if (json_last_error() == JSON_ERROR_NONE) {
                return $packages;
            }
        }

        $packages = [];

        $contents = $this->readFileContents('sos_commands/dpkg/dpkg_-l');
        if (! $contents) {
            Log::error('dpkg not found');

            return null;
        }

        if ($contents) {
            foreach ($contents as $line) {
                if (! $line) {
                    continue;
                }

                if (! preg_match('/^([uirph][nicufhWt]) ..*/', $line)) {
                    continue;
                }

                $parts = preg_split("/\s+/", $line, 5);

                $markedBit = $parts[0][0];
                $currentBit = $parts[0][1];
                $errorBit = '';

                if (strlen($parts[0]) == 3) {
                    $errorBit = $parts[0][2];
                }

                $markedState = '';
                switch ($markedBit) {
                    case 'u':
                        $markedState = 'Unknown';
                        break;
                    case 'i':
                        $markedState = 'Marked for installation';
                        break;
                    case 'r':
                        $markedState = 'Marked for removal';
                        break;
                    case 'p':
                        $markedState = 'Marked for purging';
                        break;
                    case 'h':
                        $markedState = 'On hold';
                        break;
                }

                $currentState = '';
                switch ($currentBit) {
                    case 'n':
                        $currentState = 'Not installed';
                        break;
                    case 'i':
                        $currentState = 'Successfully installed';
                        break;
                    case 'c':
                        $currentState = 'Configuration files present';
                        break;
                    case 'u':
                        $currentState = 'Package is still unpacked';
                        break;
                    case 'f':
                        $currentState = 'Failed to remove configuration files';
                        break;
                    case 'h':
                        $currentState = 'Partially installed';
                        break;
                    case 'W':
                        $currentState = 'Trig await';
                        break;
                    case 'T':
                        $currentState = 'Trig pend';
                        break;
                }

                $errorState = '';
                if ($errorBit == 'R') {
                    $errorState = 'Reinstall required';
                }

                $entry = (object) [
                    'Marked' => $markedState,
                    'Current' => $currentState,
                    'Error' => $errorState,
                    'Status' => $parts[0],
                    'Name' => $parts[1],
                    'Version' => $parts[2],
                    'Architecture' => $parts[3],
                    'Description' => $parts[4],
                ];

                // log::info(var_export($entry, true));

                $packages[] = $entry;
            }
        }

        // log::info(var_export($packages,true));

        file_put_contents($jsonContents, json_encode($packages)."\n");

        return $packages;
    }

    public function getKernelParamsData()
    {

        $jsonContents = "{$this->path}/.kparametersData.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $kparams = (array) json_decode(file_get_contents($jsonContents));
            if (json_last_error() == JSON_ERROR_NONE) {
                return $kparams;
            }
        }

        $kparams = [];

        $pfile = str_replace('_', '', file_get_contents("{$this->chartsPath}/kParamsDescs.json"));
        $dictionary = json_decode($pfile);
        if (json_last_error()) {
            Log::error(json_last_error_msg());
        }

        $contents = $this->readFileContents('sos_commands/kernel/sysctl_-a');
        if (! $contents) {
            Log::error('sysctl not found');

            return null;
        }

        $first = 0;
        if ($contents) {
            foreach ($contents as $line) {
                if (! $line) {
                    continue;
                }

                if (preg_match("/^dev\.cdrom\.info..*/", $line)) {
                    if ($first) {
                        continue;
                    }
                    $first++;
                }

                $param = explode(' = ', $line, 2);

                $dontshow = [
                    'net.core.netdevrss_key',
                    'net.core.netdev_rss_key',
                ];

                if (is_array($param) && ! in_array($param[0], $dontshow)) {
                    $dkey = str_replace('_', '', $param[0]);
                    $entry = (object) [
                        'Name' => isset($param[0]) ? $param[0] : '',
                        'Value' => isset($param[1]) ? $param[1] : '',
                        'Descr' => isset($dictionary->{$dkey}) ? $dictionary->{$dkey} : '',
                    ];

                    $kparams[] = $entry;
                }
            }
        }

        // log::info(var_export($kparams,true));

        file_put_contents($jsonContents, json_encode($kparams)."\n");

        return $kparams;
    }

    public function getTcpIpStatsData()
    {

        $jsonContents = "{$this->path}/.tcpIpStatsData.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $data = (array) json_decode(file_get_contents($jsonContents));
            if (json_last_error() == JSON_ERROR_NONE) {
                return $data;
            }
        }

        $tcpipCounters = [];
        $componentColor = [
            'primary' => 0,
            'warning' => 0,
            'danger' => 0,
        ];

        $pfile = str_replace('_', '', file_get_contents("{$this->chartsPath}/tcpIpCounters.json"));
        $dictionary = json_decode($pfile);
        if (json_last_error()) {
            Log::error(json_last_error_msg());
        }

        $contents = $this->readFileContents('/sos_commands/networking/nstat_-zas');
        if (! $contents) {
            Log::error('nstat not found');

            return null;
        }

        $first = 0;
        if ($contents) {
            // deprecated or obsolete
            $dontshow = [
                'IcmpInSrcQuenchs',
                'IcmpOutSrcQuenchs',
                'IcmpInAddrMasks',
                'IcmpInAddrMaskReps',
                'IcmpOutAddrMasks',
                'IcmpOutAddrMaskReps',
            ];

            // get the series data for the charts based in this specific counters
            $chartCounters = [
                'IpInReceives',
                'IpOutRequests',
                'IcmpInMsgs',
                'IcmpOutMsgs',
                'TcpActiveOpens',
                'TcpPassiveOpens',
                'TcpInSegs',
                'TcpOutSegs',
                'UdpInDatagrams',
                'UdpOutDatagrams',
                'UdpLiteInDatagrams',
                'UdpLiteOutDatagrams',
                'Ip6InReceives',
                'Ip6OutRequests',
                'Icmp6InMsgs',
                'Icmp6OutMsgs',
                'Udp6InDatagrams',
                'Udp6OutDatagrams',
                'UdpLite6InDatagrams',
                'UdpLite6OutDatagrams',
            ];

            $chartData = [];

            foreach ($contents as $line) {
                if (! $line) {
                    continue;
                }

                if (preg_match('/^$/', $line)) {
                    continue;
                }

                if (preg_match('/^#kernel/', $line)) {
                    continue;
                }

                [$param, $value, $trash] = preg_split("/\s\s*/", $line, 3);

                if (isset($param) && ! in_array($param, $dontshow)) {
                    if (isset($dictionary->{$param})) {
                        $color = '';
                        $icon = '';
                        $percent = 0;
                        $reference = '';
                        $threshold = '';
                        $category = '';
                        $hint = '';
                        $type = '';
                        $order = '';
                        $description = '';

                        isset($dictionary->{$param}->reference) && $reference = $dictionary->{$param}->reference;
                        isset($dictionary->{$param}->threshold) && $threshold = $dictionary->{$param}->threshold;
                        isset($dictionary->{$param}->category) && $category = $dictionary->{$param}->category;
                        isset($dictionary->{$param}->hint) && $hint = $dictionary->{$param}->hint;
                        isset($dictionary->{$param}->type) && $type = $dictionary->{$param}->type;
                        isset($dictionary->{$param}->order) && $order = $dictionary->{$param}->order;
                        isset($dictionary->{$param}->description) && $description = $dictionary->{$param}->description;

                        if (in_array($param, $chartCounters)) {
                            $chartData[] = (object) ['x' => $param, 'y' => $value];
                        }

                        // calculate the color
                        if ($reference !== '' && $threshold !== '') {

                            $total = 0;
                            if (str_contains($reference, '|')) {
                                // ad the values of all the references...
                                $references = explode('|', $reference);
                                foreach ($references as $ref) {
                                    if (isset($dictionary->{$ref})) {
                                        // naaa busca en contents
                                        $sline = preg_grep("/^$ref/", $contents);
                                        [$sparam, $svalue, $strash] = preg_split("/\s\s*/", array_pop($sline), 3);
                                        $total += $svalue;
                                    }
                                }
                            } else {
                                if (isset($dictionary->{$reference})) {
                                    // naaa busca en contents
                                    $sline = preg_grep("/^$reference/", $contents);
                                    [$sparam, $svalue, $strash] = preg_split("/\s\s*/", array_pop($sline), 3);
                                    $total = $svalue;
                                }
                            }

                            if ($total > 0) {
                                $percent = floatval($value * 100 / $total);
                                $color = 'primary';
                                if (floatval($threshold) < 0) {
                                    // I use negative values for inverting the comparission
                                    if ($percent < (floatval($threshold) * -1)) {
                                        $color = 'warning';
                                        $icon = 'phosphor-warning-duotone';
                                    }
                                    if ($percent < (floatval($threshold) * -10)) {
                                        $color = 'danger';
                                        $icon = 'phosphor-fire-duotone';
                                    }
                                } else {
                                    if ($percent > floatval($threshold) * 10) {
                                        $color = 'warning';
                                        $icon = 'phosphor-warning-duotone';
                                    }

                                    if ($percent > floatval($threshold) * 20) {
                                        $color = 'danger';
                                        $icon = 'phosphor-fire-duotone';
                                    }
                                }
                            } else {
                                $percent = '';
                            }
                            ($color) && $componentColor[$color]++;
                        }

                        // value format
                        $fvalue = '';
                        if ($type == 'byte') {
                            $fvalue = Number::filesize($value);
                        } else {
                            $fvalue = Number::format($value, precision: 0);
                        }
                        if ($percent > 0) {
                            $percent = Number::format($percent, precision: 2);
                            $percent .= '%';
                        }

                        $entry = (object) [
                            'Order' => $order,
                            'Name' => $param,
                            'Value' => $fvalue,
                            'Descr' => $description,
                            'Hint' => $hint,
                            'Category' => $category,
                            'Reference' => $reference,
                            'Threshold' => $threshold,
                            'Percentage' => $percent,
                            'Color' => $color,
                            'Icon' => $icon,
                        ];

                        $tcpipCounters[] = $entry;
                    }
                }
            }
        }

        // include only these kernel parameters:
        $kernelByteParameters = [
            'net.core.wmemdefault',
            'net.core.wmem_default',
            'net.core.rmemdefault',
            'net.core.rmem_default',
            'net.core.rmemmax',
            'net.core.wmemmax',
            'net.core.rmem_max',
            'net.core.wmem_max',
            'net.ipv4.tcp_mem',
            'net.ipv4.udp_mem',
            'net.ipv4.udp_rmem_min',
            'net.ipv4.udp_wmem_min',
        ];

        $kernelNumericParameters = [
            'net.ipv6.conf.all.disable_ipv6',
            'net.ipv6.default.all.disable_ipv6',
            'net.ipv4.conf.all.forwarding',
            'net.ipv4.conf.default.forwarding',
            'net.ipv6.conf.all.forwarding',
            'net.ipv6.conf.default.forwarding',
            'net.ipv4.ipforward',
            'net.ipv4.ip_forward',
            'net.ipv6.ip_forward',
            'net.ipv4.tcp_ecn',
            'net.ipv4.tcp_ecn_fallback',
            'net.ipv6.tcp_ecn',
            'net.ipv6.tcp_ecn_fallback',
            'net.ipv4.tcpecn',
            'net.ipv4.tcpecn_fallback',
            'net.ipv6.tcpecn',
            'net.ipv6.tcpecn_fallback',
        ];

        $allCards = $this->getNICData();
        $nicCards = [];
        foreach ($allCards as $nicName => $nicData) {
            if ($nicName !== 'lo') {
                $data = [];
                foreach ($nicData as $key => $value) {
                    $newKey = str_replace('.', '_', $key);
                    $data[$newKey] = $nicData[$key];
                }
                $nicCards[] = $data;
            }
        }

        $kernelParams = $this->getKernelParamsData();

        $networkParams = [];
        if (! empty($kernelParams)) {
            foreach ($kernelParams as $entry) {
                if (in_array($entry->Name, $kernelByteParameters)) {
                    $data = $entry;
                    $data->Value = Number::filesize(intval($entry->Value));
                    $networkParams[] = $data;
                } elseif (in_array($entry->Name, $kernelNumericParameters)) {
                    $data = $entry;
                    $data->Value = ($entry->Value) ? 'enabled' : 'disabled';
                    $networkParams[] = $data;
                }
            }
        }

        asort($componentColor, SORT_NUMERIC);

        $colors = array_keys($componentColor);

        $data = [
            'counters' => $tcpipCounters,
            'kernel' => $networkParams,
            'nics' => $nicCards,
            'chart' => $chartData,
            'color' => array_pop($colors),
        ];

        // log::info(var_export($data,true));
        // file_put_contents($jsonContents, json_encode($data) . "\n");

        return $data;
    }

    public function getSosData()
    {
        // generate the .sos.json index file

        $jsonContents = "{$this->path}/.sos.json";
        if (is_file($jsonContents)) {
            return true;
        }

        $source = "{$this->path}/sos_reports/sos.json";
        if (is_file($source)) {
            $cmd = sprintf("/bin/sed -e's/\.\.\///' %s > \"%s\"", $source, $jsonContents);
            exec($cmd, $out, $ret);
            if ($ret) {
                Log::error('sos.json generation failed');
                Log::info($cmd);
                Log::error(var_export($out, true));

                return false;
            }
        }

        return true;
    }

    public function getSystemdData()
    {
        // generate the .sos.json index file

        $jsonContents = "{$this->path}/.systemdData.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $systemdData = json_decode(file_get_contents($jsonContents), true);
            if (json_last_error() == JSON_ERROR_NONE) {
                return $systemdData;
            }
        }

        $systemdData = [];
        $systemdFiles = [
            'sos_commands/systemd/systemctl_list-units',
        ];

        $contents = '';
        foreach ($systemdFiles as $file) {
            if (! is_file("{$this->path}/{$file}")) {
                continue;
            }
            $contents = $this->readFileContents($file);
            break;
        }

        if (empty($contents)) {
            return null;
        }

        // recognised systemd unit types — used to tell genuine unit rows apart
        // from the header, the "LOAD =" legend and the trailing help/summary
        // lines, none of which end in a unit type. Types are defined by systemd
        // upstream (not per-distro); this covers every version's set, including
        // the legacy "snapshot" (removed in v225) and "busname" (removed in
        // v240) types still seen on older reports (e.g. RHEL 7 / systemd 219).
        $unitTypes = [
            'service', 'socket', 'device', 'mount', 'automount',
            'swap', 'target', 'path', 'timer', 'slice', 'scope',
            'snapshot', 'busname',
        ];

        if ($contents) {
            foreach ($contents as $line) {
                // strip the optional leading marker systemctl prints for failed /
                // transitional units — "●" (U+25CF) in a UTF-8 locale, or its
                // ASCII fallback "*" when captured under LANG=C — then normalise
                // whitespace
                $line = trim(preg_replace('/^\s*[\x{25CF}*]\s+/u', '', $line));

                if ($line === '') {
                    continue;
                }

                $parts = preg_split('/\s+/', $line);

                // a unit row needs at least the unit, load, active and sub columns
                if (count($parts) < 4) {
                    continue;
                }

                $unit = array_shift($parts);

                $uparts = explode('.', $unit);
                $type = array_pop($uparts);

                // only genuine unit rows end in a known unit type; this skips the
                // header, the legend block and the trailing help/summary lines
                if (! in_array($type, $unitTypes, true)) {
                    continue;
                }

                $loaded = array_shift($parts);
                $active = array_shift($parts);
                $sub = array_shift($parts);

                // the JOB column is empty for almost every unit — it only carries
                // a value for transitional states, so only consume it then;
                // otherwise everything after SUB is the description
                $job = '';
                if (in_array(strtolower($active), ['activating', 'deactivating', 'reloading'], true) && count($parts) > 1) {
                    $job = array_shift($parts);
                }

                $systemdData[] = [
                    'unit' => $unit,
                    'type' => $type,
                    'loaded' => $loaded,
                    'active' => $active,
                    'sub' => $sub,
                    'job' => $job,
                    'description' => implode(' ', $parts),
                ];
            }
        }
        $data = [
            'systemd' => $systemdData,
        ];

        // log::info(var_export($data, true));
        file_put_contents($jsonContents, json_encode($data)."\n");

        return $data;
    }

    public function getAIStatusReport()
    {
        // generate a sos report in markup language

        $this->DEBUG = 0;
        $user = $this->vtools->user;

        $reportFile = "{$this->path}/.report.txt";

        $cached = $this->cached;
        $cached = 0;
        if ($cached && is_file($reportFile)) {
            $report = file_get_contents($reportFile);

            return $report;
        }

        $today = date('F d Y');

        $reportContents = [];
        $reportContents[] = sprintf("## ***System Infomation***:\n");
        $reportContents[] = sprintf("---\n");
        $reportContents[] = sprintf("- ***Date***: %s\n", $today);
        if (isset($this->uname) && $this->uname) {
            $reportContents[] = sprintf("- ***Hostname***: %s\n", $this->uname['hostname']);
            $reportContents[] = sprintf("- ***Kernel version***: %s %s\n", $this->uname['kernel_release'], $this->uname['kernel_version']);
            $reportContents[] = sprintf("- ***Architecture***: %s\n", $this->uname['architecture']);
        }
        if (isset($this->os_version) && $this->os_version) {
            if (isset($this->os_version['PRETTY_NAME'])) {
                $reportContents[] = sprintf("- ***Operating System***: %s\n", $this->os_version['PRETTY_NAME']);
            } else {
                $reportContents[] = sprintf("- ***Operating System***: %s\n", $this->os_version['NAME']);
            }
        }
        $data = $this->getHostData();
        if (! (! isset($data) || empty($data) || ! $data)) {
            if (! (! isset($data->{'load average'}) || empty($data->{'load average'}) || ! $data->{'load average'})) {
                $load = $data->{'load average'};
                $reportContents[] = sprintf("- ***Uptime***: %s, %s, %s\n", $data->uptime, $data->users, $load);
            }
        }

        $reportContents[] = sprintf('## ***Resource Usage***:');

        $data = $this->getCpuData();
        if (! (! isset($data) || empty($data) || ! $data)) {
            $reportContents[] = sprintf("---\n");
            $reportContents[] = sprintf("- ***CPU***:\n");
            $reportContents[] = sprintf("- Load Average: %s\n", explode(': ', $load)[1]);
            $reportContents[] = sprintf("- CPU Usage: %s\n", 100 - $data->cpu->idle);
        }

        $data = $this->getMemoryData();
        if (! (! isset($data) || empty($data) || ! $data)) {
            $reportContents[] = sprintf("---\n");
            $reportContents[] = sprintf("- ***Memory***:\n");
            $reportContents[] = sprintf("- Total Memory: %s\n", Number::filesize($data->memory->total->value));
            $reportContents[] = sprintf("- Used Memory: %s\n", Number::filesize($data->memory->used->value));
            $reportContents[] = sprintf("- Buffer Memory: %s\n", Number::filesize($data->memory->{'buff/cache'}->value));
            $reportContents[] = sprintf("- Free Memory: %s\n", Number::filesize($data->memory->free->value));
        }

        $data = $this->getDiskData();
        if (! (! isset($data) || empty($data) || ! $data)) {
            $reportContents[] = sprintf("---\n");
            $reportContents[] = sprintf("- ***Disk Space***:\n");
            foreach ($data as $disk) {
                $reportContents[] = sprintf("- Disk: ***%s*** (%s)\n", $disk->point, $disk->label);
                $reportContents[] = sprintf("- Total Disk Space: %s\n", Number::filesize(floatval($disk->size)));
                $reportContents[] = sprintf("- Used Disk Space: %s\n", Number::filesize(floatval($disk->used)));
                $reportContents[] = sprintf("- Free Disk Space: %s\n", Number::filesize(floatval($disk->available)));
                $reportContents[] = sprintf("---\n");
            }
        }

        $data = $this->getNICData();
        if (! (! isset($data) || empty($data) || ! $data)) {
            $reportContents[] = sprintf("## ***Network***:\n");
            $reportContents[] = sprintf("---\n");
            foreach ($data as $nic => $data) {
                if ($nic != 'lo') {
                    isset($data['GENERAL.TYPE']) && $reportContents[] = sprintf("- Network Interface: %s (%s)\n", $nic, $data['GENERAL.TYPE']);
                    isset($data['IP4.ADDRESS']) && $reportContents[] = sprintf("- IP Address: %s\n", $data['IP4.ADDRESS']);
                    isset($data['IP4.GATEWAY']) && $reportContents[] = sprintf("- Gateway: %s\n", $data['IP4.GATEWAY']);
                    isset($data['IP4.DNS']) && $reportContents[] = sprintf("- DNS: %s\n", $data['IP4.DNS']);
                    isset($data['IP4.DOMAIN']) && $reportContents[] = sprintf("- Domain: %s\n", $data['IP4.DOMAIN']);
                    $reportContents[] = sprintf("---\n");
                }
            }
        }

        $data = $this->getProcessesData();
        $services = [];
        if (! (! isset($data) || empty($data) || ! $data)) {
            $services[] = sprintf("## ***Service Status***:\n");
            $services[] = sprintf("---\n");
            $processes = ['sshd', 'cron', 'rsyslog', 'auditd', 'vmtoolsd', 'systemd', 'systemd-journald', 'systemd-hostnamed', 'journalctl', 'apache', 'rpcbind', 'nfsd', 'postfix'];
            foreach ($data as $pid => $proc) {
                if ($pid == 'tasks') {
                    $reportContents[] = sprintf("## ***Processes***:\n");
                    $reportContents[] = sprintf("---\n");
                    $reportContents[] = sprintf("- Total Tasks: %s\n", $proc->tasks);
                    $reportContents[] = sprintf("- Idle Tasks: %s\n", $proc->idle);
                    $reportContents[] = sprintf("- Running Tasks: %s\n", $proc->running);
                    $reportContents[] = sprintf("- Sleeping Tasks: %s\n", $proc->sleeping);
                    $reportContents[] = sprintf("- Zombie Tasks: %s\n", $proc->zombie);
                    $reportContents[] = sprintf("---\n");
                } else {
                    $path = explode('/', $proc->CMD);
                    $name = array_pop($path);
                    $cmd = preg_replace('/ ..*$/', '', $name);
                    if (in_array($cmd, $processes)) {
                        $services[] = sprintf("- ***%s***: is running\n", $cmd);
                        $services[] = sprintf("- PID: %s\n", $proc->PID);
                        $services[] = sprintf("- Command: %s\n", $proc->CMD);
                        $services[] = sprintf("- User: %s\n", $proc->USER);
                        $services[] = sprintf("- CPU: %s\n", $proc->{'%CPU'});
                        $services[] = sprintf("- Memory: %s\n", $proc->{'%MEM'});
                        $services[] = sprintf("---\n");
                    }
                }
            }
        }
        $reportContents = array_merge($reportContents, $services);

        $data = $this->getErrorsData();
        if (! (! isset($data) || empty($data) || ! $data)) {
            $reportContents[] = sprintf("## ***Error Messages in Logs***:\n");
            $reportContents[] = sprintf("---\n");
            foreach ($data as $file => $lines) {
                $outputlines = [];
                foreach ($lines as $line) {
                    if (preg_match('/ error[ :]/i', $line)) {
                        $line = preg_replace("/^\d+:/", '', $line);
                        $outputlines[] = sprintf("%s\n", preg_replace('/ (error[:]*) */i', ' ***\\1*** ', $line));
                    }
                }
                if ($outputlines) {
                    $reportContents[] = sprintf("- ***%s***:\n", $file);
                    $reportContents = array_merge($reportContents, $outputlines);
                    $reportContents[] = sprintf("---\n");
                }
            }
        }

        /*
        Security: (Mention any security alerts or issues)
        Performance: (Note any performance bottlenecks or issues)
        Recent Changes: (Mention any recent system updates or configuration changes)
        */

        $report = implode("\n", $reportContents);
        file_put_contents($reportFile, $report);

        return $report;
    }

    /**
     * Build a compact, token-efficient health digest of the analysed system,
     * cached as .aiDigest.json next to the other per-report JSON files. This is
     * the primary context fed to the AI assistant for current-sosreport
     * analysis: high signal, ~1-3 KB, computed once at parse time from the
     * already-cached getter output (no re-parsing of raw sos files).
     */
    public function getAiDigest()
    {
        $jsonContents = "{$this->path}/.aiDigest.json";

        $cached = $this->cached;
        if ($cached && is_file($jsonContents)) {
            $digest = json_decode(file_get_contents($jsonContents), true);
            if (json_last_error() == JSON_ERROR_NONE) {
                return $digest;
            }
        }

        $host = $this->digestArray($this->getHostData());
        $cpu = $this->digestArray($this->getCpuData());
        $mem = $this->digestArray($this->getMemoryData());
        $disks = $this->digestArray($this->getDiskData());
        $procs = $this->digestArray($this->getProcessesData());
        $errors = $this->digestArray($this->getErrorsData());
        $systemd = $this->digestArray($this->getSystemdData());
        $nics = $this->digestArray($this->getNICData());

        $cores = $this->digestCoreCount($cpu, $host);
        $flags = [];

        $load = $this->digestLoad($host, $cores, $flags);
        $memory = $this->digestMemory($mem, $flags);
        $swap = $this->digestSwap($mem, $flags);
        $disksFull = $this->digestDisks($disks, 'pused', $flags, 'full');
        $disksInode = $this->digestDisks($disks, 'ipused', $flags, 'inode');
        $logIssues = $this->digestLogIssues($errors, $flags);
        $failedUnits = $this->digestFailedUnits($systemd, $flags);
        [$topCpu, $topMem] = $this->digestTopProcesses($procs);
        $tasks = $this->digestTasks($procs, $flags);
        $nicsDown = $this->digestNicsDown($nics, $flags);

        $cpuBusy = null;
        if (isset($cpu['cpu']['idle'])) {
            $cpuBusy = round(100 - (float) $cpu['cpu']['idle'], 2);
        }

        $digest = [
            'host' => [
                'hostname' => $host['hostname'] ?? null,
                'os' => $host['os version'] ?? null,
                'kernel' => $host['kernel'] ?? null,
                'sos_version' => $host['sos version'] ?? null,
                'uptime' => $host['uptime'] ?? null,
                'cores' => $cores,
            ],
            'load' => $load,
            'cpu' => ['busy_pct' => $cpuBusy, 'model' => $cpu['model'] ?? null],
            'memory' => $memory,
            'swap' => $swap,
            'disks_full' => $disksFull,
            'disks_inode_full' => $disksInode,
            'log_issues' => $logIssues,
            'failed_units' => $failedUnits,
            'top_cpu' => $topCpu,
            'top_mem' => $topMem,
            'tasks' => $tasks,
            'nics_down' => $nicsDown,
            'flags' => array_values(array_unique($flags)),
        ];

        file_put_contents($jsonContents, json_encode($digest, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)."\n");

        return $digest;
    }

    /** Normalise a getter's mixed object/array/null result to a plain array. */
    private function digestArray($data): array
    {
        if (empty($data)) {
            return [];
        }

        return json_decode(json_encode($data), true) ?: [];
    }

    private function digestCoreCount(array $cpu, array $host): int
    {
        $cores = 0;
        foreach (array_keys($cpu) as $key) {
            if (preg_match('/^cpu\d+$/', (string) $key)) {
                $cores++;
            }
        }

        if ($cores === 0 && isset($host['cores'])) {
            $cores = (int) trim((string) $host['cores']);
        }

        return $cores;
    }

    /** @param array<int, string> $flags */
    private function digestLoad(array $host, int $cores, array &$flags): array
    {
        $raw = $host['load average'] ?? '';
        preg_match_all('/\d+\.\d+/', (string) $raw, $m);
        $vals = array_map('floatval', array_slice($m[0] ?? [], -3));

        if (count($vals) < 3) {
            return ['avg1' => null, 'avg5' => null, 'avg15' => null, 'cores' => $cores, 'per_core_1m' => null, 'status' => 'unknown'];
        }

        $perCore = $cores > 0 ? round($vals[0] / $cores, 2) : null;
        $status = 'ok';
        if ($perCore !== null && $perCore >= 1.5) {
            $status = 'critical';
            $flags[] = "high_load_{$perCore}_per_core";
        } elseif ($perCore !== null && $perCore >= 1.0) {
            $status = 'warning';
            $flags[] = "elevated_load_{$perCore}_per_core";
        }

        return ['avg1' => $vals[0], 'avg5' => $vals[1], 'avg15' => $vals[2], 'cores' => $cores, 'per_core_1m' => $perCore, 'status' => $status];
    }

    /** @param array<int, string> $flags */
    private function digestMemory(array $mem, array &$flags): array
    {
        $m = $mem['memory'] ?? [];
        if (! isset($m['total']['value'])) {
            return ['total_bytes' => null, 'used_pct' => null, 'available_bytes' => null, 'status' => 'unknown'];
        }

        $pused = isset($m['pused']['value']) ? round((float) $m['pused']['value'], 1) : null;
        $status = 'ok';
        if ($pused !== null && $pused >= 90) {
            $status = 'critical';
            $flags[] = "memory_pressure_{$pused}pct";
        } elseif ($pused !== null && $pused >= 80) {
            $status = 'warning';
            $flags[] = "memory_high_{$pused}pct";
        }

        return [
            'total_bytes' => (int) $m['total']['value'],
            'used_pct' => $pused,
            'available_bytes' => isset($m['available']['value']) ? (int) $m['available']['value'] : null,
            'status' => $status,
        ];
    }

    /** @param array<int, string> $flags */
    private function digestSwap(array $mem, array &$flags): array
    {
        $s = $mem['swap'] ?? [];
        $total = isset($s['total']['value']) ? (int) $s['total']['value'] : 0;
        if ($total <= 0) {
            return ['total_bytes' => 0, 'used_pct' => null, 'status' => 'none'];
        }

        $pused = isset($s['pused']['value']) ? round((float) $s['pused']['value'], 1) : null;
        $status = 'ok';
        if ($pused !== null && $pused >= 80) {
            $status = 'critical';
            $flags[] = "swap_critical_{$pused}pct";
        } elseif ($pused !== null && $pused >= 25) {
            $status = 'warning';
            $flags[] = "swap_in_use_{$pused}pct";
        }

        return ['total_bytes' => $total, 'used_pct' => $pused, 'status' => $status];
    }

    /**
     * Filesystems at/over the threshold for either space ('pused') or inodes
     * ('ipused'). @param array<int, string> $flags
     */
    private function digestDisks(array $disks, string $field, array &$flags, string $kind): array
    {
        $out = [];
        foreach ($disks as $disk) {
            $pct = isset($disk[$field]) ? (float) $disk[$field] : 0.0;
            if ($pct >= 85) {
                $mount = $disk['point'] ?? ($disk['label'] ?? '?');
                $out[] = ['mount' => $mount, ($kind === 'inode' ? 'inode_pct' : 'used_pct') => $pct, 'fs' => $disk['fstype'] ?? null];
                $flags[] = "disk_{$kind}_{$mount}_".((int) $pct).'pct';
            }
        }

        return $out;
    }

    /** @param array<int, string> $flags */
    private function digestLogIssues(array $errors, array &$flags): array
    {
        $totals = ['oom' => 0, 'critical' => 0, 'error' => 0];
        $byFile = [];

        foreach ($errors as $logfile => $lines) {
            if (! is_array($lines)) {
                continue;
            }
            $counts = ['oom' => 0, 'critical' => 0, 'error' => 0];
            foreach ($lines as $line) {
                $l = mb_strtolower((string) $line);
                if (str_contains($l, 'oom') || str_contains($l, 'out of memory')) {
                    $counts['oom']++;
                } elseif (str_contains($l, 'critic')) {
                    $counts['critical']++;
                } elseif (str_contains($l, 'error')) {
                    $counts['error']++;
                }
            }
            foreach ($totals as $k => $_) {
                $totals[$k] += $counts[$k];
            }
            if (array_sum($counts) > 0) {
                $byFile[$logfile] = $counts;
            }
        }

        // Keep only the noisiest few files to stay compact.
        uasort($byFile, fn ($a, $b) => array_sum($b) <=> array_sum($a));
        $byFile = array_slice($byFile, 0, 8, true);

        if ($totals['oom'] > 0) {
            $flags[] = "oom_events_{$totals['oom']}";
        }
        if ($totals['critical'] > 0) {
            $flags[] = "critical_log_lines_{$totals['critical']}";
        }

        return ['oom' => $totals['oom'], 'critical' => $totals['critical'], 'error' => $totals['error'], 'by_file' => $byFile];
    }

    /** @param array<int, string> $flags */
    private function digestFailedUnits(array $systemd, array &$flags): array
    {
        $units = [];
        foreach ($systemd['systemd'] ?? [] as $row) {
            if (($row['active'] ?? '') === 'failed') {
                $units[] = $row['unit'] ?? '';
            }
        }
        $units = array_values(array_filter($units));

        if ($units !== []) {
            $flags[] = 'failed_units_'.count($units);
        }

        return array_slice($units, 0, 25);
    }

    /** @return array{0: array<int, array>, 1: array<int, array>} */
    private function digestTopProcesses(array $procs): array
    {
        $rows = [];
        foreach ($procs as $pid => $p) {
            if ($pid === 'tasks' || ! is_array($p)) {
                continue;
            }
            $rows[] = [
                'pid' => $p['PID'] ?? $pid,
                'cmd' => $p['Command'] ?? mb_substr((string) ($p['CMD'] ?? ''), 0, 80),
                'cpu_pct' => isset($p['%CPU']) ? (float) $p['%CPU'] : 0.0,
                'mem_pct' => isset($p['%MEM']) ? (float) $p['%MEM'] : 0.0,
                'rss_bytes' => isset($p['RSS']) ? (int) $p['RSS'] : 0,
            ];
        }

        $byCpu = $rows;
        usort($byCpu, fn ($a, $b) => $b['cpu_pct'] <=> $a['cpu_pct']);
        $topCpu = array_map(fn ($r) => ['pid' => $r['pid'], 'cmd' => $r['cmd'], 'cpu_pct' => $r['cpu_pct']], array_slice($byCpu, 0, 5));

        $byMem = $rows;
        usort($byMem, fn ($a, $b) => $b['rss_bytes'] <=> $a['rss_bytes']);
        $topMem = array_map(fn ($r) => ['pid' => $r['pid'], 'cmd' => $r['cmd'], 'rss_bytes' => $r['rss_bytes'], 'mem_pct' => $r['mem_pct']], array_slice($byMem, 0, 5));

        return [$topCpu, $topMem];
    }

    /** @param array<int, string> $flags */
    private function digestTasks(array $procs, array &$flags): array
    {
        $t = $procs['tasks'] ?? [];
        $zombie = (int) ($t['zombie'] ?? 0);
        if ($zombie > 0) {
            $flags[] = "zombie_processes_{$zombie}";
        }

        return [
            'total' => (int) ($t['tasks'] ?? 0),
            'running' => (int) ($t['running'] ?? 0),
            'sleeping' => (int) ($t['sleeping'] ?? 0),
            'zombie' => $zombie,
            'stopped' => (int) ($t['stopped'] ?? 0),
        ];
    }

    /** @param array<int, string> $flags */
    private function digestNicsDown(array $nics, array &$flags): array
    {
        $down = [];
        foreach ($nics as $iface => $cfg) {
            if ($iface === 'lo' || ! is_array($cfg)) {
                continue;
            }
            $link = strtolower((string) ($cfg['GENERAL.LINK_DETECTED'] ?? ''));
            if ($link === 'no') {
                $down[] = $iface;
                $flags[] = "nic_down_{$iface}";
            }
        }

        return $down;
    }

    public function summaryData($cid)
    {
        // after a successful sos report upload this function is executed.
        $this->uname = $this->unameData();
        $this->kernel_version = $this->kernelVersion();
        $this->os_version = $this->osVersion();
        $this->sos_version = $this->sosVersion();
        $this->getNICData();
        $hostinfo = $this->getHostData();

        if (isset($cid) && ! empty($cid)) {
            $case = SupportCase::where('id', $cid)->first();
            if (isset($case) && ! empty($case)) {
                if (isset($this->os_version) && ! empty($this->os_version)) {
                    // log::info(var_export($this->os_version, true));

                    $case['os_version'] = "{$this->os_version['NAME']} {$this->os_version['VERSION']}";
                    $case['os_icon'] = linuxIcon($this->os_version['ID']);
                }
                isset($this->sos_version) && $case['sos_version'] = $this->sos_version->sos_version;
                // empty stays NULL so the fleet grouping fallback (host) applies
                ! empty($hostinfo->machineid) && $case['machine_id'] = $hostinfo->machineid;
                ! empty($hostinfo->hostname) && $case['hostname'] = $hostinfo->hostname;
                $case->save();
            }
        }

        $this->getCpuData();
        $this->getMemoryData();
        $this->getDiskData();
        $this->getProcessesData();
        $this->getNetworkData();
        $this->getOpenFilesData();
        $this->getErrorsData();
        $this->getIpTablesData();
        $this->getInventoryData();
        $this->getSockstatData();
        $this->getPackagesData();
        $this->getKernelParamsData();
        $this->getSosData();
        $this->fixSosHtml($cid);
        // $this->getAIStatusReport();
        $this->getTcpIpStatsData();
        $this->getSystemdData();
        $this->getAiDigest();
    }
}

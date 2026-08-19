<?php

namespace App\Providers;

use App\Models\SupportCase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

class SosServiceProvider extends ServiceProvider
{
    // sos tools provider

    protected $dtools;

    protected $vid;

    protected $did;

    protected $cid;

    protected $os_version = null;

    public $sort_key = '';

    public $sort_type = 'numeric';

    public $sort_desc = true;

    protected $chartsPath = __DIR__.'/../../json';

    public $dateFields = [
        'TIME',
        'STIME',
        'system time', 'universal time', 'boot time',
    ];

    public $stringFields = [
        'USER', 'STAT', 'Command', 'WCHAN', 'TTY', 'policy', 'fstype', 'dtype', 'point', 'label', 'Type', 'COMMAND',
        'FD', 'TYPE', 'DEVICE', 'NAME', 'name', 'LSOF_FILENAMES', 'Proto', 'Local_Address', 'Foreign_Address', 'CMD',
        'Program_name', 'State', 'I-Node', 'PID', 'PPID', 'Path', 'USER', 'TYPE', 'FD', 'DEVICE', 'Marked', 'Current',
        'Error', 'Status', 'Name', 'Version', 'Architecture', 'Description', 'Value', 'Descr', 'Max processes (processes)',
        'Max open files (files)', 'Max cpu time (seconds)', 'Max file locks (locks)', 'Max pending signals (signals)',
        'Max realtime timeout (us)', 'Max nice priority', 'Max realtime priority', 'Max locked memory (bytes)',
        'Max msgqueue size (bytes)', 'Max file size (bytes)', 'Max data size (bytes)', 'Max stack size (bytes)',
        'Max core file size (bytes)', 'Max resident set (bytes)', 'Max address space (bytes)', 'target', 'prot',
        'chain', 'opt', 'in', 'out', 'source', 'destination', 'more', 'message', 'logfile',
        'title', 'data', 'amount',

        'hostname', 'sos version', 'os version', 'kernel',
        'time zone',
        'uptime', 'runlevel', 'load average',
        'nic', 'type', 'connection', 'mac', 'mtu', 'speed', 'linked', 'port', 'duplex',
        'ip4', 'gateway', 'dns servers', 'dns domain',
        'dhcp server', 'smtp server', 'ntp server', 'ip6',
    ];

    public $bytesFields = ['VSZ', 'RSS', 'SHR', 'size', 'used', 'free', 'total', 'available', 'network', 'shared', 'buff/cache', 'swap', 'Recv-Q', 'Send-Q', 'SIZE', 'bytes'];

    public $sortTablePages = ['disk', 'procs', 'conn', 'files', 'limits', 'packages', 'kernel'];

    public function __construct($vtools, $vid, $did, $cid)
    {
        ini_set('memory_limit', '384M');

        $this->vid = $vid;
        $this->did = $did;
        $this->cid = $cid;
        $this->dtools = new DataTools($vtools, $vid, $did);
        $this->kernel_version = $this->dtools->kernelVersion();
        $this->os_version = $this->dtools->osVersion();
    }

    public function getSummary()
    {
        $icon = 'simpleicon-linux';

        if (isset($this->os_version) && isset($this->os_version['ID'])) {
            $icon = linuxIcon($this->os_version['ID']);
        }

        $data = (object) [
            'host' => $this->getHostInfo('host', __('vault.summary_host_info'), $icon),
            'cpu' => $this->getCpuInfo('cpu', __('vault.summary_cpu_info'), 'phosphor-cpu-duotone'),
            'memory' => $this->getMemoryInfo('memory', __('vault.summary_memory_info'), 'phosphor-memory-duotone'),
            'disk' => $this->getDiskInfo('disk', __('vault.summary_disk_info'), 'phosphor-hard-drives-duotone'),
            'procs' => $this->getProcsInfo('procs', __('vault.summary_procs_info'), 'phosphor-tree-view-duotone'),
            'conn' => $this->getNetInfo('conn', __('vault.summary_network_info'), 'phosphor-network-duotone'),
            'files' => $this->getFilesInfo('files', __('vault.summary_open_files'), 'phosphor-files-duotone'),
            'errors' => $this->getErrors('errors', __('vault.summary_errors'), 'phosphor-fire-duotone'),
            'firewall' => $this->getFWInfo('firewall', __('vault.summary_firewall_info'), 'phosphor-wall-duotone'),
            'inventory' => $this->getInventoryInfo('inventory', __('vault.summary_hardware_info'), 'phosphor-computer-tower-duotone'),
            'limits' => $this->getLimitsInfo('limits', __('vault.summary_limits_info'), 'phosphor-gauge-duotone'),
            'packages' => $this->getPackagesInfo('packages', __('vault.summary_packages_info'), 'phosphor-package-duotone'),
            'kernel' => $this->getKernelInfo('kernel', __('vault.summary_kernel_config'), 'simpleicon-linux'),
            'tcpip' => $this->getTcpIpInfo('tcp/ip', __('vault.summary_tcpip_stats'), 'phosphor-chart-bar-horizontal-duotone'),
            'systemd' => $this->getSystemdInfo('systemd', __('vault.summary_systemd_stats'), 'phosphor-play-pause-duotone'),
            // "rootcause"  => $this->getRootInfo("rootcause", "Root Cause Analisys", "nf nf-md-server_security fa-xl"),
            /*
                PEND: kernel modules enabled in kernel badge
            */

            // "config"  => $this->getConfigInfo("config", "System Config", "nf nf-custom-folder_config fa-xl"),
            /*
                smtp config
                httpd config
                mysql config
                pgsql config
                redis config
                mongo config
            */
            // "perf"  => $this->getPerfInfo("perf", "Performance Info", "nf nf-md-speedometer fa-xl"), //look for saturation
            // "compliance"  => $this->getComplyInfo("compliance", "STIG Info", "nf nf-md-speedometer fa-xl"),
            // "vuln"  => $this->getVulnInfo("vuln", "Vulnerabilty Info", "nf nf-md-server_security fa-xl"),
            // "appmetrics"  => $this->getAppInfo("appmetrics", "Application Metrics", "nf nf-md-server_security fa-xl"),
        ];
        $this->dtools->getSosData();

        // file_put_contents("/tmp/summaryData.json", json_encode($data, JSON_PRETTY_PRINT));

        if (isset($this->cid) && ! empty($this->cid)) {
            $case = SupportCase::where('id', $this->cid)->first();

            if (isset($case) && ! empty($case)) {
                if (! isset($case->os_version) || empty($case->os_version)) {
                    $case->update([
                        'os_version' => $data->host->tableData1->{'os version'},
                        'sos_version' => $data->host->tableData1->{'sos version'},
                        'os_icon' => $data->host->badgeData->icon,
                    ]);
                    $case->save();
                }
            }
        }

        return $data;
    }

    public function getHostInfo($component, $description, $icon)
    {
        $compo_state = 'info';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => '',
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableTitle1' => sprintf('%s info', ucfirst($component)),
            'tableOrder1' => [
                'hostname', 'sos version', 'os version', 'kernel',
                'system time', 'universal time', 'boot time', 'time zone',
                'uptime', 'runlevel', 'load average',
                'nic', 'type', 'connection', 'mac', 'mtu', 'speed', 'linked', 'port', 'duplex',
                'ip4', 'gateway', 'dns servers', 'dns domain',
                'dhcp server', 'smtp server', 'ntp server', 'ip6', 'machineid',
            ],
            'tableHeaders1' => [
                'hostname', 'sos version', 'os version', 'kernel',
                'system time', 'universal time', 'boot time', 'time zone',
                'uptime', 'runlevel', 'load average',
                'nic', 'type', 'connection', 'mac', 'mtu', 'speed', 'linked', 'port', 'duplex',
                'ip4', 'gateway', 'dns servers', 'dns domain',
                'dhcp server', 'smtp server', 'ntp server', 'ip6', 'machineid',
            ],
            'fileBlade1' => 'theme::tools.summary.hostSection',
            'tableData1' => (object) [],
        ];

        $data->tableData1 = $this->dtools->getHostData();

        if (! isset($data->tableData1) || empty($data->tableData1) || ! $data->tableData1) {
            return null;
        }

        $data->tableData1->icon = $icon;
        $cores = 0;
        if (isset($data->tableData1->cores) && ! empty($data->tableData1->cores) && $data->tableData1->cores) {
            $cores = intval($data->tableData1->cores);
        }

        $load = [];
        if (isset($data->tableData1->{'load average'}) && ! empty($data->tableData1->{'load average'}) && $data->tableData1->{'load average'}) {
            $load = explode(', ', preg_replace('/^..*: /', '', $data->tableData1->{'load average'}));
        }

        $series = [];
        foreach ($load as $value) {
            $series[] = Number::format(floatval($value * $cores), precision: 2);
        }

        $data->badgeData->mainTitle = $description;
        if (isset($data->tableData1->hostname) && ! empty($data->tableData1->hostname) && $data->tableData1->hostname) {
            $data->badgeData->subTitle = "{$data->tableData1->hostname} up: {$data->tableData1->uptime}";
        }
        $data->badgeData->subTitle = substr($data->badgeData->subTitle, 0, 20);

        // check to see if 15 min value in the series is be grather or equal
        // to the total number of cores
        if (count($series) > 9) {
            if ((intval($series[2]) / $cores) > ($cores * 0.8)) {
                // 80%
                $compo_state = 'danger';
            } elseif ((intval($series[2]) / $cores) > ($cores * 0.6)) {
                // 60%
                $compo_state = 'warning';
            }
        }
        $data->badgeData->color = $compo_state;
        $data->badgeData->state = ['load average'];

        // get the chart
        $chartTemplate = file_get_contents("{$this->chartsPath}/radialChart.json");
        $chart = json_decode($chartTemplate, 1, 512, JSON_INVALID_UTF8_IGNORE);
        if (json_last_error()) {
            Log::error(json_last_error_msg());
        }

        // configure the chart
        if (isset($chart)) {
            $data->badgeData->chart = $chart;
            $data->badgeData->chart['colors'] = getColorArray($compo_state);
            $data->badgeData->chart['labels'] = ['1 min', '5 mins', '15 mins'];
            $data->badgeData->chart['series'] = $series;
            $data->badgeData->chart['title']['text'] = 'load average';
            $data->badgeData->chart['cores'] = $cores;
        }

        return $data;
    }

    public function getCpuInfo($component, $description, $icon)
    {
        $compo_state = 'info';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => '',
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableTitle1' => sprintf('%s info', ucfirst($component)),
            'tableOrder1' => ['cpu', 'user', 'system', 'nice', 'idle', 'iowait', 'irq', 'softirq'],
            'tableHeaders1' => ['cpu', '% user', '% system', '% nice', '% idle', '% iowait', '% irq', '% softirq'],
            'tableData1' => (object) [],
            'fileBlade1' => 'theme::tools.summary.cpuSection',
        ];

        $data->tableData1 = $this->dtools->getCpuData();
        $data->badgeData->state = ['user'];

        if (! isset($data->tableData1) || empty($data->tableData1) || ! $data->tableData1) {
            return null;
        }

        $data->badgeData->mainTitle = $description;

        // color assignation
        $skip = ['cpu', 'total', 'color'];

        $cores = 0;
        foreach ($data->tableData1 as $cpu => $entry) {
            if ($cpu == 'model') {
                continue;
            }
            if (preg_match("/^cpu\d+/", $cpu)) {
                $cores++;
            }
            if ($data->tableData1->{$cpu}->idle <= 10) {
                $color = 'danger';
                $busy = 'Overloaded';
            } elseif ($data->tableData1->{$cpu}->idle <= 25) {
                $color = 'warning';
                $busy = 'Busy';
            } elseif ($data->tableData1->{$cpu}->idle <= 50) {
                $color = 'primary';
                $busy = 'Comfortable';
            } else {
                $color = '';
                $busy = 'Idle';
            }

            $data->tableData1->{$cpu}->color = $color;
        }
        $data->badgeData->color = $color ? $color : 'primary';
        $data->badgeData->subTitle = "$busy ".$data->tableData1->model;
        $data->badgeData->subTitle = substr($data->badgeData->subTitle, 0, 40);
        $data->badgeData->mark = "{$cores} cores";

        return $data;
    }

    public function getMemoryInfo($component, $description, $icon)
    {
        // initialize the response object
        $compo_state = 'info';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mainTitle' => '',
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableTitle1' => sprintf('%s info', ucfirst($component)),
            'tableOrder1' => ['used', 'pused', 'free', 'pfree', 'buff/cache', 'pbuff', 'total', 'available', 'network', 'shared'],
            'tableOrder2' => ['used', 'pused', 'free', 'pfree', 'total', 'name', 'type'],
            'tableHeaders1' => ['used', '% used', 'free', '% free', 'buff/cache', '% buff/cache', 'total', 'available', 'network', 'shared'],
            'tableHeaders2' => ['used', '% used', 'free', '% free', 'total', 'name', 'type'],
            'tableData1' => (object) [],
            'tableData2' => (object) [],
            'fileBlade1' => 'theme::tools.summary.memorySection',
        ];

        $meminfo = $this->dtools->getMemoryData();

        if (! isset($meminfo) || empty($meminfo) || ! $meminfo) {
            return null;
        }

        $data->tableData1 = $meminfo->memory;

        if (! isset($data->tableData1) || empty($data->tableData1) || ! $data->tableData1) {
            return null;
        }

        $data->tableData2 = $meminfo->swap;
        $data->tableTitle2 = 'Swap info';

        // fill the badge data
        $data->badgeData->mainTitle = $description;
        $data->badgeData->subTitle = 'Tot Mem: '.Number::fileSize($data->tableData1->total->value, precision: 2);
        $data->badgeData->subTitle = substr($data->badgeData->subTitle, 0, 20);
        $data->badgeData->footerTitle = 'Used: '.Number::fileSize($data->tableData1->used->value, precision: 2);

        $labels1 = ['total', 'used', 'free', 'buff/cache', 'available', 'shared'];
        $series = [];
        foreach ($labels1 as $label) {
            $value = $data->tableData1->{$label}->value;
            switch ($label) {
                case 'used':
                    array_push($series, (object) ['name' => $label, 'data' => [$value]]);
                    break;
                case 'buff/cache':
                    array_push($series, (object) ['name' => $label, 'data' => [$value]]);
                    break;
                case 'free':
                    array_push($series, (object) ['name' => $label, 'data' => [$value]]);
                    break;
            }
        }

        // set the basge alert level (color)
        $pfree = $data->tableData1->pfree->value;
        if ($pfree > 20) {
            $compo_state = 'primary';
        } elseif ($pfree <= 20 && $pfree > 10) {
            $compo_state = 'warning';
            $data->tableData1->free->color = 'warning';
        } elseif ($pfree <= 10) {
            $compo_state = 'danger';
            $data->tableData1->free->color = 'danger';
        }
        $data->badgeData->color = $compo_state;
        $data->badgeData->state = ['free'];

        foreach ($data->tableOrder1 as $param) {
            $data->tableData1->{"$param"}->color = $compo_state;
        }

        // get the chart
        $chartTemplate = file_get_contents("{$this->chartsPath}/stackedBar.json");
        $chart = json_decode($chartTemplate, 1, 512, JSON_INVALID_UTF8_IGNORE);
        if (json_last_error()) {
            Log::error(json_last_error_msg());
        }

        if (isset($chart)) {
            $data->badgeData->chart = $chart;
            $data->badgeData->chart['series'] = $series;
            $data->badgeData->chart['title']['text'] = 'memory usage';
            $data->badgeData->chart['xaxis']['categories'] = ['total'];
            $data->badgeData->chart['xaxis']['max'] = $data->tableData1->total->value;
            $data->badgeData->chart['colors'] = getColorArray($compo_state);
            $data->badgeData->chart['legend']['labels']['colors'] = getColorArray('gray');
        }

        return $data;
    }

    public function getDiskInfo($component, $description, $icon)
    {
        $compo_state = 'info';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => '',
                'mainTitle' => $description,
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableTitle1' => sprintf('%s info', ucfirst($component)),
            'tableOrder1' => [
                'point',
                'size',
                'used',
                'pused',
                'isize',
                'iused',
                'ipused',
                'dtype',
                'fstype',
                'pvolumes',
                'moptions',
                'util',
                'tps',
                'aqu-sz',
                'r/s',
                'rkB/s',
                'rrqm/s',
                '%rrqm',
                'r_await',
                'rareq-sz',
                'w/s',
                'wkB/s',
                'wrqm/s',
                '%wrqm',
                'w_await',
                'wareq-sz',
                'd/s',
                'dkB/s',
                'drqm/s',
                '%drqm',
                'd_await',
                'dareq-sz',
                'f/s',
                'f_await',
                'majmin',
            ],
            'tableHeaders1' => [
                'mount point',
                'disk size',
                'disk use',
                '% use',
                'total inodes',
                'inodes use',
                '% inodes use',
                'disk type',
                'fs type',
                'disks',
                'mount opt',
                '% disk use',
                'total I/O',
                'averag queue size',
                'reads/s',
                'read kB/s',
                'read req merged/s',
                '% rrqm',
                'av. read wait',
                'read queue',
                'writes/s',
                'write kB/s',
                'write req merged/s',
                '% wrqm',
                'av. write wait',
                'write queue',
                'discards/s',
                'discarded kB/s',
                'discard req merged/s',
                '% drqm',
                'av. discard wait',
                'discard queue',
                'flushes/s',
                'av. flush wait',
                'maj:min',
            ],
            'tableData1' => [],
            'fileBlade1' => 'theme::tools.summary.sort-table',
        ];

        $data->tableData1 = $this->dtools->getDiskData();

        if (! isset($data->tableData1) || empty($data->tableData1) || ! $data->tableData1) {
            return null;
        }

        // fill the disk type data and get total and color and fill the badge data
        $compo_state = 'primary';
        $total = 0;
        foreach ($data->tableData1 as $index => $entry) {
            if (isset($entry->pused) && ! empty($entry->pused) && $entry->pused) {
                if ($entry->pused >= 90 || $entry->ipused >= 90) {
                    $compo_state = 'danger';
                } elseif ($entry->pused >= 75 || $entry->ipused >= 75) {
                    if ($compo_state != 'danger') {
                        $compo_state = 'warning';
                    }
                } else {
                    if ($compo_state != 'danger' && $compo_state != 'warning') {
                        $compo_state = 'primary';
                    }
                }
            }

            if (isset($entry->size) && ! empty($entry->size) && $entry->size) {
                // total disk capacity
                $total += floatval($entry->size);
            }
        }
        $data->badgeData->color = $compo_state;
        $data->badgeData->state = ['pused'];

        // fill the badge data
        $data->badgeData->subTitle = 'Total Disk: '.Number::fileSize($total);
        $data->badgeData->subTitle = substr($data->badgeData->subTitle, 0, 20);

        // Only show top 8 usage disks in the chart
        $disksToShow = $data->tableData1;
        if (count($data->tableData1) > 8) {
            // show only the big ones
            $disks = $data->tableData1;

            usort($disks, function ($a, $b) {
                if (trim($a->pused, '%') == trim($b->pused, '%')) {
                    return 0;
                }

                return trim($a->pused, '%') < trim($b->pused, '%');
            });

            $disksToShow = [];
            for ($i = 0; $i < 8; $i++) {
                $disksToShow[] = $disks[$i];
            }
        }

        // get the series data for the charts
        $aused = [];
        $aiused = [];
        $categories = [];
        foreach ($disksToShow as $index => $entry) {
            // check pused and ipused and set color
            $pused = trim($entry->pused, '%');
            $ipused = trim($entry->ipused, '%');
            $apused[] = $pused;
            $aipused[] = $ipused;
            $categories[] = $entry->point;
        }
        $series[] = (object) ['name' => 'space', 'data' => $apused];
        $series[] = (object) ['name' => 'inodes', 'data' => $aipused];

        // get the chart
        $chartTemplate = file_get_contents("{$this->chartsPath}/stackedBar.json");
        $chart = json_decode($chartTemplate, 1, 512, JSON_INVALID_UTF8_IGNORE);
        if (json_last_error()) {
            Log::error(json_last_error_msg());
        }

        // configure the chart
        if (isset($chart)) {
            $data->badgeData->chart = $chart;
            $data->badgeData->chart['plotOptions']['bar']['horizontal'] = false;
            $data->badgeData->chart['plotOptions']['bar']['dataLabels']['total']['offsetX'] = 0;
            $data->badgeData->chart['plotOptions']['bar']['dataLabels']['total']['offsetY'] = 0;
            $data->badgeData->chart['plotOptions']['bar']['dataLabels']['total']['style']['color'] = '#9ca3af';
            $data->badgeData->chart['grid']['padding']['top'] = 10;
            $data->badgeData->chart['grid']['padding']['bottom'] = 40;
            $data->badgeData->chart['title']['text'] = 'disk and inode usage';
            $data->badgeData->chart['series'] = $series;
            $data->badgeData->chart['xaxis']['categories'] = $categories;
            $data->badgeData->chart['colors'] = getColorArray($compo_state);
            $data->badgeData->chart['legend']['labels']['colors'] = getColorArray('gray');
        }

        return $data;
    }

    public function getProcsInfo($component, $description, $icon)
    {
        $compo_state = 'info';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => $description,
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableTitle1' => sprintf('%s info', ucfirst($component)),
            'tableOrder1' => [],
            'tableHeaders1' => [],
            'fileBlade1' => 'theme::tools.summary.sort-table',
            'tableData1' => [],
        ];

        $data->tableHeaders1 = [
            'COMMAND',
            'PID',
            'PPID',
            'USER',
            'STATE',
            '%CPU',
            // "%USR",
            // "%SYSTEM",
            // "%WAIT",
            'TOTAL CPU TIME',
            'Start TIME',
            'PRIORITY',
            'NICE',
            'TTY',
            'COMMAND',
            'VIRTUAL MEMORY',
            'RESIDENT MEMORY',
            'SHAREDME MORY',
            '%PHYSICAL MEMORY',
            // "DISK READS",
            // "DISK WRITES",
            // "I/O DELAY",
            'THREADS',
            'OPEN FILES',
            'WCHAN',
            'FULL COMMAND',
        ];
        $data->tableOrder1 = [
            'Command',
            'PID',
            'PPID',
            'USER',
            'STAT',
            '%CPU',
            // "%usr",
            // "%system",
            // "%wait",
            'TIME',
            'STIME',
            'PRI',
            'NI',
            'TTY',
            'Command',
            'VSZ',
            'RSS',
            'SHR',
            '%MEM',
            // "kB_rd/s",
            // "kB_wr/s",
            // "iodelay",
            'threads',
            'fd-nr',
            'WCHAN',
            'CMD',
        ];

        $data->tableData1 = (array) $this->dtools->getProcessesData();

        if (! isset($data->tableData1) || empty($data->tableData1) || ! $data->tableData1) {
            return null;
        }

        if (isset($data->tableData1)) {
            $data->tasks = array_pop($data->tableData1);
            if (isset($data->tasks)) {
                $data->badgeData->subTitle = 'Tasks: '.$data->tasks->tasks;
                $data->badgeData->subTitle = substr($data->badgeData->subTitle, 0, 20);
            }
        }

        $data->badgeData->state = ['%CPU', 'VSZ'];

        // sort by VSZ desc
        $this->sort_key = 'VSZ';
        $this->sort_type = 'numeric';
        $this->sort_desc = true;
        uasort($data->tableData1, [$this, 'sortProcessesData']);

        // top by mem usage
        $cuantos = 10;
        $procs = array_slice($data->tableData1, 0, $cuantos);

        // sort by CPU usage desc
        $this->sort_key = '%CPU';
        $this->sort_type = 'numeric';
        $this->sort_desc = true;
        uasort($data->tableData1, [$this, 'sortProcessesData']);

        // top by cpu usage
        // $procs = array_merge($procs, array_slice($data->tableData1,0,$cuantos));

        // get the series data for the charts
        $cpu = [];
        $vsz = [];
        $pids = [];
        $categories = [];
        $show = 0;
        foreach ($procs as $entry) {
            if (isset($entry->PID) && ! in_array($entry->PID, $pids)) {
                if ($show++ >= 4) {
                    break;
                }
                $pids[] = $entry->PID;
                $cpu[] = $entry->{'%CPU'};
                $vsz[] = isset($entry->VSZ) ? $entry->VSZ : '';
                $categories[] = $entry->Command;
            }
        }

        // $series[] = (object)["name" => "cpu", "data" => $cpu ];
        $series[] = (object) ['name' => 'mem', 'data' => $vsz];

        // get the chart
        $chartTemplate = file_get_contents("{$this->chartsPath}/stackedBar.json");
        $chart = json_decode($chartTemplate, 1, 512, JSON_INVALID_UTF8_IGNORE);
        if (json_last_error()) {
            Log::error(json_last_error_msg());
        }

        // configure the chart
        if (isset($chart)) {
            $data->badgeData->chart = $chart;
            $data->badgeData->chart['plotOptions']['bar']['horizontal'] = false;
            $data->badgeData->chart['plotOptions']['bar']['dataLabels']['total']['offsetX'] = 0;
            $data->badgeData->chart['plotOptions']['bar']['dataLabels']['total']['offsetY'] = 0;
            $data->badgeData->chart['plotOptions']['bar']['dataLabels']['total']['style']['color'] = '#9ca3af';
            $data->badgeData->chart['grid']['padding']['top'] = 10;
            $data->badgeData->chart['grid']['padding']['bottom'] = 40;
            $data->badgeData->chart['title']['text'] = "top {$cuantos} by memory usage";
            $data->badgeData->chart['series'] = $series;
            $data->badgeData->chart['colors'] = getColorArray($compo_state);

            $data->badgeData->chart['xaxis']['show'] = false;
            $data->badgeData->chart['xaxis']['categories'] = $categories;
            $data->badgeData->chart['xaxis']['min'] = 0;
            $data->badgeData->chart['xaxis']['max'] = 100;
            $data->badgeData->chart['xaxis']['labels']['show'] = true;
            $data->badgeData->chart['xaxis']['labels']['style']['colors'] = getColorArray($compo_state);
        }

        return $data;
    }

    public function getNetInfo($component, $description, $icon)
    {
        $compo_state = 'primary';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => $description,
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableTitle1' => sprintf('%s info', ucfirst($component)),
            'tableOrder1' => [],
            'tableHeaders1' => [],
            'fileBlade1' => 'theme::tools.summary.sort-table',
            'tableData1' => [],
        ];

        $data->tableTitle1 = 'TCP and UDP connections';
        $data->tableHeaders1 = ['Protocol', 'RecvQueue', 'SendQueue', 'Local Address', 'Remote Address', 'State', 'User', 'PID', 'Programname'];
        $data->tableOrder1 = ['Proto', 'Recv-Q', 'Send-Q', 'Local_Address', 'Foreign_Address', 'State', 'User', 'PID', 'Program_name'];

        $data->tableTitle2 = 'UNIX sockets';
        $data->tableHeaders2 = ['Protocol', 'Porcesses', 'Flags', 'Type', 'State', 'Inode', 'PID', 'Programname', 'Path'];
        $data->tableOrder2 = ['Proto', 'RefCnt', 'Flags', 'Type', 'State', 'I-Node', 'PID', 'Program_name', 'Path'];

        $data->tableData1 = [];
        $data->tableData2 = [];
        $conn = ['listen' => 0, 'established' => 0, 'closed' => 0, 'udp' => 0, 'unix_listen' => 0, 'unix' => 0];

        $temp = (array) $this->dtools->getNetworkData();

        if (! isset($temp) || empty($temp) || ! $temp) {
            return null;
        }

        $last = $temp[count($temp) - 1];
        $msg = '';
        if (gettype($last) != 'object') {
            array_pop($temp);
            if (preg_match('/^#INCOMPLETE:..*/', $last)) {
                $msg = str_replace('#INCOMPLETE:', '', $last);
            }
        }

        $tcp = 0;
        $unix = 0;
        foreach ($temp as $row) {
            if (isset($row) && is_object($row)) {
                if ($row->Proto == 'tcp') {
                    $tcp++;
                } elseif ($row->Proto == 'udp') {
                    $tcp++;
                } elseif ($row->Proto == 'unix') {
                    $unix++;
                }
            }
        }

        if ($msg) {
            if ($unix > 0) {
                $data->tableTitle2 .= " ($msg)";
            } else {
                $data->tableTitle1 .= " ($msg)";
            }
        }

        foreach ($temp as $row) {
            if (isset($row) && is_object($row)) {
                if ($row->Proto == 'tcp') {
                    $data->tableData1[] = $row;
                    switch ($row->State) {
                        case 'ESTABLISHED':
                        case 'SYN_SENT':
                        case 'SYN_RECV':
                        case 'ESTABLISHED':
                            $conn['established']++;
                            break;
                        case 'LISTEN':
                            $conn['listen']++;
                            break;
                        case 'TIME_WAIT':
                        case 'FIN_WAIT1':
                        case 'FIN_WAIT2':
                        case 'CLOSED':
                        case 'CLOSED_WAIT':
                        case 'LAST_ACK':
                        case 'CLOSING':
                            $conn['closed']++;
                            break;

                    }
                } elseif ($row->Proto == 'udp') {
                    $data->tableData1[] = $row;
                    $conn['udp']++;
                } elseif ($row->Proto == 'unix') {
                    $data->tableData2[] = $row;
                    switch ($row->State) {
                        case 'LISTENING':
                            $conn['unix_listen']++;
                            break;
                        case 'CONNECTING':
                        case 'CONNECTED':
                        case 'DISCONNECTING':
                        default:
                            $conn['unix']++;
                            break;
                    }
                }
            }
        }

        if (! $data->tableData1) {
            return null;
        }

        if (! $data->tableData2) {
            unset($data->tableData2);
            unset($data->tableTitle2);
            unset($data->tableHeaders2);
            unset($data->tableOrder2);
        }

        $tcpMem = 0;
        $tcpMax = 0;
        $sockstat = $this->dtools->getSockstatData();

        $data->badgeData->color = $compo_state;

        if (! (! isset($sockstat) || empty($sockstat) || ! $sockstat)) {
            if (! (! isset($sockstat->TCP) || empty($sockstat->TCP) || ! $sockstat->TCP)) {
                $tcpMem = $sockstat->TCP->mem + $sockstat->UDP->mem + $sockstat->FRAG->memory;
                if (! (! isset($sockstat->TCP->max_mem) || empty($sockstat->TCP->max_mem) || ! $sockstat->TCP->max_mem)) {
                    $tcpMax = $sockstat->TCP->max_mem + $sockstat->UDP->max_mem;
                }

                // set the alert based on the socket memeory usage
                $percentage = ($tcpMax > 0) ? intval(100 * $tcpMem / $tcpMax) : 0;
                if ($percentage >= 85) {
                    $compo_state = 'danger';
                } elseif ($percentage >= 75) {
                    $compo_state = 'warning';
                } else {
                    $compo_state = 'primary';
                }
                $data->badgeData->color = $compo_state;

                if ($tcpMem > 0) {
                    $data->tableTitle1 .= ' '.Number::fileSize(floatval($tcpMem), precision: 2)." of memory usage ({$percentage}%)";
                }
            }
        }

        $data->badgeData->subTitle = 'Sockets: '.count($temp);
        $data->badgeData->subTitle = substr($data->badgeData->subTitle, 0, 20);

        $this->sort_key = 'Proto';
        $this->sort_type = 'string';
        $this->sort_desc = false;
        uasort($data->tableData1, [$this, 'sortProcessesData']);

        // get the series data for the charts
        $sdata = [];
        foreach ($conn as $key => $value) {
            $sdata[] = (object) ['x' => $key, 'y' => $value];
        }
        $series[] = (object) ['data' => $sdata];

        // get the chart
        $chartTemplate = file_get_contents("{$this->chartsPath}/treeMap.json");
        $chart = json_decode($chartTemplate, 1, 512, JSON_INVALID_UTF8_IGNORE);
        if (json_last_error()) {
            Log::error(json_last_error_msg());
        }

        // configure the chart
        if (isset($chart)) {
            $data->badgeData->chart = $chart;
            $data->badgeData->chart['title']['text'] = 'Network connections';
            $data->badgeData->chart['series'] = $series;
            $data->badgeData->chart['colors'] = getColorArray($compo_state);
            $data->badgeData->chart['grid']['padding']['top'] = 0;
            $data->badgeData->chart['grid']['padding']['bottom'] = 20;
        }

        return $data;
    }

    public function getFilesInfo($component, $description, $icon)
    {

        $compo_state = 'info';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => $description,
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableTitle1' => sprintf('%s info', ucfirst($component)),
            'tableOrder1' => [],
            'tableHeaders1' => [],
            'fileBlade1' => 'theme::tools.summary.sort-table',
            'tableData1' => [],
        ];

        // $data->tableHeaders1 = ["COMMAND", "OPEN FILES", "PID", "USER", "FD", "TYPE", "SIZE", "File NAMES"];
        // $data->tableOrder1   = ["COMMAND", "FILES", "PID", "USER", "FD", "TYPE", "SIZE", "LSOF_FILENAMES"];

        $data->tableHeaders1 = ['COMMAND', 'OPEN FILES', 'PID', 'USER', 'File NAMES'];
        $data->tableOrder1 = ['COMMAND', 'FILES', 'PID', 'USER', 'LSOF_FILENAMES'];
        $data->tableData1 = (array) $this->dtools->getOpenFilesData();

        if (! $data->tableData1) {
            return null;
        }

        $this->sort_key = 'OPEN FILES';
        $this->sort_type = 'numeric';
        $this->sort_desc = false;
        uasort($data->tableData1, [$this, 'sortProcessesData']);

        $procs = Number::format(count($data->tableData1), precision: 0);

        // chart data
        $files = 0;
        $maxFiles = [];
        foreach ($data->tableData1 as $entry) {
            $files += $entry->FILES;
            $maxFiles[$entry->COMMAND] = $entry->FILES;
        }

        $data->badgeData->subTitle = "Procs/Files: {$procs}/{$files}";
        $data->badgeData->subTitle = substr($data->badgeData->subTitle, 0, 20);

        asort($maxFiles);

        // show the $cuantos top highes open files processes...
        $cuantos = 5;
        $charData = array_slice($maxFiles, count($maxFiles) - $cuantos);

        $series[] = (object) ['name' => 'open files', 'data' => array_reverse(array_values($charData))];

        // get the chart
        $chartTemplate = file_get_contents("{$this->chartsPath}/stackedBar.json");
        $chart = json_decode($chartTemplate, 1, 512, JSON_INVALID_UTF8_IGNORE);
        if (json_last_error()) {
            Log::error(json_last_error_msg());
        }

        // configure the chart
        if (isset($chart)) {
            $data->badgeData->chart = $chart;
            $data->badgeData->chart['plotOptions']['bar']['horizontal'] = false;
            $data->badgeData->chart['plotOptions']['bar']['dataLabels']['total']['offsetX'] = 0;
            $data->badgeData->chart['plotOptions']['bar']['dataLabels']['total']['offsetY'] = 0;
            $data->badgeData->chart['plotOptions']['bar']['dataLabels']['total']['style']['color'] = '#9ca3af';
            $data->badgeData->chart['grid']['padding']['top'] = 10;
            $data->badgeData->chart['grid']['padding']['bottom'] = 40;
            $data->badgeData->chart['title']['text'] = "top {$cuantos} procs by open files";
            $data->badgeData->chart['series'] = $series;
            $data->badgeData->chart['colors'] = getColorArray($compo_state);

            $data->badgeData->chart['xaxis']['show'] = false;
            $data->badgeData->chart['xaxis']['categories'] = array_reverse(array_keys($charData));
            $data->badgeData->chart['xaxis']['min'] = 0;
            $data->badgeData->chart['xaxis']['max'] = array_pop($maxFiles);
            $data->badgeData->chart['xaxis']['labels']['show'] = true;
            $data->badgeData->chart['xaxis']['labels']['style']['colors'] = getColorArray($compo_state);
        }

        // log::info(var_export($data,1));

        return $data;
    }

    public function getErrors($component, $description, $icon)
    {
        $compo_state = 'danger';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => $description,
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableTitle1' => sprintf('%s info', ucfirst($component)),
            'fileBlade1' => 'theme::tools.summary.errorsSection',
        ];

        $tables = $this->dtools->getErrorsData();

        if (! isset($tables) || empty($tables) || ! $tables) {
            return null;
        }

        $seriesData = [];
        $errors = 0;
        $logs = 1;
        $tname = "tableData{$logs}";
        $hname = "tableHeaders{$logs}";
        $oname = "tableOrder{$logs}";

        if (! isset($data->{$tname})) {
            $data->{$tname} = (object) [];
        }
        $data->{$tname}->data = [];

        foreach (array_keys($tables) as $title) {

            $xdata = $tables[$title];
            $n = count($xdata);
            $errors += $n;
            $seriesData[] = $n;
            $categories[] = basename($title);

            // resolve the file viewer id once per logfile
            $fid = $this->dtools->getFileIdByPath($title);

            foreach ($xdata as $record) {
                $parts = explode(':', $record);
                $line = array_shift($parts);
                $message = implode(':', $parts);

                $data->{$tname}->data[] = [
                    'logfile' => $title,
                    'errorcount' => $n,
                    'line' => $line,
                    'message' => $message,
                    'fid' => (int) $fid,
                    'search' => $this->errorSearchTerm($message),
                ];
            }

            $logs++;

            if (! isset($data->{$hname})) {
                $data->{$hname} = ['logfile', 'errorcount', 'line', 'message'];
            }

            if (! isset($data->{$oname})) {
                $data->{$oname} = ['logfile', 'errorcount', 'line', 'message'];
            }

        }

        if (! $data->tableData1) {
            return null;
        }

        $compo_state = ($errors == 0) ? 'primary' : 'warning';

        $data->badgeData->subTitle = Number::format($errors, precision: 0)." errors in {$logs} files";
        $data->badgeData->subTitle = substr($data->badgeData->subTitle, 0, 23);
        $data->badgeData->color = $compo_state;

        $cuantos = 5;
        $seriesData = array_slice($seriesData, count($seriesData) - $cuantos);
        $categories = array_slice($categories, count($categories) - $cuantos);
        $series[] = (object) ['name' => 'errors', 'data' => $seriesData];

        // get the chart
        $chartTemplate = file_get_contents("{$this->chartsPath}/stackedBar.json");
        $chart = json_decode($chartTemplate, 1, 512, JSON_INVALID_UTF8_IGNORE);
        if (json_last_error()) {
            Log::error(json_last_error_msg());
        }

        // configure the chart
        if (isset($chart)) {
            $data->badgeData->chart = $chart;
            $data->badgeData->chart['stacked'] = false;
            $data->badgeData->chart['plotOptions']['bar']['horizontal'] = false;
            $data->badgeData->chart['plotOptions']['bar']['dataLabels']['total']['offsetX'] = 0;
            $data->badgeData->chart['plotOptions']['bar']['dataLabels']['total']['offsetY'] = 0;
            $data->badgeData->chart['plotOptions']['bar']['dataLabels']['total']['style']['color'] = '#9ca3af';
            $data->badgeData->chart['grid']['padding']['top'] = 10;
            $data->badgeData->chart['grid']['padding']['bottom'] = 40;
            $data->badgeData->chart['title']['text'] = 'top log files by errors';
            $data->badgeData->chart['series'] = $series;
            $data->badgeData->chart['colors'] = getColorArray($compo_state);

            $data->badgeData->chart['xaxis']['show'] = false;
            $data->badgeData->chart['xaxis']['categories'] = $categories;
            $data->badgeData->chart['xaxis']['min'] = 0;
            $data->badgeData->chart['xaxis']['max'] = array_pop($seriesData);
            $data->badgeData->chart['xaxis']['labels']['show'] = true;
            $data->badgeData->chart['xaxis']['labels']['style']['colors'] = getColorArray($compo_state);
        }

        // log::info(var_export($data,1));

        return $data;
    }

    // Best-effort approximation of the parsed table's "message" column so the File
    // Viewer can pre-search to the matching row: strip a leading timestamp and an
    // optional "host/proc/severity:" prefix, falling back to the full message.
    private function errorSearchTerm(string $message): string
    {
        $s = preg_replace('/^\W*(\d{4}-\d{2}-\d{2}|\w{3}\s+\d{1,2})\s+\d{2}:\d{2}:\d{2}\b/', '', $message);
        $s = preg_replace('/^[^:]{0,40}:\s*/', '', $s);
        $s = trim($s);

        return mb_substr($s !== '' ? $s : trim($message), 0, 120);
    }

    public function getFWInfo($component, $description, $icon)
    {
        $compo_state = 'info';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => $description,
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableTitle1' => sprintf('%s info', ucfirst($component)),
            'fileBlade1' => 'theme::tools.summary.firewallSection',
        ];

        $iptables = (object) $this->dtools->getIpTablesData();

        if (! isset($iptables) || empty($iptables) || $iptables == null || empty(get_object_vars($iptables))) {
            return null;
        }

        $seriesData = [];
        $rules = 0;
        $chains = 1;

        $tname = "tableData{$chains}";
        $hname = "tableHeaders{$chains}";
        $oname = "tableOrder{$chains}";

        if (! isset($data->{$tname})) {
            $data->{$tname} = (object) [];
        }
        $data->{$tname}->data = [];

        foreach (array_keys((array) $iptables) as $title) {

            $n = count($iptables->{$title}->data);
            if ($n) {
                $rules += $n;
                $seriesData[] = (object) ['x' => $title, 'y' => $n];
            }

            $this->newField = $iptables->{$title}->title.' ('.$iptables->{$title}->policy.')';
            array_map(function ($item) {
                return $item->{'chain'} = $this->newField;
            }, $iptables->{$title}->data);
            $data->{$tname}->data += json_decode(json_encode($iptables->{$title}->data), true);

            $chains++;

            if (! isset($data->{$hname})) {
                $data->{$hname} = ['packets', 'bytes', 'target', 'protocol', 'options', 'in', 'out', 'source', 'destination', 'extra', 'chain'];
            }

            if (! isset($data->{$oname})) {
                $data->{$oname} = ['pkts', 'bytes', 'target', 'prot', 'opt', 'in', 'out', 'source', 'destination', 'more', 'chain'];
            }

        }

        if (! isset($data->tableData1) || empty($data->tableData1)) {
            return null;
        }

        $data->badgeData->subTitle = "{$chains} chains, ".Number::format($rules, precision: 0).' rules';
        $data->badgeData->subTitle = substr($data->badgeData->subTitle, 0, 20);

        $series[] = (object) ['data' => $seriesData];

        // get the chart
        $chartTemplate = file_get_contents("{$this->chartsPath}/treeMap.json");
        $chart = json_decode($chartTemplate, 1, 512, JSON_INVALID_UTF8_IGNORE);
        if (json_last_error()) {
            Log::error(json_last_error_msg());
        }

        // configure the chart
        if (isset($chart)) {
            $data->badgeData->chart = $chart;
            $data->badgeData->chart['title']['text'] = 'chains by rules number';
            $data->badgeData->chart['series'] = $series;
            $data->badgeData->chart['colors'] = getColorArray($compo_state);
            $data->badgeData->chart['grid']['padding']['top'] = 0;
            $data->badgeData->chart['grid']['padding']['bottom'] = 20;
        }

        // log::info(var_export($data, true));

        return $data;
    }

    public function getInventoryInfo($component, $description, $icon)
    {
        $compo_state = 'gray';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => $description,
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableTitle1' => sprintf('%s info', ucfirst($component)),
            'fileBlade1' => 'theme::tools.summary.inventorySection',
        ];

        $inventory = (object) $this->dtools->getInventoryData();

        if (! isset($inventory) || empty($inventory) || $inventory == null || empty(get_object_vars($inventory))) {
            return null;
        }

        // show in this order...
        $types = [
            '0' => 'BIOS',
            '1' => 'System',
            '3' => 'Chassis',
            '2' => 'Baseboard',
            '4' => 'Processor',
            '7' => 'Cache',

            '5' => 'Memory Controller',
            '17' => 'Memory Device',
            '33' => '64-bit Memory Error Information',

            '0104' => 'RAID Controller',
            '0100' => 'SCSI storage controller',
            'disk' => 'disks',
            'usbdisk' => 'disks',
            'namespace' => 'SDD Card',
            'cdrom' => 'cdroms',

            '0280' => 'Network Controller',
            '0200' => 'Ethernet controller',
            '0d40' => 'Wireless Controller',
            'ethernet' => 'USB Ethernet Controller',

            '8' => 'Port Connector',
            '22' => 'Portable Battery',
            '0401' => 'Multimedia Audio Controller',
            '1180' => 'Signal processing controller',
            'mouse' => 'USB Mouse',
            'keyboard' => 'USB Keyboard',
            'camera' => 'USB Camera',
            'docking' => 'USB Docking Station',

            '0300' => 'VGA compatible controller',

            '32' => 'System Boot',
            '23' => 'System Reset',
            '24' => 'Hardware Security',
            '11' => 'OEM Strings',
            '221' => 'BIOS iSCSI NIC',

            '30' => 'Out-of-band Remote Access',
            '0c05' => 'System Management Bus Controller',
            '0880' => 'System peripheral',

            '0c80' => 'Serial Bus Controller',
            '0c03' => 'USB Controller',
            '0600' => 'Host bridge',
            '0604' => 'PCI bridge',
            '0601' => 'ISA bridge',
            '0101' => 'IDE Interface',
            '0680' => 'Bridge',
            'usbdevice' => 'Unknown USB device',
        ];

        $sections = [];
        $titles = [];
        foreach ($types as $type => $title) {
            foreach (array_keys((array) $inventory) as $key) {
                if (! in_array($key, $sections)) {
                    $skip = ['disk', 'cdrom', 'namespace'];
                    if (! in_array($type, $skip)) {
                        if (strval($inventory->{$key}->type) == $type) {
                            $sections[] = $key;
                            $titles[] = $title;
                        }
                    } else {
                        if (preg_match("/{$type}\d+/", strval($inventory->{$key}->type))) {
                            $sections[] = $key;
                            $titles[] = $title;
                        }
                    }
                }
            }
        }

        $seriesData = [];
        $rules = 0;
        $keywords = 1;

        $tname = "tableData{$keywords}";
        $hname = "tableHeaders{$keywords}";
        $oname = "tableOrder{$keywords}";

        if (! isset($data->{$tname})) {
            $data->{$tname} = (object) [];
            $data->{$tname}->data = [];
        }

        if (! isset($data->{$hname})) {
            $data->{$hname} = ['amount', 'data'];
        }

        if (! isset($data->{$oname})) {
            $data->{$oname} = ['amount', 'data'];
        }

        foreach ($sections as $i => $key) {

            $xdata = (object) [];
            $n = count($inventory->{$key}->data);
            if ($n) {
                $rules += $n;
                $seriesData[] = (object) ['x' => $key, 'y' => $n];
            }

            $xdata->order = $keywords++;
            $xdata->descr = substr($inventory->{$key}->name, 0, 120);
            $title = explode(' ', $xdata->descr);

            $xdata->title = $titles[$i];
            $xdata->count = $inventory->{$key}->count;
            $xdata->icon = $inventory->{$key}->icon;
            $xdata->sum = $inventory->{$key}->sum;
            $xdata->amount = '';

            if ($inventory->{$key}->type == 4) {
                $xdata->amount = "{$xdata->count} cores";
                $xdata->sum = 1;
            } elseif ($xdata->sum > 0) {
                $xdata->amount = Number::fileSize($inventory->{$key}->sum, precision: 2);
            }
            $xdata->data = implode("\n", $inventory->{$key}->data);

            $data->{$tname}->data[] = json_decode(json_encode($xdata), true);

        }

        if (! isset($data) || (isset($data) && ! isset($data->tableData1))) {
            return null;
        }

        isset($inventory->{'System Information'}) && $product = preg_replace('/^..*: /', '', preg_grep('/Product Name:/', $inventory->{'System Information'}->data));
        isset($inventory->{'Base Board Information'}) && $board = preg_replace('/^..*: /', '', preg_grep('/Product Name:/', $inventory->{'Base Board Information'}->data));
        isset($inventory->{'BIOS Information'}) && $bios = preg_replace('/^..*: /', '', preg_grep('/Vendor:/', $inventory->{'BIOS Information'}->data));
        isset($inventory->{'BIOS Information'}) && $biosv = preg_replace('/^..*: /', '', preg_grep('/Version:/', $inventory->{'BIOS Information'}->data));

        $message = '';
        isset($product) && $message .= array_pop($product);
        $message .= '. ';
        isset($board) && $message .= array_pop($board);
        $message .= '. BIOS: ';
        isset($bios) && $message .= array_pop($bios);
        $message .= ' v';
        isset($biosv) && $message .= array_pop($biosv);

        $data->badgeData->subTitle = substr($message, 0, 106);
        $data->badgeData->subTitle = substr($data->badgeData->subTitle, 0, 80);

        return $data;
    }

    public function getLimitsInfo($component, $description, $icon)
    {
        $compo_state = 'info';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => $description,
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableTitle1' => sprintf('%s info', ucfirst($component)),
            'tableOrder1' => [],
            'tableHeaders1' => [],
            'fileBlade1' => 'theme::tools.summary.sort-table',
            'tableData1' => [],
        ];

        $data->tableHeaders1 = [
            'Command',
            'pid',
            'user',
            'Max open files (files)',
            'Max processes (processes)',
            'Max locked memory (bytes)',
            'Max pending signals (signals)',
            'Max msgqueue size (bytes)',
            'Max nice priority',
            'Max realtime priority',
            'Max realtime timeout (us)',
            'Max cpu time (seconds)',
            'Max file size (bytes)',
            'Max data size (bytes)',
            'Max stack size (bytes)',
            'Max core file size (bytes)',
            'Max resident set (bytes)',
            'Max address space (bytes)',
            'Max file locks (locks)',
        ];

        $data->tableOrder1 = [
            'Command',
            'PID',
            'USER',
            'Max open files (files)',
            'Max processes (processes)',
            'Max locked memory (bytes)',
            'Max pending signals (signals)',
            'Max msgqueue size (bytes)',
            'Max nice priority',
            'Max realtime priority',
            'Max realtime timeout (us)',
            'Max cpu time (seconds)',
            'Max file size (bytes)',
            'Max data size (bytes)',
            'Max stack size (bytes)',
            'Max core file size (bytes)',
            'Max resident set (bytes)',
            'Max address space (bytes)',
            'Max file locks (locks)',
        ];

        $data->tableData1 = (array) $this->dtools->getProcessesData();

        if (! isset($data->tableData1) || empty($data->tableData1) || ! $data->tableData1) {
            return null;
        }

        if (isset($data->tableData1)) {
            $data->tasks = array_pop($data->tableData1);
            if (isset($data->tasks)) {
                $data->badgeData->subTitle = 'ulimits for '.$data->tasks->tasks.' tasks';
                $data->badgeData->subTitle = substr($data->badgeData->subTitle, 0, 20);
            }
        }

        $this->sort_key = 'Command';
        $this->sort_type = 'string';
        $this->sort_desc = false;
        uasort($data->tableData1, [$this, 'sortProcessesData']);

        // maximum number of opened file descriptors on your Linux system.
        $contents = $this->dtools->readFileContents('proc/sys/fs/file-max');

        $file_max = 0;
        if ($contents) {
            $file_max = $contents[0];
        }
        $data->badgeData->mark = 'maximum open files '.Number::abbreviate($file_max, precision: 0);

        return $data;
    }

    public function getPackagesInfo($component, $description, $icon)
    {
        $compo_state = 'warning';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => $description,
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableTitle1' => sprintf('%s info', ucfirst($component)),
            'tableOrder1' => [],
            'tableHeaders1' => [],
            'fileBlade1' => 'theme::tools.summary.sort-table',
            'tableData1' => [],
        ];

        if (! isset($this->os_version['ID'])) {
            return null;
        }

        if (isset($this->os_version['ID'])) {
            switch ($this->os_version['ID']) {
                case 'opensuse':
                    break;
                case 'rhel':
                case 'RedHatEnterpriseServer':
                case 'almalinux':
                case 'ol':
                case 'centos':
                case 'fedora':
                    $data->tableData1 = (array) $this->dtools->getRHELPackagesData();

                    if (! isset($data->tableData1) || empty($data->tableData1) || ! $data->tableData1) {
                        return null;
                    }

                    $data->tableHeaders1 = [
                        'Name',
                        'Date',
                    ];

                    $data->tableOrder1 = [
                        'Name',
                        'Date',
                    ];

                    $data->badgeData->subTitle = 'installed: '.count($data->tableData1);

                    $removed = 'unknown';
                    $data->badgeData->mark = $removed.' removed packages';
                    break;
                case 'debian':
                case 'ubuntu':
                    $data->tableData1 = (array) $this->dtools->getUbuntuPackagesData();

                    if (! isset($data->tableData1) || empty($data->tableData1) || ! $data->tableData1) {
                        return null;
                    }

                    $data->tableHeaders1 = [
                        'Marked',
                        'Current',
                        // "Error",
                        'Status',
                        'Name',
                        'Version',
                        // "Architecture",
                        'Description',
                    ];

                    $data->tableOrder1 = [
                        'Marked',
                        'Current',
                        // "Error",
                        'Status',
                        'Name',
                        'Version',
                        // "Architecture",
                        'Description',
                    ];

                    $errors = 0;
                    $removed = 0;
                    foreach ($data->tableData1 as $pkg) {
                        if ($pkg->Status == 'rc') {
                            $removed++;
                        }
                    }
                    $data->badgeData->subTitle = 'Installed: '.count($data->tableData1) - $removed;

                    $data->badgeData->mark = $removed.' removed packages';
                    break;
            }
        }

        $this->sort_key = 'Name';
        $this->sort_type = 'string';
        $this->sort_desc = false;
        uasort($data->tableData1, [$this, 'sortProcessesData']);
        $data->badgeData->subTitle = substr($data->badgeData->subTitle, 0, 20);

        return $data;
    }

    public function getKernelInfo($component, $description, $icon)
    {
        $compo_state = 'gray';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => $description,
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableTitle1' => sprintf('%s info', ucfirst($component)),
            'tableOrder1' => [],
            'tableHeaders1' => [],
            'fileBlade1' => 'theme::tools.summary.sort-table',
            'tableData1' => [],
        ];

        $data->tableData1 = (array) $this->dtools->getKernelParamsData();

        if (! isset($data->tableData1) || empty($data->tableData1) || ! $data->tableData1) {
            return null;
        }

        $data->tableHeaders1 = ['Name', 'Value', 'Description'];

        $data->tableOrder1 = ['Name', 'Value', 'Descr'];

        $data->badgeData->mark = count($data->tableData1).' config settings';

        $k = (object) $this->kernel_version;
        if (isset($k) && ! empty($k) && $k) {
            $data->badgeData->subTitle = "{$k->kernel}.{$k->major}.{$k->minor}-{$k->patch}-{$k->flavour}";
            $data->badgeData->subTitle = substr($data->badgeData->subTitle, 0, 23);
        }

        $this->sort_key = 'Name';
        $this->sort_type = 'string';
        $this->sort_desc = false;
        uasort($data->tableData1, [$this, 'sortProcessesData']);

        return $data;
    }

    public function getTcpIpInfo($component, $description, $icon)
    {
        $compo_state = 'primary';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => $description,
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableTitle1' => sprintf('%s info', ucfirst($component)),
            'tableOrder1' => [],
            'tableHeaders1' => [],
            'fileBlade1' => 'theme::tools.summary.sort-table',
            'tableData1' => [],
        ];

        $tcpStats = $this->dtools->getTcpIpStatsData();

        if (! isset($tcpStats) || empty($tcpStats)) {
            return null;
        }

        $data->tableData1 = $tcpStats['nics'];
        $data->tableData2 = $tcpStats['kernel'];
        $data->tableData3 = $tcpStats['counters'];

        if (! isset($data->tableData1) || empty($data->tableData1) || ! $data->tableData1) {
            return null;
        }

        if (! isset($data->tableData2) || empty($data->tableData2) || ! $data->tableData2) {
            return null;
        }

        if (! isset($data->tableData3) || empty($data->tableData3) || ! $data->tableData3) {
            return null;
        }

        $data->tableHeaders1 = ['Name', 'IP', 'IPv6', 'MTU', 'State'];
        $data->tableOrder1 = ['GENERAL_DEVICE', 'IP4_ADDRESS', 'IP6_ADDRESS', 'GENERAL_MTU', 'GENERAL_STATE'];
        $data->tableTitle1 = 'Network Interfaces';

        $data->tableHeaders2 = ['Name', 'Value', 'Description'];
        $data->tableOrder2 = ['Name', 'Value', 'Descr'];
        $data->tableTitle2 = 'TCP/IP related kernel parameters';

        $data->tableHeaders3 = ['Protocol', 'Counter', 'Value', 'Percentage', 'Reference', 'Description'];
        $data->tableOrder3 = ['Category', 'Name', 'Value', 'Percentage', 'Reference', 'Descr'];
        $data->tableTitle3 = 'TCP/IP stack counters';

        $subTitle = count($data->tableData3).' TCP/IP data points';
        $data->badgeData->subTitle = substr($subTitle, 0, 25);

        $series[] = (object) ['data' => $tcpStats['chart']];
        $compo_state = $tcpStats['color'];
        $data->badgeData->color = $compo_state;

        // get the chart
        $chartTemplate = file_get_contents("{$this->chartsPath}/treeMap.json");
        $chart = json_decode($chartTemplate, 1, 512, JSON_INVALID_UTF8_IGNORE);
        if (json_last_error()) {
            Log::error(json_last_error_msg());
        }

        // configure the chart
        if (isset($chart)) {
            $data->badgeData->chart = $chart;
            $data->badgeData->chart['title']['text'] = 'Main TCP/IP stats';
            $data->badgeData->chart['series'] = $series;
            $data->badgeData->chart['colors'] = getColorArray($compo_state);
            $data->badgeData->chart['grid']['padding']['top'] = 0;
            $data->badgeData->chart['grid']['padding']['bottom'] = 20;
        }

        return $data;
    }

    public function getSystemdInfo($component, $description, $icon)
    {
        $compo_state = 'primary';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => $description,
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableTitle1' => $description,
            'fileBlade1' => 'theme::tools.summary.systemdSection',
        ];

        $tables = $this->dtools->getSystemdData();

        if (! isset($tables) || empty($tables) || ! isset($tables['systemd'])) {
            return null;
        }

        $units = $tables['systemd'];
        if (empty($units)) {
            return null;
        }

        // count units per type and tally failed / transitional states
        $counts = [];
        $failedCounts = [];
        $failed = 0;
        $warn = 0;
        $records = [];

        // preferred group ordering; remaining types keep their natural order
        $priority = ['service' => 0, 'target' => 1, 'mount' => 2, 'timer' => 3, 'socket' => 4];
        $typeOrder = [];
        $nextOrder = count($priority);

        foreach ($units as $unit) {
            $type = $unit['type'] ?? 'other';
            $counts[$type] = ($counts[$type] ?? 0) + 1;

            if (! isset($typeOrder[$type])) {
                $typeOrder[$type] = $priority[$type] ?? $nextOrder++;
            }

            $state = strtolower($unit['active'] ?? '');
            $sub = strtolower($unit['sub'] ?? '');

            if ($state === 'failed' || $sub === 'failed') {
                $failed++;
                $failedCounts[$type] = ($failedCounts[$type] ?? 0) + 1;
            } elseif (in_array($state, ['activating', 'deactivating', 'reloading'], true)) {
                $warn++;
            }

            $records[] = $unit;
        }

        // annotate each record with its type count + group order for the grouped table
        foreach ($records as &$record) {
            $type = $record['type'] ?? 'other';
            $record['typecount'] = $counts[$type] ?? 0;
            $record['typeorder'] = $typeOrder[$type] ?? $nextOrder;
            $record['typefailed'] = $failedCounts[$type] ?? 0;
        }
        unset($record);

        $data->tableData1 = (object) ['data' => $records];
        $data->tableHeaders1 = ['Type', 'Count', 'Unit', 'Loaded', 'Active', 'Sub', 'Job', 'Description'];
        $data->tableOrder1 = ['type', 'typecount', 'unit', 'loaded', 'active', 'sub', 'job', 'description'];

        // alert on failures, warn on transitional states, otherwise primary —
        // the subtitle headlines the total unit count and reflects the same state
        $total = Number::format(count($records), precision: 0);
        if ($failed > 0) {
            $compo_state = 'danger';
            $subTitle = __('vault.summary_systemd_failed', ['count' => $total, 'failed' => Number::format($failed, precision: 0)]);
        } elseif ($warn > 0) {
            $compo_state = 'warning';
            $subTitle = __('vault.summary_systemd_transition', ['count' => $total, 'warn' => Number::format($warn, precision: 0)]);
        } else {
            $compo_state = 'primary';
            $subTitle = __('vault.summary_systemd_active', ['count' => $total]);
        }

        $data->badgeData->color = $compo_state;
        $data->badgeData->subTitle = $subTitle;

        // top unit types by count for the bar chart
        arsort($counts);
        $top = array_slice($counts, 0, 5, true);
        $categories = array_keys($top);
        $seriesData = array_values($top);
        $series[] = (object) ['name' => 'units', 'data' => $seriesData];

        // get the chart
        $chartTemplate = file_get_contents("{$this->chartsPath}/stackedBar.json");
        $chart = json_decode($chartTemplate, 1, 512, JSON_INVALID_UTF8_IGNORE);
        if (json_last_error()) {
            Log::error(json_last_error_msg());
        }

        // configure the chart
        if (isset($chart)) {
            $data->badgeData->chart = $chart;
            $data->badgeData->chart['stacked'] = false;
            $data->badgeData->chart['plotOptions']['bar']['horizontal'] = false;
            $data->badgeData->chart['plotOptions']['bar']['dataLabels']['total']['offsetX'] = 0;
            $data->badgeData->chart['plotOptions']['bar']['dataLabels']['total']['offsetY'] = 0;
            $data->badgeData->chart['plotOptions']['bar']['dataLabels']['total']['style']['color'] = '#9ca3af';
            $data->badgeData->chart['grid']['padding']['top'] = 10;
            $data->badgeData->chart['grid']['padding']['bottom'] = 40;
            $data->badgeData->chart['title']['text'] = 'units by type';
            $data->badgeData->chart['series'] = $series;
            $data->badgeData->chart['colors'] = getColorArray($compo_state);

            $data->badgeData->chart['xaxis']['show'] = false;
            $data->badgeData->chart['xaxis']['categories'] = $categories;
            $data->badgeData->chart['xaxis']['min'] = 0;
            $data->badgeData->chart['xaxis']['max'] = ! empty($seriesData) ? max($seriesData) : 0;
            $data->badgeData->chart['xaxis']['labels']['show'] = true;
            $data->badgeData->chart['xaxis']['labels']['style']['colors'] = getColorArray($compo_state);
        }

        // log::info(var_export($data,1));

        return $data;
    }

    public function sortProcessesData($a, $b)
    {
        if ($this->sort_desc) {
            $temp = $a;
            $a = $b;
            $b = $temp;
        }

        if (! isset($a->{$this->sort_key}) || ! isset($b->{$this->sort_key})) {
            return 0;
        }

        switch ($this->sort_type) {
            case 'date':
                if ($a->{$this->sort_key} == $b->{$this->sort_key}) {
                    return 0;
                }

                $A = array_reverse(explode(':', $a->{$this->sort_key}));
                $secs = [86400, 3600, 60, 1];
                $totA = 0;
                foreach ($A as $n) {
                    $totA += floatval($n) * floatval(array_pop($secs));
                }

                $B = array_reverse(explode(':', $b->{$this->sort_key}));
                $secs = [86400, 3600, 60, 1];
                $totB = 0;
                foreach ($B as $n) {
                    $totB += floatval($n) * floatval(array_pop($secs));
                }

                return ($totA > $totB) ? 1 : -1;
                break;
            case 'numeric':
            case 'number':
                if ($a->{$this->sort_key} == $b->{$this->sort_key}) {
                    return 0;
                }

                return ($a->{$this->sort_key} > $b->{$this->sort_key}) ? 1 : -1;
                break;
            case 'string':
            default:
                return strcmp($a->{$this->sort_key}, $b->{$this->sort_key});
                break;
        }
    }

    public function getTop()
    {
        $icon = 'simpleicon-linux';

        if (isset($this->os_version) && isset($this->os_version['ID'])) {
            $icon = linuxIcon($this->os_version['ID']);
        }

        $data = (object) [
            'host' => $this->getTopHostInfo('host', 'Host Info', $icon),
            'cpu' => $this->getTopCpuInfo('cpu', 'CPU Info', 'phosphor-cpu-duotone'),
            'memory' => $this->getTopMemoryInfo('memory', 'Memory Info', 'phosphor-memory-duotone'),
            'procs' => $this->getTopProcsInfo('procsTop', 'Process Info', 'phosphor-tree-view-duotone'),
        ];

        // file_put_contents("/tmp/topData.json", json_encode($data, JSON_PRETTY_PRINT));

        return $data;
    }

    public function getTopProcsInfo($component, $description, $icon)
    {
        $compo_state = 'info';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => $description,
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableOrder1' => [],
            'tableHeaders1' => [],
            'fileBlade1' => 'theme::tools.summary.sort-table',
            'fileBladeData1' => (object) [
                'noModal' => true,
                'noHeader' => true,
            ],
            'tableData1' => [],
        ];

        $data->tableHeaders1 = [
            'PID',
            'USER',
            'PRIORITY',
            'NICE',
            'VIRTUAL MEM',
            'RESIDENT MEM',
            'SHARED MEM',
            'STATE',
            '% CPU',
            '% MEM',
            'TIME',
            'COMMAND',
        ];
        $data->tableOrder1 = [
            'PID',
            'USER',
            'PRI',
            'NI',
            'VSZ',
            'RSS',
            'SHR',
            'STAT',
            '%CPU',
            '%MEM',
            'TIME',
            'Command',
        ];

        $data->tableData1 = (array) $this->dtools->getProcessesData();

        if (! isset($data->tableData1) || empty($data->tableData1) || ! $data->tableData1) {
            return null;
        }

        $data->tasks = array_pop($data->tableData1);
        $data->tasksOrder = (object) [
            'initial' => 'Tasks',
            'tasks' => 'total',
            'running' => 'running',
            'sleeping' => 'sleeping',
            'idle' => 'kernel idle',
            'stopped' => 'stopped',
            'zombie' => 'zombie',
        ];

        // sort by CPU usage desc
        $this->sort_key = '%CPU';
        $this->sort_type = 'numeric';
        $this->sort_desc = true;
        uasort($data->tableData1, [$this, 'sortProcessesData']);

        // log::info(var_export($data->tableData1,1));
        return $data;
    }

    public function getTopMemoryInfo($component, $description, $icon)
    {
        // initialize the response object
        $compo_state = 'info';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mainTitle' => '',
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableOrder1' => ['memory', 'total', 'free', 'used', 'buff/cache'],
            'tableOrder2' => ['swap', 'total', 'free', 'used'],
            'tableHeaders1' => ['Memory', 'total', 'free', 'used', 'buff/cache'],
            'tableHeaders2' => ['Swap', 'total', 'free', 'used'],
            'tableData1' => (object) [],
            'tableData2' => (object) [],
            'fileBlade1' => 'theme::tools.summary.memorySection',
        ];

        $meminfo = $this->dtools->getMemoryData();

        if (! isset($meminfo) || empty($meminfo) || ! $meminfo) {
            return null;
        }

        if (! isset($meminfo->memory) || empty($meminfo->memory) || ! $meminfo->memory) {
            return null;
        }

        $data->tableData1 = $meminfo->memory;

        if (! (! isset($meminfo->swap) || empty($meminfo->swap) || ! $meminfo->swap)) {
            $data->tableData2 = $meminfo->swap;
        }

        // set the basge alert level (color)
        $pfree = $data->tableData1->pfree->value;
        if ($pfree > 20) {
            $compo_state = 'primary';
        } elseif ($pfree <= 20 && $pfree > 10) {
            $compo_state = 'warning';
            $data->tableData1->free->color = 'warning';
        } elseif ($pfree <= 10) {
            $compo_state = 'danger';
            $data->tableData1->free->color = 'danger';
        }
        $data->badgeData->color = $compo_state;

        foreach ($data->tableOrder1 as $param) {
            if (isset($data->tableData1->{"$param"})) {
                $data->tableData1->{"$param"}->color = $compo_state;
                $val = $data->tableData1->{"$param"}->value;
                $data->tableData1->{"$param"}->value = Number::fileSize(floatval($val), precision: 2);
            }
        }

        if (! (! isset($data->tableData2) || empty($data->tableData2) || ! $data->tableData2)) {
            foreach ($data->tableOrder2 as $param) {
                if (isset($data->tableData2->{"$param"})) {
                    $data->tableData2->{"$param"}->color = $compo_state;
                    $val = $data->tableData2->{"$param"}->value;
                    $data->tableData2->{"$param"}->value = Number::fileSize(floatval($val), precision: 2);
                }
            }
        }

        return $data;
    }

    public function getTopHostInfo($component, $description, $icon)
    {
        $compo_state = 'info';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => '',
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableOrder1' => [
                '',
                'system time',
                'uptime',
                'users',
                'load average',
            ],
            'tableHeaders1' => [
                'Top',
                'date',
                'uptime',
                'users',
                'load average',
            ],
            'fileBlade1' => 'theme::tools.summary.hostSection',
            'tableData1' => (object) [],
        ];

        $data->tableData1 = $this->dtools->getHostData();

        if (! isset($data->tableData1) || empty($data->tableData1) || ! $data->tableData1) {
            return null;
        }

        $cores = 0;
        if (isset($data->tableData1->cores) && ! empty($data->tableData1->cores) && $data->tableData1->cores) {
            $cores = intval($data->tableData1->cores);
        }

        $load = [];
        if (isset($data->tableData1->{'load average'}) && ! empty($data->tableData1->{'load average'}) && $data->tableData1->{'load average'}) {
            $load = explode(', ', preg_replace('/^..*: /', '', $data->tableData1->{'load average'}));
        }

        return $data;
    }

    public function getTopCpuInfo($component, $description, $icon)
    {
        $compo_state = 'info';
        $data = (object) [
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => '',
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableOrder1' => ['cpu', 'user', 'system', 'nice', 'idle', 'iowait', 'irq', 'softirq'],
            'tableHeaders1' => ['%Cpu(s)', '% User', '% System', '% Nice', '% Idle', '% Iowait', '% Irq', '% Softirq'],
            'tableData1' => (object) [],
            'fileBlade1' => 'theme::tools.summary.cpuSection',
        ];

        $data->tableData1 = $this->dtools->getCpuData();

        if (! isset($data->tableData1) || empty($data->tableData1) || ! $data->tableData1) {
            return null;
        }

        // color assignation
        $skip = ['cpu', 'total', 'color'];

        foreach ($data->tableData1 as $cpu => $entry) {
            if ($cpu == 'model') {
                continue;
            }
            if ($data->tableData1->{$cpu}->idle <= 10) {
                $color = 'danger';
            } elseif ($data->tableData1->{$cpu}->idle <= 25) {
                $color = 'warning';
            } elseif ($data->tableData1->{$cpu}->idle <= 50) {
                $color = 'primary';
            } else {
                $color = '';
            }

            $data->tableData1->{$cpu}->color = $color;
        }
        $data->badgeData->color = $color ? $color : 'primary';

        return $data;
    }

    public function getReport()
    {
        $data = (object) [
            'report' => $this->getReportInfo('report', 'Report', 'phosphor-list-checks-duotone'),
        ];

        // file_put_contents("/tmp/reportData.json", json_encode($data, JSON_PRETTY_PRINT));

        return $data;
    }

    public function getReportInfo($component, $description, $icon)
    {
        $compo_state = 'info';
        $data = (object) [
            'vid' => $this->vid,
            'did' => $this->did,
            'cid' => $this->cid,
            'component' => $component,
            'description' => $description,
            'badgeData' => (object) [
                'color' => $compo_state,
                'icon' => $icon,
                'chart' => null,
                'mark' => null,
                'mainTitle' => '',
                'subTitle' => '',
                'footerTitle' => '',
            ],
            'tableOrder1' => [],
            'tableHeaders1' => [],
            'tableData1' => $this->dtools->getAIStatusReport(),
            'fileBlade1' => 'theme::tools.report',
        ];

        return $data;
    }
}

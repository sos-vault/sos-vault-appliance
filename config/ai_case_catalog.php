<?php

/*
|--------------------------------------------------------------------------
| AI Case Data Catalog — the single source of truth for "what data exists"
|--------------------------------------------------------------------------
|
| Mil answers case questions from the parsed JSON dotfiles a sosreport (or,
| later, an OpenStack / Kubernetes capture) is distilled into. This catalog
| describes that data DECLARATIVELY so the model can be told what exists, what
| each field means, and how the files relate — instead of us hand-coding a
| keyword map per question we happen to anticipate.
|
| It is consumed by App\Services\Ai\CaseDataCatalog and drives both hybrid
| retrieval paths:
|   • the semantic single-shot selector (fallback), and
|   • the agentic fetch_case_data() tool (primary, cloud).
|
| Adding a new domain (OpenStack, Kubernetes, …) is a DATA change: register its
| sources here (and the generator that emits each file). No routing/prompt code
| changes. The integrity test (tests/Feature/Ai/CaseDataCatalogTest.php) keeps
| this catalog honest — every declared file must have a generator, and every
| join must reference a real source.
|
| Source schema (per entry, keyed by a stable source id):
|   file      string  Dotfile name under the case directory (the generator's output).
|   title     string  Short human label.
|   purpose   string  One paragraph: what the file holds and what it is the source of
|                     truth for. This is what the selector/model matches questions against.
|   shape     string  Top-level JSON type: 'object' or 'array'.
|   keyed_by  ?string When the object is a MAP keyed by a field (e.g. processes by PID),
|                     that field; null for a structured record or an array.
|   fields    array   field_name => human description (units, meaning). The data dictionary.
|   joins     array   list of { to: <source id>, on: <shared key>, note: <why correlate> }.
|                     The correlation graph — how to combine this file with others.
|   answers   array   Representative questions this source answers (retrieval signal + docs).
|
*/

return [

    // The domain this catalog describes. Future captures (openstack, kubernetes)
    // become sibling catalogs or additional 'domains' entries.
    'domain' => 'sosreport',

    'sources' => [

        'processes' => [
            'file' => '.processesData.json',
            'title' => 'Running processes (per-PID snapshot)',
            'purpose' => 'One row per process at capture time: identity, CPU and memory '
                .'usage, run state, thread and file-descriptor counts, and parentage. The '
                .'primary source for "what is running", "what is consuming CPU/memory", and '
                .'"which process owns a resource". A "tasks" entry summarises total/running/'
                .'sleeping/zombie counts.',
            'shape' => 'object',
            'keyed_by' => 'PID',
            'fields' => [
                'PID' => 'Process ID (the map key).',
                'PPID' => 'Parent process ID — follow to walk up the process tree.',
                'USER' => 'Owning user.',
                'Command' => 'Short command / executable name.',
                'CMD' => 'Full command line with arguments.',
                'STAT' => 'Process state: running, sleeping, disk-sleep (D), stopped, zombie.',
                '%CPU' => 'CPU utilisation percent at capture.',
                '%MEM' => 'Percent of physical RAM used.',
                'RSS' => 'Resident set size in bytes — actual physical RAM the process uses.',
                'VSZ' => 'Virtual memory size in bytes.',
                'SHR' => 'Shared memory (RssShmem) in bytes.',
                'threads' => 'Number of threads.',
                'fd-nr' => 'Open file-descriptor count (from /proc/PID/status FDSize).',
                'Max open files (files)' => 'Soft | hard open-files limit (RLIMIT_NOFILE).',
                'STIME' => 'Process start time.',
                'TTY' => 'Controlling terminal, or ? when none.',
                'WCHAN' => 'Kernel function the process is blocked in, if sleeping.',
            ],
            'joins' => [
                ['to' => 'network', 'on' => 'PID', 'note' => 'find the sockets/ports a process owns'],
            ],
            'answers' => [
                'what process is using the most CPU or memory',
                'how many open files (descriptors) does PID N have',
                'what is the parent process of PID N',
                'which processes are zombies or stuck in uninterruptible sleep',
                'how many processes / threads is the system running',
            ],
        ],

        'network' => [
            'file' => '.networkData.json',
            'title' => 'Network connections & listening sockets',
            'purpose' => 'One row per socket from netstat/ss: protocol, local and foreign '
                .'address:port, connection state, and the owning PID/program. The source of '
                .'truth for "what is listening on which port", "who is connected to host X", '
                .'and for mapping a port back to the process that owns it. Includes tcp, udp '
                .'and unix sockets.',
            'shape' => 'array',
            'keyed_by' => null,
            'fields' => [
                'Proto' => 'Protocol: tcp, udp, or unix.',
                'Local_Address' => 'Local address:port. For LISTEN rows, this is the listening port.',
                'Foreign_Address' => 'Remote address:port (0.0.0.0:* or ::* when listening).',
                'State' => 'Socket state: LISTEN, ESTABLISHED, TIME_WAIT, CLOSE_WAIT, … (N/A for udp/unix).',
                'PID' => 'Owning process ID — join to processes to profile the owner.',
                'Program_name' => 'Owning program name.',
                'User' => 'Socket owner (user).',
                'Recv-Q' => 'Receive-queue bytes — a persistently high value indicates the app is not reading.',
                'Send-Q' => 'Send-queue bytes — a persistently high value indicates the peer is not reading.',
                'Timer' => 'Kernel socket timer state (e.g. retransmit, keepalive).',
                'Inode' => 'Socket inode number.',
            ],
            'joins' => [
                ['to' => 'processes', 'on' => 'PID', 'note' => 'identify and resource-profile the owning process'],
                ['to' => 'sockstat', 'on' => 'protocol', 'note' => 'socket-count/memory pressure behind these connections'],
            ],
            'answers' => [
                'what processes are listening on which tcp/udp ports',
                'which process owns port N',
                'is anything connected to host or port X',
                'which sockets have a large receive/send backlog',
            ],
        ],

        'cpu' => [
            'file' => '.cpuData.json',
            'title' => 'CPU utilisation (per core)',
            'purpose' => 'Per-core CPU time breakdown (user/system/idle/iowait/…) as '
                .'percentages, plus the CPU model. The source for "how busy is the CPU", '
                .'"is the system I/O bound (high iowait)", and per-core imbalance. Keyed by '
                .'core id; a top-level "model" names the processor.',
            'shape' => 'object',
            'keyed_by' => 'cpu',
            'fields' => [
                'cpu' => 'Core id (the map key); an aggregate row represents all cores.',
                'user' => 'Percent of time in user space.',
                'nice' => 'Percent of time on niced user processes.',
                'system' => 'Percent of time in the kernel.',
                'idle' => 'Percent idle — low idle means the CPU is saturated.',
                'iowait' => 'Percent waiting on I/O — high iowait points at slow disk/storage.',
                'irq' => 'Percent servicing hardware interrupts.',
                'softirq' => 'Percent servicing soft interrupts (often network).',
                'model' => 'Processor model / vendor (top-level).',
            ],
            'joins' => [
                ['to' => 'processes', 'on' => '%CPU', 'note' => 'attribute CPU load to the heaviest processes (soft: by %CPU)'],
            ],
            'answers' => [
                'how busy is the CPU / is it saturated',
                'is the system I/O bound (high iowait)',
                'is load uneven across cores',
                'what processor does this host have',
            ],
        ],

        'memory' => [
            'file' => '.memoryData.json',
            'title' => 'Memory & swap usage',
            'purpose' => 'System RAM and swap totals and utilisation. The source for memory '
                .'pressure and swap thrashing. Values are nested as {value,…}; sizes are in '
                .'bytes and p* fields are percentages. Grouped under "memory" and "swap".',
            'shape' => 'object',
            'keyed_by' => null,
            'fields' => [
                'memory.total' => 'Total physical RAM (bytes).',
                'memory.used' => 'RAM in use (bytes).',
                'memory.pused' => 'Percent of RAM used — the key memory-pressure signal.',
                'memory.free' => 'Unused RAM (bytes).',
                'memory.available' => 'RAM available to new allocations without swapping (bytes).',
                'memory.buff/cache' => 'RAM used by kernel buffers/page cache (bytes, reclaimable).',
                'memory.shared' => 'Shared memory (bytes).',
                'swap.total' => 'Total swap (bytes).',
                'swap.used' => 'Swap in use (bytes) — sustained non-zero use indicates memory pressure.',
                'swap.pused' => 'Percent of swap used.',
            ],
            'joins' => [
                ['to' => 'processes', 'on' => 'RSS', 'note' => 'attribute RAM use to the heaviest processes (soft: by RSS/%MEM)'],
                ['to' => 'logs', 'on' => 'timestamp', 'note' => 'correlate pressure with OOM-killer events (soft: by time)'],
            ],
            'answers' => [
                'is the system low on memory / under memory pressure',
                'is it swapping',
                'how much RAM is actually available',
            ],
        ],

        'disks' => [
            'file' => '.disksData.json',
            'title' => 'Filesystems: space & inode usage',
            'purpose' => 'One row per mounted filesystem from df: device, size, used, '
                .'available, use%, mount point and type, plus inode counts and inode use%. '
                .'The source for "is a disk full", "is a filesystem out of inodes", and '
                .'mapping a path to its filesystem. Pseudo filesystems (proc, sysfs, tmpfs…) '
                .'are excluded.',
            'shape' => 'array',
            'keyed_by' => null,
            'fields' => [
                'Filesystem' => 'Backing device / source.',
                'Size' => 'Total capacity.',
                'Used' => 'Space used.',
                'Avail' => 'Space available.',
                'Use%' => 'Percent of space used — >85% is worth flagging.',
                'Mounted on' => 'Mount point (path).',
                'Type' => 'Filesystem type (xfs, ext4, …), when captured.',
                'Inodes' => 'Total inodes.',
                'IUsed' => 'Inodes used.',
                'IFree' => 'Inodes free.',
                'IUse%' => 'Percent of inodes used — a full inode table fails writes even with free space.',
            ],
            'joins' => [],
            'answers' => [
                'is any disk full or nearly full',
                'is a filesystem out of inodes',
                'what filesystem/type backs a mount point',
            ],
        ],

        'logs' => [
            'file' => '.logErrorsData.json',
            'title' => 'Log errors (error / critical / OOM)',
            'purpose' => 'Matched log lines containing error, critical or OOM, grouped by '
                .'source logfile. The timeline spine for root-cause: the messages carry '
                .'timestamps, so the failure moment can be located and correlated with '
                .'resource pressure and the owning unit/process. Keyed by logfile path; each '
                .'value is a list of matched lines.',
            'shape' => 'object',
            'keyed_by' => 'logfile path',
            'fields' => [
                '<logfile path>' => 'Map key: the log the lines came from (e.g. var/log/messages).',
                '[]' => 'List of matched log lines (each typically begins with a timestamp).',
            ],
            'joins' => [
                ['to' => 'systemd', 'on' => 'unit name', 'note' => 'tie an error to the failed service (soft: unit named in the message)'],
                ['to' => 'processes', 'on' => 'PID/name', 'note' => 'tie an error to the process that logged it (soft)'],
            ],
            'answers' => [
                'what errors or critical messages were logged',
                'was there an OOM-killer event and what did it kill',
                'when did the failure start (timeline)',
            ],
        ],

        'systemd' => [
            'file' => '.systemdData.json',
            'title' => 'systemd units',
            'purpose' => 'One row per systemd unit with its load/active/sub state and '
                .'description. The source of truth for "what service failed", "what is '
                .'enabled/running", and unit type breakdown. Under a top-level "systemd" list.',
            'shape' => 'object',
            'keyed_by' => null,
            'fields' => [
                'systemd[].unit' => 'Unit name (e.g. mysqld.service).',
                'systemd[].type' => 'Unit type: service, socket, device, mount, automount, …',
                'systemd[].loaded' => 'Load state (loaded / not-found / masked).',
                'systemd[].active' => 'Active state — "failed" marks a failed unit.',
                'systemd[].sub' => 'Fine-grained sub-state (running, exited, dead, …).',
                'systemd[].job' => 'Queued job for the unit, if any.',
                'systemd[].description' => 'Human description of the unit.',
            ],
            'joins' => [
                ['to' => 'processes', 'on' => 'unit/command', 'note' => 'find the process(es) a service runs (soft: service name ↔ Command)'],
            ],
            'answers' => [
                'what unit was marked failed by systemd',
                'is a given service loaded/active/running',
                'which services are enabled',
            ],
        ],

        'open_files' => [
            'file' => '.openFilesData.json',
            'title' => 'Open files per process (lsof)',
            'purpose' => 'Open files/handles per process from lsof, with a count and a '
                .'sample of filenames. The source for "how many/which files a process has '
                .'open" and file-descriptor leaks. Keyed by PID (join to processes).',
            'shape' => 'object',
            'keyed_by' => 'PID',
            'fields' => [
                'PID' => 'Owning process id (the map key).',
                'COMMAND' => 'Owning command name.',
                'USER' => 'Owning user.',
                'FILES' => 'Count of open files/handles for the process.',
                'LSOF_FILENAMES' => 'Newline-joined sample of open paths (capped ~200).',
                'TYPE' => 'Descriptor type (REG, DIR, IPv4, sock, …) on per-handle rows.',
            ],
            'joins' => [
                ['to' => 'processes', 'on' => 'PID', 'note' => 'cross-check fd-nr and resource-profile the owner'],
            ],
            'answers' => [
                'how many open files does PID N have',
                'which files/sockets does a process hold open',
                'is a process leaking file descriptors',
            ],
        ],

        'sockstat' => [
            'file' => '.sockstat.json',
            'title' => 'Socket memory statistics',
            'purpose' => 'Aggregate socket counts and memory per protocol (TCP/UDP/FRAG) '
                .'from /proc/net/sockstat. The source for socket-memory pressure, orphaned '
                .'and time-wait socket counts. Memory fields are in bytes.',
            'shape' => 'object',
            'keyed_by' => null,
            'fields' => [
                'TCP.inuse' => 'TCP sockets in use.',
                'TCP.orphan' => 'Orphaned TCP sockets.',
                'TCP.tw' => 'Sockets in TIME_WAIT.',
                'TCP.alloc' => 'Allocated TCP sockets.',
                'TCP.mem' => 'TCP socket memory in use (bytes).',
                'TCP.max_mem' => 'TCP socket memory ceiling (bytes).',
                'UDP.inuse' => 'UDP sockets in use.',
                'UDP.mem' => 'UDP socket memory in use (bytes).',
            ],
            'joins' => [
                ['to' => 'network', 'on' => 'protocol', 'note' => 'connect aggregate pressure to the individual sockets'],
            ],
            'answers' => [
                'is there socket-memory pressure',
                'how many sockets are in TIME_WAIT / orphaned',
            ],
        ],

        'tcpip_stats' => [
            'file' => '.tcpIpStatsData.json',
            'title' => 'TCP/IP stack counters',
            'purpose' => 'Kernel network-stack counters from nstat (retransmits, drops, '
                .'listen-queue overflows, errors, …), each tagged with a severity colour. '
                .'The source for stack-level problems: retransmission storms, listen '
                .'backlog drops, segment errors.',
            'shape' => 'object',
            'keyed_by' => null,
            'fields' => [
                '<counter>' => 'nstat counter name → value (e.g. TcpRetransSegs, TcpExtListenDrops).',
                'color' => 'Severity flag (primary/warning/danger) precomputed per counter.',
            ],
            'joins' => [],
            'answers' => [
                'are there TCP retransmissions or drops',
                'is the listen backlog overflowing',
                'are there segment/checksum errors',
            ],
        ],

        'nic' => [
            'file' => '.nicData.json',
            'title' => 'Network interfaces (links)',
            'purpose' => 'Per-interface link state and configuration: up/down, speed, MTU, '
                .'MAC and addresses. The source for "is a link down", speed/duplex and MTU '
                .'mismatches, and which IPs an interface holds.',
            'shape' => 'object',
            'keyed_by' => 'interface',
            'fields' => [
                'interface' => 'Interface name (the map key), e.g. eth0.',
                'state' => 'Link state (up/down).',
                'speed' => 'Negotiated link speed.',
                'mtu' => 'Maximum transmission unit.',
                'mac' => 'Hardware (MAC) address.',
                'addresses' => 'Assigned IP address(es).',
            ],
            'joins' => [],
            'answers' => [
                'is any network interface down',
                'what IP/MAC/MTU/speed does an interface have',
            ],
        ],

        'firewall' => [
            'file' => '.iptablesData.json',
            'title' => 'Firewall rules (iptables)',
            'purpose' => 'iptables chains with their default policy and rules. The source '
                .'for "what is the firewall policy", whether traffic to a port is allowed/'
                .'blocked, and NAT/forwarding rules.',
            'shape' => 'array',
            'keyed_by' => null,
            'fields' => [
                'title' => 'Chain name (e.g. "Chain INPUT").',
                'policy' => 'Default policy for the chain (ACCEPT/DROP).',
                'data' => 'The rules in the chain (target, proto, source, destination, ports).',
            ],
            'joins' => [],
            'answers' => [
                'what is the firewall default policy',
                'is traffic to port N allowed or blocked',
                'are there NAT/forwarding rules',
            ],
        ],

        'kernel_params' => [
            'file' => '.kparametersData.json',
            'title' => 'Kernel parameters (sysctl)',
            'purpose' => 'sysctl parameters with their value and a description. The source '
                .'for tunable kernel settings — networking, memory overcommit, file-handle '
                .'limits, etc.',
            'shape' => 'array',
            'keyed_by' => null,
            'fields' => [
                'Name' => 'sysctl key (e.g. vm.overcommit_memory).',
                'Value' => 'Configured value.',
                'Descr' => 'Human description of the parameter, when known.',
            ],
            'joins' => [],
            'answers' => [
                'what is a given sysctl set to',
                'how is memory overcommit / swappiness configured',
                'what are the network buffer / connection-tracking limits',
            ],
        ],

        'inventory' => [
            'file' => '.inventoryData.json',
            'title' => 'Hardware inventory (dmidecode)',
            'purpose' => 'Hardware inventory from dmidecode: system, BIOS, CPU, memory '
                .'modules, chassis, etc. The source for physical/virtual hardware details '
                .'and firmware versions.',
            'shape' => 'array',
            'keyed_by' => null,
            'fields' => [
                'type' => 'DMI type category (System, BIOS, Processor, Memory Device, …).',
                'name' => 'Entry name/title.',
                'data' => 'The decoded key/value lines for the entry.',
            ],
            'joins' => [],
            'answers' => [
                'what hardware / manufacturer / model is this',
                'is this physical or a VM',
                'what BIOS/firmware version is installed',
                'how much physical memory is installed and in which slots',
            ],
        ],

        'packages' => [
            'file' => '.packagesData.json',
            'title' => 'Installed packages',
            'purpose' => 'The installed-package list (rpm/dpkg) with install dates. The '
                .'source for "is package X installed and at what version". Each Name embeds '
                .'name-version-release.arch, so match on substring to find a package.',
            'shape' => 'array',
            'keyed_by' => null,
            'fields' => [
                'Name' => 'Full package identity: name-version-release.arch (e.g. openssl-1.1.1k-9.el8.x86_64).',
                'Date' => 'Install date.',
            ],
            'joins' => [],
            'answers' => [
                'is package X installed and at what version',
                'when was a package installed/updated',
            ],
        ],

        'host' => [
            'file' => '.hostData.json',
            'title' => 'Host overview',
            'purpose' => 'Consolidated host facts: hostname, uptime, load average, OS and '
                .'kernel, and headline resource figures. A good first-look summary and the '
                .'source for load average and uptime.',
            'shape' => 'object',
            'keyed_by' => null,
            'fields' => [
                'hostname' => 'System hostname.',
                'machineid' => 'The /etc/machine-id — stable host identity used by the fleet view.',
                'uptime' => 'How long the system has been up.',
                'load average' => '1/5/15-minute run-queue load — compare against core count.',
                'os version' => 'OS distribution and version.',
                'kernel' => 'Running kernel release.',
            ],
            'joins' => [
                ['to' => 'cpu', 'on' => 'core count', 'note' => 'interpret load average against the number of cores'],
            ],
            'answers' => [
                'what is the system load average / uptime',
                'what hostname / OS / kernel is this',
            ],
        ],

        'os_version' => [
            'file' => '.osVersion.json',
            'title' => 'OS release',
            'purpose' => 'Distribution identity from os-release / lsb_release: ID, version '
                .'and codename. The source for "what OS/version is this".',
            'shape' => 'object',
            'keyed_by' => null,
            'fields' => [
                'ID' => 'Distribution id (rhel, ubuntu, …).',
                'VERSION' => 'Distribution version.',
                'VERSION_CODENAME' => 'Release codename, when present.',
            ],
            'joins' => [],
            'answers' => [
                'what operating system and version is this',
            ],
        ],

        'kernel_version' => [
            'file' => '.kernelVersion.json',
            'title' => 'Kernel version (parsed)',
            'purpose' => 'The running kernel release broken into components. The source for '
                .'kernel version comparisons and flavour (e.g. el8, generic).',
            'shape' => 'object',
            'keyed_by' => null,
            'fields' => [
                'kernel' => 'Base kernel version.',
                'major' => 'Major component.',
                'minor' => 'Minor component.',
                'ABI' => 'ABI/patch number.',
                'flavour' => 'Distribution flavour/suffix.',
            ],
            'joins' => [],
            'answers' => [
                'what kernel version is running',
            ],
        ],

        'uname' => [
            'file' => '.uname.json',
            'title' => 'uname',
            'purpose' => 'Raw uname facts: os name, hostname, kernel release/version, arch '
                .'and the capture date. The source for architecture and the snapshot time.',
            'shape' => 'object',
            'keyed_by' => null,
            'fields' => [
                'os_name' => 'Operating system name (e.g. Linux).',
                'hostname' => 'Hostname.',
                'kernel_release' => 'Kernel release string.',
                'kernel_version' => 'Kernel build/version string.',
                'architecture' => 'Machine architecture (x86_64, aarch64, …).',
                'date' => 'Capture date/time reported by uname.',
            ],
            'joins' => [],
            'answers' => [
                'what architecture is this host',
                'when was the report captured',
            ],
        ],

        'sos_version' => [
            'file' => '.sosVersion.json',
            'title' => 'sos tool version',
            'purpose' => 'The version of the sos utility that produced the report (and its '
                .'pid). Useful for knowing which plugins/fields to expect.',
            'shape' => 'object',
            'keyed_by' => null,
            'fields' => [
                'sos_version' => 'Version of the sos/sosreport tool used.',
                'pid' => 'PID of the sos run.',
            ],
            'joins' => [],
            'answers' => [
                'what version of sos generated this report',
            ],
        ],

        'sos_index' => [
            'file' => '.sos.json',
            'title' => 'Report file index',
            'purpose' => 'Index of the files/paths contained in the sosreport. The source '
                .'for "does the report contain X" and locating a specific captured file.',
            'shape' => 'array',
            'keyed_by' => null,
            'fields' => [
                '[]' => 'Catalogued file paths within the report.',
            ],
            'joins' => [],
            'answers' => [
                'does the report include a given file or command output',
                'what was collected in this report',
            ],
        ],

    ],
];

<div class="space-y-6 text-sm">
    @php
        $isOpen     = $vault && strtoupper($vault->status) === 'OPEN';
        $isShared   = $vault && strtoupper($vault->shared_status) !== 'PRIVATE';

        $hasRealUsage = ! empty($dfUsage)
            && isset($dfUsage['Used'], $dfUsage['Size'], $dfUsage['Use%']);

        if ($hasRealUsage) {
            $usedDisplay  = $dfUsage['Used'];
            $sizeDisplay  = $dfUsage['Size'];
            $availDisplay = $dfUsage['Avail'] ?? '—';
            $usagePct     = (float) rtrim($dfUsage['Use%'], '%');
            $inodePct     = isset($dfUsage['IUse%']) ? (float) rtrim($dfUsage['IUse%'], '%') : null;
        } else {
            $planMb       = $vault ? round($vault->plan_size, 2) : 0;
            $usedDisplay  = null;
            $sizeDisplay  = $planMb . ' MB';
            $availDisplay = null;
            $usagePct     = null;
            $inodePct     = null;
        }

        $imageSizeDisplay = ($imageSize ?? null)
            ? number_format($imageSize / 1048576, 2) . ' MB'
            : '—';
    @endphp

    {{-- ── Identity ─────────────────────────────────────────────── --}}
    <div>
        <h3 class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-3">Identity</h3>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-3">

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">Status</dt>
                <dd class="mt-1">
                    @if($isOpen)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Open
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                            <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Closed
                        </span>
                    @endif
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">Sharing</dt>
                <dd class="mt-1">
                    @if($isShared)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-800">
                            {{ ucfirst(strtolower($vault->shared_status)) }}
                        </span>
                    @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">
                            Private
                        </span>
                    @endif
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">LUKS</dt>
                <dd class="mt-1">
                    @if($isLuksOpen ?? false)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Open
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800">
                            <span class="w-1.5 h-1.5 rounded-full bg-yellow-500"></span> Closed
                        </span>
                    @endif
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">Permissions</dt>
                <dd class="mt-1 font-mono text-gray-700">{{ $vault->perms ? '0'.$vault->perms : '—' }}</dd>
            </div>

        </dl>
    </div>

    {{-- ── Storage ──────────────────────────────────────────────── --}}
    <div>
        <h3 class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-3">Storage</h3>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-3">

            <div class="col-span-2">
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">Device / Image</dt>
                <dd class="mt-1 font-mono text-gray-700 break-all">{{ $device ?? '—' }}</dd>
            </div>

            <div class="col-span-2">
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">Mapper Name (user_vault)</dt>
                <dd class="mt-1 font-mono text-gray-700">{{ $vault->user_vault ?? '—' }}</dd>
            </div>

            @if($isOpen && ($mountPoint ?? null))
            <div class="col-span-2">
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">Mount Point</dt>
                <dd class="mt-1 font-mono text-gray-700 break-all">{{ $mountPoint }}</dd>
            </div>
            @endif

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">Image Size (on disk)</dt>
                <dd class="mt-1 font-mono text-gray-700">{{ $imageSizeDisplay }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">DB Size</dt>
                <dd class="mt-1 font-mono text-gray-700">{{ ($vault->current_size ?: $vault->plan_size) ? ($vault->current_size ?: $vault->plan_size).' MB' : '—' }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">FS Used</dt>
                <dd class="mt-1 font-mono text-gray-700">{{ $usedDisplay ?? '—' }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">FS Available</dt>
                <dd class="mt-1 font-mono text-gray-700">{{ $availDisplay ?? '—' }}</dd>
            </div>

            <div class="col-span-2">
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">Block Usage</dt>
                <dd class="mt-1">
                    @if($usagePct !== null)
                        <div class="flex items-center gap-3">
                            <div class="flex-1 bg-gray-200 rounded-full h-2">
                                <div class="h-2 rounded-full {{ $usagePct > 80 ? 'bg-red-500' : 'bg-green-500' }}"
                                     style="width: {{ min($usagePct, 100) }}%"></div>
                            </div>
                            <span class="text-xs font-semibold text-gray-700 w-12 text-right">{{ $usagePct }}%</span>
                        </div>
                    @else
                        <span class="text-gray-400 text-xs italic">
                            {{ $isOpen ? 'Unable to retrieve usage' : 'Open vault to see usage' }}
                        </span>
                    @endif
                </dd>
            </div>

            @if($inodePct !== null)
            <div class="col-span-2">
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">Inode Usage</dt>
                <dd class="mt-1">
                    <div class="flex items-center gap-3">
                        <div class="flex-1 bg-gray-200 rounded-full h-2">
                            <div class="h-2 rounded-full {{ $inodePct > 80 ? 'bg-red-500' : 'bg-blue-500' }}"
                                 style="width: {{ min($inodePct, 100) }}%"></div>
                        </div>
                        <span class="text-xs font-semibold text-gray-700 w-12 text-right">{{ $inodePct }}%</span>
                    </div>
                </dd>
            </div>
            @endif

        </dl>
    </div>

    {{-- ── Timeline ─────────────────────────────────────────────── --}}
    <div>
        <h3 class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-3">Timeline</h3>
        <dl class="grid grid-cols-3 gap-x-6 gap-y-3">

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">Created</dt>
                <dd class="mt-1 text-gray-700">
                    {{ $vault->created_at ? \Carbon\Carbon::parse($vault->created_at)->format('Y-m-d H:i') : '—' }}
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">Last Opened</dt>
                <dd class="mt-1 text-gray-700">
                    {{ $vault->last_open ? \Carbon\Carbon::parse($vault->last_open)->format('Y-m-d H:i') : '—' }}
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase tracking-wide">Last Closed</dt>
                <dd class="mt-1 text-gray-700">
                    {{ $vault->last_close ? \Carbon\Carbon::parse($vault->last_close)->format('Y-m-d H:i') : '—' }}
                </dd>
            </div>

        </dl>
    </div>

</div>

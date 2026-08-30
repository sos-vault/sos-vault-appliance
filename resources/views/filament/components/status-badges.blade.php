<div
    x-data="{
        logStart: '',
        logEnd: '',
        get linesText() {
            const cur = $wire.currentLines;
            const total = $wire.totalLines;
            if ($wire.isChunked && cur > 0) {
                return 'LINES: ' + Number(cur).toLocaleString() + ' / ' + Number(total).toLocaleString();
            }
            return 'LINES: ' + Number(total).toLocaleString();
        },
        get sharedText() {
            if ($wire.isShared) {
                const expire = $wire.shareExpire;
                return expire ? 'SHARED (until ' + expire + ')' : 'SHARED';
            }
            return 'PRIVATE';
        },
        get lockedText() {
            return $wire.isLocked ? 'LOCKED' : 'UNLOCKED';
        }
    }"
    @log-range-updated.window="logStart = $event.detail.logStart; logEnd = $event.detail.logEnd"
    class="flex flex-wrap items-center gap-1.5 py-1"

>
    {{-- LINES badge (warning) --}}
    <span
        class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 min-h-6 py-0.5 whitespace-nowrap bg-warning-50 text-warning-700 ring-warning-600/10 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/20"
        x-text="linesText"
    ></span>

    {{-- LOG START badge (warning, mono) --}}
    <template x-if="logStart">
        <span
            class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 min-h-6 py-0.5 font-mono whitespace-nowrap bg-warning-50 text-warning-700 ring-warning-600/10 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/20"
            x-text="'LOG START: ' + logStart"
        ></span>
    </template>

    {{-- LOG END badge (warning, mono) --}}
    <template x-if="logEnd">
        <span
            class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 min-h-6 py-0.5 font-mono whitespace-nowrap bg-warning-50 text-warning-700 ring-warning-600/10 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/20"
            x-text="'LOG END: ' + logEnd"
        ></span>
    </template>

    {{-- PRIVATE / SHARED badge --}}
    <span
        class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 min-h-6 py-0.5 whitespace-nowrap"
        :class="$wire.isShared
            ? 'bg-warning-50 text-warning-700 ring-warning-600/10 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/20'
            : 'bg-primary-50 text-primary-700 ring-primary-600/10 dark:bg-primary-400/10 dark:text-primary-400 dark:ring-primary-400/20'"
        x-text="sharedText"
    ></span>

    {{-- LOCKED / UNLOCKED badge --}}
    <span
        class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 min-h-6 py-0.5 whitespace-nowrap"
        :class="!$wire.isShared
            ? 'bg-primary-50 text-primary-700 ring-primary-600/10 dark:bg-primary-400/10 dark:text-primary-400 dark:ring-primary-400/20'
            : ($wire.isLocked
                ? 'bg-warning-50 text-warning-700 ring-warning-600/10 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/20'
                : 'bg-danger-50 text-danger-700 ring-danger-600/10 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/20')"
        x-text="lockedText"
    ></span>

    {{-- OWNER badge (primary) --}}
    <span
        class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 min-h-6 py-0.5 whitespace-nowrap bg-primary-50 text-primary-700 ring-primary-600/10 dark:bg-primary-400/10 dark:text-primary-400 dark:ring-primary-400/20"
        x-text="'OWNER: ' + $wire.ownerName"
    ></span>

</div>

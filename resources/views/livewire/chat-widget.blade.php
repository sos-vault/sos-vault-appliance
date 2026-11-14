<div
    x-data="{
        width: 384,
        height: 580,
        minW: 320,
        minH: 380,
        scrollToBottom() {
            this.$nextTick(() => {
                const el = this.$refs.messages;
                if (el) el.scrollTop = el.scrollHeight;
            });
        },
        startResize(e) {
            e.preventDefault();
            const sx = e.clientX, sy = e.clientY, sw = this.width, sh = this.height;
            const maxW = Math.min(window.innerWidth - 32, 900);
            const maxH = window.innerHeight - 120;
            const move = (ev) => {
                // Anchored bottom-right, so dragging the top-left handle up/left grows the panel.
                this.width = Math.max(this.minW, Math.min(maxW, sw + (sx - ev.clientX)));
                this.height = Math.max(this.minH, Math.min(maxH, sh + (sy - ev.clientY)));
            };
            const up = () => {
                localStorage.setItem('mil_chat_w', Math.round(this.width));
                localStorage.setItem('mil_chat_h', Math.round(this.height));
                document.body.style.userSelect = '';
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
            };
            document.body.style.userSelect = 'none';
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },
        caseChannel: null,
        initCaseSync() {
            // Keep the pop-out window in step with the case the user opens in the
            // main app. BroadcastChannel doesn't echo to the sender, so the app
            // window broadcasts and the detached window adopts — no loop.
            if (typeof BroadcastChannel === 'undefined') return;
            this.caseChannel = new BroadcastChannel('mil-open-case');
            this.caseChannel.onmessage = (e) => {
                if (e.data && e.data.did && e.data.cid) {
                    $wire.setCase(e.data.did, e.data.cid);
                }
            };
        },
        broadcastCase(did, cid) {
            if (this.caseChannel && did && cid) {
                this.caseChannel.postMessage({ did, cid });
            }
        },
        openDetached(url) {
            window.open(url, 'milDetached', 'popup=yes,width=440,height=720');
            $wire.set('isOpen', false);
        }
    }"
    x-init="
        width = parseInt(localStorage.getItem('mil_chat_w')) || width;
        height = parseInt(localStorage.getItem('mil_chat_h')) || height;
        initCaseSync();
        $watch('$wire.messages', () => scrollToBottom());
        @if($detached)
            // Logout broadcasts a 'close' localStorage signal (see sosViewer.closeTabs);
            // the detached Mil pop-out is a separate window, so close it too rather than
            // leaving an orphaned chat open after the session ends.
            window.addEventListener('storage', (e) => { if (e.key === 'close') window.close(); });
        @endif
    "
    @chat-set-case.window="$wire.setCase($event.detail.did, $event.detail.cid); broadcastCase($event.detail.did, $event.detail.cid)"
>
    {{-- Floating trigger button (hidden in the detached pop-out window) --}}
    @unless($detached)
    <button
        type="button"
        wire:click="toggle"
        class="fixed bottom-6 right-6 z-50 flex h-14 w-14 items-center justify-center rounded-full bg-primary-600 text-white shadow-lg ring-2 ring-white/20 transition-transform duration-200 hover:scale-110 hover:bg-primary-700 focus:outline-none dark:ring-zinc-800"
        aria-label="Open Mil AI Assistant"
    >
        <template x-if="!$wire.isOpen">
            <x-phosphor-chat-circle-dots-duotone class="h-7 w-7" />
        </template>
        <template x-if="$wire.isOpen">
            <x-phosphor-x class="h-6 w-6" />
        </template>
        {{-- Thinking pulse indicator --}}
        <template x-if="$wire.thinking">
            <span class="absolute -top-1 -right-1 flex h-4 w-4">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-warning-400 opacity-75"></span>
                <span class="relative inline-flex h-4 w-4 rounded-full bg-warning-500"></span>
            </span>
        </template>
    </button>
    @endunless

    {{-- Chat panel. Floating card in the app; full-window in the detached pop-out. --}}
    <div
        @if($detached)
            class="fixed inset-0 z-40 flex flex-col overflow-hidden bg-white dark:bg-zinc-900"
        @else
            x-show="$wire.isOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-4 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
            x-transition:leave-end="opacity-0 translate-y-4 scale-95"
            class="fixed bottom-24 right-6 z-40 flex flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-700 dark:bg-zinc-900"
            :style="`width: ${width}px; height: ${height}px`"
        @endif
    >
        @unless($detached)
        {{-- Resize handle: top-left corner (panel is pinned bottom-right, so it grows up/left) --}}
        <div
            @pointerdown="startResize($event)"
            class="absolute left-0 top-0 z-50 flex h-5 w-5 cursor-nwse-resize select-none items-center justify-center text-zinc-300 hover:text-zinc-500 dark:text-zinc-600 dark:hover:text-zinc-400"
            title="Drag to resize"
        >
            <span class="text-[11px] leading-none">⤡</span>
        </div>
        @endunless

        {{-- Header --}}
        <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex items-center gap-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-600 text-white">
                    <x-phosphor-robot-duotone class="h-5 w-5" />
                </span>
                <div>
                    <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Mil · AI Assistant</p>
                    @if($did)
                        <p class="text-xs text-success-600 dark:text-success-400">
                            <x-phosphor-folder-open-duotone class="inline h-3 w-3" />
                            Case {{ $caseLabel ?: '#'.$cid }} active
                        </p>
                    @else
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">General help</p>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-1">
                @unless($detached)
                    {{-- Detach: pop Mil out into its own chromeless window so the user
                         can chat while using the app tools in the main window. --}}
                    <button
                        type="button"
                        @click="openDetached(@js(route('mil.detached')))"
                        class="rounded p-1.5 text-zinc-400 hover:bg-zinc-200 hover:text-zinc-600 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                        title="Open in a separate window"
                    >
                        <x-phosphor-arrow-square-out-duotone class="h-4 w-4" />
                    </button>
                @endunless
                <button
                    type="button"
                    wire:click="clearHistory"
                    class="rounded p-1.5 text-zinc-400 hover:bg-zinc-200 hover:text-zinc-600 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                    title="Clear conversation"
                >
                    <x-phosphor-trash-simple class="h-4 w-4" />
                </button>
            </div>
        </div>

        {{-- Messages area --}}
        <div
            x-ref="messages"
            class="flex flex-1 flex-col gap-3 overflow-y-auto px-4 py-4"
        >
            @if($assistantNotice !== '')
                <div class="rounded-xl border border-warning-300 bg-warning-50 px-3 py-2 text-xs text-warning-800 dark:border-warning-700/60 dark:bg-warning-950/40 dark:text-warning-200">
                    <div class="prose prose-xs dark:prose-invert max-w-none prose-p:my-0">
                        {!! \Illuminate\Support\Str::markdown($assistantNotice) !!}
                    </div>
                </div>
            @endif

            @if(count($messages) === 0)
                <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center text-zinc-400 dark:text-zinc-600">
                    <x-phosphor-chat-teardrop-dots-duotone class="h-12 w-12" />
                    <p class="text-sm">{{ __('vault.chat_placeholder') }}</p>
                    @if($did)
                        <p class="text-xs text-success-600">Case metadata is loaded and ready.</p>
                    @endif
                    <div class="mt-1 w-full max-w-[16rem] space-y-1.5 text-left">
                        <p class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">Try asking:</p>
                        @foreach ([
                            '/sosvault how do I upload a sosreport?',
                            '/sos how do I limit log size to 10MB?',
                            '/linux what does load average mean?',
                            '/case how many processes are running?',
                        ] as $example)
                            <button
                                type="button"
                                wire:click="$set('input', @js($example))"
                                class="block w-full rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 py-1.5 text-left text-[11px] leading-snug text-zinc-600 hover:border-primary-300 hover:text-primary-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:text-primary-400"
                            >{{ $example }}</button>
                        @endforeach
                        <p class="text-[10px] text-zinc-400 dark:text-zinc-500">
                            Tip: start with <span class="font-medium">/sosvault</span>,
                            <span class="font-medium">/sos</span>,
                            <span class="font-medium">/linux</span> or
                            <span class="font-medium">/case</span> to focus my answer.
                        </p>
                    </div>
                </div>
            @endif

            @foreach($messages as $message)
                <div @class([
                    'flex',
                    'justify-end' => $message['role'] === 'user',
                    'justify-start' => $message['role'] === 'assistant',
                ])>
                    <div @class([
                        'max-w-[85%] rounded-2xl px-3 py-2 text-sm',
                        'rounded-br-sm bg-primary-600 text-zinc-100 [&_p]:!text-zinc-100' => $message['role'] === 'user',
                        'rounded-bl-sm bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100' => $message['role'] === 'assistant',
                    ])>
                        @if($message['role'] === 'assistant')
                            <div class="prose prose-sm dark:prose-invert max-w-none prose-p:my-1 prose-pre:my-1 prose-ul:my-1 prose-li:my-0">
                                {{-- e() escapes raw HTML; allow_unsafe_links strips javascript:/data: link
                                     schemes so a prompt-injected model reply can't emit a clickable XSS link. --}}
                                {!! \Illuminate\Support\Str::markdown(e($message['content']), ['allow_unsafe_links' => false]) !!}
                            </div>
                            <div class="mt-1.5 flex items-center gap-0.5" x-data="{ copied: false }">
                                {{-- Copy response to clipboard --}}
                                <button
                                    type="button"
                                    @click="navigator.clipboard.writeText(@js($message['content'])).then(() => { copied = true; setTimeout(() => copied = false, 1500); })"
                                    class="rounded p-1 text-zinc-400 hover:bg-zinc-200 hover:text-zinc-600 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                                    :title="copied ? 'Copied' : 'Copy response'"
                                >
                                    <span x-show="!copied" class="flex"><x-phosphor-copy-duotone class="h-3.5 w-3.5" /></span>
                                    <span x-show="copied" class="flex" style="display:none"><x-phosphor-check-duotone class="h-3.5 w-3.5 text-success-500" /></span>
                                </button>

                                @if(!empty($message['sysevent_id']))
                                    {{-- Thumbs up / down → rates the linked usage event --}}
                                    <button
                                        type="button"
                                        wire:click="rateMessage({{ $loop->index }}, 'GOOD')"
                                        @class([
                                            'rounded p-1 hover:bg-zinc-200 dark:hover:bg-zinc-700',
                                            'text-success-600 dark:text-success-400' => ($message['quality'] ?? null) === 'GOOD',
                                            'text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200' => ($message['quality'] ?? null) !== 'GOOD',
                                        ])
                                        title="Good response"
                                    >
                                        <x-phosphor-thumbs-up-duotone class="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="rateMessage({{ $loop->index }}, 'BAD')"
                                        @class([
                                            'rounded p-1 hover:bg-zinc-200 dark:hover:bg-zinc-700',
                                            'text-danger-600 dark:text-danger-400' => ($message['quality'] ?? null) === 'BAD',
                                            'text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200' => ($message['quality'] ?? null) !== 'BAD',
                                        ])
                                        title="Bad response"
                                    >
                                        <x-phosphor-thumbs-down-duotone class="h-3.5 w-3.5" />
                                    </button>
                                @endif

                                <span class="ml-auto text-[10px] opacity-60">{{ $message['time'] }}</span>
                            </div>
                        @else
                            <p>{{ $message['content'] }}</p>
                            <p class="mt-1 text-right text-[10px] opacity-60">{{ $message['time'] }}</p>
                        @endif
                    </div>
                </div>
            @endforeach

            {{-- Typing indicator --}}
            <template x-if="$wire.thinking">
                <div class="flex justify-start">
                    <div class="flex items-center gap-1 rounded-2xl rounded-bl-sm bg-zinc-100 px-4 py-3 dark:bg-zinc-800">
                        <span class="h-2 w-2 animate-bounce rounded-full bg-zinc-400 [animation-delay:-0.3s]"></span>
                        <span class="h-2 w-2 animate-bounce rounded-full bg-zinc-400 [animation-delay:-0.15s]"></span>
                        <span class="h-2 w-2 animate-bounce rounded-full bg-zinc-400"></span>
                    </div>
                </div>
            </template>
        </div>

        {{-- Input area --}}
        <div class="border-t border-zinc-200 bg-zinc-50 px-3 py-3 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex items-end gap-2">
                <div class="relative flex-1">
                    <textarea
                        x-ref="milInput"
                        wire:model="input"
                        placeholder="Ask Mil anything…"
                        rows="2"
                        x-on:keydown.enter.prevent="if (!$event.shiftKey && !$wire.thinking) $wire.send()"
                        class="w-full resize-none rounded-xl border border-zinc-300 bg-white px-3 py-2 pr-8 text-sm text-zinc-900 placeholder-zinc-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder-zinc-500"
                    ></textarea>
                    {{-- Clear the entry without holding backspace --}}
                    <button
                        type="button"
                        x-show="$wire.input && $wire.input.length > 0"
                        x-cloak
                        @click="$wire.set('input', ''); $refs.milInput.focus()"
                        class="absolute right-1.5 top-1.5 flex h-5 w-5 items-center justify-center rounded-full text-zinc-400 hover:bg-zinc-200 hover:text-zinc-600 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                        title="Clear"
                        aria-label="Clear"
                    >
                        <x-phosphor-x class="h-3 w-3" />
                    </button>
                </div>
                <button
                    type="button"
                    wire:click="send"
                    wire:loading.attr="disabled"
                    wire:target="send"
                    class="relative flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-primary-600 text-white transition-opacity hover:bg-primary-700 disabled:opacity-40"
                >
                    <span wire:loading.remove wire:target="send" class="flex"><x-phosphor-paper-plane-tilt class="h-4 w-4" /></span>
                    <span wire:loading wire:target="send" class="flex" style="display:none"><x-phosphor-spinner-gap class="h-4 w-4 animate-spin" /></span>
                </button>
            </div>
            <div class="mt-1.5 text-[10px] text-zinc-400 dark:text-zinc-600">
                <span wire:loading wire:target="send" class="font-medium text-warning-500" style="display:none">
                    <x-phosphor-spinner-gap class="inline h-3 w-3 animate-spin" />
                    Mil is thinking…
                </span>
                <span wire:loading.remove wire:target="send">Enter to send · Shift+Enter = newline · focus with <span class="font-medium">/case</span> / <span class="font-medium">/linux</span> / <span class="font-medium">/sos</span> / <span class="font-medium">/sosvault</span> · <span class="font-medium">/help</span></span>
            </div>
        </div>
    </div>
</div>

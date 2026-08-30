<div x-data="{ toggleSidebarColapsed: false, sidebarOpen: false  }"  @open-sidebar.window="sidebarOpen = true" @colapse-sidebar.window="toggleSidebarColapsed = !toggleSidebarColapsed"
    x-init="

        $watch('sidebarOpen', function(value){
            if(value){ document.body.classList.add('overflow-hidden'); } else { document.body.classList.remove('overflow-hidden'); }
        });

        $watch('toggleSidebarColapsed', function(flag){
            //initialize just in case
            const persistentSbColapsed = localStorage.getItem('sidebarColapsed');
            if(persistentSbColapsed  === undefined){
                localStorage.setItem('sidebarColapsed', false);
                return;
            }
            toggleColapsed(flag);
        });

        toggleColapsed('true', 1);

        function toggleColapsed(flag, stored = null) {
            if(stored === null) {
                localStorage.setItem('sidebarColapsed', flag);
            }

            let collapsed = (localStorage.getItem('sidebarColapsed') === 'true');

            window.dispatchEvent(
                new CustomEvent('sidebar-toggled', { detail: { collapsed: true } })
            );

            const mainApp = document.getElementById('mainApp');
            const sidebar = document.getElementById('sidebar');
            const elements = sidebar.getElementsByClassName('sbcolapse');

            [].forEach.call(elements, item => {
                const classA = collapsed ? 'flex' : 'hidden';
                const classB = collapsed ? 'hidden' : 'flex';
                if(!item.classList.contains(classA)) {
                    item.classList.add(classA, classB);
                }
                item.classList.replace(classA, classB);
            });

            const classA = collapsed ? 'w-64' : 'w-24';
            const classB = collapsed ? 'w-24' : 'w-64';
            sidebar.classList.replace(classA, classB);

            const classC = collapsed ? 'rotate-0' : 'rotate-180';
            const classD = collapsed ? 'rotate-180' : 'rotate-0';
            document.getElementById('colapser').classList.replace(classC, classD);

            const classE = collapsed ? 'lg:pl-64' : 'lg:pl-24';
            const classF = collapsed ? 'lg:pl-24' : 'lg:pl-64';
            mainApp.classList.replace(classE, classF);
        }

    "
    class="relative z-50 w-screen md:w-auto" x-cloak>

    {{-- Backdrop for mobile --}}
    <div x-show="sidebarOpen" @click="sidebarOpen=false" class="fixed top-0 right-0 z-50 w-screen h-screen duration-300 ease-out bg-black/20 dark:bg-white/10"></div>


    {{-- Sidebar --}}
    <div :class="{ '-translate-x-full': !sidebarOpen }" id=sidebar
        class="fixed top-0 left-0 flex items-stretch -translate-x-full overflow-hidden lg:translate-x-0 z-50 h-dvh md:h-screen transition-[width,transform] duration-150 ease-out bg-zinc-50 dark:bg-zinc-900 w-64 group @if(config('wave.dev_bar')){{ 'pb-10' }}@endif">


        {{-- Navigation bar for mobile --}}
        <div class="flex flex-col justify-between w-full overflow-auto md:h-full h-svh pt-4 pb-2.5">
            <div class="relative flex flex-col">

                <div class="flex justify-between">

                    <button x-on:click="sidebarOpen=false" class="flex items-center justify-center shrink-0 p-0 w-10 h-10 ml-4 rounded-md lg:hidden text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200 dark:hover:bg-zinc-700/70 hover:bg-gray-200/70">
                        <x-phosphor-x-bold class="w-6 h-6" />
                    </button>

                    <div class="sbcolapse flex stretch"></div>

                    <button x-on:click="window.dispatchEvent(new CustomEvent('colapse-sidebar'))" class="hidden items-center justify-center shrink-0 p-0 w-10 h-10 mr-4 ml-4 rounded-md lg:flex md:hidden sm:hidden xs:hidden text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200 dark:hover:bg-zinc-700/70 hover:bg-gray-200/70">
                        <x-phosphor-caret-left-bold id=colapser class="rotate-0 w-4 h-4" />
                    </button>

                </div>

                {{-- sos-vault logo --}}
                <div class="flex flex-row justify-start items-center mb-2">
                    <x-logo-icon x-data="{tooltip: 'sos-vault v2.0.0', get getTooltip() {const sdc = localStorage.getItem('sidebarColapsed'); return sdc ? this.tooltip : '' }}" x-tooltip.placement.right="getTooltip" class="ml-5 w-10 h-10" />
                    <x-logo-typography class="sbcolapse w-auto h-6 mt-1" />
                    <div class="sbcolapse fixed right-14 top-12 text-sm">v{{ setting('site.app_version') }}</div>
                </div>

                <div class="flex flex-col justify-start items-center px-4 space-y-1.5 w-full h-full text-slate-600 dark:text-zinc-300">

                    <x-app.sidebar-link href="/dashboard" icon="phosphor-house-duotone" :active="Request::is('dashboard')">{{ __('nav.nav_dashboard') }}</x-app.sidebar-link>

                    <x-app.sidebar-link href="/upload" icon="phosphor-cloud-arrow-up-duotone" :active="Request::is('upload')">{{ __('nav.nav_upload') }}</x-app.sidebar-link>

                    <x-app.sidebar-link href="/cases" icon="phosphor-ticket-duotone" :active="Request::is('cases')">{{ __('nav.nav_cases') }}</x-app.sidebar-link>

                    <x-app.sidebar-link href="/fleet" icon="phosphor-computer-tower-duotone" :active="Request::is('fleet') || Request::is('fleet/*')">{{ __('nav.nav_fleet') }}</x-app.sidebar-link>

                    <x-app.sidebar-link href="/vault" icon="phosphor-folders-duotone" :active="Request::is('vault')">{{ __('nav.nav_vault') }}</x-app.sidebar-link>

                    <x-app.sidebar-link href="/sosbrowser" :ajax="false" onclick="window.openSosBrowser(event)" icon="phosphor-tree-view-duotone" :active="Request::is('sosbrowser')">{{ __('nav.nav_browse') }}</x-app.sidebar-link>

                    <x-app.sidebar-link href="/reports" icon="phosphor-list-checks-duotone" :active="Request::is('reports')">{{ __('nav.nav_reports') }}</x-app.sidebar-link>

                </div>
            </div>

            <div class="relative px-2.5 space-y-1.5 text-slate-600 dark:text-zinc-300">

                <x-app.sidebar-link href="{{ route('notifications') }}" icon="phosphor-bell-duotone" :active="Request::is('notifications')" usage="notifications">{{ __('nav.nav_notifications') }}</x-app.sidebar-link>

                <x-app.sidebar-link href="{{ route('announcements') }}" icon="phosphor-megaphone-duotone" :active="Request::is('announcements')" usage="announcements">{{ __('nav.nav_announcements') }}</x-app.sidebar-link>

                @if (! isAppliance())
                <button
                    type="button"
                    @click="window.dispatchEvent(new CustomEvent('open-feedback'))"
                    class="text-sm font-medium border-transparent transition-colors border rounded-lg w-full h-auto hover:bg-zinc-100 dark:hover:bg-zinc-800/60 hover:text-zinc-900 dark:hover:text-zinc-100 relative flex justify-start items-center space-x-2 px-2.5 py-2 overflow-hidden"
                >
                    <x-phosphor-chat-teardrop-text-duotone
                        x-data="{tooltip: '{{ __('nav.nav_feedback') }}', get getTooltip() {const sdc = localStorage.getItem('sidebarColapsed'); return sdc ? this.tooltip : '' }}"
                        x-tooltip.placement.right="getTooltip"
                        class="shrink-0 w-6 h-6 text-zinc-400"
                    />
                    <span class="sbcolapse shrink-0 ease-out duration-50">{{ __('nav.nav_feedback') }}</span>
                </button>
                @endif

                <x-app.sidebar-link href="{{ route('blog') }}" target="_blank" icon="phosphor-book-bookmark-duotone" :active="Request::is('blog')" usage="blog">{{ __('nav.nav_documentation') }}</x-app.sidebar-link>

                <x-app.sidebar-link :href="route('changelogs')" icon="phosphor-book-open-text-duotone" :active="Request::is('changelog') || Request::is('changelog/*')">{{ __('nav.nav_changelog') }}</x-app.sidebar-link>

                <div x-show="sidebarTip" x-data="{ sidebarTip: $persist(false) }" class="px-1 py-3" x-collapse x-cloak>
                    <div class="relative w-full px-4 py-3 space-y-1 border rounded-lg bg-zinc-50 text-zinc-700 dark:text-zinc-100 dark:bg-zinc-800 border-zinc-200/60 dark:border-zinc-700">
                        <button @click="sidebarTip=false" class="absolute top-0 right-0 z-50 p-1.5 mt-2.5 mr-2.5 rounded-full opacity-80 cursor-pointer hover:opacity-100 hover:bg-zinc-100 hover:dark:bg-zinc-700 hover:dark:text-zinc-300 text-zinc-500 dark:text-zinc-400">
                            <x-phosphor-x-bold class="w-3 h-3" />
                        </button>
                        <h5 class="pb-1 text-sm font-bold -translate-y-0.5">{{ __('nav.sidebar_tip_title') }}</h5>
                        <p class="block pb-1 text-xs opacity-80 text-balance">{{ __('nav.sidebar_tip_body') }}</p>
                    </div>
                </div>

                <div class="w-full h-px my-2 bg-slate-100 dark:bg-zinc-700"></div>
                <x-app.user-menu />
            </div>
        </div>
    </div>
</div>
<script>
    setTimeout( () => {
        window.sosViewer.isImpersonating = {{ app('impersonate')->isImpersonating() ? 'true' : 'false' }};
    }, 1500);
    setTimeout( () => { window.sosViewer.vaultMonitor('{{ csrf_token() }}') }, 10000);
</script>


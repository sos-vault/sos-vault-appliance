<div
    x-data="{
        theme: 'light',
        toggle() {
            if(this.theme == 'dark'){
                this.theme = 'light';
                localStorage.setItem('theme', 'light');
            }else{
                this.theme = 'dark';
                localStorage.setItem('theme', 'dark');
            }
            window.dispatchEvent(new CustomEvent('themeChange'));
        }
    }"
    x-init="
        if(localStorage.getItem('theme')){
            theme = localStorage.getItem('theme');
        }
        if(theme=='system'){
            theme =  'light';
        }
        if(document.documentElement.classList.contains('dark')){
            theme='dark';
        }

        if(theme=='dark'){
            if(!document.documentElement.classList.contains('dark')){
                document.documentElement.classList.add('dark');
            }
            if(!document.querySelector('html').classList.contains('dark')){
                document.querySelector('html').classList.add('dark');
            }
        }

        $watch('theme', function(value){
            if(value == 'dark'){
                document.documentElement.classList.add('dark');
        } else {
                document.documentElement.classList.remove('dark');
            }
        })
    "
    x-on:click="toggle()"
    class="flex items-center px-1 py-2 text-xs rounded-md cursor-pointer select-none hover:bg-zinc-100 dark:hover:bg-zinc-800"
>

    <input type="hidden" name="toggleDarkMode" :value="theme">

    <button
        x-ref="toggle"
        type="button"
        role="switch"
        :aria-checked="theme == 'dark'"
        :aria-labelledby="$id('toggle-label')"
        :class="(theme == 'dark') ? 'bg-zinc-700' : 'bg-slate-300'"
        class="relative inline-flex shrink-0 py-1 ml-1 transition rounded-full w-7 focus:ring-0"
        x-data="{tooltip: theme == 'dark' ? 'Light Mode' : 'Dark Mode', get getTooltip() {const sdc = localStorage.getItem('sidebarColapsed'); return sdc ? this.tooltip : '' }}" x-tooltip.placement.right="getTooltip"
    >
        <span
            :class="(theme == 'dark') ? 'translate-x-[13px]' : 'translate-x-1'"
            class="w-3 h-3 transition bg-white rounded-full shadow-md focus:outline-none"
            aria-hidden="true"
        ></span>
    </button>

    <label
        :id="$id('toggle-label')"
        :class="{ 'text-zinc-600' : theme == 'light' || theme == null, 'text-zinc-300' : theme == 'dark'  }"
        class="shrink-0 ml-1.5 font-medium cursor-pointer"
    >
        <span x-show="(theme == 'light' || theme == null)" class="sbcolapse" >Dark Mode</span>
        <span x-show="(theme == 'dark')" class="sbcolapse" >Light Mode</span>
    </label>

</div>


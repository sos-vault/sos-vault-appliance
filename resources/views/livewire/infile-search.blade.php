<div>
    <div wire:ignore class="flex justify-between h-10 border-0 text-warning-700/60 dark:text-warning-600" >


        <div id="searchBox" class="flex-stretch flex center h-10 w-full border-1 dark:border-zinc-500 rounded-lg ring-0 ring-warning-700/50 dark:ring-warning-700 " >

            <i class="ph-duotone ph-magnifying-glass text-2xl self-start p-2"></i>

            <input id="searchTerm" type="text"
            class="bg-transparent border-0 border-l-1 border-zinc-200 dark:border-zinc-500 focus:ring-0 focus:outline-none h-full w-full mr-2"
            placeholder="{{ __('vault.search_infile_placeholder') }}"
            list="itemsFound"
            onchange="window.sosViewer.toggleSearch(event)"
            onfocusout="window.sosViewer.toggleRing(event)"
            onclick="window.sosViewer.toggleSearch(event)" >

            <button class="block w-6 h-full self-end mr-2" onclick="window.sosViewer.clearSearch()" x-tooltip.raw="{{ __('vault.search_infile_clear') }}">
                <i class="ph-duotone ph-x text-lg m-2 mx-1 hover:border-1 dark:hover:border-warning-600 hover:border-warning-700/50 hover:text-warning-800 dark:hover:text-warning-600 " ></i>
            </button>
        </div>

            <button class="block w-6 h-full self-end" onclick="window.sosViewer.searchPrev()" x-tooltip.raw="{{ __('vault.search_infile_prev') }}">
                <i class="ph-duotone ph-caret-left text-2xl self-start m-2 hover:border-1 dark:hover:border-warning-600 hover:text-warning-800 dark:hover:text-warning-600 "></i>
            </button>

            <button class="block w-6 h-full self-end" onclick="window.sosViewer.searchNext()" x-tooltip.raw="{{ __('vault.search_infile_next') }}">
                <i class="ph-duotone ph-caret-right text-2xl self-start m-2 hover:border-1 dark:hover:border-warning-600 hover:text-warning-800 dark:hover:text-warning-600 "></i>
            </button>

            <datalist id="itemsFound"></datalist>
    </div>

    <div id="matches" class="flex flex-row justify-center items-center w-auto h-auto p-2 text-sm text-zinc-400 " ></div>
</div>

<div>
    <div wire:ignore class="flex justify-between h-10 border-0 text-primary-700/60 dark:text-primary-600" >


        <div id="searchBoxFile" class="flex-stretch flex center h-10 w-full border-1 dark:border-zinc-500 rounded-lg ring-0 ring-primary-700/50 dark:ring-primary-700 " >

            <i class="ph-duotone ph-magnifying-glass text-2xl self-start p-2"></i>

            <input id="searchFileTerm" type="text"
            class="bg-transparent border-0 border-l-1 border-zinc-200 dark:border-zinc-500 focus:ring-0 focus:outline-none h-full w-full mr-2"
            placeholder="{{ __('vault.search_file_placeholder') }}"
            list="filesFound"
            onchange="window.sosViewer.toggleSearchFile(event)"
            onfocusout="window.sosViewer.toggleRingFile(event)"
            onclick="window.sosViewer.toggleSearchFile(event)" >

            <button class="block w-6 h-full self-end mr-2" onclick="window.sosViewer.clearSearchFile()" x-tooltip.raw="{{ __('vault.search_file_clear') }}">
                <i class="ph-duotone ph-x text-lg m-2 mx-1 hover:border-1 dark:hover:border-primary-600 hover:border-primary-700/50 hover:text-primary-800 dark:hover:text-primary-600 " ></i>
            </button>
        </div>

            <button class="block w-6 h-full self-end" onclick="window.sosViewer.searchPrevFile()" x-tooltip.raw="{{ __('vault.search_file_prev') }}">
                <i class="ph-duotone ph-caret-left text-2xl self-start m-2 hover:border-1 dark:hover:border-primary-600 hover:text-primary-800 dark:hover:text-primary-600 "></i>
            </button>

            <button class="block w-6 h-full self-end" onclick="window.sosViewer.searchNextFile()" x-tooltip.raw="{{ __('vault.search_file_next') }}">
                <i class="ph-duotone ph-caret-right text-2xl self-start m-2 hover:border-1 dark:hover:border-primary-600 hover:text-primary-800 dark:hover:text-primary-600 "></i>
            </button>

            <datalist id="filesFound"></datalist>
    </div>

    <div id="matchesFile" class="flex flex-row justify-center items-center w-auto h-auto p-2 text-sm text-zinc-400 " ></div>
</div>

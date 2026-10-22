<x-filament::section class="hidden">
    @script
        <script>
           $wire.on('showReportHierarchy', (event) => {
             window.sosViewer.growDirectory(event.tree,event.root,event.cid,event.vid,event.did,event.mode,event.cid2,event.csrft);
           });
        </script>
    @endscript
</x-filament::section>

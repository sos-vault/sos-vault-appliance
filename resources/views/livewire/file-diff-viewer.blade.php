<x-filament::section class="hidden">
    @script
        <script>
           $wire.on('getFileDiff', (event) => {
               window.sosViewer.renderDiff('containerDiff', event.left, event.right);
           });

           window.addEventListener('themeChange', () => {
               window.sosViewer.renderDiff('containerDiff');
           });

        </script>
    @endscript

</x-filament::section>


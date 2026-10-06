<x-filament::section class="hidden">
    {{-- Restore native list bullets and link affordance for HTML rendered into the
         viewer pane (the fixed sos_reports/sos.html index). The app's Tailwind
         preflight strips list-style and anchor colour/underline; these ID-scoped
         rules only match real <ul>/<li>/<a> — which no ordinary (escaped) text file
         produces — so they never affect other files or the surrounding app chrome. --}}
    <style>
        #{{ $root }} ul { list-style: disc; padding-left: 1.5em; margin: 0.25rem 0; }
        #{{ $root }} ol { list-style: decimal; padding-left: 1.5em; margin: 0.25rem 0; }
        #{{ $root }} li { display: list-item; }
        #{{ $root }} a { color: #2563eb; text-decoration: underline; cursor: pointer; }
        #{{ $root }} a:hover { color: #1d4ed8; }
        @media (prefers-color-scheme: dark) {
            #{{ $root }} a { color: #60a5fa; }
            #{{ $root }} a:hover { color: #93c5fd; }
        }
        @if ($isSosHtml)
        {{-- The index is a real HTML document, not preformatted text: let it wrap
             (the <pre> default white-space:pre is what pushed the Loaded Plugins
             table past the page width) and break over-long tokens. Preflight also
             zeroes heading/table margins, so restore some breathing room. --}}
        #{{ $root }} { white-space: normal; overflow-wrap: anywhere; max-width: 100%; overflow-x: auto; }
        #{{ $root }} h1, #{{ $root }} h2, #{{ $root }} h3, #{{ $root }} h4 { margin: 0.9em 0 0.45em; font-weight: 600; }
        #{{ $root }} table { max-width: 100%; margin: 0.35em 0 1.5em; }
        @endif
    </style>
    @script
        <script>
           document.title = "SOS Viewer $filename";
           $wire.on('getFileContents', () => { window.sosViewer.getFileContents('{{ $contents }}','{{ $metadata }}','{{ $root }}', '{{ $fid }}') });

           // In-page anchor jumps (e.g. the sos.html "Loaded Plugins" table of
           // contents) don't fire natively because the content is injected into a
           // nested scroll container. Delegate on the pane and scrollIntoView the
           // matching target. File links (/filebrowser/…, target=_blank) don't
           // start with '#', so they're untouched.
           const pane = document.getElementById('{{ $root }}');
           if (pane && !pane.dataset.anchorJump) {
               pane.dataset.anchorJump = '1';
               pane.addEventListener('click', (e) => {
                   const a = e.target.closest('a[href^="#"]');
                   if (!a || !pane.contains(a)) return;
                   const id = decodeURIComponent((a.getAttribute('href') || '').slice(1));
                   if (!id) return;
                   const sel = '[id="' + CSS.escape(id) + '"], [name="' + CSS.escape(id) + '"]';
                   const target = pane.querySelector(sel);
                   if (target) {
                       e.preventDefault();
                       // Land the target BELOW any fixed header (the file-controls
                       // panel floats over the top of the viewer), not hidden under it.
                       let offset = 0;
                       document.querySelectorAll('header').forEach((h) => {
                           const cs = getComputedStyle(h);
                           if (cs.position === 'fixed' || cs.position === 'sticky') {
                               const rr = h.getBoundingClientRect();
                               if (rr.top <= 40) offset = Math.max(offset, rr.bottom);
                           }
                       });
                       target.style.scrollMarginTop = (offset + 12) + 'px';
                       target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                   }
               });
           }
        </script>
    @endscript

    <div wire:init='openSosFile("{{ $cid }}","{{ $vid }}","{{ $did }}","{{ $fid }}","{{ $root }}")'></div>

</x-filament::section>

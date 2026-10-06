@script
<script>
    $wire.on('initializeFileTable', () => {
        //hide horizontal scroll bar
        const scrollbar = document.getElementById('float-scroll1');
        if(scrollbar) {
            scrollbar.classList.replace('flex','hidden');
        }

        $wire.resetTableFiltersForm;
        setTimeout(() => {
            const filters = $wire.get('tableFilters') || {};

            if (filters.time !== undefined) {
                $wire.removeTableFilter('time');
            }
        }, 500);
    });

    // ── Search term highlighter ──────────────────────────────────────────────
    function highlightTableSearch(root, term) {
        // Remove previous highlights
        root.querySelectorAll('mark[data-hl]').forEach(m => {
            m.replaceWith(document.createTextNode(m.textContent));
        });

        if (!term || term.length < 2) return;

        const re = new RegExp(
            `(${term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi'
        );

        root.querySelectorAll('table td').forEach(cell => {
            Array.from(cell.childNodes).forEach(node => {
                //if (node.nodeType !== Node.TEXT_NODE || !node.textContent.trim()) return;
                if (!node.textContent.trim()) return;
                const highlighted = node.textContent.replace(re,
                    '<mark data-hl class="bg-warning-200 dark:bg-warning-200 rounded px-0.5">$1</mark>'
                );
                if (highlighted !== node.textContent) {
                    const span = document.createElement('span');
                    span.innerHTML = highlighted;
                    node.replaceWith(span);
                }
            });
        });
    }

    Livewire.hook('commit', ({ component, succeed }) => {
        if (component.el !== $el) return;
        succeed(() => {
            requestAnimationFrame(() => {
                const term = ((component.snapshot?.data?.tableSearch) ?? '').trim();
                highlightTableSearch($el, term);
            });
        });
    });
</script>
@endscript
<div x-cloak wire:init="initializePage()" class="w-full">
    {{ $this->table }}
</div>

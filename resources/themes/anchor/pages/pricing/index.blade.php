<?php
    use function Laravel\Folio\{middleware, name};
    name('pricing');
    // The appliance sells nothing in-app (licensing is Admin → License); the
    // pricing/checkout surface is SaaS-only. 404 it here as with the other
    // billing pages (settings/subscription, settings/invoices).
    middleware([\App\Http\Middleware\DenyOnAppliance::class]);
?>

<x-layouts.marketing>

    <x-container class="py-10">
        <x-marketing.sections.pricing />
    </x-container>

</x-layouts.marketing>

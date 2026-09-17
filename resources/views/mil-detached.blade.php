<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <title>Mil · AI Assistant</title>
    {{-- Apply dark mode before paint to avoid a flash (mirrors the app layout). --}}
    <script>
        if (typeof (Storage) !== "undefined") {
            if (localStorage.getItem('theme') && localStorage.getItem('theme') == 'dark') {
                document.documentElement.classList.add('dark');
            }
        }
    </script>
    {{-- Compiled theme CSS + Alpine/Livewire bundle. Livewire 3 auto-injects its
         scripts/styles before </body>, exactly as in the main app layout. --}}
    @include('theme::partials.head', ['seo' => null])
</head>
<body class="h-screen overflow-hidden bg-white dark:bg-zinc-900">
    @livewire('chat-widget', ['detached' => true])

    {{-- Livewire + Alpine runtime. The theme's head partial only loads @livewireStyles
         and the Vite bundle (which does NOT start Alpine); the actual scripts live in
         the app layout's footer partial. Without these the pop-out renders but has no
         Alpine (dead Enter key) and no Livewire ($wire.send / wire:click do nothing). --}}
    @filamentScripts
    @livewireScripts
</body>
</html>

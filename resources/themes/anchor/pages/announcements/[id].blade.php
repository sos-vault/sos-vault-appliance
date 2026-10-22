
@php $announcement = App\Models\Announcement::findOrFail($id); @endphp
<x-layouts.app>
    <x-app.container>

        <div class="max-w-4xl px-5 mx-auto mt-10 lg:px-0">
            <a href="/announcements" class="flex items-center mb-6 font-mono text-sm font-bold cursor-pointer text-primary-700 ">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" class="fill-primary-700" viewBox="0 0 256 256"><path d="M224,128a8,8,0,0,1-8,8H59.31l58.35,58.34a8,8,0,0,1-11.32,11.32l-72-72a8,8,0,0,1,0-11.32l72-72a8,8,0,0,1,11.32,11.32L59.31,120H216A8,8,0,0,1,224,128Z"></path></svg>
                {{ __('notifications.ann_view_all') }}
            </a>
        </div>

        <article id="announcement-{{ $announcement->id }}" class="max-w-4xl px-5 pb-20 mx-auto prose prose-xl lg:prose-2xl lg:px-0 dark:prose-invert">

            <meta property="name" content="{{ $announcement->title }}">
            <meta property="author" typeof="Person" content="admin">
            <meta property="dateModified" content="{{ $announcement->updated_at->toIso8601String() }}">
            <meta class="uk-margin-remove-adjacent" property="datePublished" content="{{ $announcement->created_at->toIso8601String() }}">

            <div class="max-w-4xl mx-auto mt-6">
                <h1 class="flex flex-col leading-none">
                    <span class="text-gray-700 dark:text-gray-100" >{{ $announcement->title }}</span>
                    <span class="mt-10 text-base font-normal text-gray-600 dark:text-gray-100">{{ __('notifications.ann_written_on', ['date' => $announcement->created_at->format('F d, Y.')]) }}</span>
                </h1>
            </div>

            <div class="max-w-4xl mx-auto dark:text-zinc-100">
                {!! $announcement->body !!}
            </div>

        </article>

    </x-app.container>
</x-layouts.app>


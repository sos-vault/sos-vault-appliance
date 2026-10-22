<?php
    use function Laravel\Folio\{name};
    name('blog.post');
?>

<x-layouts.marketing>
@php
    $prevPost = \Wave\Post::where('category_id', $post->category_id)
        ->where('created_at', '<', $post->created_at)
        ->orderBy('created_at', 'DESC')
        ->first()
        ?? \Wave\Post::where('category_id', $post->category_id)
            ->orderBy('created_at', 'DESC')
            ->first();

    $nextPost = \Wave\Post::where('category_id', $post->category_id)
        ->where('created_at', '>', $post->created_at)
        ->orderBy('created_at', 'ASC')
        ->first()
        ?? \Wave\Post::where('category_id', $post->category_id)
            ->orderBy('created_at', 'ASC')
            ->first();
@endphp

    <article id="post-{{ $post->id }}" class="max-w-4xl px-5 pb-20 mx-auto prose prose-md dark:prose-invert lg:prose-lg lg:px-0">

        <div class="flex items-center justify-between max-w-4xl mx-auto mt-4 md:mt-8 not-prose">
            @if($prevPost)
                <a href="{{ $prevPost->link() }}" wire:navigate
                   class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-semibold rounded-full border cursor-pointer text-zinc-900 bg-zinc-100 border-zinc-200 group">
                    <svg class="w-3.5 h-3.5 duration-200 ease-out translate-x-1 group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    {{ $prevPost->title }}
                </a>
            @else
                <span></span>
            @endif

            <a href="{{ route('blog') }}" wire:navigate
               class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-semibold rounded-full border cursor-pointer text-zinc-900 bg-zinc-100 border-zinc-200 group">
                <svg class="w-3.5 h-3.5 duration-200 ease-out translate-x-1 group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                {{ __('blog.back_to_blog') }}
            </a>

            @if($nextPost)
                <a href="{{ $nextPost->link() }}" wire:navigate
                   class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-semibold rounded-full border cursor-pointer text-zinc-900 bg-zinc-100 border-zinc-200 group">
                    {{ $nextPost->title }}
                    <svg class="w-3.5 h-3.5 duration-200 ease-out -translate-x-1 group-hover:-translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </a>
            @else
                <span></span>
            @endif
        </div>

        <meta property="name" content="{{ $post->title }}">
        <meta property="author" typeof="Person" content="admin">
        <meta property="dateModified" content="{{ Carbon\Carbon::parse($post->updated_at)->toIso8601String() }}">
        <meta class="uk-margin-remove-adjacent" property="datePublished" content="{{ Carbon\Carbon::parse($post->created_at)->toIso8601String() }}">

        <div class="max-w-4xl mx-auto mt-6">

            <h1 class="flex flex-col leading-none">
                <span>{{ $post->title }}</span>
                {{-- <span class="mt-0 mt-10 text-base font-normal">Written on <time datetime="{{ Carbon\Carbon::parse($post->created_at)->toIso8601String() }}">{{ Carbon\Carbon::parse($post->created_at)->toFormattedDateString() }}</time>. Posted in <a href="{{ route('blog.category', $post->category->slug) }}" rel="category">{{ $post->category->name }}</a>.</span> --}}
            </h1>


        </div>

        @if ($post->image)
            <div class="relative">
                <img class="w-full h-auto rounded-lg" src="{{ $post->image() }}" alt="{{ $post->title }}" srcset="{{ $post->image() }}">
            </div>
        @endif

        <div class="max-w-4xl mx-auto">
            {!! $post->body !!}
        </div>

        @if($post->slug == "15-sos-report-available-plugins")
            @livewire('sos-plugins-table')
        @endif

        <div class="flex items-center justify-between max-w-4xl mx-auto mt-10 not-prose">
            @if($prevPost)
                <a href="{{ $prevPost->link() }}" wire:navigate
                   class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-semibold rounded-full border cursor-pointer text-zinc-900 bg-zinc-100 border-zinc-200 group">
                    <svg class="w-3.5 h-3.5 duration-200 ease-out translate-x-1 group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    {{ $prevPost->title }}
                </a>
            @else
                <span></span>
            @endif

            <a href="{{ route('blog') }}" wire:navigate
               class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-semibold rounded-full border cursor-pointer text-zinc-900 bg-zinc-100 border-zinc-200 group">
                <svg class="w-3.5 h-3.5 duration-200 ease-out translate-x-1 group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                {{ __('blog.back_to_blog') }}
            </a>

            @if($nextPost)
                <a href="{{ $nextPost->link() }}" wire:navigate
                   class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-semibold rounded-full border cursor-pointer text-zinc-900 bg-zinc-100 border-zinc-200 group">
                    {{ $nextPost->title }}
                    <svg class="w-3.5 h-3.5 duration-200 ease-out -translate-x-1 group-hover:-translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </a>
            @else
                <span></span>
            @endif
        </div>

    </article>

</x-layouts.marketing>

<x-layouts.marketing
    :seo="[
        'title'         => setting('site.title', 'sos-vault'),
        'description'   => setting('site.description', 'The sosreport Management and Analysis Tool'),
        'image'         => url('/og_image.png'),
        'type'          => 'website'
    ]"
>

    <x-container class="w-2/3 self-center py-12 border-t sm:py-24 border-zinc-200">

        @if(isset($mailOnHisWay) && $mailOnHisWay)
            <div class="flex flex-col justify-center py-20 sm:px-6 lg:px-8">
                <div class="sm:mx-auto sm:w-full sm:max-w-md">
                    <h2 class="mt-6
                        text-2xl
                        font-semibold
                        leading-9
                        text-gray-500
                        dark:text-white">

                        We've received your message and will get back to you as soon as possible.
                    </h2>
                    <h3 class="mt-6
                        text-2xl
                        leading-9
                        text-gray-500
                        dark:text-white">
                        Please check your inbox in the upcoming days for our response.
                        In the meantime, feel free to explore our site or follow us on social media for updates.
                        <p>
                        Thanks.
                    </h3>
                    <p class="mt-4 text-sm leading-5 text-center text-gray-600 dark:text-gray-200 max-w">
                        <x-elements.back-button
                            class="max-w-4xl mx-auto mt-4 md:mt-8"
                            text="Back to the main page"
                            :href="route('home')"
                        />

                    </p>
                </div>
            </div>
        @else
            <div class="max-w-4xl px-5 mx-auto mt-10 lg:px-0">
                <x-elements.back-button
                    class="max-w-4xl mx-auto mt-4 md:mt-8"
                    text="Back to the main page"
                    :href="route('home')"
                />
            </div
            <div class="pt-2 mx-auto prose text-center max-w-7xl">
                <div class="px-4 mx-auto w-[50%]">
                    <h2 class="mb-4 text-4xl tracking-tight font-extrabold text-center text-gray-900 dark:text-white">Contact Us</h2>
                    <p class="mb-8 lg:mb-16 font-light text-center text-gray-500 dark:text-gray-400 md:text-lg leading-6 text-balance">Got a technical issue? Want to send feedback about our service? Need details about a plan? Let us know.</p>

                    <form id="contact-form" class="space-y-8" action="{{ route('wave.contactus') }}" method="POST">
                        @csrf
                        <div class="flex flex-col justify-start align-center my-8">
                            <label class="self-start block mb-2 text-sm font-medium text-gray-900 dark:text-gray-300" for="email">Your email</label>
                            <input name="email" type="email" class="shadow-sm bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary-500 focus:border-primary-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-primary-500 dark:focus:border-primary-500 dark:shadow-sm-light" required="" placeholder="name@example.com" required>

                            @if ($errors->has('email'))
                                <div class="mt-1 text-red-500">
                                    {{ $errors->first('email') }}
                                </div>
                            @endif
                        </div>

                        <div class="flex flex-col justify-start align-center my-8">
                            <label class="self-start block mb-2 text-sm font-medium text-gray-900 dark:text-gray-300" for="subject">Subject</label>
                            <input name="subject" type="text" class="block p-3 w-full text-sm text-gray-900 bg-gray-50 rounded-lg border border-gray-300 shadow-sm focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-primary-500 dark:focus:border-primary-500 dark:shadow-sm-light" required="" placeholder="Let us know how we can help you" required>

                            @if ($errors->has('subject'))
                                <div class="mt-1 text-red-500">
                                    {{ $errors->first('subject') }}
                                </div>
                            @endif
                        </div>

                        <div class="flex flex-col justify-start align-center my-8 sm:col-span-2">
                            <label class="self-start block mb-2 text-sm font-medium text-gray-900 dark:text-gray-400" for="message">Your message</label>
                            <textarea name="message" class="block p-2.5 w-full text-sm text-gray-900 bg-gray-50 rounded-lg shadow-sm border border-gray-300 focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-primary-500 dark:focus:border-primary-500" rows="6" placeholder="Leave a comment..." required></textarea>

                            @if ($errors->has('message'))
                                <div class="mt-1 text-red-500">
                                    {{ $errors->first('message') }}
                                </div>
                            @endif
                        </div>

                        <div class="mt-6">
                            @if ($errors->has('g-recaptcha-response'))
                                <div class="mt-1 text-red-500">
                                    {{ $errors->first('g-recaptcha-response') }}
                                </div>
                            @endif
                        </div>

                        <button class="self-end py-3 px-5 text-sm font-medium text-center text-white rounded-lg bg-primary-700 sm:w-fit hover:bg-primary-600 focus:ring-4 focus:outline-none focus:ring-primary-300 g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}" data-callback='onSubmit' data-action="submit">
                        Send message</button>
                    </form>

                    <p class="pt-10">
                    @if (session('success'))
                        <span class="text-primary-700">{{ session('success') }}</span>
                    @endif
                    </p>

                </div>
            </div>
            <script>
                function onSubmit(token) {
                    document.getElementById("contact-form").submit();
                }
            </script>
        @endif

    </x-container>

</x-layouts.marketing>

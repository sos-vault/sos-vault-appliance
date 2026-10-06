<x-layouts.marketing>

    {{-- Plain CSS/JS overlay — purely client-side, no Livewire roundtrip.
         Shown while the browser is navigating away to the post-login page,
         so the user gets immediate feedback that "Opening the vault" is
         in progress. Disappears automatically when the new page loads. --}}
    <div id="loginProgress" class="hidden fixed top-4 right-4 max-w-sm" style="z-index: 99999;">
        <div class="flex items-start gap-3 px-4 py-3 bg-white dark:bg-zinc-800 border border-amber-300 dark:border-amber-500 rounded-lg shadow-lg">
            <svg class="w-5 h-5 mt-0.5 text-amber-500 animate-spin shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            <div class="text-sm text-gray-800 dark:text-gray-100 leading-snug">
                <div id="loginProgressTitle" class="font-medium">Opening your vault</div>
                <div id="loginProgressSubtext" class="text-xs text-gray-500 dark:text-gray-400">This will take a few seconds…</div>
            </div>
        </div>
    </div>

    <div id='whole' class="flex flex-col justify-center py-2 sm:px-6 lg:px-8">

        <div class="sm:mx-auto sm:w-full sm:max-w-md sm:pt-10">
            <h2 class="sm:mt-4 text-2xl font-extrabold leading-5 text-center text-gray-800 dark:text-white ">
                Welcome
            </h2>
        </div>

        {{-- Social login (Google/Facebook/GitHub) is SaaS-only — Socialite
             providers are not configured on the appliance. --}}
        @if (isSaas())
        <div class="mt-6 sm:mx-auto sm:w-full sm:max-w-md">
            <div class="flex flex-col justify-center space-y-4 px-4 py-6 bg-white dark:text-white dark:bg-gray-800 border shadow border-gray-50 sm:rounded-lg sm:px-10">

                <a id="googleButton" href="{{ url('auth/google') }}" class="flex flex-row justify-center items-center w-full px-5 py-3 font-medium text-sm text-center bg-white border-2 border-gray-300 rounded-lg hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-primary-700 dark:focus:ring-primary-700">
                    <!-- google logo -->
                    <svg version="1.1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" class="w-[20px] h-[20px] LgbsSe-Bz112c"><g><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"></path><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"></path><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"></path><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"></path><path fill="none" d="M0 0h48v48H0z"></path></g></svg>
                    <span class="px-4 flex-1"> Continue with Google</span>
                </a>

                <a id="facebookButton" href="{{ url('auth/facebook') }}" class="flex flex-row justify-center items-center w-full px-5 py-3 font-medium text-sm text-center bg-white border-2 border-gray-300 rounded-lg hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-primary-700 dark:focus:ring-primary-700">
                    <!-- facebook logo -->
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" role="img" aria-hidden="true" class="w-[20px] h-[20px] crayons-icon crayons-icon--default">
    <path d="M18.5 2.5a3 3 0 0 1 3 3v13a3 3 0 0 1-3 3h-13a3 3 0 0 1-3-3v-13a3 3 0 0 1 3-3h13Z" fill="#1877F2"></path>
    <path d="M16.12 12h-2.636v-1.781c0-.754.368-1.485 1.544-1.485h1.2V6.395s-1.087-.184-2.126-.184c-2.167 0-3.586 1.312-3.586 3.693V12H8.105v2.75h2.41v6.75h2.97v-6.757h2.214L16.115 12h.006Z" fill="#fff"></path>
</svg>
                    <span class="px-4 flex-1"> Continue with Facebook</span>
                </a>

                <a id="githubButton" href="{{ url('auth/github') }}" class="flex flex-row justify-center items-center w-full px-5 py-3 font-medium text-sm text-center bg-white border-2 border-gray-300 rounded-lg hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-primary-700 dark:focus:ring-primary-700">
                    <i class="fab fa-github fa-xl"></i>
                    <span class="px-4 flex-1"> Continue with GitHub</span>
                </a>

            </div>
        </div>
        @endif

        {{-- Self-service registration is SaaS-only — appliance accounts are
             provisioned by the admin, so the "Sign Up here" link is hidden. --}}
        @if (isSaas())
        <div class="sm:mx-auto sm:w-full sm:max-w-md sm:pt-4">
            <p class="mt-4 text-sm leading-5 text-center text-gray-600 dark:text-gray-200 max-w">
                Don't have an account?
                <a href="{{ route('register') }}"
                    class="font-medium
                    transition
                    duration-150
                    ease-in-out

                    text-primary-700
                    hover:text-primary-600
                    focus:outline-none
                    focus:underline">
                    Sign Up here
                </a>
            </p>
        </div>
        @endif

        <div class="mt-4 sm:mx-auto sm:w-full sm:max-w-md">
            <div class="px-4 py-6 bg-white dark:text-white dark:bg-gray-800 border shadow border-gray-50 sm:rounded-lg sm:px-10">
                <form action="{{ route('login') }}" method="POST" onsubmit="showLoginProgress()">
                    @csrf
                    <div>

                        @if(setting('auth.email_or_username') && setting('auth.email_or_username') == 'username')
                            <label for="username" class="block text-sm font-medium leading-5 text-gray-700">Username</label>
                            <div class="mt-1 rounded-md shadow-sm">
                                <input id="username" type="username" name="username" autocomplete="username" required class="w-full form-input dark:text-black focus:ring-primary-700" autofocus>
                            </div>

                            @if ($errors->has('username'))
                                <div class="mt-1 text-red-500">
                                    {{ $errors->first('username') }}
                                </div>
                            @endif
                        @else
                            <label for="email" class="block text-sm font-medium leading-5 text-gray-700 dark:text-white">Email address</label>
                            <div class="mt-1 rounded-md shadow-sm">
                                <input id="email" type="email" name="email" required autocomplete="email" class="w-full form-input dark:text-black focus:ring-primary-700" autofocus>
                            </div>

                            @if ($errors->has('email'))
                                <div class="mt-1 text-red-500">
                                    {{ $errors->first('email') }}
                                </div>
                            @endif
                        @endif


                    </div>

                    <div class="mt-6">
                        <label for="password" class="block text-sm font-medium leading-5 text-gray-700 dark:text-white">
                            Password
                        </label>
                        <div class="mt-1 rounded-md shadow-sm">
                            <input id="password" type="password" name="password" required autocomplete="password" class="w-full form-input dark:text-black focus:ring-primary-700">
                        </div>
                        @if ($errors->has('password'))
                            <div class="mt-1 text-red-500">
                                {{ $errors->first('password') }}
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center justify-between mt-6">
                        <div class="flex items-center">
                            <input id="remember" name="remember" type="checkbox" class=" border-2
                                rounded
                                shadow-sm
                                border-gray-300
                                text-primary-700
                                focus:border-primary-300
                                focus:ring
                                focus:ring-offset-0
                                focus:ring-primary-700
                                focus:ring-opacity-50
                                rounded-xl"

                            {{ old('remember') ? ' checked' : '' }}>
                            <label for="remember" class="block ml-2 text-sm leading-5 text-gray-900 dark:text-white">
                                Remember me
                            </label>
                        </div>

                        <div class="text-sm leading-5">
                            <a href="{{ route('password.email') }}" class="font-medium transition duration-150 ease-in-out text-primary-700 hover:text-primary-600 focus:outline-none focus:underline">
                                Forgot your password?
                            </a>
                        </div>
                    </div>

                    <div class="mt-6">
                        <span class="block w-full rounded-md shadow-sm">
                            <button id="enterButton" type="submit" class="flex
                                justify-center
                                w-full
                                px-4
                                py-2
                                text-sm
                                font-medium
                                transition
                                duration-150
                                ease-in-out
                                border
                                border-transparent
                                rounded-md

                                text-white
                                bg-primary-700
                                hover:bg-primary-600
                                focus:outline-none
                                focus:border-primary-600
                                focus:shadow-outline-primary
                                active:bg-primary-600">
                                Continue
                            </button>
                        </span>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function showLoginProgress() {
            const overlay = document.getElementById('loginProgress');
            if (overlay) overlay.classList.remove('hidden');
            document.getElementById('whole')?.classList.add('cursor-progress');

            // Opening an existing vault is quick — the post-login redirect lands
            // (and unloads this page) before the timer below fires. A first login
            // has to CREATE the encrypted vault, which takes minutes, so if we're
            // still showing the overlay after this long it's almost certainly a
            // provisioning run: tell the user it will take longer. The delay is
            // deliberately generous (11s) so a normal vault open never flips to
            // the "Initializing…" copy before its redirect unloads the page —
            // only a genuine vault initialization stays long enough to see it.
            setTimeout(function () {
                const title = document.getElementById('loginProgressTitle');
                const subtext = document.getElementById('loginProgressSubtext');
                if (title) title.textContent = 'Initializing your vault';
                if (subtext) subtext.textContent = 'The system is initializing your vault, this will take a few minutes…';
            }, 11000);
        }
        // Same feedback for the social-login redirects.
        ['googleButton', 'facebookButton', 'githubButton'].forEach((id) => {
            document.getElementById(id)?.addEventListener('click', showLoginProgress);
        });
    </script>

</x-layouts.marketing>

<x-layouts.marketing>

@php
    $trial_days = setting('billing.trial_days', 14);
    $days = $trial_days ? " $trial_days Days" : "";
    $cardUpfront = (bool) setting('billing.card_upfront');
    $registerAction = $cardUpfront ? '/pricing' : route('register');
    $usernameInRegistration = setting('auth.username_in_registration') === 'yes';
    $selectedPlan = request('price_id')
        ? Wave\Plan::where('monthly_price_id', request('price_id'))->orWhere('yearly_price_id', request('price_id'))->first()
        : null;
@endphp


    <div class="sm:mx-auto sm:w-full sm:max-w-md sm:pt-10">
        <h2 class="sm:mt-6 text-xl font-extrabold leading-9 text-center text-gray-800 dark:text-white">
            @if($selectedPlan)
                Sign up for {{ $selectedPlan->name }}
            @else
                Sign in with a social account or Sign up to start your {{ $days }} trial
            @endif
        </h2>
        <div class="mt-1 text-sm text-center text-gray-600 dark:text-gray-400">
            @if($selectedPlan)
                Create your account, then complete payment to activate your plan.
            @else
                (No credit card needed)
            @endif
        </div>
    </div>

    <div id='whole' class="flex flex-col justify-center pb-10 sm:pb-20 sm:px-6 lg:px-8">

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

        <div class="mt-4 sm:mx-auto sm:w-full sm:max-w-md">
            <div class="px-4 py-8 bg-white dark:text-white dark:bg-gray-800 border shadow border-gray-50 sm:rounded-lg sm:px-10">
                <form id="register-form" role="form" method="POST" action="{{ $registerAction }}" onsubmit="document.getElementById('whole').classList.add('cursor-progress')">
                    @csrf
                    @if(request('price_id'))
                        <input type="hidden" name="price_id" value="{{ request('price_id') }}">
                    @endif
                    <!-- If we want the user to purchase before they can create an account -->

                    <div class="pb-3 sm:border-b sm:border-gray-200">
                        <h3 class="text-lg font-medium leading-6 text-gray-800 dark:text-white">
                            Profile
                        </h3>
                        <p class="max-w-2xl mt-1 text-sm leading-5 text-gray-500 dark:text-gray-500">
                            Information about your account.
                        </p>
                    </div>

                    @csrf

                    <div class="mt-6">
                        <label for="email" class="block
                            text-sm
                            font-medium
                            leading-5
                            text-gray-800
                            dark:text-white">
                            Name
                        </label>
                        <div class="mt-1 rounded-md shadow-sm">
                            <input id="name" type="text" name="name" required class="w-full form-input dark:text-gray-800" value="{{ old('name') }}" {{ $cardUpfront ? '' : 'autofocus' }}>
                        </div>
                        @if ($errors->has('name'))
                            <div class="mt-1 text-red-500">
                                {{ $errors->first('name') }}
                            </div>
                        @endif
                    </div>

                    @if($usernameInRegistration)
                        <div class="mt-6">
                            <label for="email" class="block
                                text-sm
                                font-medium
                                leading-5
                                text-gray-800
                                dark:text-white">
                                Username
                            </label>
                            <div class="mt-1 rounded-md shadow-sm">
                                <input id="username" type="text" name="username" value="{{ old('username') }}" required class="w-full form-input dark:text-gray-800">
                            </div>
                            @if ($errors->has('username'))
                                <div class="mt-1 text-red-500">
                                    {{ $errors->first('username') }}
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="mt-6">
                        <label for="email" class="block
                            text-sm
                            font-medium
                            leading-5
                            text-gray-800
                            dark:text-white">
                            Email Address
                        </label>
                        <div class="mt-1 rounded-md shadow-sm">
                            <input id="email" type="email" name="email" value="{{ old('email') }}" required class="w-full form-input dark:text-gray-800">
                        </div>
                        @if ($errors->has('email'))
                            <div class="mt-1 text-red-500">
                                {{ $errors->first('email') }}
                            </div>
                        @endif
                    </div>

                    <div class="mt-6" x-data="{ focused: false }">
                        <label for="password" class="block
                            text-sm
                            font-medium
                            leading-5
                            text-gray-800
                            dark:text-white">
                            Password
                        </label>
                        <div class="mt-1 rounded-md shadow-sm">
                            <input id="password" type="password" name="password" required
                                class="w-full form-input dark:text-gray-800"
                                @focus="focused = true"
                                @blur="focused = false">
                        </div>
                        @if ($errors->has('password'))
                            <div class="mt-1 text-red-500">
                                {{ $errors->first('password') }}
                            </div>
                        @endif
                        <div x-show="focused" x-transition x-cloak>
                            <x-password-helper target-id="password" />
                        </div>
                    </div>

                    <div class="mt-6">
                        <label for="password_confirmation" class="block
                            text-sm
                            font-medium
                            leading-5
                            text-gray-800
                            dark:text-white">
                            Confirm Password
                        </label>
                        <div class="mt-1 rounded-md shadow-sm">
                            <input id="password_confirmation" type="password" name="password_confirmation" required class="w-full form-input dark:text-gray-800">
                        </div>
                        @if ($errors->has('password_confirmation'))
                            <div class="mt-1 text-red-500">
                                {{ $errors->first('password_confirmation') }}
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

                    <div class="flex flex-col items-center justify-center text-sm leading-5">
                        <span class="block w-full mt-5 rounded-md shadow-sm">
                            <button class="flex
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
                                active:bg-primary-600
                                g-recaptcha"
                                data-sitekey="{{ config('services.recaptcha.site_key') }}"
                                data-callback='onSubmit'
                                data-action="submit"
                                ">
                                Register
                            </button>
                        </span>
                        <a href="{{ route('login') }}" class="lg:block hidden mt-3 font-medium transition duration-150 ease-in-out text-primary-700 hover:text-primary-600 focus:outline-none focus:underline">
                            Already have an account? Login here
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        function onSubmit(token) {
            document.getElementById("register-form").submit();
        }
    </script>

</x-layouts.marketing>

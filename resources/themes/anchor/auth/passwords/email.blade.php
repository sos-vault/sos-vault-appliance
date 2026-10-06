<x-layouts.marketing>

@if(isset($mailOnHisWay) && $mailOnHisWay)
    <div class="flex flex-col justify-center py-20 sm:px-6 lg:px-8">
        <div class="sm:mx-auto sm:w-full sm:max-w-md">
            <h2 class="mt-6
                text-2xl
                font-semibold
                leading-9
                text-gray-500
                dark:text-white">

                We’ve sent a password reset link to your email.
            </h2>
            <h3 class="mt-6
                text-2xl
                leading-9
                text-gray-500
                dark:text-white">
                Please check your inbox and follow the instructions to reset your password.
            </h3>
            <p class="mt-4 text-sm leading-5 text-center text-gray-600 dark:text-gray-200 max-w">
                <a href="/"
                    class="font-medium
                    transition
                    duration-150
                    ease-in-out

                    text-primary-700
                    hover:text-primary-600
                    focus:outline-none
                    focus:underline">
                    Back to the main page
                </a>
            </p>
        </div>
    </div>
@else
<div class="flex flex-col justify-center py-20 sm:px-6 lg:px-8">
        <div class="sm:mx-auto sm:w-full sm:max-w-md">
            <h2 class="mt-6
                text-2xl
                font-extrabold
                leading-9

                text-center
                text-gray-800
                dark:text-white"
                >

                Reset Password
            </h2>
            <p class="mt-4 text-sm leading-5 text-center text-gray-600 dark:text-gray-200 max-w">
                or, return back to
                <a href="{{ route('login') }}"
                    class="font-medium
                    transition
                    duration-150
                    ease-in-out

                    text-primary-700
                    hover:text-primary-600
                    focus:outline-none
                    focus:underline">
                    login
                </a>
            </p>
        </div>

        <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">

            @if (session('status'))
                <div class="p-3 mb-3 uk-alert-primary">
                    {{ session('status') }}
                </div>
            @endif

            <div class="px-4
                py-8
                bg-white
                dark:text-white
                dark:bg-gray-800
                border
                shadow
                border-gray-50
                sm:rounded-lg
                sm:px-10">

                <form id="sendlink-form" action="{{ route('password.email') }}" method="POST">
                    @csrf

                    <div>
                        <label for="email" class="block
                            text-sm
                            font-medium
                            leading-5
                            text-gray-800
                            dark:text-white">
                            Email Address
                        </label>
                        <div class="mt-3 rounded-md shadow-sm">
                            <input id="email" type="email" name="email" required class="w-full form-input dark:text-gray-800">
                        </div>
                        @if ($errors->has('email'))
                            <div class="mt-1 text-red-500">
                                {{ $errors->first('email') }}
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

                    <div class="mt-6">
                        <span class="block w-full rounded-md shadow-sm">
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
                                Send Password Reset Link
                            </button>
                        </span>
                    </div>
                </form>

            </div>
        </div>
    </div>
    <script>
        function onSubmit(token) {
            document.getElementById("sendlink-form").submit();
        }
    </script>
@endif

</x-layouts.marketing>

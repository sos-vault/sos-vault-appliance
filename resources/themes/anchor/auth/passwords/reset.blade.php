<x-layouts.marketing>

<div class="flex flex-col justify-center py-20 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
        <h2 class="mt-6 text-2xl font-extrabold leading-none text-center text-gray-800 dark:text-white">
            Setup Your New Password
        </h2>
        <p class="mt-4 text-sm leading-5 text-center text-gray-600 dark:text-gray-200 max-w">
            @auth
                As a final step, please set a password to secure your account.
            @else
                or, return to
                <a href="{{ route('login') }}"
                    class="font-medium transition duration-150 ease-in-out text-primary-700 hover:text-primary-600 focus:outline-none focus:underline">
                    login here
                </a>
            @endauth
        </p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
        @if (session('status'))
            <div class="mb-3 uk-alert-primary">
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

            <form action="{{ route('password.request') }}" method="POST">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <input type="hidden" name="email" value="{{ $email }}">

                @if ($errors->has('email'))
                <div class="mt-1 text-red-500">
                    {{ $errors->first('email') }}
                </div>
                @endif

                @if ($errors->has('token'))
                <div class="mt-1 text-red-500">
                    {{ $errors->first('token') }}
                </div>
                @endif

                <div class="mt-6">
                    <label for="password" class="block
                        text-sm
                        font-medium
                        leading-5
                        text-gray-800
                        dark:text-white">
                        Password
                    </label>
                    <div class="mt-1 rounded-md shadow-sm">
                        <input id="password" type="password" name="password" required class="w-full form-input dark:text-gray-800">
                    </div>
                    @if ($errors->has('password'))
                    <div class="mt-1 text-red-500">
                        {{ $errors->first('password') }}
                    </div>
                    @endif
                    <x-password-helper target-id="password" />
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

                <div class="flex flex-col items-center justify-center text-sm leading-5">
                    <span class="block w-full mt-5 rounded-md shadow-sm">
                        <button type="submit" class="flex
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
                            Reset Password
                        </button>
                    </span>
                </div>

            </form>
        </div>
    </div>
</div>

</x-layouts.marketing>

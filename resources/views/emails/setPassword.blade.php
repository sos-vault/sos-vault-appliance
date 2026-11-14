<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
	<title>sos-vault Response</title>
	<meta name="viewport" content="width=device-width">
	<style type="text/css">
		@import url(https://fonts.googleapis.com/css?family=Droid+Sans);

		body {
			font-family:'Droid Sans', sans-serif;
			font-size:10pt;
			color:#555;
		}

        .green {
            color: #7b9041;
            font-size: 1.0rem;
        }

        .bggreen {
            background-color: #3a3a14;
        }

        .btn-verify {
            display: inline-block;
            background-color: #3a3a14;
            color: #ffffff !important;
            text-decoration: none;
            font-size: 1.1rem;
            font-weight: bold;
            padding: 14px 36px;
            border-radius: 6px;
            letter-spacing: 0.02em;
        }

	</style>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
    <body>
        <header class="relative scale-10 text-white my-2">
            <div class="mx-auto ">
                    <a class="inline-flex ml-4 h-8 w-auto" href="{{ config('app.url') }}" target="_blank">
                        <img class="h-16 w-auto" src="{{ versionedStorageAsset('themes/March2025/sos-vault_logo_small.png') }}" alt="sos-vault">
                    </a>
            </div>
        </header>

        <div role="main" class="px-4 py-2 overflow-none">

            <div class="text-[#3a3a14]">
                <h1 class="text-2xl font-semibold green">Welcome to sos-vault!</h1>
            </div>

            <div class="flex flex-col my-2 w-full justify-start items-start">
                <div class="border-2 border-gray-200 my-2 p-2 text-normal bg-gray-100">
                    Hi {{ $name }},
                    <br>
                    <br>
                    Your sos-vault account has been created and your vault is ready to use.
                    <br>
                    <br>
                    As a final step, please click the button below to set a password and activate your account:
                    <br>
                    <br>
                    <a href="{{ config('app.url') }}/password/reset/{{ $token }}" class="btn-verify" target="_blank">Set My Password</a>
                    <br>
                    <br>
                    Your login details:
                    <br>
                    &nbsp;&nbsp;<strong>Username:</strong> {{ $username }}
                    <br>
                    &nbsp;&nbsp;<strong>Email:</strong> {{ $email }}
                    <br>
                    <br>
                    This link will expire in 30 minutes for security reasons. If it has expired, you can
                    request a new one from the <a href="{{ config('app.url') }}/password/reset" style="color:#7b9041;">login page</a>.
                    <br>
                    <br>
                    Thank you for choosing sos-vault,
                    <br>
                    The sos-vault Team
                </div>
            </div>
        </div>

        <div class="flex flex-col my-2 w-full justify-center items-center px-8">
            <div class="text-xs mt-8">
                © {{ date("Y") }} sos-vault. All rights reserved.
            </div>
        </div>
    </body>
</html>

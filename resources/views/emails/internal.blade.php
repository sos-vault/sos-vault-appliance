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

        <div role="main" class="p-4 overflow-none mt-4">

            <div class="green">
                <h1 class="text-2xl font-semibold">{{ $title }}</h1>
                <h2 class="my-2">Customer data:</h2>
            </div>

            <hr>

            <div class="flex flex-col my-2 w-full justify-start items-start">
                <div class="w-full p-2 text-gray-800 " >
                    <table>
                        <tr><td align="right"><b>name:</b></td>     <td class="pl-4" align="left">{{ $name }}</td></tr>
                        <tr><td align="right"><b>username:</b></td> <td class="pl-4" align="left">{{ $username }}</td></tr>
                        <tr><td align="right"><b>uid:</b></td>      <td class="pl-4" align="left">{{ $uid }}</td></tr>
                        <tr><td align="right"><b>email:</b></td>    <td class="pl-4" align="left">{{ $email }}</td></tr>
                        <tr><td align="right"><b>plans:</b></td>    <td class="pl-4" align="left">{{ $plans }}</td></tr>
                        <tr><td align="right"><b>days left:</b></td><td class="pl-4" align="left">{{ $daysleft }}</td></tr>
                        <tr><td align="right"><b>since:</b></td>    <td class="pl-4" align="left">{{ $since }}</td></tr>
                    </table>
                </div>

                <hr>

                <div class="border-2 border-gray-200 my-4 p-2 ">
                    {{ $body }}
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

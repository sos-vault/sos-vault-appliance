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

        <div role="main" class="px-4 py-2 overflow-none">

            <div class="text-[#3a3a14]">
                <h1 class="text-2xl font-semibold">{{ $title }}</h1>
            </div>

            <div class="flex flex-col my-2 w-full justify-start items-start">
                <div class="border-2 border-gray-200 my-2 p-2 text-normal bg-gray-100">
                    Hi {{ $name }}!
                    <br>
                    <br>
                    Thank you for trying out sos-vault. Hope you are enjoying it.
                    <br>
                    <br>
                    Just a friendly reminder that you have <b>{{ $daysleft }} days remaining</b> on your {{ $plan }}.
                    <br>
                    <br>
                    Upgrade before <b>{{ $enddate }}</b> to maintain access to premium features like Advanced Tools, Direct Upload and Support!
                    <br>
                    <br>
                    <a href="{{ config('app.url') }}/#pricing" class="bggreen" target="_blank">Upgrade Plan</a>
                    <br>
                    <br>
                    <strong>
                    Please be aware that once the trial period has ended, you will no longer have access to your vault.
                    Your vault will be deleted 7 days after the trial ends on {{ $deletedate }} and all its contents
                    will be lost. So you have up to seven days after your trail ends to upgrade your plan.
                    </strong>
                    <br>
                    <br>
                    Our mission is to make Linux system support easier, faster, and more collaborative.
                    With sos-vault, you can securely store, browse, and analyze your sosreports with confidence.
                    <br>
                    <br>
                    We’re here to support you every step of the way, and we’re always looking to improve. Your
                    feedback is invaluable in making sos-vault more useful and robust for everyone.
                    Don’t be shy — we’d love to hear from you!
                    <br>
                    <br>
                    If you have any questions or need a hand, feel free to contact our <a href="mailto:support@sos-vault.com">support team</a> we're here to help.
                    <br>
                    <br>
                    Thank you,
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

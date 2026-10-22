<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <title>sos-vault Account Reactivated</title>
    <meta name="viewport" content="width=device-width">
    <style type="text/css">
        @import url(https://fonts.googleapis.com/css?family=Droid+Sans);
        body { font-family:'Droid Sans', sans-serif; font-size:10pt; color:#555; }
        .bggreen { background-color: #3a3a14; }
    </style>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body>
    <header class="relative text-white my-2">
        <div class="mx-auto">
            <a class="inline-flex ml-4 h-8 w-auto" href="{{ config('app.url') }}" target="_blank">
                <img class="h-16 w-auto" src="{{ versionedStorageAsset('themes/March2025/sos-vault_logo_small.png') }}" alt="sos-vault">
            </a>
        </div>
    </header>

    <div role="main" class="px-4 py-2">
        <div class="text-green-800">
            <h1 class="text-2xl font-semibold">Your account has been reactivated</h1>
        </div>

        <div class="flex flex-col my-2 w-full justify-start items-start">
            <div class="border-2 border-green-200 my-2 p-4 text-normal bg-green-50">
                Hi {{ $name }}!
                <br><br>

                Great news — your sos-vault account has been <strong>reactivated</strong>.

                @if($reason === 'chargeback_rejected')
                    The chargeback dispute has been resolved in your favour and your account access has been restored.
                @elseif($reason === 'subscription_activated')
                    Your subscription has been re-activated via Paddle and your account access has been fully restored.
                @elseif($reason === 'admin_action')
                    An administrator has manually reviewed and reactivated your account.
                @endif

                <br><br>

                You can now log in normally and your vault will be accessible again.

                <br><br>

                If you have any questions, please reach us at
                <a href="mailto:support@sos-vault.com" class="text-blue-600">support@sos-vault.com</a>.

                <br><br>
                Thank you for using sos-vault.
                <br><br>
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

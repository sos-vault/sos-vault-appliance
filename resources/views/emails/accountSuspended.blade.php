<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <title>sos-vault Account Suspended</title>
    <meta name="viewport" content="width=device-width">
    <style type="text/css">
        @import url(https://fonts.googleapis.com/css?family=Droid+Sans);
        body { font-family:'Droid Sans', sans-serif; font-size:10pt; color:#555; }
        .bgred { background-color: #7a1c1c; }
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
        <div class="text-red-800">
            <h1 class="text-2xl font-semibold">Your account has been suspended</h1>
        </div>

        <div class="flex flex-col my-2 w-full justify-start items-start">
            <div class="border-2 border-red-200 my-2 p-4 text-normal bg-red-50">
                Hi {{ $name }}!
                <br><br>

                @if($reason === 'cancellation')
                    We are writing to let you know that your sos-vault subscription has been <strong>cancelled</strong>
                    and, as a result, your account has been suspended.
                @elseif($reason === 'refund')
                    We are writing to let you know that a <strong>payment refund</strong> has been approved on your
                    account and, as a result, your account has been suspended.
                @elseif($reason === 'chargeback')
                    We are writing to let you know that a <strong>chargeback</strong> has been filed and approved
                    against your account and, as a result, your account has been suspended.
                @else
                    We are writing to let you know that your sos-vault account has been suspended due to a billing issue.
                @endif

                <br><br>

                <strong>What this means for you:</strong>
                <ul class="list-disc ml-6 mt-2">
                    <li>Your vault is now closed and inaccessible.</li>
                    <li>You may still log in and use the support bot to reach us.</li>
                    <li>Your data is preserved — it will not be deleted.</li>
                </ul>

                <br>

                If you believe this is a mistake, or if you would like to resolve the issue and have your account
                reactivated, please contact us at
                <a href="mailto:support@sos-vault.com" class="text-blue-600">support@sos-vault.com</a>
                and we will be happy to assist you.

                <br><br>

                You can also log in and use our support bot — type <strong>/inquiry</strong> or
                <strong>/complain</strong> — to reach our team directly.

                <br><br>

                Thank you for using sos-vault. We hope to resolve this matter quickly.
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

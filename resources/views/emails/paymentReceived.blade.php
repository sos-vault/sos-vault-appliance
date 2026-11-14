<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <title>sos-vault Payment Received</title>
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
        <div class="text-[#3a3a14]">
            <h1 class="text-2xl font-semibold">Payment received — thank you!</h1>
        </div>

        <div class="flex flex-col my-2 w-full justify-start items-start">
            <div class="border-2 border-gray-200 my-2 p-4 text-normal bg-gray-50">
                Hi {{ $name }}!
                <br><br>

                We have successfully processed your latest sos-vault subscription payment.
                Thank you for continuing to trust us with your SOS report analysis needs.

                <br><br>

                <strong>Payment summary:</strong>
                <ul class="list-disc ml-6 mt-2">
                    <li>Your subscription is <strong>active</strong> and fully renewed.</li>
                    @if(!empty($tokens))
                        <li>Your AI token balance has been topped up to <strong>{{ $tokens }}</strong>.</li>
                    @endif
                    @if(!empty($next_payment_at))
                        <li>Next payment due: <strong>{{ $next_payment_at }}</strong>.</li>
                    @endif
                </ul>

                <br>

                If you have any questions about your subscription or invoice, please contact us at
                <a href="mailto:support@sos-vault.com" class="text-blue-600">support@sos-vault.com</a>.

                <br><br>
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

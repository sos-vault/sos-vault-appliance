<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
	<title>Error 429 - Too Many Requests</title>
	<meta name="viewport" content="width=device-width">
	@if(! empty($exception) && method_exists($exception, 'getHeaders') && ! empty($exception->getHeaders()['Retry-After']))
		<meta http-equiv="refresh" content="{{ (int) $exception->getHeaders()['Retry-After'] }}">
	@endif
	<style type="text/css">
		@import url(https://fonts.googleapis.com/css?family=Droid+Sans);

		body {
			font-family:'Droid Sans', sans-serif;
			font-size:10pt;
			color:#555;
			line-height: 25px;
		}

		.wrapper {
			width:760px;
			margin:0 auto 5em auto;
		}

		.error-spacer {
			height:4em;
		}

		a, a:visited {
			color:#2972A3;
		}

		a:hover {
			color:#72ADD4;
		}

        .green {
            color: #7b9041;
            font-size: 1.0rem;
        }

		.main {
			overflow:hidden;
		}
	</style>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body>
    <header class="relative z-30">
        <div class="mx-auto ">
            <div class="flex items-center justify-center w-full mt-16">
                <div class="inline-flex ml-2">
                    <img class="h-24 w-auto" src="{{ versionedStorageAsset('themes/March2025/sos-vault_logo.png') }}" alt="sos-vault">
                </div>
            </div>
        </div>
    </header>

	<div class="wrapper">
		<div class="error-spacer"></div>
		<div role="main" class="main">
			<?php $messages = array('Easy there!', 'One moment, please.', 'Catching our breath.'); ?>

            <div>
                <h1 class="text-2xl green"><?php echo $messages[mt_rand(0, 2)]; ?></h1>

                <h2 class="text-3xl mt-2 mb-4 font-semibold">Error 429 (Too Many Requests)</h2>
            </div>

			<hr>

			<h3 class="text-lg mt-4 font-semibold">What does this mean?</h3>

			<p class="mt-4 text-lg">
                sos-vault received a lot of requests from you in a short window and briefly
                paused new ones to keep the service responsive for everyone. This usually
                clears on its own within a minute &mdash; this page will retry automatically,
                or you can just reload it shortly.
			</p>

			<p class="mt-4 text-lg">
                If it keeps happening during a genuine workload (for example a batch upload),
                let us know at <a href="{{ config('app.url') }}/contactus">Contact&nbsp;Us</a>
                and we'll raise your limit.
			</p>
		</div>
	</div>
</body>
</html>

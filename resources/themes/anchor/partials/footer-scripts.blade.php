@filamentScripts
@livewireScripts
<script>
    document.addEventListener('livewire:init', () => {
        // Replace the default "This page has expired" confirm() dialog.
        // A 419 here almost always means the session rotated under us
        // (e.g. immediately after login). A silent reload picks up the
        // new CSRF token and continues the user's flow.
        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                if (status === 419) {
                    preventDefault();
                    window.location.reload();
                }
            });
        });
    });
</script>
@if(config('wave.dev_bar'))
    @include('theme::partials.dev_bar')
@endif

{{--
@if(!auth()->guest() && auth()->user()->hasAnnouncements())
    @include('theme::partials.announcements')
@endif
--}}

<script src="https://www.google.com/recaptcha/api.js"></script>

{{-- @yield('javascript') --}}

@if(setting('analytics.ga_property_id', ''))
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cookieconsent/3.1.1/cookieconsent.min.js"></script>
    <script>
        window._gaPropertyId = '{{ setting("analytics.ga_property_id") }}';

        window.cookieconsent.initialise({
            palette: {
                popup: { background: '#1d1d1d', text: '#ffffff' },
                button: { background: '#f1d600' },
            },
            type: 'opt-in',
            content: {
                message: 'We use analytics cookies to understand how you use this site.',
                allow: 'Accept',
                deny: 'Decline',
                link: 'Learn more',
                href: '/p/privacy-policy',
            },
            onInitialise: function () {
                if (this.hasConsented()) { loadGA4(); }
            },
            onStatusChange: function () {
                if (this.hasConsented()) { loadGA4(); } else { disableGA4(); }
            },
        });

        function loadGA4() {
            if (window._ga4Loaded) { return; }
            window._ga4Loaded = true;
            var s = document.createElement('script');
            s.async = true;
            s.src = 'https://www.googletagmanager.com/gtag/js?id=' + window._gaPropertyId;
            document.head.appendChild(s);
            window.dataLayer = window.dataLayer || [];
            function gtag() { dataLayer.push(arguments); }
            window.gtag = gtag;
            gtag('js', new Date());
            gtag('config', window._gaPropertyId, { anonymize_ip: true });
        }

        function disableGA4() {
            window['ga-disable-' + window._gaPropertyId] = true;
        }
    </script>
@endif

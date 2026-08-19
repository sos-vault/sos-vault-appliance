<div x-data="{ cycle: 'monthly' }">

    {{-- Billing cycle toggle --}}
    <div class="flex flex-col items-center gap-2 mb-6">
        <div class="inline-flex items-center p-1 bg-zinc-100 dark:bg-zinc-700 rounded-lg">
            <button
                @click="cycle = 'monthly'"
                :class="cycle === 'monthly'
                    ? 'bg-white dark:bg-zinc-600 text-zinc-900 dark:text-white shadow-sm'
                    : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'"
                class="px-5 py-2 text-sm font-medium rounded-md transition-all duration-200 cursor-pointer"
            >
                {{ __('plan.badge_monthly') }}
            </button>
            <button
                @click="cycle = 'yearly'"
                :class="cycle === 'yearly'
                    ? 'bg-white dark:bg-zinc-600 text-zinc-900 dark:text-white shadow-sm'
                    : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'"
                class="px-5 py-2 text-sm font-medium rounded-md transition-all duration-200 cursor-pointer flex items-center gap-2"
            >
                {{ __('plan.badge_yearly') }}
                <span class="inline-flex items-center px-1.5 py-0.5 text-xs font-semibold bg-success-100 dark:bg-success-900 text-success-700 dark:text-success-300 rounded-full">
                    -17%
                </span>
            </button>
        </div>
    </div>

    <div class="flex flex-wrap mx-auto max-w-4xl gap-4 justify-center">
        @foreach(Wave\Plan::where('type', 'disk')->where('active', true)->orderBy('monthly_price')->get() as $plan)
            <div class="w-full max-w-xs">
                <div class="relative flex flex-col h-full bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg shadow-md p-6">

                    <div class="absolute right-4 top-4">
                        <span class="px-2 py-1 text-xs font-semibold uppercase border border-zinc-300 dark:border-zinc-500 text-zinc-600 dark:text-zinc-300 rounded">
                            {{ $plan->name }}
                        </span>
                    </div>

                    <div class="mt-8 mb-4">
                        {{-- Monthly price --}}
                        <div x-show="cycle === 'monthly'">
                            <span class="font-mono text-4xl font-bold text-zinc-900 dark:text-white">${{ $plan->monthly_price }}</span>
                            <span class="text-sm font-semibold text-zinc-500">{{ __('settings.plans_usd_month') }}</span>
                        </div>
                        {{-- Yearly price --}}
                        <div x-show="cycle === 'yearly'" x-cloak>
                            <span class="font-mono text-4xl font-bold text-zinc-900 dark:text-white">${{ number_format($plan->yearly_price / 12, 0) }}</span>
                            <span class="text-sm font-semibold text-zinc-500">{{ __('settings.plans_usd_month') }}</span>
                            <div class="mt-0.5 text-xs text-zinc-400">${{ number_format($plan->yearly_price, 0) }} {{ __('settings.plans_billed_annually') }}</div>
                        </div>
                    </div>

                    <p class="text-sm text-zinc-500 dark:text-zinc-300 mb-6">{{ $plan->description }}</p>

                    @if($plan->status !== 'pending')
                        <button
                            class="checkout w-full py-3 text-sm font-medium text-white bg-primary-600 hover:bg-primary-500 rounded-lg transition duration-150"
                            plan-type="disk"
                            plan-id="{{ $plan->id }}"
                            x-bind:data-plan="cycle === 'monthly' ? '{{ $plan->monthly_price_id }}' : '{{ $plan->yearly_price_id }}'"
                        >
                            {{ __('settings.disk_expansion_select_button') }}
                        </button>
                    @else
                        <div class="w-full py-3 text-center text-sm text-zinc-400">{{ __('settings.plans_coming_soon') }}</div>
                    @endif

                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-6 flex items-start gap-2 text-xs text-zinc-400 dark:text-zinc-500 max-w-2xl mx-auto">
        <i class="ph-duotone ph-info text-base mt-0.5 shrink-0"></i>
        <span>{{ __('settings.disk_expansion_disclaimer') }}</span>
    </div>

    @if(config('wave.billing_provider') == 'paddle')
        <script>
            (function () {
                // Initialize Paddle if not already done on this page
                if (typeof window.diskPlansInitialized === 'undefined') {
                    window.diskPlansInitialized = true;

                    var paddlePublicKey = '{{ config("wave.paddle.public_key") }}';
                    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

                    function initPaddle() {
                        if (typeof Paddle === 'undefined') { return; }
                        if (window.diskPaddleReady) { return; }
                        window.diskPaddleReady = true;

                        Paddle.Initialize({
                            token: paddlePublicKey,
                            checkout: {
                                settings: {
                                    displayMode: 'overlay',
                                    frameStyle: 'width: 100%; min-width: 312px; background-color: transparent; border: none;',
                                    locale: 'en',
                                    allowLogout: false,
                                },
                            },
                            eventCallback: function (data) {
                                if (data.name === 'checkout.completed') {
                                    handleDiskCheckoutComplete(data.data);
                                }
                            },
                        });

                        if ('{{ config('wave.paddle.env') }}' === 'sandbox') {
                            Paddle.Environment.set('sandbox');
                        }
                    }

                    function injectAndInit() {
                        if (typeof Paddle === 'undefined') {
                            var s = document.createElement('script');
                            s.src = 'https://cdn.paddle.com/paddle/v2/paddle.js';
                            s.onload = initPaddle;
                            document.head.appendChild(s);
                        } else {
                            initPaddle();
                        }
                    }

                    window.handleDiskCheckoutComplete = function (data) {
                        var planId = window.diskSelectedPlanId;
                        axios.post('/expandDisk', { item: planId, _token: csrf })
                            .then(function (response) {
                                var type = parseInt(response.data.status) === 1 ? 'success' : 'error';
                                if (typeof popToast === 'function') { popToast(type, response.data.message); }
                                setTimeout(function () { window.location = '/dashboard'; }, 5000);
                            });
                    };

                    document.addEventListener('DOMContentLoaded', injectAndInit);
                    document.addEventListener('livewire:navigated', injectAndInit);
                    injectAndInit();
                }

                // Attach click handlers to checkout buttons in this partial
                document.addEventListener('DOMContentLoaded', function () { attachDiskCheckoutHandlers(); });
                document.addEventListener('livewire:navigated', function () { attachDiskCheckoutHandlers(); });
                attachDiskCheckoutHandlers();

                function attachDiskCheckoutHandlers() {
                    var btns = document.querySelectorAll('.checkout[plan-type="disk"]');
                    btns.forEach(function (btn) {
                        btn.removeEventListener('click', diskBtnClick);
                        btn.addEventListener('click', diskBtnClick);
                    });
                }

                function diskBtnClick() {
                    var planId   = this.getAttribute('plan-id');
                    var priceId  = this.getAttribute('data-plan');
                    var userRole = '{{ auth()->user()?->roles?->first()?->name ?? "" }}';

                    window.diskSelectedPlanId = planId;
                    window.item = planId;
                    window.planType = 'disk';

                    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

                    if (userRole === 'admin') {
                        // Admin: skip Paddle, expand directly
                        axios.post('/expandDisk', { item: planId, _token: csrf })
                            .then(function (response) {
                                var type = parseInt(response.data.status) === 1 ? 'success' : 'error';
                                if (typeof popToast === 'function') { popToast(type, response.data.message); }
                                setTimeout(function () { window.location = '/dashboard'; }, 5000);
                            });
                    } else if (paddlePublicKey && typeof Paddle !== 'undefined') {
                        Paddle.Checkout.open({
                            items: [{ priceId: priceId, quantity: 1 }],
                            customer: { email: '{{ auth()->user()?->email ?? "" }}' },
                        });
                    } else {
                        alert('Payment system not available. Please try again.');
                    }
                }
            })();
        </script>
    @endif

</div>

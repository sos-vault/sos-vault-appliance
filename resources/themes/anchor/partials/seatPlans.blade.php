@php
    $seatPlans = Wave\Plan::where('type', 'seat')->where('active', true)->orderBy('display_order')->orderBy('monthly_price')->get();
    $group = App\Models\Group::where('owner_id', auth()->id())->first();
    $usedSeats = $group ? $group->members()->count() : 0;
    $totalSeats = $group ? $group->max_members : 0;
@endphp

<div x-data="{ cycle: 'monthly' }">

    {{-- Current seat usage --}}
    @if($group)
        <div class="flex items-center gap-2 mb-4 text-sm text-zinc-500 dark:text-zinc-400">
            <i class="ph-duotone ph-users text-lg text-primary-500"></i>
            <span>{{ __('settings.seat_addon_current_usage', ['used' => $usedSeats, 'total' => $totalSeats]) }}</span>
        </div>
    @endif

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
        @foreach($seatPlans as $plan)
            <div class="w-full max-w-xs" x-data="{ qty: 1 }">
                <div class="relative flex flex-col h-full bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg shadow-md p-6">

                    <div class="absolute right-4 top-4">
                        <span class="px-2 py-1 text-xs font-semibold uppercase border border-zinc-300 dark:border-zinc-500 text-zinc-600 dark:text-zinc-300 rounded">
                            {{ $plan->name }}
                        </span>
                    </div>

                    <div class="mt-8 mb-4">
                        {{-- Monthly price (per seat) --}}
                        <div x-show="cycle === 'monthly'">
                            <span class="font-mono text-4xl font-bold text-zinc-900 dark:text-white">${{ $plan->monthly_price }}</span>
                            <span class="text-sm font-semibold text-zinc-500">{{ __('settings.plans_usd_month') }}</span>
                            <div class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-400">{{ __('plan.per_seat') }}</div>
                        </div>
                        {{-- Yearly price (per seat) --}}
                        <div x-show="cycle === 'yearly'" x-cloak>
                            <span class="font-mono text-4xl font-bold text-zinc-900 dark:text-white">${{ number_format($plan->yearly_price / 12, 0) }}</span>
                            <span class="text-sm font-semibold text-zinc-500">{{ __('settings.plans_usd_month') }}</span>
                            <div class="mt-0.5 text-xs text-zinc-400">${{ number_format($plan->yearly_price, 0) }} {{ __('settings.plans_billed_annually') }}</div>
                            <div class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-400">{{ __('plan.per_seat') }}</div>
                        </div>
                    </div>

                    <p class="text-sm text-zinc-500 dark:text-zinc-300 mb-4">{{ $plan->description }}</p>

                    @if($plan->status !== 'pending')
                        {{-- Quantity selector --}}
                        <div class="flex items-center gap-3 mb-4">
                            <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('settings.seat_addon_quantity_label') }}</span>
                            <div class="flex items-center border border-zinc-200 dark:border-zinc-600 rounded-lg overflow-hidden">
                                <button
                                    @click="qty = Math.max(1, qty - 1)"
                                    class="px-3 py-1.5 text-sm font-bold text-zinc-600 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors"
                                    type="button"
                                >−</button>
                                <span x-text="qty" class="px-3 py-1.5 text-sm font-semibold text-zinc-800 dark:text-white min-w-[2rem] text-center border-x border-zinc-200 dark:border-zinc-600"></span>
                                <button
                                    @click="qty = Math.min(20, qty + 1)"
                                    class="px-3 py-1.5 text-sm font-bold text-zinc-600 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors"
                                    type="button"
                                >+</button>
                            </div>
                        </div>

                        <button
                            @click="openSeatCheckout(cycle === 'monthly' ? '{{ $plan->monthly_price_id }}' : '{{ $plan->yearly_price_id }}', {{ $plan->id }}, qty)"
                            class="w-full py-3 text-sm font-medium text-white bg-primary-600 hover:bg-primary-500 rounded-lg transition duration-150"
                            type="button"
                        >
                            <span x-text="'{{ __('settings.seat_addon_subscribe_button', ['qty' => '']) }}'.replace(':qty', qty)"></span>
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
        <span>{{ __('settings.seat_addon_disclaimer') }}</span>
    </div>

    @if(config('wave.billing_provider') == 'paddle')
        <script>
            (function () {
                if (typeof window.seatPlansInitialized === 'undefined') {
                    window.seatPlansInitialized = true;

                    var paddlePublicKey = '{{ config("wave.paddle.public_key") }}';
                    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

                    window.seatSelectedPlanId = null;
                    window.seatSelectedQty = 1;

                    function initSeatPaddle() {
                        if (typeof Paddle === 'undefined') { return; }
                        // Paddle may already be initialized by the service plans partial — skip re-init
                        if (window.paddleInitialized || window.diskPaddleReady) { return; }
                        window.seatPaddleReady = true;

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
                                    handleSeatCheckoutComplete(data.data);
                                }
                            },
                        });

                        if ('{{ config('wave.paddle.env') }}' === 'sandbox') {
                            Paddle.Environment.set('sandbox');
                        }
                    }

                    function injectAndInitSeat() {
                        if (typeof Paddle === 'undefined') {
                            var s = document.createElement('script');
                            s.src = 'https://cdn.paddle.com/paddle/v2/paddle.js';
                            s.onload = initSeatPaddle;
                            document.head.appendChild(s);
                        } else {
                            initSeatPaddle();
                        }
                    }

                    window.handleSeatCheckoutComplete = function (data) {
                        var planId = window.seatSelectedPlanId;
                        var qty    = window.seatSelectedQty;
                        axios.post('/addSeats', { item: planId, quantity: qty, _token: csrf })
                            .then(function (response) {
                                var type = parseInt(response.data.status) === 1 ? 'success' : 'error';
                                if (typeof popToast === 'function') { popToast(type, response.data.message); }
                                setTimeout(function () { window.location.reload(); }, 3000);
                            });
                    };

                    document.addEventListener('DOMContentLoaded', injectAndInitSeat);
                    document.addEventListener('livewire:navigated', injectAndInitSeat);
                    injectAndInitSeat();
                }

                window.openSeatCheckout = function (priceId, planId, qty) {
                    if (! priceId || priceId === '') {
                        alert('{{ __('settings.plans_coming_soon') }}');
                        return;
                    }
                    window.seatSelectedPlanId = planId;
                    window.seatSelectedQty    = qty;

                    if (paddlePublicKey && typeof Paddle !== 'undefined') {
                        Paddle.Checkout.open({
                            items: [{ priceId: priceId, quantity: qty }],
                            customer: { email: '{{ auth()->user()?->email ?? "" }}' },
                        });
                    } else {
                        alert('Payment system not available. Please try again.');
                    }
                };

            })();
        </script>
    @endif

</div>

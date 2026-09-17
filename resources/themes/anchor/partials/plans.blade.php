@php
    $plans = Wave\Plan::whereIn('type', ['service', 'seat'])
        ->whereEnglishNameNot('Free')
        ->where(fn ($q) => $q->where('active', true)->orWhere('status', 'pending'))
        ->with('planFeatures')
        ->orderBy('display_order')
        ->orderBy('monthly_price')
        ->get();
    $planCount = $plans->count();
@endphp

<div class="flex flex-col w-full" x-data="{
    cycle: 'monthly',
    slide: 0,
    total: {{ $planCount }},
    perPage: window.innerWidth < 768 ? 1 : 2,
    init() {
        const update = () => {
            const newPerPage = window.innerWidth < 768 ? 1 : 2;
            if (newPerPage !== this.perPage) {
                this.perPage = newPerPage;
                this.slide = Math.min(this.slide, Math.max(0, this.total - this.perPage));
            }
        };
        window.addEventListener('resize', update);
    }
}">

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
        <p x-show="cycle === 'yearly'" x-cloak class="text-xs text-zinc-400 dark:text-zinc-500">
            {{ __('settings.plans_yearly_savings_note') }}
        </p>
    </div>

    <div class="flex w-auto self-center p-2 bg-transparent
        md:text-sm text-xs
        text-start leading-6
        text-zinc-800 dark:text-white
    ">
        <i class="ph-duotone ph-warning-circle text-3xl text-primary-600 p-2"></i>
        {{ __('settings.plans_price_disclaimer') }}
    </div>

    {{-- Carousel --}}
    <div class="relative mt-6 max-w-4xl mx-auto w-full px-12 overflow-visible">

        {{-- Prev button --}}
        <button
            @click="slide = Math.max(0, slide - 1)"
            :disabled="slide === 0"
            :class="slide === 0 ? 'opacity-30 cursor-not-allowed' : 'hover:bg-zinc-100 dark:hover:bg-zinc-700 cursor-pointer'"
            class="absolute left-0 top-1/2 -translate-y-1/2 z-50 flex items-center justify-center w-9 h-9 rounded-full bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-600 shadow transition-all duration-200"
            aria-label="Previous plan"
        >
            <i class="ph-bold ph-caret-left text-zinc-600 dark:text-zinc-300"></i>
        </button>

        {{-- Slides container --}}
        <div class="overflow-hidden rounded-lg">
            <div
                class="flex transition-transform duration-300 ease-in-out"
                :style="`transform: translateX(-${slide * (100 / perPage)}%)`"
            >
                @foreach($plans as $plan)
                    <div class="w-full md:w-1/2 shrink-0 px-3">
                        <div class="relative flex flex-col h-full bg-white dark:text-white dark:bg-zinc-800 border border-zinc-200 rounded-lg shadow-xl">
                            <div class="px-10 pt-7">
                                @if($plan->status == "pending")
                                    <span class="absolute z-40 lg:right-32 w-auto lg:-mt-4 -mt-8 bg-primary-100 text-primary-800 text-xs font-medium me-2 px-3.5 py-1.5 rounded-full dark:bg-primary-900 dark:text-primary-300">{{ __('settings.plans_coming_soon') }}</span>
                                @endif

                                <div class="absolute right-0 inline-block mr-6 transform">
                                    <h2 class="relative z-20 w-full h-full px-2 py-1 text-xs font-semibold leading-tight tracking-wide text-center uppercase bg-white dark:text-white dark:bg-zinc-800 border-1 @if($plan->default){{ 'border-primary-400 text-primary-500' }}@else{{ 'border-zinc-900 text-zinc-800' }}@endif rounded">{{ $plan->name }}</h2>
                                </div>
                            </div>

                            <div class="pl-4 lg:mt-2 mt-8 lg:min-h-[3.0rem] min-h-[4.0rem]">
                                {{-- Monthly price --}}
                                <div x-show="cycle === 'monthly'">
                                    <span class="font-mono text-4xl font-bold">${{ $plan->monthly_price }}</span>
                                    <span class="text-lg font-semibold text-zinc-500">{{ __('settings.plans_usd_month') }}</span>
                                </div>
                                {{-- Yearly price --}}
                                <div x-show="cycle === 'yearly'" x-cloak>
                                    <span class="font-mono text-4xl font-bold">${{ number_format($plan->yearly_price / 12, 0) }}</span>
                                    <span class="text-lg font-semibold text-zinc-500">{{ __('settings.plans_usd_month') }}</span>
                                    <div class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-400">
                                        ${{ number_format($plan->yearly_price, 0) }} {{ __('settings.plans_billed_annually') }}
                                    </div>
                                </div>
                            </div>

                            <div class="px-4 mt-2 pb-1 lg:h-24 h-48">
                                <p class="text-lg leading-7 h-16 text-zinc-500 dark:text-zinc-100">{{ $plan->description }}</p>
                            </div>

                            @if($plan->type === 'seat')
                                {{-- Extra seat add-on: only Team/Enterprise owners can subscribe --}}
                                @if($plan->status === 'pending')
                                    <div class="px-4 py-4">&nbsp;</div>
                                @else
                                    @auth
                                        @if(auth()->user()->hasRole(['Team', 'Enterprise', 'Self-hosted']) && auth()->user()->isTeamManager())
                                            <button
                                                @click="openSeatCheckout(cycle === 'monthly' ? '{{ $plan->monthly_price_id }}' : '{{ $plan->yearly_price_id }}', {{ $plan->id }}, 1)"
                                                class="self-center flex justify-center w-2/3 p-4 text-base font-medium text-white transition duration-150 ease-in-out bg-primary-500 hover:bg-primary-400 border border-transparent rounded-lg focus:outline-none"
                                            >
                                                {{ __('settings.subscription_subscribe_button') }}
                                            </button>
                                        @else
                                            <div class="self-center flex justify-center w-2/3 p-3 text-sm text-center text-zinc-400 dark:text-zinc-500 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                                                {{ __('settings.seat_addon_requires_team_label') }}
                                            </div>
                                        @endif
                                    @else
                                        <div class="self-center flex justify-center w-2/3 p-3 text-sm text-center text-zinc-400 dark:text-zinc-500 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                                            {{ __('settings.seat_addon_requires_team_label') }}
                                        </div>
                                    @endauth
                                @endif
                            @elseif($plan->status != "pending")
                                @auth
                                    @if(auth()->user()->subscribedToPlan($plan->getTranslation('name', 'en')))
                                        <div class="self-center flex justify-center w-2/3 p-4 text-base font-medium text-green-600 cursor-default border border-zinc-200 rounded-lg">
                                            {{ __('settings.subscription_subscribed_label') }}
                                        </div>
                                    @elseif(auth()->user()->subscriber())
                                        <div x-data="{ confirm{{ $plan->id }}: false }" class="flex flex-col items-center w-full px-4">
                                            <button
                                                @click="confirm{{ $plan->id }} = true"
                                                class="self-center w-2/3 p-4 text-base font-medium text-white transition duration-150 ease-in-out bg-zinc-800 hover:bg-zinc-700 border border-transparent rounded-lg focus:outline-none"
                                            >
                                                {{ __('settings.subscription_switch_plan_button') }}
                                            </button>
                                            <div
                                                x-show="confirm{{ $plan->id }}"
                                                x-cloak
                                                class="mt-3 w-full p-4 bg-zinc-50 dark:bg-zinc-700 rounded-lg border border-zinc-200 dark:border-zinc-600 text-center"
                                            >
                                                <p class="text-sm text-zinc-600 dark:text-zinc-300 mb-3">{{ __('settings.subscription_switch_plan_confirm', ['plan' => $plan->name]) }}</p>
                                                <div class="flex gap-2 justify-center">
                                                    <button
                                                        @click="confirm{{ $plan->id }} = false"
                                                        class="px-4 py-2 text-sm text-zinc-600 bg-white dark:bg-zinc-600 dark:text-zinc-100 border border-zinc-300 rounded-lg hover:bg-zinc-100"
                                                    >
                                                        {{ __('settings.cancel') }}
                                                    </button>
                                                    <button
                                                        @click="Livewire.dispatch('switchPlanById', { planId: {{ $plan->id }}, cycle: cycle === 'monthly' ? 'month' : 'year' }); confirm{{ $plan->id }} = false"
                                                        class="px-4 py-2 text-sm text-white bg-primary-600 hover:bg-primary-500 border border-transparent rounded-lg"
                                                    >
                                                        {{ __('settings.subscription_switch_plan_confirm_button') }}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        <button
                                            @click="openCheckout(cycle === 'monthly' ? '{{ $plan->monthly_price_id }}' : '{{ $plan->yearly_price_id }}')"
                                            class="self-center flex justify-center w-2/3 p-4 text-base font-medium text-white transition duration-150 ease-in-out @if($plan->default){{ 'bg-gradient-to-r from-warning-700 to-warning-600 hover:from-warning-600 hover:to-warning-700' }}@else{{ 'bg-primary-500 hover:bg-primary-400' }}@endif border border-transparent rounded-lg focus:outline-none"
                                        >
                                            {{ __('settings.subscription_subscribe_button') }}
                                        </button>
                                    @endif
                                @else
                                    <button
                                        @click="openCheckout(cycle === 'monthly' ? '{{ $plan->monthly_price_id }}' : '{{ $plan->yearly_price_id }}')"
                                        class="self-center flex justify-center w-2/3 p-4 text-base font-medium text-white transition duration-150 ease-in-out @if($plan->default){{ 'bg-gradient-to-r from-warning-700 to-warning-600 hover:from-warning-600 hover:to-warning-700' }}@else{{ 'bg-primary-500 hover:bg-primary-400' }}@endif border border-transparent rounded-lg focus:outline-none">
                                        {{ __('settings.subscription_get_started') }}
                                    </button>
                                @endauth
                            @else
                                <div class="px-4 py-4">&nbsp;</div>
                            @endif

                            <div class="pl-4 pt-0 pb-12 mt-8 mb-auto rounded-b-lg">
                                <ul class="flex flex-col space-y-4">
                                    @foreach($plan->planFeatures->where('enabled', true) as $feature)
                                        <li class="relative">
                                            <div class="flex items-center text-sm text-balance leading-4 text-zinc-500 dark:text-zinc-100">
                                                <i class="fas fa-check fa-lg w-4 h-4 pt-2 mr-3 text-primary-700"></i>

                                                @if($feature->description && ($feature->type === 'bool' || !$feature->amount))
                                                    <span>{{ $feature->description }}</span>
                                                @else
                                                    <span>
                                                        {{ $feature->name }}&nbsp;
                                                        @if($feature->type === 'numeric' && $feature->amount > 0)
                                                            <span class="font-semibold">{{ intval($feature->amount) }} {{ $feature->units }}</span>
                                                        @endif
                                                    </span>
                                                @endif
                                            </div>
                                            @if($feature->type === 'numeric' && $feature->description)
                                                <div class="ml-9 max-w-48 text-zinc-400 dark:text-zinc-300 text-sm">
                                                    ({{ $feature->description }})
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>

                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Next button --}}
        <button
            @click="slide = Math.min(total - perPage, slide + 1)"
            :disabled="slide >= total - perPage"
            :class="slide >= total - perPage ? 'opacity-30 cursor-not-allowed' : 'hover:bg-zinc-100 dark:hover:bg-zinc-700 cursor-pointer'"
            class="absolute right-0 top-1/2 -translate-y-1/2 z-50 flex items-center justify-center w-9 h-9 rounded-full bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-600 shadow transition-all duration-200"
            aria-label="Next plan"
        >
            <i class="ph-bold ph-caret-right text-zinc-600 dark:text-zinc-300"></i>
        </button>
    </div>

    {{-- Dot indicators --}}
    <div class="flex justify-center gap-2 mt-5">
        @for($i = 0; $i < $planCount; $i++)
            <button
                @click="slide = {{ $i }}"
                x-show="{{ $i }} <= total - perPage"
                :class="slide === {{ $i }} ? 'bg-primary-600 w-4' : 'bg-zinc-300 dark:bg-zinc-600 w-2'"
                class="h-2 rounded-full transition-all duration-200"
                aria-label="Go to slide {{ $i + 1 }}"
            ></button>
        @endfor
    </div>

    @if(config('wave.paddle.env') == 'sandbox')
        <div class="px-2 mx-auto mt-12 max-w-4xl w-full">
            <div class="w-full p-10 text-zinc-600 bg-blue-50 dark:bg-zinc-600 dark:text-zinc-100">
                <div class="flex items-center pb-4">
                    <i class="fas fa-credit-card fa-xl w-8 h-8 pt-2 mr-2 text-primary-700"></i>
                    <div class="relative">
                        <h2 class="text-base font-bold text-primary-700">{{ __('settings.plans_sandbox_heading') }}</h2>
                        <p class="text-sm text-zinc-400">{{ __('settings.plans_sandbox_description') }}</p>
                    </div>
                </div>
                <div class="pt-2 text-sm font-bold text-zinc-500 dark:text-zinc-100">
                    {{ __('settings.plans_sandbox_card_number') }} <span class="ml-2 font-mono text-green-500">4242 4242 4242 4242</span>
                </div>
                <div class="pt-2 text-sm font-bold text-zinc-500 dark:text-zinc-100">
                    {{ __('settings.plans_sandbox_expiry') }} <span class="ml-2 font-mono text-green-500">{{ __('settings.plans_sandbox_expiry_value') }}</span>
                </div>
                <div class="pt-2 text-sm font-bold text-zinc-500 dark:text-zinc-100">
                    {{ __('settings.plans_sandbox_security_code') }} <span class="ml-2 font-mono text-green-500">{{ __('settings.plans_sandbox_security_value') }}</span>
                </div>
            </div>
        </div>
    @endif

    @if(config('wave.billing_provider') == 'paddle')

        @auth
            <livewire:billing.checkout :headless="true" />
            <div x-data x-show="false" @loader-show.window="$el.style.display='flex'" @loader-hide.window="$el.style.display='none'"
                 class="hidden fixed inset-0 justify-center items-center w-screen h-screen z-[99999]">
                <div class="absolute inset-0 z-10 w-screen h-screen bg-black opacity-50"></div>
                <div class="flex relative z-20 justify-center items-center px-3.5 py-2 bg-black bg-opacity-30 rounded-full">
                    <svg class="w-4 h-4 text-white animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    <p class="ml-2 font-medium text-white" id="plans-loader-msg">{{ __('settings.plans_loader_loading') }}</p>
                </div>
            </div>
        @endauth

        <script>
            window.paddle_public_key = '{{ config("wave.paddle.public_key") }}';

            window.injectPaddleCDN = function () {
                if (typeof Paddle === 'undefined') {
                    const script = document.createElement('script');
                    script.src = 'https://cdn.paddle.com/paddle/v2/paddle.js';
                    document.head.appendChild(script);
                }
            };

            window.whenPaddleIsReady = function (callback) {
                let interval = setInterval(function () {
                    if (typeof Paddle !== 'undefined') {
                        clearInterval(interval);
                        callback();
                    }
                }, 200);
            };

            window.initialize = function () {
                Paddle.Initialize({
                    token: paddle_public_key,
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
                            @auth
                                // If this was a seat plan checkout, handle it directly
                                if (window.seatSelectedPlanId) {
                                    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
                                    axios.post('/addSeats', {
                                        item: window.seatSelectedPlanId,
                                        quantity: window.seatSelectedQty || 1,
                                        _token: csrf
                                    }).then(function (response) {
                                        window.seatSelectedPlanId = null;
                                        if (typeof popToast === 'function') {
                                            popToast(parseInt(response.data.status) === 1 ? 'success' : 'error', response.data.message);
                                        }
                                        setTimeout(function () { window.location.reload(); }, 3000);
                                    });
                                } else {
                                    verifyPaddleTransaction(data.data);
                                }
                            @else
                                guestCheckoutComplete(data.data);
                            @endauth
                        }
                    },
                });

                if ('{{ config('wave.paddle.env') }}' === 'sandbox') {
                    Paddle.Environment.set('sandbox');
                }
            };

            window.openCheckout = function (priceId) {
                if (paddle_public_key) {
                    Paddle.Checkout.open({
                        items: [{ priceId: priceId, quantity: 1 }],
                        @auth customer: { email: '{{ auth()->user()->email }}' }, @endauth
                    });
                } else {
                    alert('Paddle API keys and tokens must be set in the admin settings.');
                }
            };

            window.openSeatCheckout = function (priceId, planId, qty) {
                if (! priceId) { return; }
                window.seatSelectedPlanId = planId;
                window.seatSelectedQty    = qty || 1;
                if (paddle_public_key) {
                    Paddle.Checkout.open({
                        items: [{ priceId: priceId, quantity: window.seatSelectedQty }],
                        @auth customer: { email: '{{ auth()->user()->email }}' }, @endauth
                    });
                } else {
                    alert('Paddle API keys and tokens must be set in the admin settings.');
                }
            };

            @auth
            window.verifyPaddleTransaction = function (data) {
                window.Livewire.dispatch('verifyPaddleTransaction', { transactionId: data.transaction_id });
            };

            window.savePaddleSubscription = function (transactionId) {
                Paddle.Checkout.close();
                window.dispatchEvent(new CustomEvent('loader-show'));
                window.dispatchEvent(new CustomEvent('loader-message', { detail: { message: 'Verifying Subscription' } }));
                window.Livewire.dispatch('savePaddleSubscription', { transactionId: transactionId });
            };

            window.closeLoader = function () {
                window.dispatchEvent(new CustomEvent('loader-hide'));
            };
            @else
            window.guestCheckoutComplete = function (data) {
                Paddle.Checkout.close();

                // Show full-screen overlay so the user knows work is in progress
                const overlay = document.createElement('div');
                overlay.id = 'guest-checkout-overlay';
                Object.assign(overlay.style, {
                    position: 'fixed', top: '0', left: '0',
                    width: '100%', height: '100%',
                    backgroundColor: 'rgba(0,0,0,0.75)',
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                    zIndex: '9999',
                });
                overlay.innerHTML = `
                    <div style="background:#f4f4f5;border-radius:0.75rem;padding:2rem 2.5rem;max-width:26rem;text-align:center;box-shadow:0 25px 50px rgba(0,0,0,.5);">
                        <svg style="width:2.5rem;height:2.5rem;margin:0 auto 1rem;color:{{ config('wave.primary_color', '#7b9041') }};animation:spin 1s linear infinite;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <p style="color:#94a3b8;font-size:1rem;font-weight:600;margin-bottom:.5rem;">Creating your account&hellip;</p>
                        <p style="color:#94a3b8;font-size:.875rem;">Your vault is being initialised. This may take a few seconds.</p>
                    </div>
                    <style>@keyframes spin{to{transform:rotate(360deg)}}</style>`;
                document.body.appendChild(overlay);

                const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                fetch('/checkout/complete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ transaction_id: data.transaction_id }),
                })
                .then(function (r) {
                    if (! r.ok) { throw new Error('Server error: ' + r.status); }
                    return r.json();
                })
                .then(function (res) {
                    if (res.status === 1) {
                        window.location = res.redirect;
                    } else {
                        alert(res.message || 'An error occurred. Please contact support.');
                    }
                })
                .catch(function (err) {
                    console.error('Checkout error:', err);
                    alert('An error occurred completing your checkout. Please contact support and quote your transaction ID: ' + data.transaction_id);
                });
            };
            @endauth

            document.addEventListener('livewire:navigated', () => { injectPaddleCDN(); whenPaddleIsReady(initialize); });
            document.addEventListener('DOMContentLoaded', () => { injectPaddleCDN(); whenPaddleIsReady(initialize); });
        </script>

    @endif

</div>

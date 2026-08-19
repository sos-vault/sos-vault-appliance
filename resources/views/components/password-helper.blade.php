@php
    $policy = \App\Services\PasswordPolicy::requirements();
    $targetId = $targetId ?? 'password';
    $allowed = $policy['allowed_signs'];
@endphp

<div
    x-data="passwordHelper({
        minLength: {{ (int) $policy['min_length'] }},
        minDigits: {{ (int) $policy['min_digits'] }},
        minUpper: {{ (int) $policy['min_upper'] }},
        minLower: {{ (int) $policy['min_lower'] }},
        minSigns: {{ (int) $policy['min_signs'] }},
        targetId: '{{ $targetId }}',
    })"
    x-init="init()"
    class="mt-2 text-xs text-gray-600 dark:text-gray-300"
>
    <p class="font-medium mb-1">{{ __('auth.password_helper_title') }}</p>
    <ul class="list-disc list-inside space-y-0.5">
        @if ($policy['min_length'] > 0)
            <li :class="counts.length >= {{ (int) $policy['min_length'] }} ? 'text-green-600 dark:text-green-400 font-medium' : ''">
                <span x-text="counts.length"></span> /
                {{ $policy['min_length'] }}
                — {{ __('auth.password_helper_min_length') }}
            </li>
        @endif
        @if ($policy['min_upper'] > 0)
            <li :class="counts.upper >= {{ (int) $policy['min_upper'] }} ? 'text-green-600 dark:text-green-400 font-medium' : ''">
                <span x-text="counts.upper"></span> /
                {{ $policy['min_upper'] }}
                — {{ __('auth.password_helper_min_upper') }}
            </li>
        @endif
        @if ($policy['min_lower'] > 0)
            <li :class="counts.lower >= {{ (int) $policy['min_lower'] }} ? 'text-green-600 dark:text-green-400 font-medium' : ''">
                <span x-text="counts.lower"></span> /
                {{ $policy['min_lower'] }}
                — {{ __('auth.password_helper_min_lower') }}
            </li>
        @endif
        @if ($policy['min_digits'] > 0)
            <li :class="counts.digits >= {{ (int) $policy['min_digits'] }} ? 'text-green-600 dark:text-green-400 font-medium' : ''">
                <span x-text="counts.digits"></span> /
                {{ $policy['min_digits'] }}
                — {{ __('auth.password_helper_min_digits') }}
            </li>
        @endif
        @if ($policy['min_signs'] > 0)
            <li :class="counts.signs >= {{ (int) $policy['min_signs'] }} ? 'text-green-600 dark:text-green-400 font-medium' : ''">
                <span x-text="counts.signs"></span> /
                {{ $policy['min_signs'] }}
                — {{ __('auth.password_helper_min_signs') }}
                <span class="block ml-4 text-gray-500 dark:text-gray-400 break-all">{{ __('auth.password_helper_allowed_signs') }} {{ $allowed }}</span>
            </li>
        @endif
    </ul>
</div>

@once
    <script>
        document.addEventListener('alpine:init', () => {
            window.Alpine.data('passwordHelper', (cfg) => ({
                cfg,
                counts: { length: 0, upper: 0, lower: 0, digits: 0, signs: 0 },
                init() {
                    const el = document.getElementById(this.cfg.targetId);
                    if (! el) return;
                    const update = () => this.recount(el.value);
                    el.addEventListener('input', update);
                    update();
                },
                recount(value) {
                    const v = value || '';
                    this.counts.length = v.length;
                    this.counts.upper = (v.match(/[A-Z]/g) || []).length;
                    this.counts.lower = (v.match(/[a-z]/g) || []).length;
                    this.counts.digits = (v.match(/\d/g) || []).length;
                    this.counts.signs = (v.match(/[^A-Za-z0-9]/g) || []).length;
                },
            }));
        });
    </script>
@endonce

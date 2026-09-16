{{-- Marketing footer (Features, Pricing, Status, Terms, social links, etc.) is
     SaaS-only. The appliance build has no public marketing surface, so it is
     hidden entirely there. --}}
@if (isSaas())
<footer class="pt-10">
    <x-container>
        <div class="flex flex-wrap items-start justify-between pb-20">
            <a href="#_" class="flex items-center w-auto mt-1 text-lg font-bold transition-all duration-300 ease-out brightness-100 md:w-1/6 hover:brightness-100">
                <x-logo-full class="shrink-0 w-auto h-8"></x-logo-full>
            </a>
            <div class="grid w-full grid-cols-2 pt-2 mt-20 gap-y-16 sm:grid-cols-4 lg:gap-x-8 md:w-5/6 md:mt-0 md:pr-6">
                <div class="md:justify-self-end">
                    <h3 class="font-semibold text-black dark:text-wave-700">{{ __('marketing.footer_product') }}</h3>
                    <ul class="mt-6 space-y-4 text-sm">
                        <li>
                            <a href="/#features" class="relative inline-block text-black dark:text-white group">
                                <span class="absolute bottom-0 w-full transition duration-150 ease-out transform -translate-y-1 border-b border-black opacity-0 group-hover:opacity-100 group-hover:translate-y-1"></span>
                                <span>{{ __('marketing.footer_features') }}</span>
                            </a>
                        </li>
                        <li>
                            <a href="/#pricing" class="relative inline-block text-black dark:text-white group">
                                <span class="absolute bottom-0 w-full transition duration-150 ease-out transform -translate-y-1 border-b border-black opacity-0 group-hover:opacity-100 group-hover:translate-y-1"></span>
                                <span>{{ __('marketing.footer_pricing') }}</span>
                            </a>
                        </li>
                        <li>
                            <a href="https://stats.uptimerobot.com/icEt1voUMW" target="_blank" class="relative inline-block text-black dark:text-white group">
                                <span class="absolute bottom-0 w-full transition duration-150 ease-out transform -translate-y-1 border-b border-black opacity-0 group-hover:opacity-100 group-hover:translate-y-1"></span>
                                <span>{{ __('marketing.footer_status') }}</span>
                            </a>
                        </li>
                    </ul>
                </div>
                <div class="md:justify-self-end">
                    <h3 class="font-semibold text-black dark:text-wave-700">{{ __('marketing.footer_contact') }}</h3>
                    <ul class="mt-6 space-y-4 text-sm">
                        <li>
                            <a href="/p/about" class="relative inline-block text-black dark:text-white group">
                                <span class="absolute bottom-0 w-full transition duration-150 ease-out transform -translate-y-1 border-b border-black opacity-0 group-hover:opacity-100 group-hover:translate-y-1"></span>
                                <span>{{ __('marketing.footer_about') }}</span>
                            </a>
                        </li>
                        <li>
                            <a href="/blog" class="relative inline-block text-black dark:text-white group">
                                <span class="absolute bottom-0 w-full transition duration-150 ease-out transform -translate-y-1 border-b border-black opacity-0 group-hover:opacity-100 group-hover:translate-y-1"></span>
                                <span>{{ __('marketing.footer_blog') }}</span>
                            </a>
                        </li>
                        <li>
                            <a href="/contactus" class="relative inline-block text-black dark:text-white group">
                                <span class="absolute bottom-0 w-full transition duration-150 ease-out transform -translate-y-1 border-b border-black opacity-0 group-hover:opacity-100 group-hover:translate-y-1"></span>
                                <span>{{ __('marketing.footer_contact_us') }}</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="flex flex-col items-center justify-between py-10 border-t border-solid lg:flex-row border-gray">
            <ul class="flex flex-wrap space-x-5 text-xs">
                <li class="mb-6 text-center flex-full lg:flex-none lg:mb-0">&copy; {{ __('marketing.footer_copyright', ['year' => date('Y'), 'site' => setting('site.title', 'sos-vault')]) }}</li>
                <li class="lg:ml-6">
                    <a href="/p/terms-of-service" class="relative inline-block text-black dark:text-white group">
                        <span class="absolute bottom-0 w-full transition duration-150 ease-out transform -translate-y-1 border-b border-black opacity-0 group-hover:opacity-100 group-hover:translate-y-0"></span>
                        <span>{{ __('marketing.footer_terms') }}</span>
                    </a>
                </li>
                <li class="lg:ml-6">
                    <a href="/p/privacy-policy" class="relative inline-block text-black dark:text-white group">
                        <span class="absolute bottom-0 w-full transition duration-150 ease-out transform -translate-y-1 border-b border-black opacity-0 group-hover:opacity-100 group-hover:translate-y-0"></span>
                        <span>{{ __('marketing.footer_privacy') }}</span>
                    </a>
                </li>
                <li class="lg:ml-6">
                    <a href="/p/refund-policy" class="relative inline-block text-black dark:text-white group">
                        <span class="absolute bottom-0 w-full transition duration-150 ease-out transform -translate-y-1 border-b border-black opacity-0 group-hover:opacity-100 group-hover:translate-y-0"></span>
                        <span>{{ __('marketing.footer_refund') }}</span>
                    </a>
                </li>
                <li class="ml-auto mr-auto text-center lg:ml-6 lg:mr-0">
                    <a href="/p/security-and-compliance" class="relative inline-block text-black dark:text-white group">
                        <span class="absolute bottom-0 w-full transition duration-150 ease-out transform -translate-y-1 border-b border-black opacity-0 group-hover:opacity-100 group-hover:translate-y-0"></span>
                        <span>{{ __('marketing.footer_security') }}</span>
                    </a>
                </li>
            </ul>

            <ul class="flex items-center mt-10 space-x-5 lg:mt-0">
                <li>
                    <a target="_blank" href="https://www.facebook.com/people/Sos-vault/61574826941996/" class="text-gray-600 hover:text-gray-900 dark:hover:text-gray-100">
                        <span class="sr-only">{{ __('marketing.footer_facebook_sr') }}</span>
                        <i class="fa-brands fa-facebook fa-xl"></i>
                    </a>
                </li>
                {{--
                <li>
                    <a target="_blank" href="https://x.com/sos_vault" class="text-gray-600 hover:text-gray-900 dark:hover:text-gray-100">
                        <span class="sr-only">X</span>
                        <i class="fa-brands fa-x-twitter fa-xl"></i>
                    </a>
                </li>
                --}}
                <li>
                    <a target="_blank" href="https://www.youtube.com/@sos-vault" class="text-gray-600 hover:text-gray-900 dark:hover:text-gray-100">
                        <span class="sr-only">{{ __('marketing.footer_youtube_sr') }}</span>
                        <i class="fa-brands fa-youtube fa-xl"></i>
                    </a>
                </li>
                <li>
                    <a target="_blank" href="https://www.reddit.com/r/sos_vault" class="text-gray-600 hover:text-gray-900 dark:hover:text-gray-100">
                        <span class="sr-only">{{ __('marketing.footer_reddit_sr') }}</span>
                        <i class="fa-brands fa-reddit fa-xl"></i>
                    </a>
                </li>
                <li>
                    <a target="_blank" href="https://www.linkedin.com/in/thomas-anderson-43753b32b/" class="text-gray-600 hover:text-gray-900 dark:hover:text-gray-100">
                        <span class="sr-only">{{ __('marketing.footer_linkedin_sr') }}</span>
                        <i class="fa-brands fa-linkedin fa-xl"></i>
                    </a>
                </li>
                <li>
                    <a target="_blank" href="https://medium.com/@linuxjedi2000" class="text-gray-600 hover:text-gray-900 dark:hover:text-gray-100">
                        <span class="sr-only">{{ __('marketing.footer_medium_sr') }}</span>
                        <i class="fa-brands fa-medium fa-xl"></i>
                    </a>
                </li>
            </ul>
        </div>
    </x-container>
</footer>
@endif

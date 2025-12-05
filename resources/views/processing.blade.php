<!DOCTYPE html>
<html lang="bg">
<head>
    <script data-cookieconsent="ignore">
        window.dataLayer = window.dataLayer || [];
        function gtag() { dataLayer.push(arguments); }
        gtag("consent", "default", {
            ad_personalization: "denied",
            ad_storage: "denied",
            ad_user_data: "denied",
            analytics_storage: "denied",
            functionality_storage: "denied",
            personalization_storage: "denied",
            security_storage: "granted",
            wait_for_update: 500,
        });
        gtag("set", "ads_data_redaction", true);
        gtag("set", "url_passthrough", false);
    </script>

    <script id="Cookiebot" src="https://consent.cookiebot.com/uc.js" data-cbid="7ddab848-8cc9-4d60-9385-f4297558f760" data-blockingmode="auto" type="text/javascript"></script>

    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-WB1FXEX76C"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', 'G-WB1FXEX76C');
        gtag('event', 'page_view', {
            page_path: '/session/[uuid]',
            page_title: 'Session Page',
        });
    </script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Processing...') }}</title>
    @vite('resources/css/app.css')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>[x-cloak]{display:none !important;}</style>

    <!-- Make share links available to both desktop and mobile menus -->
    @php
        $links = Share::page(url('/'), 'Check out this text extraction site!')
            ->facebook()
            ->twitter()
            ->linkedin()
            ->reddit()
            ->getRawLinks();
    @endphp

    <!-- Load processing JavaScript (includes progressTracker and export functions) -->
    @vite('resources/js/processing.js')
    
    <!-- Load Alpine (via Vite) AFTER progressTracker is defined -->
    @vite('resources/js/app.js')
</head>

<body class="bg-gray-100 text-gray-800 flex flex-col min-h-screen">
<!-- Fixed Header -->
<header x-data="{ open: false, shareOpen: false }" id="site-header"
        class="fixed top-0 left-0 w-full bg-white border-b border-gray-200 shadow z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">

            <!-- Logo -->
            <a href="{{ url('/') }}" class="flex items-center space-x-2" aria-label="File to Text Home">
                <img src="{{ asset('favicon-32x32.png') }}" alt="File to Text Logo" class="h-8 w-auto">
                <span class="text-lg font-bold text-gray-800">File to Text</span>
            </a>

            <!-- Desktop Navigation -->
            <nav class="hidden md:flex space-x-6 relative">
                <a href="{{ route('resultsExplanation') }}" class="text-gray-700 hover:text-blue-600 font-medium">
                    Results Explanation
                </a>
                <a href="{{ url('/privacy-policy') }}" class="text-gray-700 hover:text-blue-600 font-medium">
                    Privacy Policy
                </a>

                <!-- Share Menu -->
                <div class="relative">
                    <button @click="shareOpen = !shareOpen" class="text-gray-700 hover:text-blue-600 font-medium">
                        Share
                    </button>
                    <div x-show="shareOpen" x-cloak
                         @click.outside="shareOpen = false"
                         class="absolute right-0 mt-10 w-40 bg-white rounded-xl shadow-lg p-3 space-y-2"
                         x-transition>
                        <p class="text-xs font-semibold text-gray-600">Share this page:</p>
                        @foreach ($links as $platform => $link)
                            <a href="{{ $link }}" target="_blank" rel="noopener noreferrer"
                               class="block text-white text-xs font-semibold py-1 px-3 rounded hover:opacity-90
                               {{ $platform === 'facebook' ? 'bg-blue-600' : '' }}
                               {{ $platform === 'twitter' ? 'bg-sky-500' : '' }}
                               {{ $platform === 'linkedin' ? 'bg-blue-700' : '' }}
                               {{ $platform === 'reddit' ? 'bg-orange-600' : '' }}">
                                {{ ucfirst($platform) }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </nav>

            <!-- Mobile Menu Button -->
            <div class="md:hidden">
                <button @click="open = !open" class="text-gray-700 hover:text-blue-600 focus:outline-none" aria-label="Toggle Menu">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Mobile Dropdown Menu -->
    <div x-show="open" x-cloak class="md:hidden bg-white border-t border-gray-200 shadow" x-transition>
        <nav class="px-4 py-4 space-y-4">
            <a href="{{ route('resultsExplanation') }}" class="block text-gray-700 hover:text-blue-600 font-medium">
                Results Explanation
            </a>
            <a href="{{ url('/privacy-policy') }}" class="block text-gray-700 hover:text-blue-600 font-medium">
                Privacy Policy
            </a>

            <!-- Share Block for Mobile -->
            <div>
                <p class="text-xs font-semibold text-gray-600 mb-2">Share this page:</p>
                @foreach ($links as $platform => $link)
                    <a href="{{ $link }}" target="_blank" rel="noopener noreferrer"
                       class="inline-block text-white text-xs font-semibold py-1 px-3 rounded hover:opacity-90 mr-1 mb-1
                       {{ $platform === 'facebook' ? 'bg-blue-600' : '' }}
                       {{ $platform === 'twitter' ? 'bg-sky-500' : '' }}
                       {{ $platform === 'linkedin' ? 'bg-blue-700' : '' }}
                       {{ $platform === 'reddit' ? 'bg-orange-600' : '' }}">
                        {{ ucfirst($platform) }}
                    </a>
                @endforeach
            </div>
        </nav>
    </div>
</header>

<main
    x-data="progressTracker('{{ $uuid }}', {{ \Illuminate\Support\Facades\Cache::get($uuid)['pages'] ?? 0 }})"
    x-init="startPolling()"
    class="flex-grow"
    style="margin-top: var(--header-height)"
>
    <!-- Expired Banner - Shows at top when expired -->
    <div x-show="expired" x-cloak class="bg-yellow-50 border-b-2 border-yellow-400 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <svg class="h-5 w-5 text-yellow-600 mr-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                    <p class="text-yellow-800 font-medium">
                        {{ __('This upload is no longer available — please upload again.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Processing state - Hide when expired, show banner instead -->
    <div x-show="processing && !expired" class="text-center pt-20 px-4">
        <h1 class="text-3xl font-bold mb-4">{{ __('Processing document...') }}</h1>
        <p class="text-gray-600 mb-10">{{ __('Please wait. This can take a minute or two.') }}</p>
        <svg class="mx-auto animate-spin h-12 w-12 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
        </svg>

        <!-- Progress bar -->
        <div class="w-full max-w-md mx-auto mt-6">
            <div class="h-4 bg-gray-200 rounded-full overflow-hidden">
                <div class="h-full bg-blue-500 transition-all duration-500"
                     :style="`width: ${(pages / (totalPages || 1)) * 100}%`">
                </div>
            </div>
            <p class="text-sm text-gray-500 mt-2" x-text="`Pages processed: ${pages} / ${totalPages || '?'}`"></p>
        </div>
    </div>

    <!-- Results - Always show if there are results, regardless of expired status -->
    <div class="max-w-4xl mx-auto py-12 px-4">
        <div x-show="(fetchedPages.size > 0 || done)" class="py-10 px-4 bg-white rounded-lg shadow-md">
            <h2 class="text-3xl font-bold text-center mb-8">{{ __('Results from text detection') }}</h2>
            <div id="results" class="text-left space-y-4"></div>
        </div>
    </div>
</main>

<script>
    document.documentElement.style.setProperty(
        '--header-height',
        document.getElementById('site-header').offsetHeight + 'px'
    );
</script>

<footer id="actionFooter" class="hidden fixed bottom-0 left-0 w-full bg-white border-t border-gray-200 shadow z-50 overflow-visible">
    <div class="max-w-7xl mx-auto px-4 py-3 flex flex-col sm:flex-row items-center justify-center gap-3">
        <div class="max-w-7xl mx-auto px-4 py-3 flex flex-col sm:flex-row items-center justify-center gap-3">
            <div x-data="{ open: false }" class="relative inline-block text-left">
                <button @click="open = !open"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-6 rounded transition text-center">
                    Export
                </button>

                <div x-show="open" x-cloak
                     @click.outside="open = false"
                     @keydown.escape.window="open = false"
                     x-transition
                     class="absolute right-0 bottom-full mb-2 w-48 bg-white border border-gray-200 rounded shadow-lg z-50 max-h-[50vh] overflow-auto">
                    <button @click="exportText(); open = false" class="block w-full px-4 py-2 text-left hover:bg-gray-100">Text</button>
                    <button @click="sendArrayToExport('{{ route('export.pdf') }}', 'export.pdf'); open = false" class="block w-full px-4 py-2 text-left hover:bg-gray-100">PDF</button>
                    <button @click="sendArrayToExport('{{ route('export.pptx') }}', 'export.pptx'); open = false" class="block w-full px-4 py-2 text-left hover:bg-gray-100">PPTX</button>
                </div>
            </div>
        </div>
    </div>
</footer>

</body>
</html>

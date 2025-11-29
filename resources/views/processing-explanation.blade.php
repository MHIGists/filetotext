<!DOCTYPE html>
<html lang="en">
<head>
	<script data-cookieconsent="ignore">    window.dataLayer = window.dataLayer || [];

        function gtag() {
            dataLayer.push(arguments);
        }

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
        gtag("set", "url_passthrough", false);</script>
    <script id="Cookiebot" src="https://consent.cookiebot.com/uc.js" data-cbid="7ddab848-8cc9-4d60-9385-f4297558f760" data-blockingmode="auto" type="text/javascript"></script>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-WB1FXEX76C" type="text/plain" data-cookieconsent="statistics"></script>
    <script type="text/plain" data-cookieconsent="statistics">
        window.dataLayer = window.dataLayer || [];

        function gtag() {
            dataLayer.push(arguments);
        }

        gtag('js', new Date());

        gtag('config', 'G-WB1FXEX76C');
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Text Detection Results & How to Interpret Them') }}</title>
    <meta name="description"
          content="View and understand your text detection results with image previews, extracted text, and export options.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>[x-cloak] {
            display: none !important
        }</style>
    @vite('resources/css/app.css')
    @vite('resources/js/app.js')
</head>
<body class="bg-gray-50 text-gray-800 flex flex-col min-h-screen">

<!-- Header -->
<header id="site-header" class="fixed top-0 left-0 w-full bg-white border-b border-gray-200 shadow z-50"
        x-data="{ mobileOpen: false }">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">

            <!-- Logo -->
            <a href="{{ url('/') }}" class="flex items-center space-x-2" aria-label="File to Text Home">
                <img src="{{ asset('favicon-32x32.png') }}" alt="File to Text Logo" class="h-8 w-auto">
                <span class="text-lg font-bold text-gray-800">File to Text</span>
            </a>

            <!-- Desktop Nav -->
            <nav class="hidden md:flex space-x-6 relative">
                <a href="{{ route('resultsExplanation') }}" class="text-gray-700 hover:text-blue-600 font-medium">Results
                    Explanation</a>
                <a href="{{ url('/privacy-policy') }}" class="text-gray-700 hover:text-blue-600 font-medium">Privacy
                    Policy</a>

                <!-- Share Dropdown -->
                <div x-data="{ shareOpen: false }" class="relative">
                    <button @click="shareOpen = !shareOpen" class="text-gray-700 hover:text-blue-600 font-medium">
                        Share
                    </button>
                    <div x-show="shareOpen" x-cloak @click.outside="shareOpen=false"
                         x-transition
                         class="absolute right-0 mt-10 w-40 bg-white rounded-xl shadow-lg p-3 space-y-2">
                        @php
                            $links = Share::page(url('/'), 'Check out this text extraction site!')
                              ->facebook()->twitter()->linkedin()->reddit()->getRawLinks();
                        @endphp
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
            <button @click="mobileOpen = !mobileOpen"
                    class="md:hidden text-gray-700 hover:text-blue-600 focus:outline-none" aria-label="Toggle menu">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
        </div>
    </div>

    <!-- Mobile Dropdown -->
    <nav x-show="mobileOpen" x-cloak x-transition
         class="md:hidden bg-white border-t border-gray-200 shadow px-4 py-4 space-y-4">
        <a href="{{ route('resultsExplanation') }}" class="block text-gray-700 hover:text-blue-600 font-medium">Results
            Explanation</a>
        <a href="{{ url('/privacy-policy') }}" class="block text-gray-700 hover:text-blue-600 font-medium">Privacy
            Policy</a>

        <!-- Mobile Share -->
        <div>
            <p class="text-sm font-semibold text-gray-600">Share this page:</p>
            @foreach ($links as $platform => $link)
                <a href="{{ $link }}" target="_blank" rel="noopener noreferrer"
                   class="inline-block mt-1 text-white text-xs font-semibold py-1 px-3 rounded hover:opacity-90
          {{ $platform === 'facebook' ? 'bg-blue-600' : '' }}
          {{ $platform === 'twitter' ? 'bg-sky-500' : '' }}
          {{ $platform === 'linkedin' ? 'bg-blue-700' : '' }}
          {{ $platform === 'reddit' ? 'bg-orange-600' : '' }}">
                    {{ ucfirst($platform) }}
                </a>
            @endforeach
        </div>
    </nav>
</header>

<!-- Main Content -->
<main id="main-content"
      class="flex-grow w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-12 pt-20 pb-20 overflow-x-hidden">
    <header>
        <h1 class="text-3xl font-bold">{{ __('Your Text Detection Results') }}</h1>
        <p class="mt-1 text-gray-600">{{ __('Understand the output, how it was generated, and how to improve accuracy.') }}</p>
    </header>
    <!-- Section 1: Explanation -->
    <section aria-labelledby="understanding-results">
        <h2 id="understanding-results" class="text-2xl font-semibold mb-4">{{ __('Understanding Your Results') }}</h2>
        <p class="mb-4 text-gray-700">
            {{ __('The image shown below represents exactly what our text detection tool “sees” when processing your document.
            If parts of the text look unclear, cropped, or distorted here, they may not be accurately recognized.
            To improve detection, try adjusting the settings on the front page and re-upload your document.') }}
        </p>
        <p class="text-gray-700">
            {{ __('Next to each processed page, you’ll find the text extracted from it. You can review and edit the extracted text before exporting your results in your preferred format.') }}
        </p>
    </section>

    <!-- Section 2: Results Display -->
    <section aria-labelledby="results-display">
        <h2 id="results-display" class="text-2xl font-semibold mb-4">{{ __('Detected Images & Extracted Text') }}</h2>
        <div id="results" class="space-y-8">
            {{-- Example of a processed page layout --}}
            <article class="grid md:grid-cols-2 gap-6 bg-white p-6 rounded-lg shadow">
                <figure>
                    <img src="/example-processed-image.png" alt="Processed document page preview"
                         class="w-full rounded border">
                    <figcaption
                            class="mt-2 text-sm text-gray-500">{{ __('Image as interpreted by the detection tool.') }}</figcaption>
                </figure>
                <div>
                    <h3 class="text-lg font-semibold mb-2">{{ __('Extracted Text') }}</h3>
                    <pre class="bg-gray-100 p-3 rounded overflow-x-auto whitespace-pre-wrap break-words text-sm leading-relaxed">
                        This is a sample of the extracted text from the processed page. It is exactly what the OCR engine recognized from the image on the left.
                        </pre>
                </div>
            </article>
        </div>
    </section>

    <!-- Section 3: Export Instructions -->
    <section aria-labelledby="export-results">
        <h2 id="export-results" class="text-2xl font-semibold mb-4">{{ __('Exporting Your Results') }}</h2>
        <p class="mb-4 text-gray-700">
            {{ __('Once you’ve reviewed the extracted text, you can export your results in several formats:') }}
        </p>
        <ul class="list-disc list-inside text-gray-700 mb-4">
            <li>{{ __('Plain text (.txt) — for quick edits or lightweight storage.') }}</li>
            <li>{{ __('PDF — for a shareable, fixed-layout version of your extracted content.') }}</li>
            <li>{{ __('PPTX — for presentation-ready slides of your extracted text.') }}</li>
        </ul>
        <p class="text-gray-700">
            {{ __('Use the "Export" button at the bottom of the page to download your chosen format. All text from all pages will be included in your exported file.') }}
        </p>
    </section>
</main>

<!-- Footer -->
<footer id="actionFooter" class="fixed bottom-0 left-0 w-full bg-white border-t border-gray-200 shadow z-50">
    <div class="max-w-7xl mx-auto px-4 py-3 flex flex-col sm:flex-row items-center justify-center gap-3">
        <div x-data="{ open: false }" class="relative">
            <button @click="open = !open"
                    class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-6 rounded transition text-center">
                {{ __('Export') }}
            </button>
            <div x-show="open" x-cloak @click.outside="open = false" x-transition
                 class="absolute right-0 bottom-full mb-2 w-48 bg-white border border-gray-200 rounded shadow-lg z-50">
                <button class="block w-full px-4 py-2 text-left hover:bg-gray-100">{{ __('Text') }}</button>
                <button class="block w-full px-4 py-2 text-left hover:bg-gray-100">{{ __('PDF') }}</button>
                <button class="block w-full px-4 py-2 text-left hover:bg-gray-100">{{ __('PPTX') }}</button>
            </div>
        </div>
    </div>
</footer>

</body>
</html>

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

    <!-- Define progressTracker BEFORE Alpine starts -->
    <script>
    // Plain (non-module) script so it's global. Loaded before app.js (which starts Alpine).
    function progressTracker(jobId) {
        return {
            pages: 0, // processed pages (backend "remaining" == processed/ready)
            totalPages: {{ \Illuminate\Support\Facades\Cache::get($uuid)['pages'] ?? 0 }},
            resultsContainer: null, // DOM reference to results container
            fetchedPages: new Set(), // Track which pages have been fetched to prevent duplicates
            loadedPages: 0,       // how many pages we've actually fetched/displayed
            processing: true,
            done: false,
            expired: false,
            error: '',
            pollInterval: 3000,
            maxRetries: 1000,
            attempts: 0,
            fetchingPages: new Set(), // Track pages currently being fetched to prevent concurrent fetches

            async startPolling() {
                // Initialize results container reference for direct DOM manipulation
                this.resultsContainer = document.getElementById('results');
                
                while (!this.done && this.attempts < this.maxRetries) {
                    try {
                        const res = await fetch(`/status/${jobId}`);
                        if (!res.ok) throw new Error('Status check failed');
                        const data = await res.json();

                        if (data.expired) {
                            this.expired = true;
                            this.processing = false;
                            break;
                        }

                        // Update processed pages count
                        if (typeof data.remaining === 'number' && data.remaining >= 0) {
                            this.pages = data.remaining;
                        }

                        // Update total pages if backend provides it
                        if (typeof data.totalPages === 'number' && data.totalPages > 0) {
                            this.totalPages = data.totalPages;
                        }

                        // Fetch and display newly available pages
                        await this.preloadAvailablePages();

                        if (data.done) {
                            // Force the bar to 100% and mark as done
                            if (this.totalPages > 0) this.pages = this.totalPages;
                            this.done = true;
                            this.processing = false;

                            // In case totalPages wasn't known, fetch the rest sequentially
                            if (this.totalPages === 0) {
                                let page = this.loadedPages + 1;
                                // Try to fetch until we hit a hole (404/204)
                                while (await this.fetchPage(page)) {
                                    this.loadedPages = page;
                                    page++;
                                    if (page > 2000) break;
                                }
                            }

                            // Show action footer when done
                            document.getElementById('actionFooter').classList.remove('hidden');
                            break;
                        }
                    } catch (err) {
                        this.error = '{{ __('Error while checking status.') }}';
                        console.error(err);
                    }

                    this.attempts++;
                    await new Promise(r => setTimeout(r, this.pollInterval));
                }
            },

            async preloadAvailablePages() {
                // Fetch and display pages we haven't loaded yet, up to `pages` (processed count)
                if (this.pages > this.loadedPages) {
                    const fetchPromises = [];
                    for (let page = this.loadedPages + 1; page <= this.pages; page++) {
                        // Skip if already fetched or currently being fetched
                        if (this.fetchedPages.has(page) || this.fetchingPages.has(page)) {
                            continue;
                        }
                        fetchPromises.push(this.fetchPage(page));
                    }
                    // Wait for all fetches to complete
                    await Promise.allSettled(fetchPromises);
                }
            },

            async fetchPage(page) {
                // Prevent duplicate fetches
                if (this.fetchedPages.has(page) || this.fetchingPages.has(page)) {
                    return false;
                }

                this.fetchingPages.add(page);
                
                try {
                    const res = await fetch(`/result/${jobId}/page/${page}`);
                    if (!res.ok || res.status === 404 || res.status === 204) {
                        this.appendErrorPage(page);
                        this.fetchedPages.add(page);
                        this.fetchingPages.delete(page);
                        return false;
                    }

                    const html = await res.text();
                    if (!html.trim()) {
                        this.appendErrorPage(page);
                        this.fetchedPages.add(page);
                        this.fetchingPages.delete(page);
                        return false;
                    }

                    // Use direct DOM manipulation instead of string concatenation
                    this.appendPageToDOM(html);
                    this.fetchedPages.add(page);
                    this.loadedPages = Math.max(this.loadedPages, page);
                    this.fetchingPages.delete(page);
                    return true;
                } catch (err) {
                    this.appendErrorPage(page);
                    this.fetchedPages.add(page);
                    this.fetchingPages.delete(page);
                    console.error(err);
                    return false;
                }
            },

            appendPageToDOM(html) {
                if (!this.resultsContainer) {
                    this.resultsContainer = document.getElementById('results');
                }
                if (!this.resultsContainer) return;

                // Create a temporary container to parse HTML
                const temp = document.createElement('div');
                temp.innerHTML = html.trim();
                
                // Append each child node directly to avoid string concatenation
                while (temp.firstChild) {
                    this.resultsContainer.appendChild(temp.firstChild);
                }
            },

            appendErrorPage(page) {
                if (!this.resultsContainer) {
                    this.resultsContainer = document.getElementById('results');
                }
                if (!this.resultsContainer) return;

                const errorDiv = document.createElement('div');
                errorDiv.className = 'bg-white shadow-md rounded-lg mb-8 overflow-hidden';
                errorDiv.innerHTML = `
                    <div class="bg-gray-200 px-4 py-2 font-semibold text-gray-700">Page ${page}</div>
                    <div class="p-4 text-red-600">error</div>
                `;
                this.resultsContainer.appendChild(errorDiv);
            }
        }
    }
    </script>

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
    x-data="progressTracker('{{ $uuid }}')"
    x-init="startPolling()"
    class="flex-grow"
    style="margin-top: var(--header-height)"
>
    <!-- Processing state -->
    <div x-show="processing" class="text-center pt-20 px-4">
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

    <!-- Results -->
    <div class="max-w-4xl mx-auto py-12 px-4">
        <div x-show="(fetchedPages.size > 0 || done) && !expired && !error" class="py-10 px-4 bg-white rounded-lg shadow-md">
            <h2 class="text-3xl font-bold text-center mb-8">{{ __('Results from text detection') }}</h2>
            <div id="results" class="text-left space-y-4"></div>
        </div>

        <!-- Expired -->
        <p x-show="expired" class="text-red-600 text-center mt-10">
            {{ __('This upload is no longer available — please upload again.') }}
        </p>

        <!-- Error -->
        <p x-show="error" class="text-red-600 text-center mt-10" x-text="error"></p>
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

<script>
    function getPreTexts() {
        return Array.from(document.querySelectorAll('pre')).map(pre => pre.innerText);
    }

    function exportText() {
        const combinedText = getPreTexts().join('\n\n');
        const bom = '\uFEFF';
        const blob = new Blob([bom + combinedText], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'pre-text-export.txt';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    function sendArrayToExport(url, filename) {
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json; charset=utf-8',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({ pages: getPreTexts() })
        })
        .then(response => {
            if (!response.ok) throw new Error('Export failed');
            return response.blob();
        })
        .then(blob => {
            const downloadUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(downloadUrl);
        })
        .catch(err => alert(err.message));
    }
</script>
</body>
</html>

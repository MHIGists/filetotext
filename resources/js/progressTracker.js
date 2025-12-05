// Progress tracker for processing page
// Plain (non-module) script so it's global. Loaded before app.js (which starts Alpine).
export function progressTracker(jobId, totalPages = 0) {
    return {
        pages: 0, // processed pages (backend "remaining" == processed/ready)
        totalPages: totalPages,
        resultsContainer: null, // DOM reference to results container
        fetchedPages: new Set(), // Track which pages have been fetched to prevent duplicates
        loadedPages: 0,       // how many pages we've actually fetched/displayed
        processing: true,
        done: false,
        expired: false,
        hasError: false, // Track if there are any errors (but don't show expired message)
        pollInterval: 3000,
        maxRetries: 1000,
        attempts: 0,
        fetchingPages: new Set(), // Track pages currently being fetched to prevent concurrent fetches
        pageElements: new Map(), // Map of page number to DOM element for efficient sorting
        pageRetries: new Map(), // Track retry attempts per page for 404 errors
        maxRetriesPerPage: 3, // Maximum number of retries for a single page on 404
        retryDelay: 10000, // Delay between retries in milliseconds

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
                    // Set error flag but don't set expired - errors are different from expiration
                    this.hasError = true;
                    console.error(err);
                    // Continue polling on error, don't break
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

        async fetchPage(page, retryAttempt = 0) {
            // Prevent duplicate fetches (unless we're retrying)
            if (this.fetchedPages.has(page)) {
                return false;
            }

            // If already fetching and not a retry, skip
            if (this.fetchingPages.has(page) && retryAttempt === 0) {
                return false;
            }

            this.fetchingPages.add(page);

            try {
                const res = await fetch(`/result/${jobId}/page/${page}`);

                // Handle 404 with retry logic
                if (res.status === 404) {
                    const currentRetries = this.pageRetries.get(page) || 0;

                    if (currentRetries < this.maxRetriesPerPage) {
                        // Retry the fetch after a delay
                        this.pageRetries.set(page, currentRetries + 1);
                        this.fetchingPages.delete(page);

                        // Wait before retrying
                        await new Promise(r => setTimeout(r, this.retryDelay));

                        // Retry the fetch
                        return await this.fetchPage(page, retryAttempt + 1);
                    } else {
                        // Max retries reached, show error
                        this.appendErrorPage(page);
                        this.fetchedPages.add(page);
                        this.fetchingPages.delete(page);
                        this.pageRetries.delete(page); // Clean up retry tracking
                        return false;
                    }
                }

                // Handle other non-OK status codes (204, etc.)
                if (!res.ok || res.status === 204) {
                    this.appendErrorPage(page);
                    this.fetchedPages.add(page);
                    this.fetchingPages.delete(page);
                    this.pageRetries.delete(page); // Clean up retry tracking
                    return false;
                }

                const html = await res.text();
                if (!html.trim()) {
                    this.appendErrorPage(page);
                    this.fetchedPages.add(page);
                    this.fetchingPages.delete(page);
                    this.pageRetries.delete(page); // Clean up retry tracking
                    return false;
                }

                // Success! Clear retry tracking and add page to DOM
                this.pageRetries.delete(page);
                this.appendPageToDOM(html);
                this.fetchedPages.add(page);
                this.loadedPages = Math.max(this.loadedPages, page);
                this.fetchingPages.delete(page);
                return true;
            } catch (err) {
                // Network errors - also retry on these
                const currentRetries = this.pageRetries.get(page) || 0;

                if (currentRetries < this.maxRetriesPerPage) {
                    // Retry the fetch after a delay
                    this.pageRetries.set(page, currentRetries + 1);
                    this.fetchingPages.delete(page);

                    // Wait before retrying
                    await new Promise(r => setTimeout(r, this.retryDelay));

                    // Retry the fetch
                    return await this.fetchPage(page, retryAttempt + 1);
                } else {
                    // Max retries reached, show error
                    this.appendErrorPage(page);
                    this.fetchedPages.add(page);
                    this.fetchingPages.delete(page);
                    this.pageRetries.delete(page); // Clean up retry tracking
                    console.error(err);
                    return false;
                }
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

            // Extract page number from the HTML to store in our map
            const pageDiv = temp.querySelector('.bg-gray-200');
            let pageNum = null;
            if (pageDiv) {
                const pageText = pageDiv.textContent.trim();
                const match = pageText.match(/Page\s+(\d+)/);
                if (match) {
                    pageNum = parseInt(match[1], 10);
                }
            }

            // Get the main page container (first child div)
            const pageElement = temp.firstElementChild;
            if (pageElement && pageNum !== null) {
                // Check if this page already exists in the DOM
                const existingElement = this.pageElements.get(pageNum);
                if (existingElement && existingElement.parentNode === this.resultsContainer) {
                    // Page already exists and is displayed, don't add it again
                    return;
                }

                // Store element with page number for sorting
                this.pageElements.set(pageNum, pageElement);

                // Only reorder if we need to (smart reordering that doesn't remove existing pages)
                this.reorderPages();
            } else if (pageElement) {
                // If we can't extract page number, just append (fallback)
                this.resultsContainer.appendChild(pageElement);
            }
        },

        reorderPages() {
            if (!this.resultsContainer) return;

            // Get all page numbers and sort them
            const sortedPages = Array.from(this.pageElements.keys()).sort((a, b) => a - b);

            // Get current children in DOM order and create a map for quick lookup
            const currentChildren = Array.from(this.resultsContainer.children);
            const pageToElementMap = new Map();
            currentChildren.forEach((child) => {
                // Find which page this element represents by checking our pageElements map
                for (const [pageNum, element] of this.pageElements.entries()) {
                    if (element === child) {
                        pageToElementMap.set(pageNum, child);
                        break;
                    }
                }
            });

            // Check if reordering is needed
            let needsReorder = false;
            if (currentChildren.length !== sortedPages.length) {
                needsReorder = true;
            } else {
                // Check if elements are in the correct order
                for (let i = 0; i < sortedPages.length; i++) {
                    const expectedPage = sortedPages[i];
                    const expectedElement = this.pageElements.get(expectedPage);
                    if (currentChildren[i] !== expectedElement) {
                        needsReorder = true;
                        break;
                    }
                }
            }

            // Only reorder if necessary
            if (!needsReorder) return;

            // Smart reordering: iterate through sorted pages and ensure each is in correct position
            // This preserves existing DOM elements and only moves/reorders them
            for (let i = 0; i < sortedPages.length; i++) {
                const pageNum = sortedPages[i];
                const element = this.pageElements.get(pageNum);
                if (!element) continue;

                const isInDOM = element.parentNode === this.resultsContainer;
                const currentPosition = isInDOM ? Array.from(this.resultsContainer.children).indexOf(element) : -1;

                if (!isInDOM) {
                    // New element - insert at correct position
                    const referenceNode = i < this.resultsContainer.children.length
                        ? this.resultsContainer.children[i]
                        : null;
                    if (referenceNode) {
                        this.resultsContainer.insertBefore(element, referenceNode);
                    } else {
                        this.resultsContainer.appendChild(element);
                    }
                } else if (currentPosition !== i) {
                    // Element exists but is in wrong position - move it to correct position
                    const referenceNode = i < this.resultsContainer.children.length
                        ? this.resultsContainer.children[i]
                        : null;
                    // Only move if reference node is different (avoid unnecessary moves)
                    if (referenceNode && referenceNode !== element) {
                        this.resultsContainer.insertBefore(element, referenceNode);
                    }
                }
            }
        },

        appendErrorPage(page) {
            if (!this.resultsContainer) {
                this.resultsContainer = document.getElementById('results');
            }
            if (!this.resultsContainer) return;

            // Check if error page already exists
            const existingElement = this.pageElements.get(page);
            if (existingElement && existingElement.parentNode === this.resultsContainer) {
                // Error page already displayed, don't add it again
                return;
            }

            // Mark that we have errors but don't set expired
            this.hasError = true;

            const errorDiv = document.createElement('div');
            errorDiv.className = 'bg-white shadow-md rounded-lg mb-8 overflow-hidden';
            errorDiv.innerHTML = `
                <div class="bg-gray-200 px-4 py-2 font-semibold text-gray-700">Page ${page}</div>
                <div class="p-4 text-red-600">error</div>
            `;

            // Store in pageElements map for sorting
            this.pageElements.set(page, errorDiv);

            // Reorder all pages (smart reordering that doesn't remove existing pages)
            this.reorderPages();
        }
    }
}


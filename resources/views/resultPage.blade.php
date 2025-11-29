            <div class="bg-white shadow-md rounded-lg mb-8 overflow-hidden">
                <div class="bg-gray-200 px-4 py-2 font-semibold text-gray-700">
                    Page {{ $result['page'] ?? 0 }}
                </div>
                <div class="p-4 flex flex-col md:flex-row gap-4">
                    <div class="flex-shrink-0 w-full md:w-1/2">
                        <img src="{{ $result['image'] }}" alt="Page Image {{ $result['page'] }}"
                             class="rounded shadow w-full h-auto object-contain max-h-[600px] border" />
                    </div>
                    <div class="w-full md:w-1/2 bg-gray-50 p-4 rounded overflow-auto max-h-[600px]">
                        <pre class="whitespace-pre-wrap text-sm font-mono">{!! $result['text'] !!}</pre>
                    </div>
                </div>
            </div>

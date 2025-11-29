<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Резултати от OCR</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-gray-100 text-gray-800">

    <div class="max-w-4xl mx-auto py-10 px-4">
        <h1 class="text-3xl font-bold text-center mb-10">Резултати от OCR сканиране</h1>

        @foreach ($results as $result)
            @if(!$result)
                @continue
            @endif
            @php $result = $result[array_key_first($result)] @endphp
            <div class="bg-white shadow-md rounded-lg mb-8 overflow-hidden">
                <div class="bg-gray-200 px-4 py-2 font-semibold text-gray-700">
                    Страница {{ $result['page'] ?? 0 }}
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
        @endforeach

        <div class="text-center mt-10">
            <a href="{{ url('/') }}"
               class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded">
                Качи нов файл
            </a>
        </div>
    </div>

</body>
</html>


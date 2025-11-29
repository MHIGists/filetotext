<?php

namespace App\Http\Controllers;

use Cache;
use App\Jobs\ProcessPdfPage;
use Illuminate\Http\Request;
use Str;
use App\Jobs\ProcessImage;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\Style\Alignment;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;

class FileUploadController extends Controller
{
    public function upload(Request $request)
    {
        set_time_limit(0);

        $request->merge([
            'greyscale' => $request->has('greyscale'),
        ]);

        $allowedImageTypes = ['jpg', 'jpeg', 'png', 'bmp', 'tiff', 'webp'];
        $allowedDocs = ['pdf', 'ppt', 'pptx'];

        $rules = [
            'consent' => 'accepted',
            'file' => 'nullable|file|mimes:' . implode(',', array_merge($allowedDocs, $allowedImageTypes)),
            'photo' => 'nullable|file|mimes:' . implode(',', $allowedImageTypes),
            'greyscale' => 'boolean',
            'contrast' => 'integer|between:-15,15',
            'dpi' => 'required|integer|in:50,100,150,200,250,300',
            'languages' => 'nullable|array',
            'languages.*' => 'string|in:' . implode(',', self::getTesseractInstalledLanguages()),
        ];
        if (!$request->hasFile('file') && !$request->hasFile('photo')) {
            return back()->withErrors(['file' => 'Please upload a file or take a picture.'])->withInput();
        }
        $request->validate($rules);
        $file = $request->file('file') ?? $request->file('photo');
        $ext = $file->getClientOriginalExtension();
        $path = $file->store('uploads', 'public');
        $fullPath = storage_path('app/public/'.$path);

        $uuid = (string)Str::uuid();

        if (in_array($ext, ['ppt', 'pptx'])) {
            $uuid = $this->extractTextFromPptImages(
                $fullPath,
                $request->input('greyscale', true),
                $request->input('dpi', 300),
                $request->input('languages'),
                $request->input('contrast', 1)
            );
        } elseif ($ext === 'pdf') {
            $uuid = $this->extractTextFromPdfImages(
                $fullPath,
                $request->input('greyscale', true),
                $request->input('dpi', 300),
                $request->input('languages'),
                $request->input('contrast', 1)
            );
        } elseif (in_array($ext, $allowedImageTypes)) {
            $results = [
                'uuid' => $uuid,
                'pages' => 1,
                'filePath' => $fullPath,
            ];
            Cache::put($uuid, $results, now()->addMinutes(5));
            ProcessImage::dispatch(
                $fullPath,
                $uuid,
                $request->input('greyscale', true),
                $request->input('dpi', 300),
                $request->input('languages'),
                $request->input('contrast', 1)
            );
        }

        return redirect()->route('processing', ['uuid' => $uuid]);
    }

    private function extractTextFromPptImages($filePath, $greyscale = false, $dpi = 300, $languages = ['eng'], int $contrast = 1)
    {
        if (!file_exists($filePath)) {
            return "PPT file not found at: $filePath";
        }

        $uuid = (string)sha1_file($filePath);
        $pdfOutputDir = storage_path("app/temp_ppt_pdf");
        $pdfOutputPath = $pdfOutputDir.'/'.pathinfo($filePath, PATHINFO_FILENAME).'.pdf';

        // Ensure the output directory exists
        if (!is_dir($pdfOutputDir) && !mkdir($pdfOutputDir, 0755, true) && !is_dir($pdfOutputDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $pdfOutputDir));
        }

        // Convert PPTX to PDF using LibreOffice
        $escapedInput = escapeshellarg($filePath);
        $escapedOutput = escapeshellarg($pdfOutputDir);
        $command = "libreoffice --headless --convert-to pdf $escapedInput --outdir $escapedOutput";
        exec($command, $output, $returnVar);

        if ($returnVar !== 0 || !file_exists($pdfOutputPath)) {
            return "Failed to convert PowerPoint to PDF.";
        }

        // Save metadata to cache
        $results = [
            'uuid' => $uuid,
            'original' => $filePath,
            'pdf' => $pdfOutputPath,
        ];
        Cache::put($uuid, $results, now()->addMinutes(5));

        // Now process the PDF instead of the PPT
        return $this->extractTextFromPdfImages($pdfOutputPath, $greyscale, $dpi, $languages, $contrast);
    }


    private function extractTextFromPdfImages($filePath, $greyscale = false, $dpi = 300, $languages = ['eng'], int $contrast = 1)
    {
        if (!file_exists($filePath)) {
            return "PDF file not found at: $filePath";
        }
        $pageCount = (int)shell_exec("pdfinfo ".escapeshellarg($filePath)." | grep Pages | awk '{print $2}'");
        $uuid = Str::uuid();
        $results = [
            'uuid' => $uuid,
            'pages' => $pageCount,
            'filePath' => $filePath,
        ];
        Cache::put($uuid, $results, now()->addMinutes(5));
        for ($i = 0; $i < $pageCount; $i++) {
            ProcessPdfPage::dispatch($filePath, $i, $uuid, $greyscale, $dpi, $languages, $contrast);
        }

        return $uuid;
    }

    public function status($uuid)
{
    $cache = Cache::get($uuid);

    // If cache entry not found -> tell the client this is an old/expired upload
    if (empty($cache) || !is_array($cache)) {
        Log::debug("status: cache miss for {$uuid}");
        return response()->json([
            'done'      => false,
            'remaining' => 0,
            'expired'   => true,
        ]);
    }

    $pagesTotal = isset($cache['pages']) ? (int) $cache['pages'] : 0;
    $pagesProcessed = 0;

    for ($i = 1; $i <= $pagesTotal; $i++) {
        $page = Cache::get("{$uuid}_{$i}");
        if (!empty($page)) {
            $pagesProcessed++;
        }
    }

    Log::debug("status: {$pagesTotal} pages total, {$pagesProcessed} processed for {$uuid}");

    $done = ($pagesTotal > 0 && $pagesProcessed >= $pagesTotal);

    if ($done) {
        // cleanup temporary uploaded file if present
        if (!empty($cache['filePath']) && file_exists($cache['filePath'])) {
            @unlink($cache['filePath']);
        }
    }

    return response()->json([
        'done'      => $done,
        'remaining' => $pagesProcessed,
        'expired'   => false,
    ]);
}


    public function result($uuid)
    {
        $cache = Cache::get($uuid);

        if (!$cache) {
            abort(404, "Result not found or not ready yet.");
        }
        $results = [];
        for ($i = 1; $i < $cache['pages']; $i++) {
            $results[] = Cache::get($uuid.'_'.$i);
        }
        return view('results', ['results' => $results]);
    }

    public function resultPage($uuid, $pageNum)
    {
        $cache = Cache::get($uuid.'_'.$pageNum);

        if (!$cache) {
            abort(404, "Result not found or not ready yet.");
        }
        return view('resultPage', ['result' => $cache[$pageNum]]);
    }

    public function processing($uuid)
    {
        return view('processing', ['uuid' => $uuid]);
    }

    public static function getTesseractInstalledLanguages(): array
    {
        $languageMap = [
            'Arabic' => 'ara',
            'Armenian' => 'hye',
            'Bengali' => 'ben',
            'Canadian Aboriginal' => 'Canadian_Aboriginal',
            'Cherokee' => 'chr',
            'Chinese (Simplified)' => 'chi_sim+HanS',
            'Chinese (Simplified, Vertical)' => 'chi_sim_vert+HanS_vert',
            'Chinese (Traditional)' => 'chi_tra+HanT',
            'Chinese (Traditional, Vertical)' => 'chi_tra_vert+HanT_vert',
            'Cyrillic' => 'Cyrillic',
            'Devanagari' => 'Devanagari',
            'Ethiopic' => 'Ethiopic',
            'Fraktur' => 'Fraktur',
            'Georgian' => 'kat',
            'Greek' => 'ell',
            'Gujarati' => 'guj',
            'Gurmukhi' => 'pan',
            'Han (Simplified)' => 'HanS',
            'Han (Simplified, Vertical)' => 'HanS_vert',
            'Han (Traditional)' => 'HanT',
            'Han (Traditional, Vertical)' => 'HanT_vert',
            'Hangul' => 'Hangul',
            'Hangul (Vertical)' => 'Hangul_vert',
            'Hebrew' => 'heb',
            'Japanese' => 'jpn',
            'Japanese (Vertical)' => 'jpn_vert',
            'Kannada' => 'kan',
            'Khmer' => 'khm',
            'Lao' => 'lao',
            'Latin' => 'Latin',
            'Malayalam' => 'mal',
            'Myanmar' => 'mya',
            'Oriya' => 'ori',
            'Sinhala' => 'sin',
            'Syriac' => 'syr',
            'Tamil' => 'tam',
            'Telugu' => 'tel',
            'Thaana' => 'Thaana',
            'Thai' => 'tha',
            'Tibetan' => 'bod',
            'Vietnamese' => 'vie',
            'Afrikaans' => 'afr',
            'Amharic' => 'amh',
            'Assamese' => 'asm',
            'Azerbaijani' => 'aze',
            'Azerbaijani (Cyrillic)' => 'aze_cyrl',
            'Belarusian' => 'bel',
            'Bosnian' => 'bos',
            'Breton' => 'bre',
            'Bulgarian' => 'bul',
            'Catalan' => 'cat',
            'Cebuano' => 'ceb',
            'Czech' => 'ces',
            'Corsican' => 'cos',
            'Welsh' => 'cym',
            'Danish' => 'dan',
            'Divehi' => 'div',
            'Dzongkha' => 'dzo',
            'English' => 'eng',
            'Middle English' => 'enm',
            'Esperanto' => 'epo',
            'Estonian' => 'est',
            'Basque' => 'eus',
            'Faroese' => 'fao',
            'Persian' => 'fas',
            'Filipino' => 'fil',
            'Finnish' => 'fin',
            'French' => 'fra',
            'Old French' => 'frm',
            'Fraktur (Old German)' => 'frk',
            'Irish' => 'gle',
            'Scottish Gaelic' => 'gla',
            'Galician' => 'glg',
            'Ancient Greek' => 'grc',
            'Haitian Creole' => 'hat',
            'Hindi' => 'hin',
            'Croatian' => 'hrv',
            'Hungarian' => 'hun',
            'Icelandic' => 'isl',
            'Indonesian' => 'ind',
            'Italian' => 'ita',
            'Old Italian' => 'ita_old',
            'Javanese' => 'jav',
            'Kazakh' => 'kaz',
            'Kyrgyz' => 'kir',
            'Kurdish' => 'kmr',
            'Latvian' => 'lav',
            'Lithuanian' => 'lit',
            'Luxembourgish' => 'ltz',
            'Macedonian' => 'mkd',
            'Maltese' => 'mlt',
            'Mongolian' => 'mon',
            'Maori' => 'mri',
            'Malay' => 'msa',
            'Nepali' => 'nep',
            'Dutch' => 'nld',
            'Norwegian' => 'nor',
            'Occitan' => 'oci',
            'Pashto' => 'pus',
            'Polish' => 'pol',
            'Portuguese' => 'por',
            'Quechua' => 'que',
            'Romanian' => 'ron',
            'Russian' => 'rus',
            'Sanskrit' => 'san',
            'Slovak' => 'slk',
            'Slovenian' => 'slv',
            'Spanish' => 'spa',
            'Old Spanish' => 'spa_old',
            'Albanian' => 'sqi',
            'Serbian' => 'srp',
            'Serbian (Latin)' => 'srp_latn',
            'Sundanese' => 'sun',
            'Swahili' => 'swa',
            'Swedish' => 'swe',
            'Tatar' => 'tat',
            'Tigrinya' => 'tir',
            'Tongan' => 'ton',
            'Turkish' => 'tur',
            'Uyghur' => 'uig',
            'Urdu' => 'urd',
            'Uzbek' => 'uzb',
            'Uzbek (Cyrillic)' => 'uzb_cyrl',
            'Yiddish' => 'yid',
            'Yoruba' => 'yor',
        ];

//        $cachedLanguages = Cache::get('cachedTesseractLanguages', null);
//        if (!$cachedLanguages) {
//            $output = shell_exec('tesseract --list-langs 2>&1');
//            $installedLanguages = explode("\n", trim($output));
//            if (isset($installedLanguages[0]) && str_contains($installedLanguages[0], 'List of available languages')) {
//                array_shift($installedLanguages);
//            }
//            Cache::put('cachedTesseractLanguages', $installedLanguages, now()->addDays(7));
//            $cachedLanguages = $installedLanguages;
//        }
        return $languageMap;
    }
	
	public function exportPdf(Request $request)
{
    $pages = $request->input('pages', []);

    if (!is_array($pages) || empty($pages)) {
        return response()->json(['error' => 'Pages array is required'], 400);
    }

    // Try to load DejaVu Sans from dompdf package (commonly available)
    $fontPathCandidates = [
        base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf'),
        base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-webfont.ttf'),
        // fallback to other potential locations if you installed fonts elsewhere:
        storage_path('fonts/DejaVuSans.ttf'),
        resource_path('fonts/DejaVuSans.ttf'),
    ];

    $fontDataUri = null;
    foreach ($fontPathCandidates as $p) {
        if (file_exists($p) && is_readable($p)) {
            $b64 = base64_encode(file_get_contents($p));
            // TrueType font
            $fontDataUri = "data:font/truetype;charset=utf-8;base64,{$b64}";
            break;
        }
    }

    // If no local font found, you can optionally throw or log and fall back to default.
    if (! $fontDataUri) {
        // Better to fail loudly so you know to install a font with Cyrillic support.
        return response()->json(['error' => 'Cyrillic-capable font not found on server. Place DejaVuSans.ttf in vendor/dompdf/... or storage/fonts and retry.'], 500);
    }

    // Build HTML with embedded font and simple page breaks
    $css = <<<CSS
    <style>
      @font-face {
        font-family: 'DejaVu Sans Embedded';
        src: url("{$fontDataUri}") format('truetype');
        font-weight: normal;
        font-style: normal;
        font-display: swap;
      }
      html, body {
        font-family: 'DejaVu Sans Embedded', DejaVu, sans-serif;
        font-size: 12pt;
        margin: 0;
        padding: 1.2cm;
        box-sizing: border-box;
      }
      .page {
        page-break-after: always;
        white-space: pre-wrap; /* preserve newlines and wrap long lines */
        word-wrap: break-word;
      }
    </style>
    CSS;

    $html = '<!doctype html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $css . '</head><body>';
    foreach ($pages as $pageText) {
        // escape HTML but preserve newlines
        $safe = nl2br(htmlspecialchars((string)$pageText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $html .= "<div class=\"page\">{$safe}</div>";
    }
    $html .= '</body></html>';

    // Dompdf options
    $options = new Options();
    $options->setIsHtml5ParserEnabled(true);
    // remote not needed since we embed the font
    $options->setIsRemoteEnabled(false);
    // ensure default charset usage
    $options->setChroot(base_path());

    $dompdf = new Dompdf($options);
    $dompdf->setPaper('A4', 'portrait');

    // load with explicit UTF-8
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    return Response::make($dompdf->output(), 200, [
        'Content-Type' => 'application/pdf; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="export.pdf"',
    ]);
}
	
	public function exportPptx(Request $request)
    {
        $pages = $request->input('pages', []);

        $presentation = new PhpPresentation();
        $presentation->removeSlideByIndex(0);

        foreach ($pages as $pageText) {
            $slide = $presentation->createSlide();
            $shape = $slide->createRichTextShape()
                ->setHeight(600)
                ->setWidth(800)
                ->setOffsetX(50)
                ->setOffsetY(50);

            $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $shape->createTextRun($pageText);
        }

        $writer = IOFactory::createWriter($presentation, 'PowerPoint2007');

        ob_start();
        $writer->save("php://output");
        $pptxData = ob_get_clean();

        return Response::make($pptxData, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'Content-Disposition' => 'attachment; filename="export.pptx"',
        ]);
    }
}

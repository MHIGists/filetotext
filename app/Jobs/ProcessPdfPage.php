<?php

namespace App\Jobs;

use App\Services\ChatGPTService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Imagick;
use Log;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Cache;
use Throwable;

class ProcessPdfPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $filePath;
    public int $pageNumber;
    public string $uuid;
    public bool $greyscale;
    public int $dpi;
    public array $languages;
	public int $applyContrast;
    public bool $translate;

    public function __construct(string $filePath, int $pageNumber, string $uuid, bool $greyscale = true, int $dpi = 300, array $languages = [], int $applyContrast = 0, bool $translate = false)
    {
        $this->filePath = $filePath;
        $this->pageNumber = $pageNumber;
        $this->uuid = $uuid;
        $this->greyscale = $greyscale;
        $this->dpi = $dpi;
        $this->languages = $languages;
    	$this->applyContrast = $applyContrast;
        $this->translate = $translate;
    }

    public function handle()
    {
        $pageNum = $this->pageNumber + 1;
        try {
            // Step 1: Convert one PDF page to image
            $imagick = new Imagick();
            $imagick->setResolution($this->dpi, $this->dpi);
            $imagick->readImage($this->filePath . '[' . $this->pageNumber . ']');
            $imagick->setImageFormat('jpeg');

            if ($this->greyscale) {
                // Grayscale and binarize for OCR
                $imagick->setImageColorspace(Imagick::COLORSPACE_GRAY);
                $imagick->thresholdImage(0.5 * Imagick::getQuantum());
            }

        	if ($this->applyContrast != 0) {
    			$increase = $this->applyContrast > 0;
    			$imagick->sigmoidalContrastImage($increase, abs($this->applyContrast), 0);
			}

            // Save to temp file for OCR
            $tempImagePath = tempnam(sys_get_temp_dir(), 'pdfpage_') . '.jpg';
            $imagick->writeImage($tempImagePath);

            // Step 2: OCR
            $text = (new TesseractOCR($tempImagePath))
                ->withoutTempFiles()
                ->dpi($this->dpi)
                ->lang(implode('+', $this->languages))
                ->threadLimit(1)
                ->run(20);

            // Re-read and resize for display
            $imagick->readImage($tempImagePath); // reload original for display
            $imagick->resizeImage(1024, 1024, Imagick::FILTER_LANCZOS, 1, true);
            $base64Image = 'data:image/jpeg;base64,' . base64_encode($imagick->getImageBlob());

            $imagick->clear();
            unlink($tempImagePath);

            // Step 3: Update cached results
            if ($this->translate) {
                $cache[$pageNum]['text'] = ChatGPTService::askToTranslate($text);
            }else{
                $cache[$pageNum]['text'] = preg_replace('/^\s*$(\r\n?|\n)/m', '', $text);
            }
            $cache[$pageNum]['page'] = $pageNum;
            $cache[$pageNum]['image'] = $base64Image;
            Cache::put($this->uuid . '_' . $pageNum, $cache, now()->addMinutes(5));

        } catch (Throwable $exception) {
            if (isset($tempImagePath, $imagick)) {
                $cache[$pageNum]['text'] = 'No text found';
                $cache[$pageNum]['page'] = $pageNum;
                $cache[$pageNum]['image'] = $base64Image ?? 'No image found!';
                Cache::put($this->uuid . '_' . $pageNum, $cache, now()->addMinutes(5));
                $imagick->clear();
                unlink($tempImagePath);
            }
            Log::debug($exception->getMessage());
            Log::debug($exception->getTraceAsString());
        }
    }
}

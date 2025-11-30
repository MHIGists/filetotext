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

class ProcessImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $imagePath;
    public string $uuid;
    public bool $greyscale;
    public int $dpi;
    public array $languages;
    public int $applyContrast;
    public bool $translate;

    public function __construct(
        string $imagePath,
        string $uuid,
        bool $greyscale = true,
        int $dpi = 300,
        array $languages = [],
        int $applyContrast = 0,
        bool $translate = false
    ) {
        $this->imagePath = $imagePath;
        $this->uuid = $uuid;
        $this->greyscale = $greyscale;
        $this->dpi = $dpi;
        $this->languages = $languages;
        $this->applyContrast = $applyContrast;
        $this->translate = $translate;
    }

    public function handle()
    {
        $pageNum = 1;
        try {
            $imagick = new Imagick($this->imagePath);
            $imagick->setResolution($this->dpi, $this->dpi);

            if ($this->greyscale) {
                $imagick->setImageColorspace(Imagick::COLORSPACE_GRAY);
                $imagick->thresholdImage(0.5 * Imagick::getQuantum());
            }

           if ($this->applyContrast != 0) {
    			$increase = $this->applyContrast > 0;
    			$imagick->sigmoidalContrastImage($increase, abs($this->applyContrast), 0);
			}

            $tempImagePath = tempnam(sys_get_temp_dir(), 'ocrimg_') . '.jpg';
            $imagick->setImageFormat('jpeg');
            $imagick->writeImage($tempImagePath);

            $text = (new TesseractOCR($tempImagePath))
                ->withoutTempFiles()
                ->dpi($this->dpi)
                ->lang(implode('+', $this->languages))
                ->threadLimit(1)
                ->run(20);

            $imagick->readImage($tempImagePath);
            $imagick->resizeImage(1024, 1024, Imagick::FILTER_LANCZOS, 1, true);
            $base64Image = 'data:image/jpeg;base64,' . base64_encode($imagick->getImageBlob());

            $imagick->clear();
            unlink($tempImagePath);

            // Match PDF job caching format exactly
            if ($this->translate){
                $cache[$pageNum]['text'] = ChatGPTService::askToTranslate($text);
            }else{
                $cache[$pageNum]['text'] = preg_replace('/^\s*$(\r\n?|\n)/m', '', $text);
            }
            $cache[$pageNum]['page'] = $pageNum;
            $cache[$pageNum]['image'] = $base64Image;
            Cache::put($this->uuid . '_' . $pageNum, $cache, now()->addMinutes(5));
        } catch (\Throwable $exception) {
            if (isset($imagick, $tempImagePath)) {
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

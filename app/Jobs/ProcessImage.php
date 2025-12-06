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
use RuntimeException;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Cache;
use Throwable;

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
        bool   $greyscale = true,
        int    $dpi = 300,
        array  $languages = [],
        int    $applyContrast = 0,
        bool   $translate = false
    )
    {
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
        $tempImagePath = null;
        $imagick = null;
        $displayImagick = null;

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

            $displayImagick = clone $imagick;
            $tempImagePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ocrimg_'.uniqid('', true).'.jpg';

            $imagick->setImageFormat('jpeg');
            if ($imagick->writeImage($tempImagePath) === false || !file_exists($tempImagePath)) {
                throw new RuntimeException("Failed to write temporary image: {$tempImagePath}");
            }

            // OCR
            $text = (new TesseractOCR($tempImagePath))
                ->withoutTempFiles()
                ->dpi($this->dpi)
                ->lang(implode('+', $this->languages))
                ->threadLimit(1)
                ->run(20);

            $displayImagick->resizeImage(1024, 1024, Imagick::FILTER_LANCZOS, 1, true);
            $base64Image = 'data:image/jpeg;base64,'.base64_encode($displayImagick->getImageBlob());

            if ($this->translate) {
                $cache[$pageNum]['text'] = ChatGPTService::askToTranslate($text);
            } else {
                $cache[$pageNum]['text'] = preg_replace('/^\s*$(\r\n?|\n)/m', '', $text);
            }
            $cache[$pageNum]['page'] = $pageNum;
            $cache[$pageNum]['image'] = $base64Image;
            Cache::put($this->uuid.'_'.$pageNum, $cache, now()->addMinutes(15));
        } catch (Throwable $exception) {
            Log::error($exception->getMessage());
            Log::error($exception->getTraceAsString());

            $cache[$pageNum]['text'] = 'No text found';
            $cache[$pageNum]['page'] = $pageNum;
            $cache[$pageNum]['image'] = $base64Image ?? 'No image found!';
            Cache::put($this->uuid.'_'.$pageNum, $cache, now()->addMinutes(15));
        } finally {
            if ($imagick instanceof Imagick) {
                try {
                    $imagick->clear();
                } catch (Throwable $_) {
                }
            }

            if ($displayImagick instanceof Imagick) {
                try {
                    $displayImagick->clear();
                } catch (Throwable $_) {
                }
            }

            if ($tempImagePath && file_exists($tempImagePath)) {
                if (!@unlink($tempImagePath)) {
                    Log::warning("Unable to unlink temp image: {$tempImagePath}");
                }
            }
        }
    }

}

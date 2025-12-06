<?php

namespace App\Services;

use Exception;
use Http;
use Illuminate\Support\Facades\RateLimiter;

class ChatGPTService
{
    private const RPM_LIMIT = 450;             // requests per 60 seconds The actual RPM is 500
    private const TOKEN_LIMIT = 400_000;       // The actual TPM is 500k
    private const WINDOW_SECONDS = 60;

    public static function askToTranslate(string $text): ?string
    {
        $rpmKey = 'openai-translate-rpm';

        if (RateLimiter::tooManyAttempts($rpmKey, self::RPM_LIMIT)) {
            sleep(RateLimiter::availableIn($rpmKey));
        }
        RateLimiter::hit($rpmKey, self::WINDOW_SECONDS);
        $tokenKey = 'openai-translate-input-tokens';
        $estimatedTokens = self::estimateTokens($text);

        // Ensure adding these tokens does not exceed TPM
        while (RateLimiter::attempts($tokenKey) + $estimatedTokens > self::TOKEN_LIMIT) {
            sleep(RateLimiter::availableIn($tokenKey));
        }

        // Increment token usage
        RateLimiter::hit($tokenKey, self::WINDOW_SECONDS, $estimatedTokens);

        // ---- API CALL ----
        $apiKey = config('openai.api_key');
        if ($apiKey === null) {
            throw new Exception('OpenAI API key not configured.');
        }

        $systemPrompt = <<<EOD
Translate the following medical text from English to Bulgarian. Interpret the content accurately, including clinical terminology, pathophysiology, diagnostics, treatments, and any contextual nuances. Produce a fluent Bulgarian translation that reads as if written by a qualified medical professional, using precise medical vocabulary and maintaining a formal, clinical tone. Preserve the original meaning without simplifying or omitting details. Provide only the translated text.
EOD;

        $response = Http::timeout(120)
            ->connectTimeout(30)
            ->retry(3, 2000)
            ->withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4.1',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $text],
                ],
                'temperature' => 0,
                'max_tokens' => 8192,
            ]);

        if ($response->successful()) {
            return trim($response->json('choices.0.message.content', ''));
        }

        return null;
    }

    private static function estimateTokens(string $input): int
    {
        return (int)ceil(strlen($input) / 4);
    }
}

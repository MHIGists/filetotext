<?php

namespace App\Services;

use Exception;
use Http;
use Illuminate\Support\Facades\RateLimiter;

class ChatGPTService
{
    public static function askToTranslate(string $text): ?string
    {
        $apiKey = config('openai.api_key');
        if ($apiKey === null) {
            throw new Exception('OpenAI API key not configured.');
        }

        $rpmKey = 'openai-rpm';
        $tokenKey = 'openai-tpm';
        $tokenCount = self::estimateTokens($text);

        self::throttleBasedOnStoredLimits($rpmKey, $tokenKey, $tokenCount);
        $systemPrompt = <<<EOD
Translate the following medical text from English to Bulgarian. Interpret the content accurately, including clinical terminology, pathophysiology, diagnostics, treatments, and any contextual nuances. Produce a fluent Bulgarian translation that reads as if written by a qualified medical professional, using precise medical vocabulary and maintaining a formal, clinical tone. Preserve the original meaning without simplifying or omitting details. Provide only the translated text.
EOD;

        $response = Http::timeout(120)
            ->connectTimeout(30)
            ->retry(3, 10000)
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
        self::updateSharedLimiterFromHeaders($rpmKey, $tokenKey, $response, $tokenCount);
        if ($response->successful()) {
            return trim($response->json('choices.0.message.content', ''));
        }

        return null;
    }

    private static function estimateTokens(string $input): int
    {
        return (int)ceil(strlen($input) / 4);
    }

    private static function throttleBasedOnStoredLimits(string $rpmKey, string $tokenKey, int $incomingTokens): void
    {
        if (RateLimiter::tooManyAttempts($rpmKey, RateLimiter::attempts($rpmKey) + 1)) {
            sleep(RateLimiter::availableIn($rpmKey));
        }
        while ((RateLimiter::attempts($tokenKey) + $incomingTokens) >
            RateLimiter::attempts("{$tokenKey}:limit")) {

            sleep(RateLimiter::availableIn($tokenKey));
        }
    }

    private static function updateSharedLimiterFromHeaders(
        string $rpmKey,
        string $tokenKey,
               $response,
        int    $tokenCount
    ): void
    {
        $headers = $response->headers();
        $remainingRequests = (int)($headers['x-ratelimit-remaining-requests'][0] ?? 1);
        $resetRequests = (int)($headers['x-ratelimit-reset-requests'][0] ?? 60);
        $limitRequests = (int)($headers['x-ratelimit-limit-requests'][0] ?? 500);
        $remainingTokens = (int)($headers['x-ratelimit-remaining-tokens'][0] ?? 100000);
        $resetTokens = (int)($headers['x-ratelimit-reset-tokens'][0] ?? 60);
        $limitTokens = (int)($headers['x-ratelimit-limit-tokens'][0] ?? 500000);
        RateLimiter::clear("{$tokenKey}:limit");
        RateLimiter::increment("{$tokenKey}:limit", $resetTokens, $limitTokens);
        RateLimiter::hit($rpmKey, $limitRequests);
        RateLimiter::increment($tokenKey, $resetTokens, $tokenCount);
        if ($remainingRequests <= 0) {
            RateLimiter::hit($rpmKey, $resetRequests * 2);
        }
        if ($remainingTokens <= 0) {
            RateLimiter::increment($tokenKey, $resetTokens * 2, $limitTokens);
        }
    }
}

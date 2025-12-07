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
        self::handleRateLimitFromResponse($response, $tokenCount);
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
            $waitTime = RateLimiter::availableIn($rpmKey);
            if ($waitTime > 0) {
                sleep($waitTime);
            }
        }
        $currentTokens = RateLimiter::attempts($tokenKey);
        $tokenLimit = RateLimiter::attempts("{$tokenKey}:limit");

        if (($currentTokens + $incomingTokens) > $tokenLimit && $tokenLimit > 0) {
            $waitTime = RateLimiter::availableIn($tokenKey);
            if ($waitTime > 0) {
                sleep($waitTime);
            }
        }
    }

    private static function handleRateLimitFromResponse(
        $response,
        int $tokenCount
    ): void {
        if ($response->status() === 429) {
            $headers = $response->headers();
            $retryAfter = (int)($headers['retry-after'][0] ?? 0);
            if ($retryAfter > 0) {
                sleep($retryAfter);
                return;
            }
            $resetRequests = (int)($headers['x-ratelimit-reset-requests'][0] ?? 0);
            $resetTokens = (int)($headers['x-ratelimit-reset-tokens'][0] ?? 0);
            $waitTime = max($resetRequests, $resetTokens);
            if ($waitTime > 0) {
                sleep($waitTime);
            }
        }
        $headers = $response->headers();
        $remainingRequests = (int)($headers['x-ratelimit-remaining-requests'][0] ?? 1);
        $remainingTokens = (int)($headers['x-ratelimit-remaining-tokens'][0] ?? 100000);
        $resetRequests = (int)($headers['x-ratelimit-reset-requests'][0] ?? 60);
        $resetTokens = (int)($headers['x-ratelimit-reset-tokens'][0] ?? 60);
        if ($remainingRequests <= 1 && $resetRequests > 0) {
            sleep($resetRequests);
        } elseif ($remainingTokens < $tokenCount && $resetTokens > 0) {
            sleep($resetTokens);
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
        $remainingRequests = self::getHeaderValue($headers, 'x-ratelimit-remaining-requests', 1);
        $resetRequests = self::getHeaderValue($headers, 'x-ratelimit-reset-requests', 60);
        $limitRequests = self::getHeaderValue($headers, 'x-ratelimit-limit-requests', 500);
        $remainingTokens = self::getHeaderValue($headers, 'x-ratelimit-remaining-tokens', 100000);
        $resetTokens = self::getHeaderValue($headers, 'x-ratelimit-reset-tokens', 60);
        $limitTokens = self::getHeaderValue($headers, 'x-ratelimit-limit-tokens', 500000);
        RateLimiter::clear("{$tokenKey}:limit");
        if ($limitTokens > 0) {
            RateLimiter::increment("{$tokenKey}:limit", $resetTokens, $limitTokens);
        }
        if ($limitRequests > 0) {
            RateLimiter::hit($rpmKey, $resetRequests);
        }
        RateLimiter::increment($tokenKey, $resetTokens, $tokenCount);
        if ($remainingRequests <= 0 && $resetRequests > 0) {
            RateLimiter::hit($rpmKey, $resetRequests);
        }
        if ($remainingTokens <= 0 && $resetTokens > 0) {
            RateLimiter::increment($tokenKey, $resetTokens, $limitTokens);
        }
    }

    private static function getHeaderValue(array $headers, string $key, int $default): int
    {
        if (!isset($headers[$key])) {
            return $default;
        }
        $value = $headers[$key];
        if (is_array($value)) {
            return (int)($value[0] ?? $default);
        }

        return (int)($value ?? $default);
    }
}

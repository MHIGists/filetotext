<?php

namespace App\Services;

use Exception;
use Http;

class ChatGPTService
{
    public static function askToTranslate(string $text): ?string
    {
        $key = config('openai.api_key');
        if ($key === null) {
            throw new Exception('OpenAI API key not configured.');
        }

        $systemPrompt = <<<EOD
Translate the following medical text from English to Bulgarian. Interpret the content accurately, including clinical terminology, pathophysiology, diagnostics, treatments, and any contextual nuances. Produce a fluent Bulgarian translation that reads as if written by a qualified medical professional, using precise medical vocabulary and maintaining a formal, clinical tone. Preserve the original meaning without simplifying or omitting details. Provide only the translated text.
EOD;

        $response = Http::timeout(120)
            ->connectTimeout(30)
            ->retry(3, 2000)
            ->withHeaders([
                'Authorization' => "Bearer {$key}",
                'Content-Type' => 'application/json',
            ])
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4.1',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $text],
                ],
                'temperature' => 0.2,
                'max_tokens' => 8192,
            ]);


        if ($response->successful()) {
            $choices = $response->json('choices');
            if (isset($choices[0]['message']['content'])) {
                return trim($choices[0]['message']['content']);
            }
        }

        return null;
    }
}

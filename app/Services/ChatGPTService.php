<?php

namespace App\Services;

use Http;

class ChatGPTService
{
    public static function askToTranslate(string $text): ?string
    {
        $key = config('openai.api_key');
        if ($key) {
            throw new \Exception('OpenAI API key not configured.');
        }

        $systemPrompt = <<<EOD
You are a translator whose sole task is to translate text from English to Bulgarian. Translate as literally as possible while keeping the final Bulgarian text natural, correct, and fluent. Do not change the meaning, tone, or structure unless required for natural Bulgarian expression. Provide only the translation with no explanations.
EOD;

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$key}",
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4', // or 'gpt-4', 'gpt-3.5-turbo' if available
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $text],
            ],
            'temperature' => 0.2,
            'max_tokens' => 1500,
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

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function analyze(string $systemPrompt, string $userPrompt): string
    {
        $apiKey = config('services.anthropic.api_key');

        if (empty($apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
        ])->timeout(60)->post(self::API_URL, [
            'model' => config('services.anthropic.model', 'claude-sonnet-4-5'),
            'max_tokens' => 1500,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Anthropic API error: '.$response->status().' '.$response->body());
        }

        $blocks = $response->json('content', []);
        $text = collect($blocks)
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");

        return $text !== '' ? $text : 'The AI returned an empty response.';
    }
}

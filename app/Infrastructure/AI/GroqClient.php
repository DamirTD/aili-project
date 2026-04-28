<?php

namespace App\Infrastructure\AI;

use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class GroqClient
{
    public function text(
        string $apiKey,
        string $baseUrl,
        bool|string $verifyOption,
        string $model,
        string $prompt
    ): Response {
        return Http::withOptions(['verify' => $verifyOption])
            ->withToken($apiKey)
            ->timeout(90)
            ->post(
                $baseUrl.'/chat/completions',
                [
                    'model' => $model,
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Ты медицинский помощник triage. Возвращай только валидный JSON.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ]
            );
    }

    public function vision(
        string $apiKey,
        string $baseUrl,
        bool|string $verifyOption,
        string $model,
        string $prompt,
        UploadedFile $image
    ): Response {
        $mimeType = $image->getMimeType() ?: 'image/jpeg';
        $encoded = base64_encode(file_get_contents($image->getRealPath()));

        return Http::withOptions(['verify' => $verifyOption])
            ->withToken($apiKey)
            ->timeout(90)
            ->post(
                $baseUrl.'/chat/completions',
                [
                    'model' => $model,
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Ты медицинский помощник triage. Возвращай только валидный JSON.',
                        ],
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => $prompt,
                                ],
                                [
                                    'type' => 'image_url',
                                    'image_url' => [
                                        'url' => "data:{$mimeType};base64,{$encoded}",
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]
            );
    }

    public function openAiCompatibleText(
        string $apiKey,
        string $baseUrl,
        bool|string $verifyOption,
        string $model,
        string $prompt
    ): Response {
        return Http::withOptions(['verify' => $verifyOption])
            ->withToken($apiKey)
            ->timeout(90)
            ->post(
                rtrim($baseUrl, '/').'/chat/completions',
                [
                    'model' => $model,
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Ты медицинский помощник triage. Возвращай только валидный JSON.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ]
            );
    }

    public function deepSeekText(
        string $apiKey,
        string $baseUrl,
        bool|string $verifyOption,
        string $model,
        string $prompt
    ): Response {
        return $this->openAiCompatibleText(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            verifyOption: $verifyOption,
            model: $model,
            prompt: $prompt
        );
    }
}


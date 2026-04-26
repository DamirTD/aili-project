<?php

namespace App\Application\Diagnosis\Queries;

use App\Application\Diagnosis\Support\DiagnosisResponseMapper;
use App\Infrastructure\AI\GroqClient;
use App\Infrastructure\Medical\MedicalSourcesProvider;
use App\Infrastructure\Medical\OwidInsightsProvider;
use RuntimeException;

class AnalyzeDiagnosisHandler
{
    public function __construct(
        private readonly GroqClient $groqClient,
        private readonly MedicalSourcesProvider $sourcesProvider,
        private readonly OwidInsightsProvider $owidInsightsProvider,
        private readonly DiagnosisResponseMapper $responseMapper
    ) {
    }

    public function __invoke(AnalyzeDiagnosisQuery $query): array
    {
        $apiKey = config('services.groq_ai.api_key');
        $model = config('services.groq_ai.model', 'openai/gpt-oss-120b');
        $visionModel = config('services.groq_ai.vision_model');
        $baseUrl = rtrim(config('services.groq_ai.base_url', 'https://api.groq.com/openai/v1'), '/');
        $verifySsl = filter_var(config('services.groq_ai.verify_ssl', true), FILTER_VALIDATE_BOOL);
        $caBundle = config('services.groq_ai.ca_bundle');

        if (! $apiKey) {
            throw new RuntimeException('GROQ_AI не задан в .env');
        }

        $verifyOption = $verifySsl;
        if ($caBundle && is_string($caBundle)) {
            $verifyOption = $caBundle;
        }

        $sources = $this->sourcesProvider->getSources($query->description, $verifyOption);
        $owidInsights = $this->owidInsightsProvider->getInsights($query->description, $verifyOption);
        $prompt = $this->buildPrompt(
            description: $query->description,
            sources: $sources,
            owidInsights: $owidInsights,
            hasImage: $query->image !== null,
            age: $query->age,
            gender: $query->gender
        );

        $usedVision = false;
        $imageNote = null;

        if ($query->image && is_string($visionModel) && $visionModel !== '') {
            $response = $this->groqClient->vision(
                apiKey: $apiKey,
                baseUrl: $baseUrl,
                verifyOption: $verifyOption,
                model: $visionModel,
                prompt: $prompt,
                image: $query->image
            );

            if (! $response->failed()) {
                $usedVision = true;
            } else {
                $imageNote = 'Vision-модель недоступна для вашего ключа, выполнен текстовый fallback.';
                $response = $this->groqClient->text(
                    apiKey: $apiKey,
                    baseUrl: $baseUrl,
                    verifyOption: $verifyOption,
                    model: $model,
                    prompt: $prompt
                );
            }
        } else {
            $response = $this->groqClient->text(
                apiKey: $apiKey,
                baseUrl: $baseUrl,
                verifyOption: $verifyOption,
                model: $model,
                prompt: $prompt
            );

            if ($query->image) {
                $imageNote = 'Изображение получено, но vision-модель не настроена в .env.';
            }
        }

        if ($response->failed()) {
            throw new RuntimeException('Ошибка Groq AI: '.$response->body());
        }

        $rawText = data_get($response->json(), 'choices.0.message.content', '{}');
        $decoded = $this->parseJsonFromModel((string) $rawText);

        if (! is_array($decoded)) {
            throw new RuntimeException('Не удалось разобрать ответ модели.');
        }

        return $this->responseMapper->map(
            decoded: $decoded,
            sources: $sources,
            owidInsights: $owidInsights,
            hasImage: $query->image !== null,
            usedVision: $usedVision,
            imageNote: $imageNote
        );
    }

    protected function buildPrompt(
        string $description,
        array $sources,
        array $owidInsights,
        bool $hasImage,
        ?int $age,
        ?string $gender
    ): string {
        $sourcesText = '';
        foreach ($sources as $index => $source) {
            $title = $source['title'] ?? 'Без названия';
            $url = $source['url'] ?? '';
            $snippet = $source['snippet'] ?? '';
            $sourcesText .= ($index + 1).". {$title}\nURL: {$url}\nФрагмент: {$snippet}\n\n";
        }

        $owidText = '';
        foreach ($owidInsights as $index => $item) {
            $title = $item['title'] ?? 'OWID метрика';
            $advice = $item['advice'] ?? 'Следите за факторами риска.';
            $why = $item['why'] ?? 'Фактор связан с осложнениями здоровья.';
            $today = $item['today'] ?? 'Запланируйте профилактический осмотр.';
            $url = $item['url'] ?? '';
            $owidText .= ($index + 1).". {$title}\nСовет: {$advice}\nПочему важно: {$why}\nЧто сделать сегодня: {$today}\nURL: {$url}\n";
        }

        $profileText = '';
        if ($age !== null || $gender !== null) {
            $genderRu = match ($gender) {
                'male' => 'мужской',
                'female' => 'женский',
                'other' => 'другой',
                default => 'не указан',
            };
            $ageText = $age !== null ? (string) $age : 'не указан';
            $profileText = "Профиль пациента: возраст {$ageText}, пол {$genderRu}. Учитывай это при оценке.\n";
        }

        return "Ты медицинский ассистент для предварительного triage.\n".
            "Пользователь дал описание симптомов: {$description}\n".
            $profileText.
            ($hasImage
                ? "Пользователь также приложил изображение. Учти визуальные признаки при формировании ответа.\n"
                : '').
            "Ниже внешние источники по симптомам, используй их для рассуждения:\n{$sourcesText}".
            "Ниже контекстные рекомендации OWID:\n{$owidText}\n".
            "Отвечай СТРОГО на русском языке.\n".
            "Не придумывай ссылки. Если источников недостаточно, оставь sources пустым массивом.\n".
            "Верни ТОЛЬКО JSON без markdown с полями:\n".
            "diagnosis (строка), confidence (низкая|средняя|высокая), urgency (низкая|средняя|высокая|срочно), ".
            "about (строка), possible_causes (массив строк), care_plan (массив строк), red_flags (массив строк), sources (массив объектов с полями title,url).";
    }

    protected function parseJsonFromModel(string $rawText): ?array
    {
        $decoded = json_decode($rawText, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($rawText, '{');
        $end = strrpos($rawText, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $jsonSlice = substr($rawText, $start, $end - $start + 1);
        $decoded = json_decode($jsonSlice, true);

        return is_array($decoded) ? $decoded : null;
    }
}


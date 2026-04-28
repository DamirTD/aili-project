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

        $domain = $this->detectDomain($query->description);
        $sources = $this->sourcesProvider->getSources($query->description, $verifyOption, $domain);
        $sources = $this->rerankSourcesWithinDomain($sources, $query->description, $domain);
        $sources = $this->filterValidSources($sources);
        $owidInsights = $this->owidInsightsProvider->getInsights($query->description, $verifyOption, $domain);
        $owidInsights = $this->filterValidInsights($owidInsights);
        $prompt = $this->buildPrompt(
            description: $query->description,
            sources: $sources,
            owidInsights: $owidInsights,
            domain: $domain,
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
            domain: $domain,
            age: $query->age,
            gender: $query->gender,
            hasImage: $query->image !== null,
            usedVision: $usedVision,
            imageNote: $imageNote
        );
    }

    protected function buildPrompt(
        string $description,
        array $sources,
        array $owidInsights,
        ?string $domain,
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

        $domainText = $domain !== null && $domain !== '' ? $domain : 'neutral';

        $sourcesContext = $sourcesText !== ''
            ? "Ниже внешние источники по симптомам, используй их для рассуждения:\n{$sourcesText}"
            : "Внешних релевантных источников для этого запроса не найдено. Не придумывай ссылки и не заполняй sources.\n";

        return "Ты медицинский ассистент для предварительного triage.\n".
            "Пользователь дал описание симптомов: {$description}\n".
            "Определенный домен симптомов: {$domainText}.\n".
            $profileText.
            ($hasImage
                ? "Пользователь также приложил изображение. Учти визуальные признаки при формировании ответа.\n"
                : '').
            $sourcesContext.
            "Ниже контекстные рекомендации OWID:\n{$owidText}\n".
            "Тон ответа: сдержанный, но человеческий. Пиши коротко, ясно и без нагнетания.\n".
            "Формат: короткие смысловые карточки, чтобы пользователь быстро понял, что делать.\n".
            "Не смешивай разные медицинские домены. Если домен respiratory, не выводи кардиологический диагноз без явных red flags (например, давящая боль в груди, выраженная одышка в покое, иррадиация в руку/челюсть).\n".
            "Считай список источников уже отфильтрованным и ранжированным внутри домена, опирайся прежде всего на них.\n".
            "Отвечай СТРОГО на русском языке.\n".
            "Не придумывай ссылки. Если источников недостаточно, оставь sources пустым массивом.\n".
            "Верни ТОЛЬКО JSON без markdown с полями:\n".
            "diagnosis (строка), confidence (низкая|средняя|высокая), urgency (низкая|средняя|высокая|срочно), ".
            "about (строка), confidence_reason (строка), possible_causes (массив строк), care_plan (массив строк), do_not_do (массив строк), ".
            "home_care_window (строка), red_flags (массив строк), followup_questions (массив из 2-3 строк), sources (массив объектов с полями title,url).";
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

    protected function detectDomain(string $description): string
    {
        $domains = config('medical_triage.symptom_domains', []);
        if (! is_array($domains) || $domains === []) {
            return 'neutral';
        }

        $text = mb_strtolower($description);
        $bestDomain = 'neutral';
        $bestScore = 0;

        foreach ($domains as $domain => $config) {
            if (! is_array($config)) {
                continue;
            }

            $score = 0;
            $positive = is_array($config['positive_keywords'] ?? null) ? $config['positive_keywords'] : [];
            $negative = is_array($config['negative_keywords'] ?? null) ? $config['negative_keywords'] : [];

            foreach ($positive as $keyword) {
                $keywordLc = mb_strtolower((string) $keyword);
                if ($keywordLc !== '' && str_contains($text, $keywordLc)) {
                    $score += 2;
                }
            }

            foreach ($negative as $keyword) {
                $keywordLc = mb_strtolower((string) $keyword);
                if ($keywordLc !== '' && str_contains($text, $keywordLc)) {
                    $score -= 1;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestDomain = (string) $domain;
            }
        }

        return $bestScore > 0 ? $bestDomain : 'neutral';
    }

    protected function rerankSourcesWithinDomain(array $sources, string $description, ?string $domain): array
    {
        if ($sources === []) {
            return [];
        }

        $descriptionLc = mb_strtolower($description);
        $keywords = $this->getDomainKeywords((string) ($domain ?? ''));
        $englishTokens = $this->buildEnglishTokensFromDescription($description);
        $ranked = [];

        foreach ($sources as $source) {
            $text = mb_strtolower(
                ((string) ($source['title'] ?? '')).' '.
                ((string) ($source['snippet'] ?? '')).' '.
                ((string) ($source['url'] ?? '')).' '.
                ((string) ($source['source_domain'] ?? ''))
            );
            $score = 0;

            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($text, $keyword)) {
                    $score += 3;
                }
            }

            foreach (preg_split('/\s+/u', $descriptionLc) ?: [] as $token) {
                $token = trim($token);
                if (mb_strlen($token) < 4) {
                    continue;
                }
                if (str_contains($text, $token)) {
                    $score++;
                }
            }

            foreach ($englishTokens as $token) {
                if ($token !== '' && str_contains($text, $token)) {
                    $score += 2;
                }
            }

            $host = (string) parse_url((string) ($source['url'] ?? ''), PHP_URL_HOST);
            $hostLc = mb_strtolower($host);
            if ($hostLc !== '' && (str_contains($hostLc, 'nlm.nih.gov') || str_contains($hostLc, 'medlineplus.gov'))) {
                $score += 2;
            }

            $source['_score'] = $score;
            $ranked[] = $source;
        }

        usort($ranked, static fn (array $a, array $b): int => (($b['_score'] ?? 0) <=> ($a['_score'] ?? 0)));

        return array_map(static function (array $item): array {
            unset($item['_score']);

            return $item;
        }, array_values(array_slice($ranked, 0, 6)));
    }

    protected function getDomainKeywords(string $domain): array
    {
        $domains = config('medical_triage.symptom_domains', []);
        $config = is_array($domains[$domain] ?? null) ? $domains[$domain] : [];
        $keywords = is_array($config['positive_keywords'] ?? null) ? $config['positive_keywords'] : [];

        return array_map(
            static fn (mixed $keyword): string => mb_strtolower((string) $keyword),
            $keywords
        );
    }

    protected function buildEnglishTokensFromDescription(string $description): array
    {
        $text = mb_strtolower($description);
        $dictionary = config('medical_sources.translation.ru_to_en', []);
        $tokens = [];

        if (! is_array($dictionary)) {
            return [];
        }

        foreach ($dictionary as $ruStem => $enTerm) {
            $ruStem = mb_strtolower(trim((string) $ruStem));
            $enTerm = mb_strtolower(trim((string) $enTerm));
            if ($ruStem === '' || $enTerm === '') {
                continue;
            }
            if (! str_contains($text, $ruStem)) {
                continue;
            }

            foreach (preg_split('/\s+/u', $enTerm) ?: [] as $part) {
                $part = trim($part);
                if (mb_strlen($part) >= 3) {
                    $tokens[] = $part;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    protected function filterValidSources(array $sources): array
    {
        $filtered = [];
        foreach ($sources as $source) {
            $title = trim((string) ($source['title'] ?? ''));
            $url = trim((string) ($source['url'] ?? ''));
            if ($title === '' || ! preg_match('/^https?:\/\//i', $url)) {
                continue;
            }
            $filtered[] = [
                'title' => $title,
                'url' => $url,
                'snippet' => (string) ($source['snippet'] ?? ''),
                'source_domain' => (string) ($source['source_domain'] ?? ''),
                'language' => (string) ($source['language'] ?? ''),
            ];
        }

        return array_values(array_slice($filtered, 0, 6));
    }

    protected function filterValidInsights(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = trim((string) ($item['url'] ?? ''));
            if ($url !== '' && ! preg_match('/^https?:\/\//i', $url)) {
                continue;
            }
            $result[] = $item;
        }

        return array_values(array_slice($result, 0, 3));
    }
}


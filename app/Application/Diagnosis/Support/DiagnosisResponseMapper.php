<?php

namespace App\Application\Diagnosis\Support;

class DiagnosisResponseMapper
{
    public function map(
        array $decoded,
        array $sources,
        array $owidInsights,
        string $domain,
        ?int $age,
        ?string $gender,
        bool $hasImage,
        bool $usedVision,
        ?string $imageNote
    ): array {
        return [
            'diagnosis' => (string) ($decoded['diagnosis'] ?? 'Недостаточно данных'),
            'confidence' => $this->normalizeLevel((string) ($decoded['confidence'] ?? 'низкая')),
            'urgency' => $this->normalizeUrgency((string) ($decoded['urgency'] ?? 'не определена')),
            'about' => (string) ($decoded['about'] ?? ''),
            'confidence_reason' => (string) ($decoded['confidence_reason'] ?? 'Оценка основана на описании симптомов и доступном контексте источников.'),
            'possible_causes' => is_array($decoded['possible_causes'] ?? null) ? $decoded['possible_causes'] : [],
            'care_plan' => is_array($decoded['care_plan'] ?? null) ? $decoded['care_plan'] : [],
            'do_not_do' => is_array($decoded['do_not_do'] ?? null) ? $decoded['do_not_do'] : [],
            'home_care_window' => (string) ($decoded['home_care_window'] ?? 'Оцените динамику симптомов в ближайшие 24-48 часов.'),
            'red_flags' => is_array($decoded['red_flags'] ?? null) ? $decoded['red_flags'] : [],
            'followup_questions' => is_array($decoded['followup_questions'] ?? null) ? array_values(array_slice($decoded['followup_questions'], 0, 3)) : [],
            'personalization_note' => $this->buildPersonalizationNote($age, $gender),
            'domain' => $domain,
            'sources' => $sources,
            'owid_insights' => $owidInsights,
            'disclaimer' => 'Это не медицинский диагноз. Обратитесь к врачу.',
            'source' => 'groq_ai_rag',
            'image_note' => $hasImage
                ? ($usedVision
                    ? 'Изображение учтено при анализе.'
                    : ($imageNote ?? 'Изображение получено, но анализ выполнен в текстовом режиме.'))
                : null,
        ];
    }

    protected function buildPersonalizationNote(?int $age, ?string $gender): ?string
    {
        if ($age === null && $gender === null) {
            return null;
        }

        $parts = [];
        if ($age !== null) {
            $parts[] = "возраст {$age}";
        }

        if ($gender !== null) {
            $genderText = match ($gender) {
                'male' => 'мужской пол',
                'female' => 'женский пол',
                'other' => 'указан другой пол',
                default => null,
            };

            if ($genderText !== null) {
                $parts[] = $genderText;
            }
        }

        if ($parts === []) {
            return null;
        }

        return 'Персонализация: учтены '.implode(', ', $parts).'.';
    }

    protected function normalizeLevel(string $value): string
    {
        $text = mb_strtolower(trim($value));
        return match (true) {
            str_contains($text, 'high'), str_contains($text, 'высок') => 'высокая',
            str_contains($text, 'medium'), str_contains($text, 'med'), str_contains($text, 'сред') => 'средняя',
            str_contains($text, 'low'), str_contains($text, 'низ') => 'низкая',
            default => $value === '' ? 'низкая' : $value,
        };
    }

    protected function normalizeUrgency(string $value): string
    {
        $text = mb_strtolower(trim($value));
        return match (true) {
            str_contains($text, 'urgent'), str_contains($text, 'critical'), str_contains($text, 'сроч') => 'срочно',
            str_contains($text, 'high'), str_contains($text, 'высок') => 'высокая',
            str_contains($text, 'medium'), str_contains($text, 'med'), str_contains($text, 'сред') => 'средняя',
            str_contains($text, 'low'), str_contains($text, 'низ') => 'низкая',
            default => $value === '' ? 'не определена' : $value,
        };
    }
}


<?php

namespace App\Application\Diagnosis\Support;

class DiagnosisResponseMapper
{
    public function map(
        array $decoded,
        array $sources,
        array $owidInsights,
        string $domain,
        array $triageSignals,
        ?int $age,
        ?string $gender,
        bool $hasImage,
        bool $usedVision,
        ?string $imageNote
    ): array {
        $confidence = $this->normalizeLevel((string) ($decoded['confidence'] ?? 'низкая'));
        $severity = $this->normalizeSeverity((string) ($decoded['severity'] ?? ($triageSignals['severity'] ?? 'средняя')));
        $ruleRedFlags = is_array($triageSignals['red_flags'] ?? null) ? $triageSignals['red_flags'] : [];
        $modelRedFlags = is_array($decoded['red_flags'] ?? null) ? $decoded['red_flags'] : [];
        $mergedRedFlags = array_values(array_slice(array_unique(array_filter(array_merge($modelRedFlags, $ruleRedFlags))), 0, 6));
        $urgency = $this->resolveUrgency(
            modelUrgency: $this->normalizeUrgency((string) ($decoded['urgency'] ?? 'не определена')),
            severity: $severity,
            redFlagsCount: count($mergedRedFlags)
        );
        $confidenceScore = $this->computeConfidenceScore(
            confidence: $confidence,
            domain: $domain,
            sourcesCount: count($sources),
            redFlagsCount: count($mergedRedFlags),
            hasProfile: $age !== null || $gender !== null
        );

        return [
            'diagnosis' => (string) ($decoded['diagnosis'] ?? 'Недостаточно данных'),
            'confidence' => $confidence,
            'confidence_score' => $confidenceScore,
            'urgency' => $urgency,
            'severity' => $severity,
            'about' => (string) ($decoded['about'] ?? ''),
            'confidence_reason' => (string) ($decoded['confidence_reason'] ?? 'Оценка основана на описании симптомов и доступном контексте источников.'),
            'possible_causes' => is_array($decoded['possible_causes'] ?? null) ? $decoded['possible_causes'] : [],
            'care_plan' => is_array($decoded['care_plan'] ?? null) ? $decoded['care_plan'] : [],
            'do_not_do' => is_array($decoded['do_not_do'] ?? null) ? $decoded['do_not_do'] : [],
            'home_care_window' => (string) ($decoded['home_care_window'] ?? 'Оцените динамику симптомов в ближайшие 24-48 часов.'),
            'red_flags' => $mergedRedFlags,
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

    protected function normalizeSeverity(string $value): string
    {
        $text = mb_strtolower(trim($value));
        return match (true) {
            str_contains($text, 'critical'), str_contains($text, 'крит') => 'критическая',
            str_contains($text, 'severe'), str_contains($text, 'тяж') => 'тяжелая',
            str_contains($text, 'medium'), str_contains($text, 'moderate'), str_contains($text, 'сред') => 'средняя',
            str_contains($text, 'light'), str_contains($text, 'mild'), str_contains($text, 'легк') => 'легкая',
            default => $value === '' ? 'средняя' : $value,
        };
    }

    protected function resolveUrgency(string $modelUrgency, string $severity, int $redFlagsCount): string
    {
        if ($redFlagsCount >= 2 || $severity === 'критическая') {
            return 'срочно';
        }
        if ($redFlagsCount === 1 || $severity === 'тяжелая') {
            return $this->maxUrgency($modelUrgency, 'высокая');
        }
        if ($severity === 'средняя') {
            return $this->maxUrgency($modelUrgency, 'средняя');
        }

        return $modelUrgency;
    }

    protected function maxUrgency(string $left, string $right): string
    {
        $weight = [
            'не определена' => 0,
            'низкая' => 1,
            'средняя' => 2,
            'высокая' => 3,
            'срочно' => 4,
        ];

        return ($weight[$left] ?? 0) >= ($weight[$right] ?? 0) ? $left : $right;
    }

    protected function computeConfidenceScore(
        string $confidence,
        string $domain,
        int $sourcesCount,
        int $redFlagsCount,
        bool $hasProfile
    ): int {
        $cfg = config('medical_triage.triage_rules.confidence', []);
        $score = (int) ($cfg['base'] ?? 45);

        $score += match ($confidence) {
            'высокая' => 28,
            'средняя' => 15,
            default => 4,
        };

        if ($domain !== '' && $domain !== 'neutral') {
            $score += (int) ($cfg['domain_bonus'] ?? 10);
        }

        $sourcesBonusPerItem = (int) ($cfg['sources_bonus_per_item'] ?? 5);
        $maxSourcesBonus = (int) ($cfg['max_sources_bonus'] ?? 20);
        $score += min($sourcesCount * $sourcesBonusPerItem, $maxSourcesBonus);

        $redFlagBonusPerItem = (int) ($cfg['red_flag_bonus_per_item'] ?? 4);
        $maxRedFlagBonus = (int) ($cfg['max_red_flag_bonus'] ?? 12);
        $score += min($redFlagsCount * $redFlagBonusPerItem, $maxRedFlagBonus);

        if ($hasProfile) {
            $score += (int) ($cfg['profile_bonus'] ?? 8);
        }

        return max(0, min(100, $score));
    }
}


<?php

namespace App\Application\Diagnosis\Support;

class DiagnosisResponseMapper
{
    public function map(
        array $decoded,
        array $sources,
        array $owidInsights,
        bool $hasImage,
        bool $usedVision,
        ?string $imageNote
    ): array {
        return [
            'diagnosis' => (string) ($decoded['diagnosis'] ?? 'Недостаточно данных'),
            'confidence' => (string) ($decoded['confidence'] ?? 'низкая'),
            'urgency' => (string) ($decoded['urgency'] ?? 'не определена'),
            'about' => (string) ($decoded['about'] ?? ''),
            'possible_causes' => is_array($decoded['possible_causes'] ?? null) ? $decoded['possible_causes'] : [],
            'care_plan' => is_array($decoded['care_plan'] ?? null) ? $decoded['care_plan'] : [],
            'red_flags' => is_array($decoded['red_flags'] ?? null) ? $decoded['red_flags'] : [],
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
}


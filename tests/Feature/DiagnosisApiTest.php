<?php

namespace Tests\Feature;

use App\Application\Shared\QueryBus\QueryBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiagnosisApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnosis_endpoint_requires_text_or_image(): void
    {
        $response = $this->postJson('/api/diagnosis/analyze', []);

        $response->assertStatus(422);
    }

    public function test_diagnosis_endpoint_returns_analysis(): void
    {
        $this->mock(QueryBus::class, function ($mock): void {
            $mock->shouldReceive('ask')
                ->once()
                ->andReturn([
                    'diagnosis' => 'Возможна вирусная инфекция',
                    'confidence' => 'средняя',
                    'urgency' => 'средняя',
                    'about' => 'Симптомы похожи на ОРВИ.',
                    'confidence_reason' => 'Типичный респираторный паттерн без тяжелых красных флагов.',
                    'possible_causes' => ['Вирусная инфекция'],
                    'care_plan' => ['Пить воду', 'Обратиться к терапевту'],
                    'do_not_do' => ['Не начинать антибиотики без назначения врача'],
                    'home_care_window' => 'Наблюдайте симптомы 24-48 часов.',
                    'red_flags' => ['Одышка'],
                    'followup_questions' => ['Есть ли одышка в покое?', 'Какая максимальная температура?'],
                    'personalization_note' => 'Персонализация: учтены возраст 31.',
                    'domain' => 'respiratory',
                    'sources' => [],
                    'owid_insights' => [],
                    'disclaimer' => 'Это не медицинский диагноз. Обратитесь к врачу.',
                    'source' => 'groq_ai_rag',
                    'image_note' => null,
                ]);
        });

        $response = $this->postJson('/api/diagnosis/analyze', [
            'description' => 'Температура и кашель 2 дня',
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'diagnosis',
                'confidence',
                'urgency',
                'about',
                'confidence_reason',
                'possible_causes',
                'care_plan',
                'do_not_do',
                'home_care_window',
                'red_flags',
                'followup_questions',
                'personalization_note',
                'domain',
                'sources',
                'owid_insights',
                'disclaimer',
                'source',
                'image_note',
            ])
            ->assertJsonPath('confidence', 'средняя')
            ->assertJsonPath('urgency', 'средняя')
            ->assertJsonPath('home_care_window', 'Наблюдайте симптомы 24-48 часов.');
    }

    public function test_diagnosis_endpoint_validates_profile_fields(): void
    {
        $response = $this->postJson('/api/diagnosis/analyze', [
            'description' => 'Головная боль',
            'age' => 400,
            'gender' => 'invalid',
        ]);

        $response->assertStatus(422);
    }
}

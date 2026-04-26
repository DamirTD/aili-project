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
                    'possible_causes' => ['Вирусная инфекция'],
                    'care_plan' => ['Пить воду', 'Обратиться к терапевту'],
                    'red_flags' => ['Одышка'],
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
                'possible_causes',
                'care_plan',
                'red_flags',
                'sources',
                'owid_insights',
                'disclaimer',
                'source',
                'image_note',
            ])
            ->assertJsonPath('confidence', 'средняя')
            ->assertJsonPath('urgency', 'средняя');
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

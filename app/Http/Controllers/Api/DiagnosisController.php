<?php

namespace App\Http\Controllers\Api;

use App\Application\Diagnosis\Queries\AnalyzeDiagnosisQuery;
use App\Application\Shared\QueryBus\QueryBus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DiagnosisController extends Controller
{
    public function analyze(Request $request, QueryBus $queryBus)
    {
        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:4000'],
            'image' => ['nullable', 'image', 'max:5120'],
            'gender' => ['nullable', 'string', 'in:male,female,other'],
            'age' => ['nullable', 'integer', 'min:0', 'max:120'],
        ]);

        $description = trim((string) ($validated['description'] ?? ''));
        $image = $request->file('image');

        if ($description === '' && ! $image) {
            return response()->json([
                'message' => 'Нужно добавить текст симптомов или изображение.',
            ], 422);
        }

        try {
            $result = $queryBus->ask(
                new AnalyzeDiagnosisQuery(
                    description: $description ?: 'Описание не указано',
                    image: $image,
                    age: $validated['age'] ?? null,
                    gender: $validated['gender'] ?? null
                )
            );
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($result);
    }
}

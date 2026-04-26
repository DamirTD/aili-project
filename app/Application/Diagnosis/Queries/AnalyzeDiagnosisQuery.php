<?php

namespace App\Application\Diagnosis\Queries;

use Illuminate\Http\UploadedFile;

class AnalyzeDiagnosisQuery
{
    public function __construct(
        public readonly string $description,
        public readonly ?UploadedFile $image,
        public readonly ?int $age,
        public readonly ?string $gender
    ) {
    }
}


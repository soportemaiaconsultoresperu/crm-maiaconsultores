<?php

namespace App\Contracts\Courses;

interface PdfRenderer
{
    /** @param array<string, mixed> $data */
    public function render(string $view, array $data): string;
}

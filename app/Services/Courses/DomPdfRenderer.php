<?php

namespace App\Services\Courses;

use App\Contracts\Courses\PdfRenderer;
use Barryvdh\DomPDF\Facade\Pdf;

class DomPdfRenderer implements PdfRenderer
{
    public function render(string $view, array $data): string
    {
        return Pdf::loadView($view, $data)->setPaper('a4', 'landscape')->output();
    }
}

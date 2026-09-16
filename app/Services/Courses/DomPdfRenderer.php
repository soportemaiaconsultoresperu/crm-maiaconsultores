<?php

namespace App\Services\Courses;

use App\Contracts\Courses\PdfRenderer;
use Barryvdh\DomPDF\Facade\Pdf;

class DomPdfRenderer implements PdfRenderer
{
    public function render(string $view, array $data): string
    {
        return Pdf::loadView($view, $data)
->setPaper('a4', 'landscape')
// dompdf refuses to read anything outside its chroot, and it answers a refused
// path by SILENTLY falling back — which is how the certificate ended up rendered
// in the default font with no error anywhere. The project root and the font
// directory are both declared, so the logo and the embedded fonts resolve.
->setOption('chroot', [base_path(), storage_path('fonts')])
// Metrics live in storage, not inside vendor: the package ships no published
// config here, and writing into vendor is not something a deploy should depend on.
->setOption('fontDir', storage_path('fonts'))
->setOption('fontCache', storage_path('fonts'))
->output();
    }
}

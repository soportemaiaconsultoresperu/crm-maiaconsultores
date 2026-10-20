<?php

use App\Http\Controllers\CourseTalks\CourseAcademicDocumentController;
use App\Http\Controllers\CourseTalks\CourseCommercialDocumentController;
use Illuminate\Support\Facades\Route;

/*
 * "Ver PDF" of the course documents surfaces. Required from routes/web.php.
 *
 * Each action authorizes the viewer of the document's edition and then redirects
 * to a freshly minted, short-lived signed link (the same one the QR and the
 * deliveries use), so the document listings never render a signed URL. The
 * `documents/{id}/pdf` and `commercial-documents/{id}/pdf` segments do not
 * overlap any `course-talks` route registered in routes/web.php.
 */
Route::middleware(['auth', 'active'])
    ->prefix('course-talks')
    ->name('course-talks.')
    ->group(function (): void {
        Route::get('documents/{academicDocument}/pdf', [CourseAcademicDocumentController::class, 'pdf'])
            ->name('documents.pdf');
        Route::get('commercial-documents/{commercialDocument}/pdf', [CourseCommercialDocumentController::class, 'pdf'])
            ->name('commercial-documents.pdf');
    });

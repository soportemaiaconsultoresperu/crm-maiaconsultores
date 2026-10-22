<?php

/*
|--------------------------------------------------------------------------
| Content scheduling module ("Cronograma de contenidos")
|--------------------------------------------------------------------------
|
| Attachment ceilings are read from `content.attachments.*` with in-code
| defaults (ContentAttachmentService), so they are not repeated here.
|
| Export caps: an export whose filtered set exceeds the cap is refused with
| a message asking the user to narrow it (for example by month), never
| silently truncated. The PDF cap is lower because Dompdf renders the whole
| document in memory.
|
*/

return [

    'export' => [
        'pdf_max_rows' => (int) env('CONTENT_EXPORT_PDF_MAX_ROWS', 500),
        'excel_max_rows' => (int) env('CONTENT_EXPORT_EXCEL_MAX_ROWS', 5000),
    ],

];

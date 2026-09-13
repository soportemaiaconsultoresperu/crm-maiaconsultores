<?php

return [
    'igv_rate' => 0.18,
    'delivery_due_days' => 1,
    'default_currency' => 'PEN',
    'email_document_link_minutes' => 10080,

    /*
    |--------------------------------------------------------------------------
    | Automatic academic document generation
    |--------------------------------------------------------------------------
    |
    | The delta spec requires the system to generate the applicable academic
    | document automatically "WHEN the final missing condition becomes complete",
    | so the eligibility job generates as soon as its conditions are met. That
    | makes generation ASYNCHRONOUS, and an asynchronous side effect needs a way
    | to be stopped: revoking `course-talks.*` permissions hides the module and
    | stops new human actions, but it does not stop a job that is already being
    | processed by a worker, and it does not stop the trigger services (which run
    | in the request that completes a condition) from dispatching new ones.
    |
    | This flag is that stop switch, and it is the ONLY one the module needs:
    | `false` makes the job return before it evaluates anything, so completing a
    | condition changes nothing and documents are generated only through the
    | operator's generate action. It defaults to `true` because the spec's
    | requirement is the default state; a deployment that wants generation off
    | must say so explicitly.
    |
    */
    'automatic_document_generation_enabled' => env('COURSES_AUTOMATIC_DOCUMENT_GENERATION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | System author
    |--------------------------------------------------------------------------
    |
    | The eligibility job runs with no user: nobody performed the act, so there is
    | no human whose id could be recorded as the author of the generated document.
    | The module does not invent one and does not leave the trail anonymous — it
    | attributes the automatic generation to a dedicated, explicitly non-human
    | SYSTEM account (seeded by `CourseSystemAuthorSeeder`), which is the account
    | named here. The job fails closed and logs if that account is missing, because
    | `documents.uploaded_by` is a NOT NULL reference to `users`: without an author
    | the private file cannot be registered at all.
    |
    */
    'system_author_email' => env('COURSES_SYSTEM_AUTHOR_EMAIL', 'sistema.certificados@crm-maia.invalid'),
];

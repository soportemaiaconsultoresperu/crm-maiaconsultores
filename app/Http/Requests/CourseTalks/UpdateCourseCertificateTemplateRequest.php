<?php

namespace App\Http\Requests\CourseTalks;

/**
 * The update verb of the certificate template form.
 *
 * It carries exactly the contract of creation — the form always submits the
 * whole configuration, so a partial-update contract would describe a payload
 * this surface cannot produce — and it inherits its rules, its payload builder
 * and the same ability (`course-talks.templates.manage`) on purpose, so the two
 * verbs cannot drift apart in what they accept. It exists as its own class so
 * the two can diverge later without reopening the create contract, and so each
 * route keeps the FormRequest that names its own verb.
 */
class UpdateCourseCertificateTemplateRequest extends StoreCourseCertificateTemplateRequest {}

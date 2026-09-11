<?php

namespace App\Http\Requests\CourseTalks;

use App\Enums\Courses\PaymentStatus;
use App\Models\Courses\CourseEnrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the payment-status payload: the target status must be one of the
 * domain statuses. Every status is offered by the form and the allowed
 * transitions stay in CourseEnrollmentService::changePaymentStatus(), so an
 * illegal transition is reported by the service instead of being guessed at
 * here.
 */
class UpdateCourseEnrollmentPaymentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', CourseEnrollment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'payment_status' => ['required', 'string', Rule::enum(PaymentStatus::class)],
        ];
    }

    public function attributes(): array
    {
        return ['payment_status' => 'estado de pago'];
    }

    public function paymentStatus(): PaymentStatus
    {
        return PaymentStatus::from($this->validated('payment_status'));
    }
}

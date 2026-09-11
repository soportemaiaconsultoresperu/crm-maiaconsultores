<?php

namespace App\Exceptions\Courses;

/**
 * Invalid course-edition data, optionally tagged with the form field that must
 * be fixed so presentation layers never infer a field from the message text.
 */
class InvalidCourseEditionData extends \InvalidArgumentException
{
    public function __construct(string $message = '', private readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    /** Named constructor for throw sites that know which field must be fixed. */
    public static function forField(string $field, string $message): self
    {
        return new self($message, $field);
    }

    /** Nullable by design: message-only throw sites report no specific field. */
    public function field(): ?string
    {
        return $this->field;
    }
}

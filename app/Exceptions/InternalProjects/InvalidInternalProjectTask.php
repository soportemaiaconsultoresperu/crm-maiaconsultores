<?php

namespace App\Exceptions\InternalProjects;

/**
 * A task write the internal projects domain refused, tagged with a stable
 * reason and the attribute to fix so a controller can map it to a form error
 * (`field()`) without parsing the Spanish message, which is safe to show.
 */
class InvalidInternalProjectTask extends \InvalidArgumentException
{
    /** The phase does not belong to the task's project. */
    public const PHASE_OUTSIDE_PROJECT = 'phase_outside_project';

    /** The due date is earlier than the start date. */
    public const DUE_BEFORE_START = 'due_before_start';

    /** The weight is not an integer of at least 1. */
    public const INVALID_WEIGHT = 'invalid_weight';

    /** A required attribute (phase, title, dates) is missing. */
    public const MISSING_ATTRIBUTE = 'missing_attribute';

    private function __construct(string $message, private readonly string $reason, private readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public static function phaseOutsideProject(): self
    {
        return new self(
            'La fase seleccionada no pertenece a este proyecto.',
            self::PHASE_OUTSIDE_PROJECT,
            'internal_project_phase_id',
        );
    }

    public static function dueBeforeStart(): self
    {
        return new self(
            'La fecha de vencimiento no puede ser anterior a la fecha de inicio.',
            self::DUE_BEFORE_START,
            'due_on',
        );
    }

    public static function invalidWeight(): self
    {
        return new self('El peso de la tarea debe ser un número entero mayor o igual a 1.', self::INVALID_WEIGHT, 'weight');
    }

    public static function missing(string $field, string $label): self
    {
        return new self(sprintf('Debe indicar %s de la tarea.', $label), self::MISSING_ATTRIBUTE, $field);
    }

    /** The stable reason, safe for presentation layers to branch on. */
    public function reason(): string
    {
        return $this->reason;
    }

    /** The task attribute that must be fixed. */
    public function field(): ?string
    {
        return $this->field;
    }
}

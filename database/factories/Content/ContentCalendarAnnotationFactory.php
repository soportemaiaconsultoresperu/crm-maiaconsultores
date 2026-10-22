<?php

namespace Database\Factories\Content;

use App\Models\Content\ContentCalendarAnnotation;
use App\Support\Content\PeruTime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentCalendarAnnotation>
 */
class ContentCalendarAnnotationFactory extends Factory
{
    protected $model = ContentCalendarAnnotation::class;

    /**
     * A future day in the Peru calendar, stored as its UTC instant, so a
     * factory-made annotation lands on the same day an editor would have
     * written it on.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $peruDate = fake()->dateTimeBetween('+1 day', '+3 months')->format('Y-m-d');

        return [
            'content_project_id' => ContentProjectFactory::new(),
            'annotated_at' => PeruTime::toUtc($peruDate.' 00:00:00'),
            'name' => fake('es_PE')->sentence(3),
            'notes' => null,
        ];
    }

    /**
     * Pin the annotation to a specific Peruvian calendar date (Y-m-d).
     */
    public function onDate(string $peruDate): static
    {
        return $this->state(fn (): array => [
            'annotated_at' => PeruTime::toUtc($peruDate.' 00:00:00'),
        ]);
    }

    /**
     * Store a raw UTC instant, so a test can pin the case where the UTC date
     * and the Peruvian date differ.
     */
    public function atUtc(string $utcInstant): static
    {
        return $this->state(fn (): array => [
            'annotated_at' => $utcInstant,
        ]);
    }
}

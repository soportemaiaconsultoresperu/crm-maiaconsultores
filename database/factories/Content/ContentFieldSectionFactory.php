<?php

namespace Database\Factories\Content;

use App\Enums\Content\ContentFieldType;
use App\Models\Content\ContentFieldSection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ContentFieldSection> */
class ContentFieldSectionFactory extends Factory
{
    protected $model = ContentFieldSection::class;

    public function definition(): array
    {
        $name = fake('es_PE')->unique()->words(3, true);

        return [
            'content_project_id' => ContentProjectFactory::new(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'sort' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Compose a project with one section, a text field and a list field that
     * owns options — the smallest shape a grid screen needs. Reaches the
     * section's own project so every row is scoped to the same project.
     */
    public function withFields(): static
    {
        return $this->afterCreating(function (ContentFieldSection $section): void {
            ContentFieldDefinitionFactory::new()
                ->for($section, 'section')
                ->for($section->project, 'project')
                ->create([
                    'key' => 'texto_'.fake()->unique()->numberBetween(1, 999999),
                    'label' => 'Texto libre',
                    'type' => ContentFieldType::Text,
                ]);

            $list = ContentFieldDefinitionFactory::new()
                ->for($section, 'section')
                ->for($section->project, 'project')
                ->listType()
                ->create([
                    'key' => 'lista_'.fake()->unique()->numberBetween(1, 999999),
                    'label' => 'Opción múltiple',
                ]);

            ContentFieldOptionFactory::new()->count(3)->for($list, 'definition')->create();
        });
    }
}

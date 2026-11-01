<?php

namespace Database\Factories;

use App\Models\Testimonial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Testimonial> */
class TestimonialFactory extends Factory
{
    protected $model = Testimonial::class;

    public function definition(): array
    {
        return [
            'quote' => fake()->paragraph(),
            'author_name' => fake()->firstName(),
            'author_context' => null,
            'locale' => 'ro',
            'publication_consent_at' => null,
            'is_published' => false,
            'sort_order' => 0,
        ];
    }

    public function approvedAndPublished(): static
    {
        return $this->state(fn (): array => [
            'publication_consent_at' => now(),
            'is_published' => true,
        ]);
    }
}

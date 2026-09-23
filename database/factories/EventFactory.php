<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organizer_id' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraphs(3, true),
            'location' => fake()->city(),
            'starts_at' => fake()->dateTimeBetween('+1 day', '+2 months'),
            'capacity' => fake()->numberBetween(5, 100),
            'image_url' => null,
        ];
    }

    /**
     * 定員なし(無制限)のイベント。
     */
    public function unlimited(): static
    {
        return $this->state(fn (array $attributes) => [
            'capacity' => null,
        ]);
    }

    /**
     * 開催日時が過去のイベント。
     */
    public function past(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => fake()->dateTimeBetween('-2 months', '-1 day'),
        ]);
    }
}

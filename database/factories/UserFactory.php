<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The phone number is the identity here, so it is what the factory always
     * produces. Email and password stay null, which is exactly the shape of a
     * member their manager created and who has never signed in.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '98912'.fake()->unique()->numerify('#######'),
            'phone_verified_at' => now(),
            'email' => null,
            'password' => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['phone_verified_at' => null]);
    }

    public function optedOutOfSms(): static
    {
        return $this->state(fn () => ['sms_opted_out_at' => now()]);
    }

    public function withPhone(string $phone): static
    {
        return $this->state(fn () => ['phone' => $phone]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 *
 * Markaziy identifikatsiya (auth.users) uchun. auth.users'da `email`/
 * `email_verified_at` YO'Q — identifikator `login`. Parol 'hashed' cast bilan
 * ketadi, lekin test barqarorligi uchun oldindan hash qilinadi.
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'login' => fake()->unique()->userName(),
            'name' => fake()->name(),
            'phone' => fake()->optional()->numerify('+9989########'),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Faolsizlantirilgan (login qila olmaydigan) foydalanuvchi.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}

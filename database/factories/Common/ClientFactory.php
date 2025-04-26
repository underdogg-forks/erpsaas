<?php

namespace Database\Factories\Common;

use App\Models\Common\Address;
use App\Models\Common\Client;
use App\Models\Common\Contact;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Client::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 1,
            'name' => $this->faker->company,
            'currency_code' => fn (array $attributes) => Company::find($attributes['company_id'])->default->currency_code ?? 'USD',
            'account_number' => $this->faker->unique()->numerify(str_repeat('#', 12)),
            'website' => $this->faker->url,
            'notes' => $this->faker->sentence,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    public function withContacts(int $count = 1): self
    {
        return $this->has(
            Contact::factory()
                ->count($count)
                ->useParentCompany()
        );
    }

    public function withPrimaryContact(): self
    {
        return $this->has(
            Contact::factory()
                ->primary()
                ->useParentCompany()
        );
    }

    public function withAddresses(): self
    {
        return $this
            ->has(Address::factory()->billing()->useParentCompany())
            ->has(Address::factory()->shipping()->useParentCompany());
    }
}

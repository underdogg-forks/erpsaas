<?php

namespace Database\Factories\Common;

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\AdjustmentType;
use App\Enums\Common\OfferingType;
use App\Models\Accounting\Account;
use App\Models\Accounting\Adjustment;
use App\Models\Common\Offering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offering>
 */
class OfferingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Offering::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id'         => 1,
            'name'               => $this->faker->words(3, true),
            'description'        => $this->faker->sentence,
            'type'               => $this->faker->randomElement(OfferingType::cases()),
            'price'              => $this->faker->numberBetween(5, 1000),
            'sellable'           => false,
            'purchasable'        => false,
            'income_account_id'  => null,
            'expense_account_id' => null,
            'created_by'         => 1,
            'updated_by'         => 1,
        ];
    }

    public function withSalesAdjustments(): self
    {
        return $this->afterCreating(function (Offering $offering) {
            $incomeAccount = Account::query()
                ->where('company_id', $offering->company_id)
                ->where('category', AccountCategory::Revenue)
                ->where('type', AccountType::OperatingRevenue)
                ->inRandomOrder()
                ->firstOrFail();

            $offering->updateQuietly([
                'sellable'          => true,
                'income_account_id' => $incomeAccount->id,
            ]);

            $adjustments = $offering->company?->adjustments()
                ->where('type', AdjustmentType::Sales)
                ->pluck('id');

            $adjustmentsToAttach = $adjustments->isNotEmpty()
                ? $adjustments->random(min(2, $adjustments->count()))
                : Adjustment::factory()->salesTax()->count(2)->create()->pluck('id');

            $offering->salesAdjustments()->attach($adjustmentsToAttach);
        });
    }

    public function withPurchaseAdjustments(): self
    {
        return $this->afterCreating(function (Offering $offering) {
            $expenseAccount = Account::query()
                ->where('company_id', $offering->company_id)
                ->where('category', AccountCategory::Expense)
                ->where('type', AccountType::OperatingExpense)
                ->inRandomOrder()
                ->firstOrFail();

            $offering->updateQuietly([
                'purchasable'        => true,
                'expense_account_id' => $expenseAccount->id,
            ]);

            $adjustments = $offering->company?->adjustments()
                ->where('type', AdjustmentType::Purchase)
                ->pluck('id');

            $adjustmentsToAttach = $adjustments->isNotEmpty()
                ? $adjustments->random(min(2, $adjustments->count()))
                : Adjustment::factory()->purchaseTax()->count(2)->create()->pluck('id');

            $offering->purchaseAdjustments()->attach($adjustmentsToAttach);
        });
    }
}

<?php

namespace Database\Factories\Accounting;

use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\EstimateStatus;
use App\Models\Accounting\DocumentLineItem;
use App\Models\Accounting\Estimate;
use App\Models\Common\Client;
use App\Models\Company;
use App\Models\Setting\DocumentDefault;
use App\Utilities\Currency\CurrencyConverter;
use App\Utilities\RateCalculator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Estimate>
 */
class EstimateFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Estimate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $estimateDate = $this->faker->dateTimeBetween('-1 year');

        return [
            'company_id' => 1,
            'client_id' => fn (array $attributes) => Client::where('company_id', $attributes['company_id'])->inRandomOrder()->value('id'),
            'header' => 'Estimate',
            'subheader' => 'Estimate',
            'estimate_number' => $this->faker->unique()->numerify('EST-####'),
            'reference_number' => $this->faker->unique()->numerify('REF-####'),
            'date' => $estimateDate,
            'expiration_date' => Carbon::parse($estimateDate)->addDays($this->faker->numberBetween(14, 30)),
            'status' => EstimateStatus::Draft,
            'discount_method' => $this->faker->randomElement(DocumentDiscountMethod::class),
            'discount_computation' => AdjustmentComputation::Percentage,
            'discount_rate' => function (array $attributes) {
                $discountMethod = DocumentDiscountMethod::parse($attributes['discount_method']);

                if ($discountMethod?->isPerDocument()) {
                    return $this->faker->numberBetween(50000, 200000); // 5% - 20%
                }

                return 0;
            },
            'currency_code' => function (array $attributes) {
                $client = Client::find($attributes['client_id']);

                return $client->currency_code ??
                    Company::find($attributes['company_id'])->default->currency_code ??
                    'USD';
            },
            'terms' => $this->faker->sentence,
            'footer' => $this->faker->sentence,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    public function withLineItems(int $count = 3): static
    {
        return $this->afterCreating(function (Estimate $estimate) use ($count) {
            DocumentLineItem::factory()
                ->count($count)
                ->forEstimate($estimate)
                ->create();

            $this->recalculateTotals($estimate);
        });
    }

    public function approved(): static
    {
        return $this->afterCreating(function (Estimate $estimate) {
            $this->ensureLineItems($estimate);

            if (! $estimate->canBeApproved()) {
                return;
            }

            $approvedAt = Carbon::parse($estimate->date)
                ->addHours($this->faker->numberBetween(1, 24));

            $estimate->approveDraft($approvedAt);
        });
    }

    public function accepted(): static
    {
        return $this->afterCreating(function (Estimate $estimate) {
            $this->ensureSent($estimate);

            $acceptedAt = Carbon::parse($estimate->last_sent_at)
                ->addDays($this->faker->numberBetween(1, 7));

            $estimate->markAsAccepted($acceptedAt);
        });
    }

    public function converted(): static
    {
        return $this->afterCreating(function (Estimate $estimate) {
            if (! $estimate->wasAccepted()) {
                $this->accepted()->callAfterCreating(collect([$estimate]));
            }

            $convertedAt = Carbon::parse($estimate->accepted_at)
                ->addDays($this->faker->numberBetween(1, 7));

            $estimate->convertToInvoice($convertedAt);
        });
    }

    public function declined(): static
    {
        return $this->afterCreating(function (Estimate $estimate) {
            $this->ensureSent($estimate);

            $declinedAt = Carbon::parse($estimate->last_sent_at)
                ->addDays($this->faker->numberBetween(1, 7));

            $estimate->markAsDeclined($declinedAt);
        });
    }

    public function sent(): static
    {
        return $this->afterCreating(function (Estimate $estimate) {
            $this->ensureApproved($estimate);

            $sentAt = Carbon::parse($estimate->approved_at)
                ->addHours($this->faker->numberBetween(1, 24));

            $estimate->markAsSent($sentAt);
        });
    }

    public function viewed(): static
    {
        return $this->afterCreating(function (Estimate $estimate) {
            $this->ensureSent($estimate);

            $viewedAt = Carbon::parse($estimate->last_sent_at)
                ->addHours($this->faker->numberBetween(1, 24));

            $estimate->markAsViewed($viewedAt);
        });
    }

    public function expired(): static
    {
        return $this
            ->state([
                'expiration_date' => now()->subDays($this->faker->numberBetween(1, 30)),
            ])
            ->afterCreating(function (Estimate $estimate) {
                $this->ensureApproved($estimate);
            });
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Estimate $estimate) {
            $this->ensureLineItems($estimate);

            $number = DocumentDefault::getBaseNumber() + $estimate->id;

            $estimate->updateQuietly([
                'estimate_number' => "EST-{$number}",
                'reference_number' => "REF-{$number}",
            ]);

            if ($estimate->wasApproved() && $estimate->is_currently_expired) {
                $estimate->updateQuietly([
                    'status' => EstimateStatus::Expired,
                ]);
            }
        });
    }

    protected function ensureLineItems(Estimate $estimate): void
    {
        if (! $estimate->hasLineItems()) {
            $this->withLineItems()->callAfterCreating(collect([$estimate]));
        }
    }

    protected function ensureApproved(Estimate $estimate): void
    {
        if (! $estimate->wasApproved()) {
            $this->approved()->callAfterCreating(collect([$estimate]));
        }
    }

    protected function ensureSent(Estimate $estimate): void
    {
        if (! $estimate->hasBeenSent()) {
            $this->sent()->callAfterCreating(collect([$estimate]));
        }
    }

    protected function recalculateTotals(Estimate $estimate): void
    {
        $estimate->refresh();

        if (! $estimate->hasLineItems()) {
            return;
        }

        $subtotalCents = $estimate->lineItems()->sum('subtotal');
        $taxTotalCents = $estimate->lineItems()->sum('tax_total');

        $discountTotalCents = 0;

        if ($estimate->discount_method?->isPerLineItem()) {
            $discountTotalCents = $estimate->lineItems()->sum('discount_total');
        } elseif ($estimate->discount_method?->isPerDocument() && $estimate->discount_rate) {
            if ($estimate->discount_computation?->isPercentage()) {
                $scaledRate = RateCalculator::parseLocalizedRate($estimate->discount_rate);
                $discountTotalCents = RateCalculator::calculatePercentage($subtotalCents, $scaledRate);
            } else {
                $discountTotalCents = CurrencyConverter::convertToCents($estimate->discount_rate, $estimate->currency_code);
            }
        }

        $grandTotalCents = $subtotalCents + $taxTotalCents - $discountTotalCents;
        $currencyCode = $estimate->currency_code;

        $estimate->update([
            'subtotal' => CurrencyConverter::convertCentsToFormatSimple($subtotalCents, $currencyCode),
            'tax_total' => CurrencyConverter::convertCentsToFormatSimple($taxTotalCents, $currencyCode),
            'discount_total' => CurrencyConverter::convertCentsToFormatSimple($discountTotalCents, $currencyCode),
            'total' => CurrencyConverter::convertCentsToFormatSimple($grandTotalCents, $currencyCode),
        ]);
    }
}

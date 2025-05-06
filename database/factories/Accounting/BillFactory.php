<?php

namespace Database\Factories\Accounting;

use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\BillStatus;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\PaymentMethod;
use App\Models\Accounting\Bill;
use App\Models\Accounting\DocumentLineItem;
use App\Models\Banking\BankAccount;
use App\Models\Common\Vendor;
use App\Models\Company;
use App\Models\Setting\DocumentDefault;
use App\Utilities\Currency\CurrencyConverter;
use App\Utilities\RateCalculator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Bill>
 */
class BillFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Bill::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $isFutureBill = $this->faker->boolean();

        if ($isFutureBill) {
            $billDate = $this->faker->dateTimeBetween('-10 days', '+10 days');
        } else {
            $billDate = $this->faker->dateTimeBetween('-1 year', '-30 days');
        }

        $dueDays = $this->faker->numberBetween(14, 60);

        return [
            'company_id' => 1,
            'vendor_id' => fn (array $attributes) => Vendor::where('company_id', $attributes['company_id'])->inRandomOrder()->value('id'),
            'bill_number' => $this->faker->unique()->numerify('BILL-####'),
            'order_number' => $this->faker->unique()->numerify('PO-####'),
            'date' => $billDate,
            'due_date' => Carbon::parse($billDate)->addDays($dueDays),
            'status' => BillStatus::Open,
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
                $vendor = Vendor::find($attributes['vendor_id']);

                return $vendor->currency_code ??
                    Company::find($attributes['company_id'])->default->currency_code ??
                    'USD';
            },
            'notes' => $this->faker->sentence,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    public function withLineItems(int $count = 3): static
    {
        return $this->afterCreating(function (Bill $bill) use ($count) {
            DocumentLineItem::factory()
                ->count($count)
                ->forBill($bill)
                ->create();

            $this->recalculateTotals($bill);
        });
    }

    public function initialized(): static
    {
        return $this->afterCreating(function (Bill $bill) {
            $this->ensureLineItems($bill);

            if ($bill->wasInitialized()) {
                return;
            }

            $postedAt = Carbon::parse($bill->date)
                ->addHours($this->faker->numberBetween(1, 24));

            $bill->createInitialTransaction($postedAt);
        });
    }

    public function partial(int $maxPayments = 4): static
    {
        return $this->afterCreating(function (Bill $bill) use ($maxPayments) {
            $this->ensureInitialized($bill);

            $this->withPayments(max: $maxPayments, billStatus: BillStatus::Partial)
                ->callAfterCreating(collect([$bill]));
        });
    }

    public function paid(int $maxPayments = 4): static
    {
        return $this->afterCreating(function (Bill $bill) use ($maxPayments) {
            $this->ensureInitialized($bill);

            $this->withPayments(max: $maxPayments)
                ->callAfterCreating(collect([$bill]));
        });
    }

    public function overdue(): static
    {
        return $this
            ->state([
                'due_date' => now()->subDays($this->faker->numberBetween(1, 30)),
            ])
            ->afterCreating(function (Bill $bill) {
                $this->ensureInitialized($bill);
            });
    }

    public function withPayments(?int $min = null, ?int $max = null, BillStatus $billStatus = BillStatus::Paid): static
    {
        $min ??= 1;

        return $this->afterCreating(function (Bill $bill) use ($billStatus, $max, $min) {
            $this->ensureInitialized($bill);

            $bill->refresh();

            $amountDue = $bill->getRawOriginal('amount_due');

            $totalAmountDue = match ($billStatus) {
                BillStatus::Partial => (int) floor($amountDue * 0.5),
                default => $amountDue,
            };

            if ($totalAmountDue <= 0 || empty($totalAmountDue)) {
                return;
            }

            $paymentCount = $max && $min ? $this->faker->numberBetween($min, $max) : $min;
            $paymentAmount = (int) floor($totalAmountDue / $paymentCount);
            $remainingAmount = $totalAmountDue;

            $paymentDate = Carbon::parse($bill->initialTransaction->posted_at);
            $paymentDates = [];

            for ($i = 0; $i < $paymentCount; $i++) {
                $amount = $i === $paymentCount - 1 ? $remainingAmount : $paymentAmount;

                if ($amount <= 0) {
                    break;
                }

                $postedAt = $paymentDate->copy()->addDays($this->faker->numberBetween(1, 30));
                $paymentDates[] = $postedAt;

                $data = [
                    'posted_at' => $postedAt,
                    'amount' => CurrencyConverter::convertCentsToFormatSimple($amount, $bill->currency_code),
                    'payment_method' => $this->faker->randomElement(PaymentMethod::class),
                    'bank_account_id' => BankAccount::where('company_id', $bill->company_id)->inRandomOrder()->value('id'),
                    'notes' => $this->faker->sentence,
                ];

                $bill->recordPayment($data);
                $remainingAmount -= $amount;
            }

            if ($billStatus !== BillStatus::Paid) {
                return;
            }

            $latestPaymentDate = max($paymentDates);
            $bill->updateQuietly([
                'status' => $billStatus,
                'paid_at' => $latestPaymentDate,
            ]);
        });
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Bill $bill) {
            $this->ensureInitialized($bill);

            $number = DocumentDefault::getBaseNumber() + $bill->id;

            $bill->updateQuietly([
                'bill_number' => "BILL-{$number}",
                'order_number' => "PO-{$number}",
            ]);

            if ($bill->wasInitialized() && $bill->is_currently_overdue) {
                $bill->updateQuietly([
                    'status' => BillStatus::Overdue,
                ]);
            }
        });
    }

    protected function ensureLineItems(Bill $bill): void
    {
        if (! $bill->hasLineItems()) {
            $this->withLineItems()->callAfterCreating(collect([$bill]));
        }
    }

    protected function ensureInitialized(Bill $bill): void
    {
        if (! $bill->wasInitialized()) {
            $this->initialized()->callAfterCreating(collect([$bill]));
        }
    }

    protected function recalculateTotals(Bill $bill): void
    {
        $bill->refresh();

        if (! $bill->hasLineItems()) {
            return;
        }

        $subtotalCents = $bill->lineItems()->sum('subtotal');
        $taxTotalCents = $bill->lineItems()->sum('tax_total');

        $discountTotalCents = 0;

        if ($bill->discount_method?->isPerLineItem()) {
            $discountTotalCents = $bill->lineItems()->sum('discount_total');
        } elseif ($bill->discount_method?->isPerDocument() && $bill->discount_rate) {
            if ($bill->discount_computation?->isPercentage()) {
                $scaledRate = RateCalculator::parseLocalizedRate($bill->discount_rate);
                $discountTotalCents = RateCalculator::calculatePercentage($subtotalCents, $scaledRate);
            } else {
                $discountTotalCents = CurrencyConverter::convertToCents($bill->discount_rate, $bill->currency_code);
            }
        }

        $grandTotalCents = $subtotalCents + $taxTotalCents - $discountTotalCents;
        $currencyCode = $bill->currency_code;

        $bill->update([
            'subtotal' => CurrencyConverter::convertCentsToFormatSimple($subtotalCents, $currencyCode),
            'tax_total' => CurrencyConverter::convertCentsToFormatSimple($taxTotalCents, $currencyCode),
            'discount_total' => CurrencyConverter::convertCentsToFormatSimple($discountTotalCents, $currencyCode),
            'total' => CurrencyConverter::convertCentsToFormatSimple($grandTotalCents, $currencyCode),
        ]);
    }
}

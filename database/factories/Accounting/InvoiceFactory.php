<?php

namespace Database\Factories\Accounting;

use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\InvoiceStatus;
use App\Enums\Accounting\PaymentMethod;
use App\Models\Accounting\DocumentLineItem;
use App\Models\Accounting\Invoice;
use App\Models\Banking\BankAccount;
use App\Models\Common\Client;
use App\Models\Company;
use App\Models\Setting\DocumentDefault;
use App\Utilities\Currency\CurrencyConverter;
use App\Utilities\RateCalculator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Invoice::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $invoiceDate = $this->faker->dateTimeBetween('-1 year');

        return [
            'company_id'           => 1,
            'client_id'            => fn (array $attributes) => Client::where('company_id', $attributes['company_id'])->inRandomOrder()->value('id'),
            'header'               => 'Invoice',
            'subheader'            => 'Invoice',
            'invoice_number'       => $this->faker->unique()->numerify('INV-####'),
            'order_number'         => $this->faker->unique()->numerify('ORD-####'),
            'date'                 => $invoiceDate,
            'due_date'             => Carbon::parse($invoiceDate)->addDays($this->faker->numberBetween(14, 60)),
            'status'               => InvoiceStatus::Draft,
            'discount_method'      => $this->faker->randomElement(DocumentDiscountMethod::class),
            'discount_computation' => AdjustmentComputation::Percentage,
            'discount_rate'        => function (array $attributes) {
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
            'terms'      => $this->faker->sentence,
            'footer'     => $this->faker->sentence,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    public function withLineItems(int $count = 3): static
    {
        return $this->afterCreating(function (Invoice $invoice) use ($count) {
            DocumentLineItem::factory()
                ->count($count)
                ->forInvoice($invoice)
                ->create();

            $this->recalculateTotals($invoice);
        });
    }

    public function approved(): static
    {
        return $this->afterCreating(function (Invoice $invoice) {
            $this->ensureLineItems($invoice);

            if ( ! $invoice->canBeApproved()) {
                return;
            }

            $approvedAt = Carbon::parse($invoice->date)
                ->addHours($this->faker->numberBetween(1, 24));

            $invoice->approveDraft($approvedAt);
        });
    }

    public function sent(): static
    {
        return $this->afterCreating(function (Invoice $invoice) {
            $this->ensureApproved($invoice);

            $sentAt = Carbon::parse($invoice->approved_at)
                ->addHours($this->faker->numberBetween(1, 24));

            $invoice->markAsSent($sentAt);
        });
    }

    public function partial(int $maxPayments = 4): static
    {
        return $this->afterCreating(function (Invoice $invoice) use ($maxPayments) {
            $this->ensureSent($invoice);

            $this->withPayments(max: $maxPayments, invoiceStatus: InvoiceStatus::Partial)
                ->callAfterCreating(collect([$invoice]));
        });
    }

    public function paid(int $maxPayments = 4): static
    {
        return $this->afterCreating(function (Invoice $invoice) use ($maxPayments) {
            $this->ensureSent($invoice);

            $this->withPayments(max: $maxPayments)
                ->callAfterCreating(collect([$invoice]));
        });
    }

    public function overpaid(int $maxPayments = 4): static
    {
        return $this->afterCreating(function (Invoice $invoice) use ($maxPayments) {
            $this->ensureSent($invoice);

            $this->withPayments(max: $maxPayments, invoiceStatus: InvoiceStatus::Overpaid)
                ->callAfterCreating(collect([$invoice]));
        });
    }

    public function overdue(): static
    {
        return $this
            ->state([
                'due_date' => now()->subDays($this->faker->numberBetween(1, 30)),
            ])
            ->afterCreating(function (Invoice $invoice) {
                $this->ensureApproved($invoice);
            });
    }

    public function withPayments(?int $min = null, ?int $max = null, InvoiceStatus $invoiceStatus = InvoiceStatus::Paid): static
    {
        $min ??= 1;

        return $this->afterCreating(function (Invoice $invoice) use ($invoiceStatus, $max, $min) {
            $this->ensureSent($invoice);

            $invoice->refresh();

            $amountDue = $invoice->getRawOriginal('amount_due');

            $totalAmountDue = match ($invoiceStatus) {
                InvoiceStatus::Overpaid => $amountDue + random_int(1000, 10000),
                InvoiceStatus::Partial  => (int) floor($amountDue * 0.5),
                default                 => $amountDue,
            };

            if ($totalAmountDue <= 0 || empty($totalAmountDue)) {
                return;
            }

            $paymentCount    = $max && $min ? $this->faker->numberBetween($min, $max) : $min;
            $paymentAmount   = (int) floor($totalAmountDue / $paymentCount);
            $remainingAmount = $totalAmountDue;

            $paymentDate  = Carbon::parse($invoice->approved_at);
            $paymentDates = [];

            for ($i = 0; $i < $paymentCount; $i++) {
                $amount = $i === $paymentCount - 1 ? $remainingAmount : $paymentAmount;

                if ($amount <= 0) {
                    break;
                }

                $postedAt       = $paymentDate->copy()->addDays($this->faker->numberBetween(1, 30));
                $paymentDates[] = $postedAt;

                $data = [
                    'posted_at'       => $postedAt,
                    'amount'          => CurrencyConverter::convertCentsToFormatSimple($amount, $invoice->currency_code),
                    'payment_method'  => $this->faker->randomElement(PaymentMethod::class),
                    'bank_account_id' => BankAccount::where('company_id', $invoice->company_id)->inRandomOrder()->value('id'),
                    'notes'           => $this->faker->sentence,
                ];

                $invoice->recordPayment($data);
                $remainingAmount -= $amount;
            }

            if ($invoiceStatus !== InvoiceStatus::Paid) {
                return;
            }

            $latestPaymentDate = max($paymentDates);
            $invoice->updateQuietly([
                'status'  => $invoiceStatus,
                'paid_at' => $latestPaymentDate,
            ]);
        });
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Invoice $invoice) {
            $this->ensureLineItems($invoice);

            $number = DocumentDefault::getBaseNumber() + $invoice->id;

            $invoice->updateQuietly([
                'invoice_number' => "INV-{$number}",
                'order_number'   => "ORD-{$number}",
            ]);

            if ($invoice->wasApproved() && $invoice->is_currently_overdue) {
                $invoice->updateQuietly([
                    'status' => InvoiceStatus::Overdue,
                ]);
            }
        });
    }

    protected function ensureLineItems(Invoice $invoice): void
    {
        if ( ! $invoice->hasLineItems()) {
            $this->withLineItems()->callAfterCreating(collect([$invoice]));
        }
    }

    protected function ensureApproved(Invoice $invoice): void
    {
        if ( ! $invoice->wasApproved()) {
            $this->approved()->callAfterCreating(collect([$invoice]));
        }
    }

    protected function ensureSent(Invoice $invoice): void
    {
        if ( ! $invoice->hasBeenSent()) {
            $this->sent()->callAfterCreating(collect([$invoice]));
        }
    }

    protected function recalculateTotals(Invoice $invoice): void
    {
        $invoice->refresh();

        if ( ! $invoice->hasLineItems()) {
            return;
        }

        $subtotalCents = $invoice->lineItems()->sum('subtotal');
        $taxTotalCents = $invoice->lineItems()->sum('tax_total');

        $discountTotalCents = 0;

        if ($invoice->discount_method?->isPerLineItem()) {
            $discountTotalCents = $invoice->lineItems()->sum('discount_total');
        } elseif ($invoice->discount_method?->isPerDocument() && $invoice->discount_rate) {
            if ($invoice->discount_computation?->isPercentage()) {
                $scaledRate         = RateCalculator::parseLocalizedRate($invoice->discount_rate);
                $discountTotalCents = RateCalculator::calculatePercentage($subtotalCents, $scaledRate);
            } else {
                $discountTotalCents = CurrencyConverter::convertToCents($invoice->discount_rate, $invoice->currency_code);
            }
        }

        $grandTotalCents = $subtotalCents + $taxTotalCents - $discountTotalCents;
        $currencyCode    = $invoice->currency_code;

        $invoice->update([
            'subtotal'       => CurrencyConverter::convertCentsToFormatSimple($subtotalCents, $currencyCode),
            'tax_total'      => CurrencyConverter::convertCentsToFormatSimple($taxTotalCents, $currencyCode),
            'discount_total' => CurrencyConverter::convertCentsToFormatSimple($discountTotalCents, $currencyCode),
            'total'          => CurrencyConverter::convertCentsToFormatSimple($grandTotalCents, $currencyCode),
        ]);
    }
}

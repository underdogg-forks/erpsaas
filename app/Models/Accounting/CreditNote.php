<?php

namespace App\Models\Accounting;

use App\Casts\MoneyCast;
use App\Casts\RateCast;
use App\Collections\Accounting\DocumentCollection;
use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\CreditNoteStatus;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\DocumentType;
use App\Enums\Accounting\JournalEntryType;
use App\Enums\Accounting\TransactionType;
use App\Filament\Company\Resources\Sales\CreditNoteResource;
use App\Models\Common\Client;
use App\Models\Company;
use App\Models\Setting\DocumentDefault;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use Filament\Actions\Action;
use Filament\Actions\MountableAction;
use Filament\Actions\ReplicateAction;
use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[CollectedBy(DocumentCollection::class)]
class CreditNote extends Document
{
    protected $table = 'credit_notes';

    protected $fillable = [
        'company_id',
        'client_id',
        'logo',
        'header',
        'subheader',
        'credit_note_number',
        'reference_number',
        'date',
        'approved_at',
        'last_sent_at',
        'last_viewed_at',
        'status',
        'currency_code',
        'discount_method',
        'discount_computation',
        'discount_rate',
        'subtotal',
        'tax_total',
        'discount_total',
        'total',
        'amount_used',
        'terms',
        'footer',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
        'approved_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'status' => CreditNoteStatus::class,
        'discount_method' => DocumentDiscountMethod::class,
        'discount_computation' => AdjustmentComputation::class,
        'discount_rate' => RateCast::class,
        'subtotal' => MoneyCast::class,
        'tax_total' => MoneyCast::class,
        'discount_total' => MoneyCast::class,
        'total' => MoneyCast::class,
        'amount_used' => MoneyCast::class,
    ];

    // Basic Relationships

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // Transaction Relationships

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'transactionable');
    }

    public function initialTransaction(): MorphOne
    {
        return $this->morphOne(Transaction::class, 'transactionable')
            ->where('type', TransactionType::Journal);
    }

    // Track where this credit note has been applied

    public function applications(): Collection
    {
        // Find all invoice transactions that reference this credit note
        return Transaction::where('type', TransactionType::CreditNote)
            ->where('is_payment', true)
            ->whereJsonContains('meta->credit_note_id', $this->id)
            ->get()
            ->map(function ($transaction) {
                return [
                    'invoice' => $transaction->transactionable,
                    'amount' => $transaction->amount,
                    'date' => $transaction->posted_at,
                    'transaction' => $transaction,
                ];
            })
            ->filter(function ($item) {
                return ! is_null($item['invoice']);
            });
    }

    public function appliedInvoices(): Collection
    {
        return $this->applications()->pluck('invoice');
    }

    // Document Interface Implementation

    public static function documentType(): DocumentType
    {
        return DocumentType::CreditNote;
    }

    public function documentNumber(): ?string
    {
        return $this->credit_note_number;
    }

    public function documentDate(): ?string
    {
        return $this->date?->toDefaultDateFormat();
    }

    public function dueDate(): ?string
    {
        return null;
    }

    public function amountDue(): ?string
    {
        return null;
    }

    public function referenceNumber(): ?string
    {
        return $this->reference_number;
    }

    // Computed Properties

    protected function availableBalance(): Attribute
    {
        return Attribute::get(function () {
            $totalCents = (int) $this->getRawOriginal('total');
            $amountUsedCents = (int) $this->getRawOriginal('amount_used');

            return CurrencyConverter::convertCentsToFormatSimple($totalCents - $amountUsedCents);
        });
    }

    protected function availableBalanceCents(): Attribute
    {
        return Attribute::get(function () {
            $totalCents = (int) $this->getRawOriginal('total');
            $amountUsedCents = (int) $this->getRawOriginal('amount_used');

            return $totalCents - $amountUsedCents;
        });
    }

    // Status Methods

    public function isFullyApplied(): bool
    {
        return $this->availableBalanceCents <= 0;
    }

    public function isPartiallyApplied(): bool
    {
        $amountUsedCents = (int) $this->getRawOriginal('amount_used');

        return $amountUsedCents > 0 && ! $this->isFullyApplied();
    }

    public function isDraft(): bool
    {
        return $this->status === CreditNoteStatus::Draft;
    }

    public function wasApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function hasBeenSent(): bool
    {
        return $this->last_sent_at !== null;
    }

    public function hasBeenViewed(): bool
    {
        return $this->last_viewed_at !== null;
    }

    public function canBeAppliedToInvoice(): bool
    {
        return in_array($this->status->value, CreditNoteStatus::canBeApplied()) &&
            $this->availableBalanceCents > 0;
    }

    // Application Methods

    public function applyToInvoice(Invoice $invoice, string $amount): void
    {
        // Validate currencies match
        if ($this->currency_code !== $invoice->currency_code) {
            throw new \RuntimeException('Cannot apply credit note with different currency to invoice.');
        }

        // Validate available amount
        $amountCents = CurrencyConverter::convertToCents($amount, $this->currency_code);

        if ($amountCents > $this->availableBalanceCents) {
            throw new \RuntimeException('Cannot apply more than the available credit note amount.');
        }

        // Create transaction on the invoice
        $invoice->transactions()->create([
            'company_id' => $this->company_id,
            'type' => TransactionType::CreditNote,
            'is_payment' => true,
            'posted_at' => now(),
            'amount' => $amount,
            'account_id' => Account::getAccountsReceivableAccount()->id,
            'description' => "Credit Note #{$this->credit_note_number} applied to Invoice #{$invoice->invoice_number}",
            'meta' => [
                'credit_note_id' => $this->id,
            ],
        ]);

        // Update amount used on credit note
        $this->amount_used = CurrencyConverter::convertCentsToFormatSimple(
            (int) $this->getRawOriginal('amount_used') + $amountCents
        );
        $this->save();

        // Update status if needed
        $this->updateStatusBasedOnUsage();

        // Update invoice payment status
        $invoice->updatePaymentStatus();
    }

    protected function updateStatusBasedOnUsage(): void
    {
        if ($this->isFullyApplied()) {
            $this->status = CreditNoteStatus::Applied;
        } elseif ($this->isPartiallyApplied()) {
            $this->status = CreditNoteStatus::Partial;
        }

        $this->save();
    }

    public function autoApplyToInvoices(): void
    {
        // Skip if no available amount
        if ($this->availableBalanceCents <= 0) {
            return;
        }

        // Find unpaid invoices for this client, ordered by due date (oldest first)
        $unpaidInvoices = Invoice::where('client_id', $this->client_id)
            ->where('currency_code', $this->currency_code)
            ->unpaid()
            ->orderBy('due_date')
            ->get();

        // Apply to invoices until amount is used up
        foreach ($unpaidInvoices as $invoice) {
            $invoiceAmountDueCents = (int) $invoice->getRawOriginal('amount_due');

            if ($invoiceAmountDueCents <= 0 || $this->availableBalanceCents <= 0) {
                continue;
            }

            // Calculate amount to apply to this invoice
            $applyAmountCents = min($this->availableBalanceCents, $invoiceAmountDueCents);
            $applyAmount = CurrencyConverter::convertCentsToFormatSimple($applyAmountCents);

            // Apply to invoice
            $this->applyToInvoice($invoice, $applyAmount);

            if ($this->availableBalanceCents <= 0) {
                break;
            }
        }
    }

    // Accounting Methods

    public function createInitialTransaction(?Carbon $postedAt = null): void
    {
        $postedAt ??= $this->date;

        $total = $this->formatAmountToDefaultCurrency($this->getRawOriginal('total'));

        $transaction = $this->transactions()->create([
            'company_id' => $this->company_id,
            'type' => TransactionType::Journal,
            'posted_at' => $postedAt,
            'amount' => $total,
            'description' => 'Credit Note Creation for Credit Note #' . $this->credit_note_number,
        ]);

        $baseDescription = "{$this->client->name}: Credit Note #{$this->credit_note_number}";

        // Credit AR (opposite of invoice)
        $transaction->journalEntries()->create([
            'company_id' => $this->company_id,
            'type' => JournalEntryType::Credit,
            'account_id' => Account::getAccountsReceivableAccount()->id,
            'amount' => $total,
            'description' => $baseDescription,
        ]);

        // Handle line items - debit revenue accounts (reverse of invoice)
        foreach ($this->lineItems as $lineItem) {
            $lineItemDescription = "{$baseDescription} › {$lineItem->offering->name}";
            $lineItemSubtotal = $this->formatAmountToDefaultCurrency($lineItem->getRawOriginal('subtotal'));

            $transaction->journalEntries()->create([
                'company_id' => $this->company_id,
                'type' => JournalEntryType::Debit,
                'account_id' => $lineItem->offering->income_account_id,
                'amount' => $lineItemSubtotal,
                'description' => $lineItemDescription,
            ]);

            // Handle adjustments
            foreach ($lineItem->adjustments as $adjustment) {
                $adjustmentAmount = $this->formatAmountToDefaultCurrency($lineItem->calculateAdjustmentTotalAmount($adjustment));

                $transaction->journalEntries()->create([
                    'company_id' => $this->company_id,
                    'type' => $adjustment->category->isDiscount() ? JournalEntryType::Credit : JournalEntryType::Debit,
                    'account_id' => $adjustment->account_id,
                    'amount' => $adjustmentAmount,
                    'description' => $lineItemDescription,
                ]);
            }
        }
    }

    public function approveDraft(?Carbon $approvedAt = null): void
    {
        if (! $this->isDraft()) {
            throw new \RuntimeException('Credit note is not in draft status.');
        }

        $this->createInitialTransaction();

        $approvedAt ??= now();

        $this->update([
            'approved_at' => $approvedAt,
            'status' => CreditNoteStatus::Sent,
        ]);

        // Auto-apply if configured in settings
        if ($this->company->settings->auto_apply_credit_notes ?? true) {
            $this->autoApplyToInvoices();
        }
    }

    public function convertAmountToDefaultCurrency(int $amountCents): int
    {
        $defaultCurrency = CurrencyAccessor::getDefaultCurrency();
        $needsConversion = $this->currency_code !== $defaultCurrency;

        if ($needsConversion) {
            return CurrencyConverter::convertBalance($amountCents, $this->currency_code, $defaultCurrency);
        }

        return $amountCents;
    }

    public function formatAmountToDefaultCurrency(int $amountCents): string
    {
        $convertedCents = $this->convertAmountToDefaultCurrency($amountCents);

        return CurrencyConverter::convertCentsToFormatSimple($convertedCents);
    }

    // Other methods

    public function markAsSent(?Carbon $sentAt = null): void
    {
        $sentAt ??= now();

        $this->update([
            'status' => CreditNoteStatus::Sent,
            'last_sent_at' => $sentAt,
        ]);
    }

    public function markAsViewed(?Carbon $viewedAt = null): void
    {
        $viewedAt ??= now();

        $this->update([
            'status' => CreditNoteStatus::Viewed,
            'last_viewed_at' => $viewedAt,
        ]);
    }

    // Utility Methods

    public static function getNextDocumentNumber(?Company $company = null): string
    {
        $company ??= auth()->user()?->currentCompany;

        if (! $company) {
            throw new \RuntimeException('No current company is set for the user.');
        }

        $defaultSettings = $company->defaultCreditNote;

        $numberPrefix = $defaultSettings->number_prefix ?? 'CN-';

        $latestDocument = static::query()
            ->whereNotNull('credit_note_number')
            ->latest('credit_note_number')
            ->first();

        $lastNumberNumericPart = $latestDocument
            ? (int) substr($latestDocument->credit_note_number, strlen($numberPrefix))
            : DocumentDefault::getBaseNumber();

        $numberNext = $lastNumberNumericPart + 1;

        return $defaultSettings->getNumberNext(
            prefix: $numberPrefix,
            next: $numberNext
        );
    }

    // Action Methods

    public static function getReplicateAction(string $action = ReplicateAction::class): MountableAction
    {
        return $action::make()
            ->excludeAttributes([
                'status',
                'amount_used',
                'created_by',
                'updated_by',
                'created_at',
                'updated_at',
                'credit_note_number',
                'date',
                'approved_at',
                'last_sent_at',
                'last_viewed_at',
            ])
            ->modal(false)
            ->beforeReplicaSaved(function (self $original, self $replica) {
                $replica->status = CreditNoteStatus::Draft;
                $replica->credit_note_number = self::getNextDocumentNumber();
                $replica->date = now();
                $replica->amount_used = 0;
            })
            ->databaseTransaction()
            ->after(function (self $original, self $replica) {
                $original->replicateLineItems($replica);
            })
            ->successRedirectUrl(static function (self $replica) {
                return CreditNoteResource::getUrl('edit', ['record' => $replica]);
            });
    }

    public static function getApproveAction(string $action = Action::class): MountableAction
    {
        return $action::make('approve')
            ->label('Approve')
            ->icon('heroicon-m-check-circle')
            ->visible(fn (self $record) => $record->isDraft())
            ->requiresConfirmation()
            ->databaseTransaction()
            ->successNotificationTitle('Credit note approved')
            ->action(function (self $record) {
                $record->approveDraft();
            });
    }

    public function replicateLineItems(Model $target): void
    {
        $this->lineItems->each(function (DocumentLineItem $lineItem) use ($target) {
            $replica = $lineItem->replicate([
                'documentable_id',
                'documentable_type',
                'subtotal',
                'total',
                'created_by',
                'updated_by',
                'created_at',
                'updated_at',
            ]);

            $replica->documentable_id = $target->id;
            $replica->documentable_type = $target->getMorphClass();
            $replica->save();

            $replica->adjustments()->sync($lineItem->adjustments->pluck('id'));
        });
    }
}

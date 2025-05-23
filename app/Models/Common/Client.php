<?php

namespace App\Models\Common;

use App\Concerns\Blamable;
use App\Concerns\CompanyOwned;
use App\Enums\Common\AddressType;
use App\Models\Accounting\Estimate;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\RecurringInvoice;
use App\Models\Accounting\Transaction;
use App\Models\Setting\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Client extends Model
{
    use Blamable;
    use CompanyOwned;
    use HasFactory;

    protected $table = 'clients';

    protected $fillable = [
        'company_id',
        'name',
        'currency_code',
        'account_number',
        'website',
        'notes',
        'created_by',
        'updated_by',
    ];

    public static function createWithRelations(array $data): self
    {
        /** @var Client $client */
        $client = self::create($data);

        if (isset($data['primaryContact'], $data['primaryContact']['first_name'])) {
            $client->primaryContact()->create([
                'is_primary' => true,
                'first_name' => $data['primaryContact']['first_name'],
                'last_name' => $data['primaryContact']['last_name'],
                'email' => $data['primaryContact']['email'],
                'phones' => $data['primaryContact']['phones'] ?? [],
            ]);
        }

        if (isset($data['secondaryContacts'])) {
            foreach ($data['secondaryContacts'] as $contactData) {
                if (isset($contactData['first_name'])) {
                    $client->secondaryContacts()->create([
                        'is_primary' => false,
                        'first_name' => $contactData['first_name'],
                        'last_name' => $contactData['last_name'],
                        'email' => $contactData['email'],
                        'phones' => $contactData['phones'] ?? [],
                    ]);
                }
            }
        }

        if (isset($data['billingAddress'], $data['billingAddress']['address_line_1'])) {
            $client->billingAddress()->create([
                'type' => AddressType::Billing,
                'address_line_1' => $data['billingAddress']['address_line_1'],
                'address_line_2' => $data['billingAddress']['address_line_2'] ?? null,
                'country_code' => $data['billingAddress']['country_code'] ?? null,
                'state_id' => $data['billingAddress']['state_id'] ?? null,
                'city' => $data['billingAddress']['city'] ?? null,
                'postal_code' => $data['billingAddress']['postal_code'] ?? null,
            ]);
        }

        if (isset($data['shippingAddress'])) {
            $shippingData = $data['shippingAddress'];
            $shippingAddress = [
                'type' => AddressType::Shipping,
                'recipient' => $shippingData['recipient'] ?? null,
                'phone' => $shippingData['phone'] ?? null,
                'notes' => $shippingData['notes'] ?? null,
            ];

            if ($shippingData['same_as_billing'] ?? false) {
                $billingAddress = $client->billingAddress;
                if ($billingAddress) {
                    $shippingAddress = [
                        ...$shippingAddress,
                        'parent_address_id' => $billingAddress->id,
                        'address_line_1' => $billingAddress->address_line_1,
                        'address_line_2' => $billingAddress->address_line_2,
                        'country_code' => $billingAddress->country_code,
                        'state_id' => $billingAddress->state_id,
                        'city' => $billingAddress->city,
                        'postal_code' => $billingAddress->postal_code,
                    ];
                    $client->shippingAddress()->create($shippingAddress);
                }
            } elseif (isset($shippingData['address_line_1'])) {
                $shippingAddress = [
                    ...$shippingAddress,
                    'address_line_1' => $shippingData['address_line_1'],
                    'address_line_2' => $shippingData['address_line_2'] ?? null,
                    'country_code' => $shippingData['country_code'] ?? null,
                    'state_id' => $shippingData['state_id'] ?? null,
                    'city' => $shippingData['city'] ?? null,
                    'postal_code' => $shippingData['postal_code'] ?? null,
                ];

                $client->shippingAddress()->create($shippingAddress);
            }
        }

        return $client;
    }

    public function updateWithRelations(array $data): self
    {
        $this->update($data);

        if (isset($data['primaryContact'], $data['primaryContact']['first_name'])) {
            $this->primaryContact()->updateOrCreate(
                ['is_primary' => true],
                [
                    'first_name' => $data['primaryContact']['first_name'],
                    'last_name' => $data['primaryContact']['last_name'],
                    'email' => $data['primaryContact']['email'],
                    'phones' => $data['primaryContact']['phones'] ?? [],
                ]
            );
        }

        if (isset($data['secondaryContacts'])) {
            // Delete removed contacts
            $existingIds = collect($data['secondaryContacts'])->pluck('id')->filter()->all();
            $this->secondaryContacts()->whereNotIn('id', $existingIds)->delete();

            // Update or create contacts
            foreach ($data['secondaryContacts'] as $contactData) {
                if (isset($contactData['first_name'])) {
                    $this->secondaryContacts()->updateOrCreate(
                        ['id' => $contactData['id'] ?? null],
                        [
                            'is_primary' => false,
                            'first_name' => $contactData['first_name'],
                            'last_name' => $contactData['last_name'],
                            'email' => $contactData['email'],
                            'phones' => $contactData['phones'] ?? [],
                        ]
                    );
                }
            }
        }

        if (isset($data['billingAddress'], $data['billingAddress']['address_line_1'])) {
            $this->billingAddress()->updateOrCreate(
                ['type' => AddressType::Billing],
                [
                    'address_line_1' => $data['billingAddress']['address_line_1'],
                    'address_line_2' => $data['billingAddress']['address_line_2'] ?? null,
                    'country_code' => $data['billingAddress']['country_code'] ?? null,
                    'state_id' => $data['billingAddress']['state_id'] ?? null,
                    'city' => $data['billingAddress']['city'] ?? null,
                    'postal_code' => $data['billingAddress']['postal_code'] ?? null,
                ]
            );
        }

        if (isset($data['shippingAddress'])) {
            $shippingData = $data['shippingAddress'];

            if ($shippingData['same_as_billing'] ?? false) {
                $billingAddress = $this->billingAddress;
                if ($billingAddress) {
                    $shippingAddress = [
                        'type' => AddressType::Shipping,
                        'recipient' => $shippingData['recipient'] ?? null,
                        'phone' => $shippingData['phone'] ?? null,
                        'notes' => $shippingData['notes'] ?? null,
                        'parent_address_id' => $billingAddress->id,
                        'address_line_1' => $billingAddress->address_line_1,
                        'address_line_2' => $billingAddress->address_line_2,
                        'country_code' => $billingAddress->country_code,
                        'state_id' => $billingAddress->state_id,
                        'city' => $billingAddress->city,
                        'postal_code' => $billingAddress->postal_code,
                    ];

                    $this->shippingAddress()->updateOrCreate(
                        ['type' => AddressType::Shipping],
                        $shippingAddress
                    );
                }
            } elseif (isset($shippingData['address_line_1'])) {
                $shippingAddress = [
                    'type' => AddressType::Shipping,
                    'recipient' => $shippingData['recipient'] ?? null,
                    'phone' => $shippingData['phone'] ?? null,
                    'notes' => $shippingData['notes'] ?? null,
                    'parent_address_id' => null,
                    'address_line_1' => $shippingData['address_line_1'],
                    'address_line_2' => $shippingData['address_line_2'] ?? null,
                    'country_code' => $shippingData['country_code'] ?? null,
                    'state_id' => $shippingData['state_id'] ?? null,
                    'city' => $shippingData['city'] ?? null,
                    'postal_code' => $shippingData['postal_code'] ?? null,
                ];

                $this->shippingAddress()->updateOrCreate(
                    ['type' => AddressType::Shipping],
                    $shippingAddress
                );
            }
        }

        return $this;
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'payeeable');
    }

    public function contacts(): MorphMany
    {
        return $this->morphMany(Contact::class, 'contactable');
    }

    public function primaryContact(): MorphOne
    {
        return $this->morphOne(Contact::class, 'contactable')
            ->where('is_primary', true);
    }

    public function secondaryContacts(): MorphMany
    {
        return $this->morphMany(Contact::class, 'contactable')
            ->where('is_primary', false);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    public function billingAddress(): MorphOne
    {
        return $this->morphOne(Address::class, 'addressable')
            ->where('type', AddressType::Billing);
    }

    public function shippingAddress(): MorphOne
    {
        return $this->morphOne(Address::class, 'addressable')
            ->where('type', AddressType::Shipping);
    }

    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function recurringInvoices(): HasMany
    {
        return $this->hasMany(RecurringInvoice::class);
    }
}

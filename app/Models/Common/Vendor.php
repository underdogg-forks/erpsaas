<?php

namespace App\Models\Common;

use App\Concerns\Blamable;
use App\Concerns\CompanyOwned;
use App\Enums\Common\ContractorType;
use App\Enums\Common\VendorType;
use App\Models\Accounting\Bill;
use App\Models\Accounting\Transaction;
use App\Models\Setting\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Vendor extends Model
{
    use Blamable;
    use CompanyOwned;
    use HasFactory;

    protected $table = 'vendors';

    protected $fillable = [
        'company_id',
        'name',
        'type',
        'contractor_type',
        'ssn',
        'ein',
        'currency_code',
        'account_number',
        'website',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'type' => VendorType::class,
        'contractor_type' => ContractorType::class,
        'ssn' => 'encrypted',
        'ein' => 'encrypted',
    ];

    public static function createWithRelations(array $data): self
    {
        /** @var Vendor $vendor */
        $vendor = self::create($data);

        if (isset($data['contact'], $data['contact']['first_name'])) {
            $vendor->contact()->create([
                'is_primary' => true,
                'first_name' => $data['contact']['first_name'],
                'last_name' => $data['contact']['last_name'],
                'email' => $data['contact']['email'],
                'phones' => $data['contact']['phones'] ?? [],
            ]);
        }

        if (isset($data['address'], $data['address']['type'], $data['address']['address_line_1'])) {
            $vendor->address()->create([
                'type' => $data['address']['type'],
                'address_line_1' => $data['address']['address_line_1'],
                'address_line_2' => $data['address']['address_line_2'] ?? null,
                'country_code' => $data['address']['country_code'] ?? null,
                'state_id' => $data['address']['state_id'] ?? null,
                'city' => $data['address']['city'] ?? null,
                'postal_code' => $data['address']['postal_code'] ?? null,
            ]);
        }

        return $vendor;
    }

    public function updateWithRelations(array $data): self
    {
        $this->update($data);

        if (isset($data['contact'], $data['contact']['first_name'])) {
            $this->contact()->updateOrCreate(
                ['is_primary' => true],
                [
                    'first_name' => $data['contact']['first_name'],
                    'last_name' => $data['contact']['last_name'],
                    'email' => $data['contact']['email'],
                    'phones' => $data['contact']['phones'] ?? [],
                ]
            );
        }

        if (isset($data['address'], $data['address']['type'], $data['address']['address_line_1'])) {
            $this->address()->updateOrCreate(
                ['type' => $data['address']['type']],
                [
                    'address_line_1' => $data['address']['address_line_1'],
                    'address_line_2' => $data['address']['address_line_2'] ?? null,
                    'country_code' => $data['address']['country_code'] ?? null,
                    'state_id' => $data['address']['state_id'] ?? null,
                    'city' => $data['address']['city'] ?? null,
                    'postal_code' => $data['address']['postal_code'] ?? null,
                ]
            );
        }

        return $this;
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'payeeable');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function address(): MorphOne
    {
        return $this->morphOne(Address::class, 'addressable');
    }

    public function contact(): MorphOne
    {
        return $this->morphOne(Contact::class, 'contactable');
    }
}

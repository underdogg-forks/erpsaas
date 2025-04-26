<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create a single admin user and their personal company
        $user = User::factory()
            ->withPersonalCompany(function (CompanyFactory $factory) {
                return $factory
                    ->state([
                        'name' => 'ERPSAAS',
                    ])
                    ->withTransactions()
                    ->withOfferings()
                    ->withClients()
                    ->withVendors()
                    ->withInvoices(50)
                    ->withRecurringInvoices()
                    ->withEstimates(50)
                    ->withBills(50);
            })
            ->create([
                'name' => 'Admin',
                'email' => 'admin@erpsaas.com',
                'password' => bcrypt('password'),
                'current_company_id' => 1,  // Assuming this will be the ID of the created company
            ]);

        $additionalCompanies = [
            ['name' => 'European Retail GmbH', 'country' => 'DE', 'currency' => 'EUR', 'locale' => 'de'],
            ['name' => 'UK Services Ltd', 'country' => 'GB', 'currency' => 'GBP', 'locale' => 'en'],
            ['name' => 'Canadian Manufacturing Inc', 'country' => 'CA', 'currency' => 'CAD', 'locale' => 'en'],
            ['name' => 'Australian Hospitality Pty', 'country' => 'AU', 'currency' => 'AUD', 'locale' => 'en'],
        ];

        foreach ($additionalCompanies as $companyData) {
            Company::factory()
                ->state([
                    'name' => $companyData['name'],
                    'user_id' => $user->id,
                    'personal_company' => false,
                ])
                ->withCompanyProfile($companyData['country'])
                ->withCompanyDefaults($companyData['currency'], $companyData['locale'])
                ->withTransactions(100)
                ->withOfferings()
                ->withClients()
                ->withVendors()
                ->withInvoices(20)
                ->withRecurringInvoices()
                ->withEstimates(15)
                ->withBills(15)
                ->create();
        }
    }
}

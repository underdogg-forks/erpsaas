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
                    ->withTransactions(250)
                    ->withOfferings()
                    ->withClients()
                    ->withVendors()
                    ->withInvoices(30)
                    ->withRecurringInvoices()
                    ->withEstimates(30)
                    ->withBills(30);
            })
            ->create([
                'name' => 'Admin',
                'email' => 'admin@erpsaas.com',
                'password' => bcrypt('password'),
                'current_company_id' => 1,  // Assuming this will be the ID of the created company
            ]);

        // Only use en locale for now
        $additionalCompanies = [
            ['name' => 'British Crown Analytics', 'country' => 'GB', 'currency' => 'GBP', 'locale' => 'en'],
            ['name' => 'Swiss Precision Group', 'country' => 'CH', 'currency' => 'CHF', 'locale' => 'en'],
            ['name' => 'Tokyo Future Technologies', 'country' => 'JP', 'currency' => 'JPY', 'locale' => 'en'],
            ['name' => 'Sydney Harbor Systems', 'country' => 'AU', 'currency' => 'AUD', 'locale' => 'en'],
            ['name' => 'Mumbai Software Services', 'country' => 'IN', 'currency' => 'INR', 'locale' => 'en'],
            ['name' => 'Singapore Digital Hub', 'country' => 'SG', 'currency' => 'SGD', 'locale' => 'en'],
            ['name' => 'Dubai Business Consulting', 'country' => 'AE', 'currency' => 'AED', 'locale' => 'en'],
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
                ->withInvoices(15)
                ->withRecurringInvoices()
                ->withEstimates(15)
                ->withBills(15)
                ->create();
        }
    }
}

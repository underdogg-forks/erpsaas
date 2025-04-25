<?php

namespace App\Filament\Pages\Auth;

use Wallo\FilamentCompanies\Pages\Auth\Login as BaseLogin;

class Login extends BaseLogin
{
    public function mount(): void
    {
        parent::mount();

        if (app()->environment('demo')) {
            $this->form->fill([
                'email' => 'admin@erpsaas.com',
                'password' => 'password',
                'remember' => true,
            ]);
        }
    }
}

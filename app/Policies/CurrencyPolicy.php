<?php

namespace App\Policies;

use App\Models\Setting\Currency;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CurrencyPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Currency $currency): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Currency $currency): bool
    {
        return $currency->isDisabled();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Currency $currency): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Currency $currency): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Currency $currency): bool
    {
        return false;
    }
}

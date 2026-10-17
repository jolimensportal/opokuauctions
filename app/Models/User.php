<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Enums\Role;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

#[Fillable(['name', 'email', 'password', 'role', 'phone', 'country_code'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    /**
     * A buyer's wallet in their own currency. Null for Ops and Owner, who hold
     * no buyer balance.
     */
    public function wallet(string $currencyCode = 'usd'): ?\App\Models\Wallet
    {
        return \App\Models\Wallet::where('user_id', $this->id)
            ->where('currency_code', $currencyCode)
            ->first();
    }

    public function isBuyer(): bool
    {
        return $this->role === Role::Buyer;
    }

    /**
     * Gate for the Filament panel.
     *
     * Implementing FilamentUser is what makes this method authoritative.
     * Without the contract, Filament ignores it entirely and falls back to
     * "allow anyone, but only when app.env is local" — which silently denies
     * every real user in production while looking like it works in dev.
     *
     * Declared on the model rather than the panel provider so the rule travels
     * with the user and cannot be forgotten by a future panel. A buyer hitting
     * /admin is refused outright; hiding the link would not be enough.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role->canAccessPanel();
    }
}

<?php
namespace App\Providers;

use App\Models\Booking\Hold;
use App\Policies\Booking\HoldPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{

    protected $policies = [
        Hold::class =>
            HoldPolicy::class,
    ];

}

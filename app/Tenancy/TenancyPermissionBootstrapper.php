<?php

namespace App\Tenancy;

use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class TenancyPermissionBootstrapper implements TenancyBootstrapper
{
    public function __construct(protected PermissionRegistrar $registrar)
    {
    }

    public function bootstrap(Tenant $tenant): void
    {
        config(['permission.guard_name' => 'tenant']);
        $this->registrar->forgetCachedPermissions();
    }

    public function revert(): void
    {
        config(['permission.guard_name' => 'system']);
        $this->registrar->forgetCachedPermissions();
    }
}
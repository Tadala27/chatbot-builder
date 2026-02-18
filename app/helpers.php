<?php

use Spatie\Multitenancy\Models\Tenant;

if (!function_exists('tenant')) {
    function tenant()
    {
        return Tenant::current();
    }
}
<?php

namespace App\Services\Tenant;

use App\Models\CompanySetting;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

class TenantMailer
{
    /**
     * Returns a configured Mailer for the current tenant.
     * Falls back to the default system mailer if the tenant has no mail config.
     */
    public static function mailer(): Mailer
    {
        $setting = static::getSetting();

        if (!$setting || !$setting->mail_driver) {
            return Mail::mailer(config('mail.default'));
        }

        $tenantMailerName = 'tenant_'.tenant()?->id;

        config(["mail.mailers.{$tenantMailerName}" => static::buildMailConfig($setting)]);

        if ($setting->mail_from_address) {
            config([
                "mail.mailers.{$tenantMailerName}.from" => [
                    'address' => $setting->mail_from_address,
                    'name' => $setting->mail_from_name ?? tenant()?->name ?? config('mail.from.name'),
                ],
            ]);
        }

        return Mail::mailer($tenantMailerName);
    }

    private static function getSetting(): ?CompanySetting
    {
        try {
            return CompanySetting::current();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function buildMailConfig(CompanySetting $setting): array
    {
        return match ($setting->mail_driver) {
            'smtp' => [
                'transport' => 'smtp',
                'host' => $setting->mail_host,
                'port' => $setting->mail_port ?? 587,
                'encryption' => $setting->mail_encryption ?? 'tls',
                'username' => $setting->mail_username,
                'password' => $setting->mail_password,
                'timeout' => 30,
            ],
            'sendmail' => [
                'transport' => 'sendmail',
                'path' => '/usr/sbin/sendmail -bs -i',
            ],
            default => [
                'transport' => config('mail.mailers.'.config('mail.default').'.transport', 'smtp'),
            ],
        };
    }
}
<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('attendance:auto-checkout')
    ->everyFifteenMinutes()
    ->description('Automatically complete check-out for employees who forgot to check out.');

// Billing: generate invoice setiap tanggal 5 jam 00:30
Schedule::command('billing:generate-invoices')
    ->cron('30 0 5 * *')
    ->description('Generate billing invoices for all active tenants (every 5th of month).');

// Billing: suspend tenant overdue setiap tanggal 11 jam 00:30
Schedule::command('billing:suspend-overdue')
    ->cron('30 0 11 * *')
    ->description('Suspend tenants with overdue invoices (every 11th of month).');

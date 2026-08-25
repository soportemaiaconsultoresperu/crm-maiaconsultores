<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Scheduled tasks (timezone America/Lima, RNF-UX-002).
 *
 * activities:mark-overdue    daily 02:00 — RF-ACT-003 (mark overdue).
 * activities:notify-upcoming  daily 02:05 — RF-ACT-007 (24h reminders).
 * activities:notify-overdue   every 15 min — RF-ACT-003 (overdue noise).
 * campaigns:mark-overdue     every 15 min — RF-CAMP-007 (campaign overdue noise).
 * campaigns:recompute-kpis   daily 02:10 — RF-CAMP-010 (KPIs cache refresh).
 * invoices:mark-overdue      daily 00:10 — persist customer invoices as Vencida.
 */
Schedule::command('activities:mark-overdue')
    ->dailyAt('02:00')
    ->timezone('America/Lima');

Schedule::command('activities:notify-upcoming')
    ->dailyAt('02:05')
    ->timezone('America/Lima');

Schedule::command('activities:notify-overdue')
    ->everyFifteenMinutes()
    ->timezone('America/Lima');

Schedule::command('campaign:mark-overdue')
    ->everyFifteenMinutes()
    ->timezone('America/Lima');

Schedule::command('campaign:recompute-kpis')
    ->dailyAt('02:10')
    ->timezone('America/Lima');

Schedule::command('invoices:mark-overdue')
    ->dailyAt('00:10')
    ->timezone('America/Lima')
    ->withoutOverlapping();
<?php

namespace Plugins\ExpenseClaims;

use App\Domains\Accounting\Contracts\ClosingCheckInterface;
use App\Domains\Accounting\Events\JournalDraftPosted;
use App\Domains\Accounting\Services\JournalEntryReferences;
use App\Domains\Accounting\Support\SystemReferencePrefixes;
use App\Domains\Payroll\Contracts\ReimbursementSourceInterface;
use App\Support\Plugins\PluginNavigation;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Plugins\ExpenseClaims\Console\ImportAirtableCommand;
use Plugins\ExpenseClaims\Listeners\ApprovalNotice;
use Plugins\ExpenseClaims\Listeners\PostRepaymentDrafts;
use Plugins\ExpenseClaims\Services\ClosingCheck;
use Plugins\ExpenseClaims\Services\JournalOwners;
use Plugins\ExpenseClaims\Services\ReimbursementSource;

class ExpenseClaimsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/expense-claims.php', 'expense-claims');
        $this->app->singleton(ReimbursementSourceInterface::class, ReimbursementSource::class);
        $this->app->singleton(ClosingCheck::class);
        $this->app->tag([ClosingCheck::class], ClosingCheckInterface::TAG);
        $this->commands([ImportAirtableCommand::class]);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'expense-claims');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../migrations');
        Event::listen(Login::class, ApprovalNotice::class);
        Event::listen(JournalDraftPosted::class, PostRepaymentDrafts::class);
        // Entries of claims and debts are changed here, not in the journal.
        $this->callAfterResolving(JournalEntryReferences::class, fn (JournalEntryReferences $references) => JournalOwners::register($references));
        // EC-PAY-, EC-DEBT-, EC-ADJ-…: references of this plugin's entries cannot be typed by hand.
        $this->callAfterResolving(SystemReferencePrefixes::class, fn (SystemReferencePrefixes $prefixes) => $prefixes->extend('EC-'));

        $navigation = $this->app->make(PluginNavigation::class);
        // Own claims: everyone who can enter expenses; balances: managers only (§8.2, D39).
        $navigation->add(['expenses', 'payroll'], 'expense_claims', 'expense-claims::ec.nav_claims', '/expense-claims', 'expenses.create');
        $navigation->add(['expenses', 'payroll'], 'expense_balances', 'expense-claims::ec.nav_balances', '/expense-balances', 'expenses.approve');
        $navigation->add('organization_settings_nav', 'expense_settings', 'expense-claims::ec.nav_settings', '/settings/expense-claims', 'expenses.approve');
    }
}

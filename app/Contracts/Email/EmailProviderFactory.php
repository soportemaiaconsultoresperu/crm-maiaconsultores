<?php

declare(strict_types=1);

namespace App\Contracts\Email;

use App\Models\IntegrationAccount;
use App\Services\Email\GmailProvider;
use App\Services\Email\OutlookProvider;
use App\Services\Email\SmtpProvider;
use InvalidArgumentException;

/**
 * B13 Pasada B — Selects the concrete {@see EmailProvider} implementation
 * for a given {@see IntegrationAccount}.
 *
 * Selection rules (decision 10a — SMTP / Gmail / Outlook, no IMAP):
 *
 *   - `provider` = `smtp`     → SmtpProvider
 *   - `provider` = `gmail`    → GmailProvider
 *   - `provider` = `google`   → GmailProvider (Google Workspace OAuth row)
 *   - `provider` = `outlook`  → OutlookProvider
 *
 * Anything else raises {@see InvalidArgumentException}. Per-account overrides
 * of the implementation class (e.g. an operator chooses a custom SMTP
 * wrapper) are deferred to B13 Pasada C.
 */
class EmailProviderFactory
{
    /**
     * @param  IntegrationAccount  $account
     */
    public function for(IntegrationAccount $account): EmailProvider
    {
        return $this->make((string) $account->provider, $account);
    }

    /**
     * @param  string  $provider  smtp|gmail|outlook
     */
    public function make(string $provider, ?IntegrationAccount $account = null): EmailProvider
    {
        return match ($provider) {
            'smtp' => new SmtpProvider($account),
            'gmail', 'google' => new GmailProvider($account),
            'outlook' => new OutlookProvider($account),
            default => throw new InvalidArgumentException(
                sprintf('Unknown email provider "%s".', $provider),
            ),
        };
    }
}

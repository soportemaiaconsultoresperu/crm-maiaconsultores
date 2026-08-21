<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Integrations\Contracts\EmailProvider;
use App\Integrations\Dto\EmailSendResult;
use App\Models\IntegrationAccount;

/**
 * Test-only EmailProvider used by AdapterFactoryTest. Lives in
 * tests/Support so it never leaks into production autoloading.
 */
class DummyEmailAdapter implements EmailProvider
{
    public ?IntegrationAccount $boundAccount = null;

    public function send(array $message): EmailSendResult
    {
        return EmailSendResult::accepted('test-id-'.uniqid());
    }

    public function connect(array $config): void
    {
        // no-op for tests
    }

    public function refresh(): void
    {
        // no-op for tests
    }

    public function disconnect(): void
    {
        // no-op for tests
    }

    public function isHealthy(): bool
    {
        return true;
    }

    public function bindAccount(?IntegrationAccount $account): void
    {
        $this->boundAccount = $account;
    }
}
<?php

namespace App\Console\Commands;

use App\Application\Tenancy\AccountProvisioningService;
use App\Domain\Shared\TenantRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

final class ProvisionAccountCommand extends Command
{
    protected $signature = 'statusconnect:provision-account
        {--email= : User email (required)}
        {--name= : User display name (defaults to the part of the email before @)}
        {--org= : Organization (tenant) name; required when the tenant does not exist yet}
        {--slug= : Tenant slug, also used as the status page slug (required)}
        {--role=owner : Membership role: owner, admin or viewer}
        {--title= : Status page title (defaults to the organization name)}
        {--locale=en : Status page locale (en or pt_BR)}
        {--timezone=UTC : Status page timezone}
        {--platform-admin : Grant platform admin to a newly created user}';

    protected $description = 'Create or complete an account: user, tenant, membership, production environment and status page (idempotent)';

    public function handle(AccountProvisioningService $provisioning): int
    {
        $input = [
            'email' => (string) $this->option('email'),
            'name' => (string) ($this->option('name') ?: strstr((string) $this->option('email'), '@', true)),
            'org' => (string) ($this->option('org') ?: $this->option('slug')),
            'slug' => (string) $this->option('slug'),
            'role' => (string) $this->option('role'),
            'title' => $this->option('title') ?: null,
            'locale' => (string) $this->option('locale'),
            'timezone' => (string) $this->option('timezone'),
        ];

        $validator = Validator::make($input, [
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:255'],
            'org' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'role' => ['required', 'in:owner,admin,viewer'],
            'title' => ['nullable', 'string', 'max:255'],
            'locale' => ['required', 'in:en,pt_BR,pt-BR'],
            'timezone' => ['required', 'timezone'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }

        try {
            $account = $provisioning->provision([
                ...$input,
                'role' => TenantRole::from($input['role']),
                'platform_admin' => (bool) $this->option('platform-admin'),
            ]);
        } catch (\DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($account->created === []
            ? 'Nothing to do: the account already exists.'
            : 'Created: '.implode(', ', $account->created).'.');

        $this->table(['Field', 'Value'], [
            ['User', $account->user->email],
            ['Tenant', "{$account->tenant->name} ({$account->tenant->slug})"],
            ['Role', $account->membership->role],
            ['Environment', $account->environment->name],
            ['Status page', '/status/'.$account->statusPageSlug],
        ]);

        if ($account->temporaryPassword !== null) {
            $this->warn('Temporary password (shown once, ask the user to change it): '.$account->temporaryPassword);
        }

        return self::SUCCESS;
    }
}

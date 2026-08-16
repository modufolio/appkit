<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Command;

use Modufolio\Appkit\Core\AppInterface;
use Modufolio\Appkit\Security\SecurityConfigurator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Display the configured firewalls, access-control rules and role hierarchy —
 * appkit's counterpart to Symfony's debug:firewall.
 *
 * With no argument it lists every firewall; with a firewall name it shows that
 * firewall's full configuration plus the access-control rules scoped to it.
 */
#[AsCommand(
    name: 'debug:firewall',
    description: 'Display the security firewalls, access-control rules and role hierarchy'
)]
final class FirewallDebugCommand extends Command
{
    public function __construct(private readonly AppInterface $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDefinition([
                new InputArgument('name', InputArgument::OPTIONAL, 'A firewall name'),
            ])
            ->setHelp(
                <<<'EOF'
The <info>%command.name%</info> command displays the configured firewalls:

  <info>php %command.full_name%</info>

To inspect a single firewall in detail, pass its name:

  <info>php %command.full_name% main</info>
EOF
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $firewalls = $this->app->getFirewalls();

        if ([] === $firewalls) {
            $io->warning('No firewalls are configured.');

            return Command::SUCCESS;
        }

        $name = $input->getArgument('name');

        if (null !== $name) {
            return $this->describeFirewall($io, (string) $name, $firewalls);
        }

        $this->listFirewalls($io, $firewalls);
        $this->describeAccessControl($io);
        $this->describeRoleHierarchy($io);

        return Command::SUCCESS;
    }

    /**
     * @param array<string, array<string, mixed>> $firewalls
     */
    private function listFirewalls(SymfonyStyle $io, array $firewalls): void
    {
        $io->title('Firewalls');

        $rows = [];
        foreach ($firewalls as $fwName => $config) {
            $rows[] = [
                $fwName,
                $config['pattern'] ?? '(none)',
                $this->formatList($config['methods'] ?? []) ?: '*',
                $config['host'] ?? '',
                $this->formatBool(($config['security'] ?? true) !== false),
                $this->formatBool((bool) ($config['stateless'] ?? false)),
                $this->formatList($config['authenticators'] ?? []),
                $config['entry_point'] ?? '',
            ];
        }

        $io->table(
            ['Name', 'Pattern', 'Methods', 'Host', 'Security', 'Stateless', 'Authenticators', 'Entry point'],
            $rows,
        );
        $io->note('A firewall handles a request only if its pattern, methods, host and ips all match; firewalls are tried top-to-bottom and the first match wins.');
    }

    /**
     * @param array<string, array<string, mixed>> $firewalls
     */
    private function describeFirewall(SymfonyStyle $io, string $name, array $firewalls): int
    {
        if (!isset($firewalls[$name])) {
            $io->error(sprintf('The firewall "%s" does not exist. Configured: %s.', $name, implode(', ', array_keys($firewalls))));

            return Command::FAILURE;
        }

        $io->title(sprintf('Firewall "%s"', $name));

        $rows = [];
        foreach ($firewalls[$name] as $key => $value) {
            $rows[] = [$key, $this->formatValue($value)];
        }
        $io->table(['Option', 'Value'], $rows);

        $scoped = array_filter(
            $this->app->getAccessControlRules(),
            static fn (array $rule): bool => ($rule['firewall'] ?? null) === $name,
        );

        if ([] !== $scoped) {
            $io->section('Access-control rules scoped to this firewall');
            $this->renderAccessControlTable($io, $scoped);
        }

        return Command::SUCCESS;
    }

    private function describeAccessControl(SymfonyStyle $io): void
    {
        $rules = $this->app->getAccessControlRules();

        if ([] === $rules) {
            return;
        }

        $io->title('Access-control rules');
        $this->renderAccessControlTable($io, $rules);
        $io->note('Rules are evaluated in order; the first matching non-public rule decides the request.');
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private function renderAccessControlTable(SymfonyStyle $io, array $rules): void
    {
        $rows = [];
        foreach ($rules as $rule) {
            $roles = $rule['roles'] ?? [];
            $isPublic = in_array(SecurityConfigurator::PUBLIC_ACCESS, (array) $roles, true);

            $rows[] = [
                $rule['path'] ?? '/',
                $isPublic ? '<info>PUBLIC</info>' : $this->formatList($roles),
                $this->formatList($rule['methods'] ?? []),
                $rule['firewall'] ?? '',
                $this->formatList($rule['ips'] ?? []),
                $rule['requires_channel'] ?? '',
            ];
        }

        $io->table(['Path', 'Roles', 'Methods', 'Firewall', 'IPs', 'Channel'], $rows);
    }

    private function describeRoleHierarchy(SymfonyStyle $io): void
    {
        $hierarchy = $this->app->getRoleHierarchy();
        $map = $hierarchy?->getMap() ?? [];

        if ([] === $map) {
            return;
        }

        $io->title('Role hierarchy');

        $rows = [];
        foreach ($map as $role => $inherited) {
            $rows[] = [$role, $this->formatList($inherited)];
        }

        $io->table(['Role', 'Inherits'], $rows);
    }

    private function formatBool(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    /**
     * @param array<int, string>|mixed $list
     */
    private function formatList(mixed $list): string
    {
        return is_array($list) ? implode(', ', $list) : (string) $list;
    }

    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $this->formatBool($value);
        }

        if ($value instanceof \Closure) {
            return '(closure)';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '(array)';
        }

        return (string) $value;
    }
}

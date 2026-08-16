<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Command;

use Modufolio\Appkit\Core\AppInterface;
use Modufolio\Appkit\Security\AccessControl\AccessRule;
use Modufolio\Appkit\Security\FirewallConfiguration;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Validate the security configuration without booting a request.
 *
 * The Kernel validates firewall config against FirewallConfiguration on every
 * non-prod boot, but skips it in prod for performance (the config is trusted
 * once deployed). This command runs the same validation on demand — regardless
 * of environment — so a deploy pipeline can fail the build on a misconfigured
 * firewall or access-control rule before it ever reaches production.
 *
 * It checks:
 *  - firewalls against the FirewallConfiguration schema (types, the `methods`
 *    footgun, callable csrf_validator, …);
 *  - access-control rules against AccessRule (a malformed rule that would be
 *    silently skipped at runtime — and thus fail open — is reported here).
 */
#[AsCommand(
    name: 'security:validate',
    description: 'Validate the firewall and access-control configuration'
)]
final class SecurityValidateCommand extends Command
{
    public function __construct(private readonly AppInterface $app)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Security configuration validation');

        $errors = [];

        try {
            (new Processor())->processConfiguration(
                new FirewallConfiguration(),
                [['firewalls' => $this->app->getFirewalls()]],
            );
            $io->writeln(' <info>✓</info> Firewalls');
        } catch (InvalidConfigurationException $e) {
            $errors[] = 'Firewalls: '.$e->getMessage();
            $io->writeln(' <error>✗</error> Firewalls');
        }

        try {
            foreach ($this->app->getAccessControlRules() as $rule) {
                AccessRule::fromArray($rule);
            }
            $io->writeln(' <info>✓</info> Access-control rules');
        } catch (\InvalidArgumentException $e) {
            $errors[] = 'Access-control rules: '.$e->getMessage();
            $io->writeln(' <error>✗</error> Access-control rules');
        }

        if ([] !== $errors) {
            $io->newLine();
            $io->error($errors);

            return Command::FAILURE;
        }

        $io->success('Security configuration is valid.');

        return Command::SUCCESS;
    }
}

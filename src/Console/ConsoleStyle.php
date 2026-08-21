<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @author    Javier Eguiluz <javier.eguiluz@gmail.com>
 * @author    Ryan Weaver <weaverryan@gmail.com>
 *
 * @see       https://github.com/symfony/maker-bundle
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
final class ConsoleStyle extends SymfonyStyle
{
    public function __construct(
        InputInterface $input,
        private OutputInterface $output,
    ) {
        parent::__construct($input, $output);
    }

    /**
     * @param string|list<string> $message
     */
    public function success(string|array $message): void
    {
        foreach ((array) $message as $line) {
            $this->writeln('<fg=green;options=bold,underscore>OK</> '.$line);
        }
    }

    /**
     * @param string|list<string> $message
     */
    public function comment(string|array $message): void
    {
        $this->text($message);
    }

    public function getOutput(): OutputInterface
    {
        return $this->output;
    }
}

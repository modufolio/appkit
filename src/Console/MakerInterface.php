<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Interface that all maker commands must implement.
 *
 * @author    Ryan Weaver <ryan@knpuniversity.com>
 *
 * @method static string getCommandDescription()
 *
 * @see       https://github.com/symfony/maker-bundle
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
interface MakerInterface
{
    /**
     * Return the command name for your maker (e.g. make:report).
     */
    public static function getCommandName(): string;

    /**
     * Configure the command: set description, input arguments, options, etc.
     *
     * By default, all arguments will be asked interactively. If you want
     * to avoid that, use the $inputConfig->setArgumentAsNonInteractive() method.
     */
    public function configureCommand(Command $command, InputConfiguration $inputConfig): void;

    /**
     * If necessary, you can use this method to interactively ask the user for input.
     */
    public function interact(InputInterface $input, ConsoleStyle $io, Command $command): void;

    /**
     * Called after normal code generation: allows you to do anything.
     */
    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void;
}

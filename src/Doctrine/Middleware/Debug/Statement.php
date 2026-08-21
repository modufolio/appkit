<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Doctrine\Middleware\Debug;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;

/**
 * @author    Filippo Tessarotto <zoeslam@gmail.com>
 *
 * @see       https://github.com/Slamdunk/dbal-debugstack-middleware
 *
 * @copyright Filippo Tessarotto
 * @license   https://opensource.org/licenses/MIT
 */
final class Statement extends AbstractStatementMiddleware
{
    /** @var array<int|string, mixed> */
    private array $params = [];

    /** @var array<int|string, ParameterType|int|string|null> */
    private array $types = [];

    public function __construct(
        StatementInterface $statement,
        private readonly DebugStack $debugStack,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type = ParameterType::STRING): void
    {
        $this->params[$param] = $value;
        $this->types[$param] = $type;

        parent::bindValue($param, $value, $type);
    }

    public function execute(): ResultInterface
    {
        $start = Query::start();
        $result = parent::execute();
        $elapsed = Query::end($start);

        $this->debugStack->append(new Query(
            $this->sql,
            $this->params,
            $this->types,
            $elapsed
        ));

        return $result;
    }
}

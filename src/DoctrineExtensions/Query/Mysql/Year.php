<?php

declare(strict_types=1);

namespace App\DoctrineExtensions\Query\Mysql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

class Year extends FunctionNode
{
    public $date;

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'EXTRACT(YEAR FROM ' . $sqlWalker->walkStringPrimary($this->date) . ')';
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->date = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}

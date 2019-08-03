<?php

declare(strict_types=1);

use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\InsertStatement;
use PhpMyAdmin\SqlParser\Token;
use Symfony\Component\Finder\Finder;

require __DIR__ . '/vendor/autoload.php';

function format(string $query): string {
    /** @var Token[] $tokens */
    $tokens = (new Parser($query))->list->tokens;
    $braceDepth = 0;

    $parts = array_map(static function (Token $token) use (&$braceDepth): string {
        if ($token->token === '(') {
            ++$braceDepth;
        }

        if ($token->token === ')') {
            --$braceDepth;
        }

        if ($braceDepth === 0 && $token->type === Token::TYPE_OPERATOR && $token->token === ',') {
            return ",\n";
        }

        if ($token->type === Token::TYPE_SYMBOL) {
            return $token->value;
        }

        if ($token->type === Token::TYPE_KEYWORD && $token->keyword === 'VALUES') {
            return "\nVALUES\n";
        }

        if ($token->type === Token::TYPE_NONE) {
            return "{$token->token} ";
        }

        return $token->token ?? '';
    }, $tokens);

    $lines = explode("\n", implode('', $parts));
    $trimmedLines = array_map('trim', $lines);
    return implode("\n", $trimmedLines);
}

function addNewColumnToQuery(string $query, string $table, string $newColumn, string $default): string {
    $statements = (new Parser($query))->statements;
    $formattedStatements = [];

    foreach ($statements as $statement) {
        if (
            $statement instanceof InsertStatement
            && $statement->into->dest->table === $table
            && ! in_array($newColumn, $statement->into->columns, true)
        ) {
            foreach ($statement->values as $value) {
                $value->values[] = $default;
                $value->raw[] = $default;
            }

            $statement->into->columns[] = $newColumn;
        }

        $formattedStatements[] = format($statement->build());
    }

    return implode(";\n\n", $formattedStatements) . ";\n";
}

$options = getopt('', [
    'table:',
    'column:',
    'default:',
    'dir:',
]);

if (array_keys($options) !== ['table', 'column', 'default', 'dir']) {
    echo <<<HELP
    Usage: php test.php --table users --column signed_up_at --default '"2019-01-01 00:00:00"' --dir fixtures/

    HELP;
    exit(1);
}

try {
    $files = (new Finder())->in($options['dir'])->name('*.sql');
} catch (\Symfony\Component\Finder\Exception\DirectoryNotFoundException $e) {
    echo "Directory does not exist\n";
    exit(1);
}

/** @var \Symfony\Component\Finder\SplFileInfo $file */
foreach ($files as $file) {
    $query = $file->getContents();

    $result = addNewColumnToQuery($query, $options['table'], $options['column'], $options['default']);

    $file->openFile('w')->fwrite($result);
}

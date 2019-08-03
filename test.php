<?php

declare(strict_types=1);

use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\InsertStatement;
use PhpMyAdmin\SqlParser\Token;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use Symfony\Component\Finder\Finder;

require __DIR__ . '/vendor/autoload.php';

function addNewColumnToQuery(string $query, string $table, string $newColumn, string $default): string {
    $parser = new Parser($query);
    $statements = $parser->statements;
    /** @var Token[] $inputTokens */
    $inputTokens = $parser->list->tokens;
    $outputTokens = [];

    foreach ($statements as $statement) {
        $start = $statement->first;
        $end = $statement->last;

        if (
            ! $statement instanceof InsertStatement
            || $statement->into->dest->table !== $table
            || in_array($newColumn, $statement->into->columns, true)
        ) {
            for ($i = $start; $i <= $end; ++$i) {
                $outputTokens[] = $inputTokens[$i]->token;
            }
            continue;
        }

        $braceDepth = 0;
        $hasInsertedColumn = false;

        for ($i = $start; $i <= $end; ++$i) {
            $token = $inputTokens[$i];

            if ($token->token === '(') {
                ++$braceDepth;
            }

            if ($token->token === ')') {
                --$braceDepth;

                if ($braceDepth === 0) {
                    if ($hasInsertedColumn) {
                        $outputTokens[] = ", {$default})";
                    } else {
                        $outputTokens[] = ", {$newColumn})";
                        $hasInsertedColumn = true;
                    }

                    continue;
                }
            }

            $outputTokens[] = $token->token;
        }
    }

    for ($i = $end + 1; $i < count($inputTokens); ++$i) {
        $outputTokens[] = $inputTokens[$i]->token;
    }

    return implode('', $outputTokens);
}

$options = getopt('', [
    'table:',
    'column:',
    'default:',
    'dir:',
]);

if (!isset($options['table'], $options['column'], $options['default'], $options['dir'])) {
    echo <<<HELP
    Usage: php test.php
        --table users
        --column signed_up_at
        --default '"2019-01-01 00:00:00"'
        --dir fixtures/

    HELP;
    exit(1);
}

try {
    $files = (new Finder())->in($options['dir'])->name('*.sql');
} catch (DirectoryNotFoundException $e) {
    echo "Directory does not exist\n";
    exit(1);
}

/** @var \Symfony\Component\Finder\SplFileInfo $file */
foreach ($files as $file) {
    $query = $file->getContents();

    $result = addNewColumnToQuery($query, $options['table'], $options['column'], $options['default']);

    $file->openFile('w')->fwrite($result);
}

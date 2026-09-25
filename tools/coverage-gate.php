<?php

declare(strict_types=1);

/**
 * Coverage gate: fails the build when line/branch coverage drops below the
 * configured minimums. Reads a PHPUnit Clover report.
 *
 * Usage: php tools/coverage-gate.php coverage.xml 90 75
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/coverage-gate.php <coverage.xml> [minLine%] [minBranch%]\n");
    exit(2);
}

$file = $argv[1];
$minLine = (float) ($argv[2] ?? 90.0);
$minBranch = (float) ($argv[3] ?? 75.0);

if (!\is_file($file)) {
    fwrite(STDERR, "Coverage file not found: {$file}\n");
    exit(1);
}

$previous = \libxml_use_internal_errors(true);
$xml = \simplexml_load_file($file);
\libxml_clear_errors();
\libxml_use_internal_errors($previous);

if ($xml === false) {
    fwrite(STDERR, "Unable to parse coverage file: {$file}\n");
    exit(1);
}

$metrics = $xml->project->metrics ?? null;

if ($metrics === null) {
    fwrite(STDERR, "No <metrics> element found in: {$file}\n");
    exit(1);
}

$statements = (int) ($metrics['statements'] ?? 0);
$coveredStatements = (int) ($metrics['coveredstatements'] ?? 0);
$conditionals = (int) ($metrics['conditionals'] ?? 0);
$coveredConditionals = (int) ($metrics['coveredconditionals'] ?? 0);

// No executable statements yet (empty src/) or no branch data (pcov) — skip.
$linePct = $statements > 0 ? 100.0 * $coveredStatements / $statements : 100.0;
$branchPct = $conditionals > 0 ? 100.0 * $coveredConditionals / $conditionals : 100.0;

printf(
    "Line coverage:    %5.1f%% (%d/%d, required >= %.1f%%)\n",
    $linePct,
    $coveredStatements,
    $statements,
    $minLine,
);
printf(
    "Branch coverage:  %5.1f%% (%d/%d, required >= %.1f%%)\n",
    $branchPct,
    $coveredConditionals,
    $conditionals,
    $minBranch,
);

$failed = false;

if ($statements > 0 && $linePct < $minLine) {
    fwrite(STDERR, "FAIL: line coverage below threshold\n");
    $failed = true;
}

if ($conditionals > 0 && $branchPct < $minBranch) {
    fwrite(STDERR, "FAIL: branch coverage below threshold\n");
    $failed = true;
}

exit($failed ? 1 : 0);

<?php

/**
 * Iteratively fix missing Schema modules by running phpunit and parsing errors.
 */
$schemaDir = __DIR__ . '/Schema';
$testsDir = __DIR__;
$baseDir = dirname($testsDir);

// Build table -> module mapping
$tableToModule = [];
foreach (glob($schemaDir . '/*Module.php') as $moduleFile) {
    $shortName = basename($moduleFile, '.php');
    $content = file_get_contents($moduleFile);
    if (preg_match_all("/Schema::create\('([^']+)'/", $content, $matches)) {
        foreach ($matches[1] as $tableName) {
            $tableToModule[$tableName] = $shortName;
        }
    }
}

// Also handle Schema::table (for FK additions)
foreach (glob($schemaDir . '/*Module.php') as $moduleFile) {
    $shortName = basename($moduleFile, '.php');
    $content = file_get_contents($moduleFile);
    if (preg_match_all("/Schema::table\('([^']+)'/", $content, $matches)) {
        foreach ($matches[1] as $tableName) {
            if (! isset($tableToModule[$tableName])) {
                $tableToModule[$tableName] = $shortName;
            }
        }
    }
}

$maxIterations = 3;
for ($iter = 1; $iter <= $maxIterations; $iter++) {
    echo "=== Iteration {$iter} ===\n";

    $output = shell_exec('cd ' . escapeshellarg($baseDir) . ' && ./vendor/bin/phpunit --no-coverage 2>&1');

    // Parse: class -> missing tables
    $classTableMap = [];
    $lines = explode("\n", $output);
    $lastClass = null;

    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        if (preg_match('/^[0-9]+\) (MultiTenantSaas\\\\Tests\\\\(?:[A-Za-z\\\\]+))::/', $line, $m)) {
            $lastClass = $m[1];
        } elseif ($lastClass && preg_match('/no such table: ([a-z_]+)/', $line, $m)) {
            $table = $m[1];
            $classTableMap[$lastClass][] = $table;
            $lastClass = null;
        }
    }

    if (empty($classTableMap)) {
        echo "No more missing tables!\n";
        break;
    }

    $uniqueClasses = [];
    foreach ($classTableMap as $cls => $tables) {
        $uniqueClasses[$cls] = array_unique($tables);
    }

    echo 'Found ' . count($uniqueClasses) . " classes with missing tables.\n";

    // Find test file for each class
    $testFiles = array_merge(
        glob($testsDir . '/*Test.php') ?: [],
        glob($testsDir . '/*/*Test.php') ?: []
    );

    $classToFile = [];
    foreach ($testFiles as $file) {
        $content = file_get_contents($file);
        if (preg_match('/class\s+([A-Za-z0-9_]+)\s+extends\s+TestCase/', $content, $m)) {
            $ns = 'MultiTenantSaas\\Tests';
            // Check for subdirectory namespace
            $relPath = str_replace($testsDir . '/', '', $file);
            if (strpos($relPath, '/') !== false) {
                $subDir = explode('/', $relPath)[0];
                $ns .= '\\' . $subDir;
            }
            $classToFile[$ns . '\\' . $m[1]] = $file;
        }
    }

    $fixed = 0;
    foreach ($uniqueClasses as $className => $missingTables) {
        $file = $classToFile[$className] ?? null;
        if (! $file || ! file_exists($file)) {
            continue;
        }

        $content = file_get_contents($file);

        // Determine needed modules
        $neededModules = [];
        foreach ($missingTables as $table) {
            if (isset($tableToModule[$table]) && $tableToModule[$table] !== 'CoreModule') {
                $neededModules[$tableToModule[$table]] = true;
            }
        }

        if (empty($neededModules)) {
            continue;
        }

        // Get existing modules
        $existingModules = [];
        if (preg_match('/protected\s+array\s+\$uses\s*=\s*\[([^\]]*)\]/', $content, $match)) {
            preg_match_all('/([A-Za-z]+Module)::class/', $match[1], $existingMatches);
            $existingModules = $existingMatches[1];
        }

        // Add new modules
        $newModules = [];
        foreach (array_keys($neededModules) as $mod) {
            if (! in_array($mod, $existingModules)) {
                $newModules[] = $mod;
                $existingModules[] = $mod;
            }
        }

        if (empty($newModules)) {
            continue;
        }

        echo basename($file) . ': +' . implode(', ', $newModules) . "\n";

        // Update or add $uses
        if (preg_match('/protected\s+array\s+\$uses\s*=\s*\[[^\]]*\]/', $content)) {
            $newUsesArray = array_map(fn ($m) => "{$m}::class", $existingModules);
            $newUsesStr = 'protected array $uses = [' . implode(', ', $newUsesArray) . ']';
            $content = preg_replace('/protected\s+array\s+\$uses\s*=\s*\[[^\]]*\]/', $newUsesStr, $content);
        } else {
            $usesArray = array_map(fn ($m) => "{$m}::class", $existingModules);
            $usesStr = "\n    protected array \$uses = [" . implode(', ', $usesArray) . "];\n";
            $content = preg_replace('/(extends\s+TestCase\s*\{)/', '$1' . $usesStr, $content);
        }

        // Add use statements
        $missingUseStmts = [];
        foreach ($newModules as $mod) {
            $useStmt = "use MultiTenantSaas\\Tests\\Schema\\{$mod};";
            if (strpos($content, $useStmt) === false) {
                $missingUseStmts[] = $useStmt;
            }
        }

        if (! empty($missingUseStmts)) {
            $lines = explode("\n", $content);
            $lastTopUseIdx = -1;
            foreach ($lines as $i => $line) {
                if (preg_match('/^class\s+/', $line)) {
                    break;
                }
                if (preg_match('/^use\s+.+;$/', $line)) {
                    $lastTopUseIdx = $i;
                }
            }
            if ($lastTopUseIdx >= 0) {
                array_splice($lines, $lastTopUseIdx + 1, 0, $missingUseStmts);
                $content = implode("\n", $lines);
            }
        }

        file_put_contents($file, $content);
        $fixed++;
    }

    echo "Fixed {$fixed} files.\n\n";
}

<?php

declare(strict_types=1);

use CoquiBot\Toolkits\PythonEnv\PythonEnvToolkit;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

uses()->group('arch');

test('PythonEnvToolkit implements ToolkitInterface')
    ->expect(PythonEnvToolkit::class)
    ->toImplement(ToolkitInterface::class);

test('source files use strict types')
    ->expect('CoquiBot\\Toolkits\\PythonEnv')
    ->toUseStrictTypes();

test('runtime classes are final')
    ->expect('CoquiBot\\Toolkits\\PythonEnv\\Runtime')
    ->toBeFinal();

test('tool classes are final and readonly')
    ->expect('CoquiBot\\Toolkits\\PythonEnv\\Tool')
    ->toBeFinal()
    ->toBeReadonly();

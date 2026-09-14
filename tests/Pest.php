<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest Bootstrap
|--------------------------------------------------------------------------
*/

use Mockery as M;

require_once __DIR__ . '/../vendor/autoload.php';

afterEach(function () {
    M::close();
});

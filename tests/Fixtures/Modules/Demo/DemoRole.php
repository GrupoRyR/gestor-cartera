<?php

namespace Tests\Fixtures\Modules\Demo;

/**
 * Proves that permission seeders accept backed enums as role names.
 */
enum DemoRole: string
{
    case Manager = 'gerente_cartera';
}

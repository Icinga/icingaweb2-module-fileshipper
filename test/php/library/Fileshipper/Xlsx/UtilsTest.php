<?php

namespace Tests\Icinga\Module\Fileshipper;

use Icinga\Module\Fileshipper\Xlsx\Utils;
use PHPUnit\Framework\TestCase;

final class UtilsTest extends TestCase
{
    public function testIsValid(): void
    {
        $actual = Utils::toUnixTimeStamp(17000000);
        $expected = 1466590838400.0;

        $this->assertSame($actual, $expected);
    }
}

<?php

namespace Tests\Icinga\Module\Fileshipper;

use Icinga\Module\Fileshipper\ProvidedHook\Director\ImportSource;
use PHPUnit\Framework\TestCase;

final class ImportSourceTest extends TestCase
{
    private $actualData;

    protected function setUp(): void
    {
        $this->actualData = [
            '0' => (object)[
                'host' => 'host1',
                'address' => '127.0.0.1'
            ],
            '1' => (object)[
                'host' => 'host2',
                'address' => '127.0.0.2'
            ]];
    }

    public function testfetchDataWithCSV(): void
    {
        $is = new ImportSource();

        $is->setSettings([
            'basedir' => getcwd() . '/test/config',
            'file_name' => 'test.csv',
            'file_format' => 'csv',
            'csv_delimiter' => ',',
            'csv_enclosure' => '"']
        );


        $this->assertSame($is->getName(), 'Import from files (fileshipper)');
        $this->assertEquals($is->fetchData(), $this->actualData);
    }

    public function testfetchDataWithJSON(): void
    {
        $is = new ImportSource();

        $is->setSettings([
            'basedir' => getcwd() . '/test/config',
            'file_name' => 'test.json',
            'file_format' => 'json']
        );

        $this->assertEquals($is->fetchData(), $this->actualData);
    }

    public function testfetchDataWithYAML(): void
    {
        $is = new ImportSource();

        $is->setSettings([
            'basedir' => getcwd() . '/test/config',
            'file_name' => 'test.yaml',
            'file_format' => 'yaml']
        );

        // Requires php-yaml
        $this->assertEquals($is->fetchData(), $this->actualData);
    }

    public function testfetchDataWithXLSX(): void
    {
        $is = new ImportSource();

        $is->setSettings([
            'basedir' => getcwd() . '/test/config',
            'file_name' => 'test.xlsx',
            'file_format' => 'xslx', // TODO typo, should be xlsx
            'worksheet_addressing' => 'by_name',
            'worksheet_name' => 'Sheet1',
        ]);

        // Requires php-zip
        $this->assertEquals($is->fetchData(), $this->actualData);
    }

    public function testfetchDataWithXML(): void
    {
        $is = new ImportSource();

        $is->setSettings([
            'basedir' => getcwd() . '/test/config',
            'file_name' => 'test.xml',
            'file_format' => 'xml',
        ]);

        // Requires php-xml
        $this->assertEquals($is->fetchData(), $this->actualData);
    }

    public function testlistColumns(): void
    {
        $is = new ImportSource();

        $is->setSettings([
            'basedir' => getcwd() . '/test/config',
            'file_name' => 'test.json',
            'file_format' => 'json']
        );

        $this->assertEquals($is->listColumns(), [0 => 'host', 1 => 'address']);
    }
}

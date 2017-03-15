<?php

namespace Icinga\Module\Fileshipper\ProvidedHook\Director;

use DirectoryIterator;
use Icinga\Application\Config;
use Icinga\Exception\ConfigurationError;
use Icinga\Exception\IcingaException;
use Icinga\Module\Director\Hook\ImportSourceHook;
use Icinga\Module\Director\Web\Form\QuickForm;
use Symfony\Component\Yaml\Yaml;

class ImportSource extends ImportSourceHook
{
    protected $db;

    protected $haveSymfonyYaml;

    public function getName()
    {
        return 'Import from files (fileshipper)';
    }

    public function fetchData()
    {
        $basedir  = $this->getSetting('basedir');
        $filename = $this->getSetting('file_name');
        $format   = $this->getSetting('file_format');

        if ($filename === '*') {
            return $this->fetchFiles($basedir, $format);
        }

        return (array) $this->fetchFile($basedir, $filename, $format);
    }

    public function listColumns()
    {
        return array_keys((array) current($this->fetchData()));
    }

    public static function addSettingsFormFields(QuickForm $form)
    {
        $form->addElement('select', 'file_format', array(
            'label'        => $form->translate('File format'),
            'description'  => $form->translate(
                'Available file formats, usually CSV, JSON, YAML and XML. Whether'
                . ' all of those are available eventually depends on various'
                . ' libraries installed on your system. Please have a look at'
                . ' the documentation in case your list is not complete.'
            ),
            'required'     => true,
            'class'        => 'autosubmit',
            'multiOptions' => $form->optionalEnum(
                static::listAvailableFormats($form)
            ),
        ));

        $format = $form->getSentOrObjectSetting('file_format');

        if ($format === 'csv') {
            $form->addElement('text', 'csv_delimiter', array(
                'label'       => $form->translate('Field delimiter'),
                'description' => $form->translate(
                    'This sets the field delimiter. One character only, defaults'
                    . ' to comma: ,'
                ),
                'value'       => ',',
                'required'    => true,
            ));

            $form->addElement('text', 'csv_enclosure', array(
                'label'       => $form->translate('Value enclosure'),
                'description' => $form->translate(
                    'This sets the field enclosure character. One character only,'
                    . ' defaults to double quote: "'
                ),
                'value'       => '"',
                'required'    => true,
            ));

            /*
            // Not configuring escape as it behaves strangely. "te""st" works fine.
            // Seems that even in case we use \, it must be "manually" removed later
            // on
            $form->addElement('text', 'csv_escape', array(
                'label'       => $form->translate('Escape character'),
                'description' => $form->translate(
                    'This sets the escaping character. One character only,'
                    . ' defaults to backslash: \\'
                ),
                'value'       => '\\',
                'required'    => true,
            ));
            */
        }

        $form->addElement('select', 'basedir', array(
            'label'        => $form->translate('Base directoy'),
            'description'  => sprintf(
                $form->translate(
                    'This import rule will only work with files relative to this'
                    . ' directory. The content of this list depends on your'
                    . ' configuration in "%s"'
                ),
                Config::module('fileshipper', 'imports')->getConfigFile()
            ),
            'required'     => true,
            'class'        => 'autosubmit',
            'multiOptions' => $form->optionalEnum(static::listBaseDirectories()),
        ));


        if (! $format || ! ($basedir = $form->getSentOrObjectSetting('basedir'))) {
            return $form;
        }

        $form->addElement('select', 'file_name', array(
            'label'        => $form->translate('File name'),
            'description'  => $form->translate(
                'Choose a file from the above directory or * to import all files'
                . ' from there at once'
            ),
            'required'     => true,
            'multiOptions' => $form->optionalEnum(self::enumFiles($basedir, $form)),
        ));

        return $form;
    }

    protected function fetchFiles($basedir, $format)
    {
        $result = array();
        foreach (static::listFiles($basedir) as $file) {
            $result[$file] = (object) $this->fetchFile($basedir, $file, $format);
        }

        return $result;
    }

    protected function fetchFile($basedir, $file, $format)
    {
        $filename = $basedir . '/' . $file;

        switch ($format) {
            case 'yaml':
                return $this->readYamlFile($filename);
            case 'json':
                return $this->readJsonFile($filename);
            case 'csv':
                return $this->readCsvFile($filename);
            case 'xml':
                libxml_disable_entity_loader(true);
                return $this->readXmlFile($filename);
            default:
                throw new ConfigurationError(
                    'Unsupported file format: %s',
                    $format
                );
        }
    }

    protected function readCsvFile($filename)
    {
        $fh = fopen($filename, 'r');
        $lines = array();
        $delimiter = $this->getSetting('csv_delimiter');
        $enclosure = $this->getSetting('csv_enclosure');
        // $escape    = $this->getSetting('csv_escape');

        $headers = fgetcsv($fh, 0, $delimiter, $enclosure/*, $escape*/);
        $row = 1;
        while ($line = fgetcsv($fh, 0, $delimiter, $enclosure/*, $escape*/)) {
            if (empty($line)) {
                continue;
            }
            if (count($headers) !== count($line)) {
                throw new IcingaException(
                    'Column count in row %d does not match columns in header row',
                    $row
                );
            }

            $line = array_combine($headers, $line);
            foreach ($line as $key => & $value) {
                if ($value === '') {
                    $value = null;
                }
            }
            $lines[] = (object) $line;

            $row ++;
        }
        fclose($fh);

        return $lines;
    }

    protected function readJsonFile($filename)
    {
        $content = @file_get_contents($filename);
        if ($content === false) {
            throw new IcingaException(
                'Unable to read JSON file "%s"',
                $filename
            );
        }

        $data = @json_decode($content);
        if ($data === null) {
            throw new IcingaException(
                'Unable to load JSON data'
            );
        }

        return $data;
    }

    protected function readXmlFile($file)
    {
        $lines = array();
        $content = file_get_contents($file);
        foreach (simplexml_load_string($content) as $entry) {
            $line = null;
            $lines[] = $this->normalizeSimpleXML($entry);
        }

        return $lines;
    }

    protected function normalizeSimpleXML($obj)
    {
        $data = $obj;
        if (is_object($data)) {
            $data = (object) get_object_vars($data);
        }

        if (is_object($data)) {
            foreach ($data as $key => $value) {
                $data->$key = $this->normalizeSimpleXml($value);
            }
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->normalizeSimpleXml($value);
            }
        }

        return $data;
    }

    protected function readYamlFile($file)
    {
        return $this->fixYamlObjects(
            yaml_parse_file($file)
        );
    }

    protected function fixYamlObjects($what)
    {
        if (is_array($what)) {
            foreach (array_keys($what) as $key) {
                if (! is_int($key)) {
                    $what = (object) $what;
                    break;
                }
            }
        }

        if (is_array($what) || is_object($what)) {
            foreach ($what as $k => $v) {
                if (! empty($v)) {
                    if (is_object($what)) {
                        $what->$k = $this->fixYamlObjects($v);
                    } elseif (is_array($what)) {
                        $what[$k] = $this->fixYamlObjects($v);
                    }
                }
            }
        }

        return $what;
    }

    protected static function listAvailableFormats(QuickForm $form)
    {
        $formats = array(
            'csv'  => $form->translate('CSV (Comma Separated Value)'),
            'json' => $form->translate('JSON (JavaScript Object Notation)'),
        );

        if (function_exists('simplexml_load_file')) {
            $formats['xml'] = $form->translate('XML (Extensible Markup Language)');
        }

        if (function_exists('yaml_parse_file')) {
            $formats['yaml'] = $form->translate('YAML (Ain\'t Markup Language)');
        }

        return $formats;
    }

    protected static function listBaseDirectories()
    {
        $dirs = array();

        foreach (Config::module('fileshipper', 'imports') as $key => $section) {
            if (($dir = $section->get('basedir')) && @is_dir($dir)) {
                $dirs[$dir] = $key;
            }
        }

        return $dirs;
    }

    protected static function enumFiles($basedir, QuickForm $form)
    {
        return array_merge(
            array(
                '*' => sprintf('* (%s)', $form->translate('all files'))
            ),
            static::listFiles($basedir)
        );
    }

    protected static function listFiles($basedir)
    {
        $files = array();

        $dir = new DirectoryIterator($basedir);
        foreach ($dir as $file) {
            if ($file->isFile()) {
                $filename = $file->getBasename();
                if ($filename[0] !== '.') {
                    $files[$filename] = $filename;
                }
            }
        }

        ksort($files);

        return $files;
    }
}

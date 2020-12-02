<?php

namespace Icinga\Module\Fileshipper\ProvidedHook\Director;

use DirectoryIterator;
use Icinga\Application\Config;
use Icinga\Exception\ConfigurationError;
use Icinga\Exception\IcingaException;
use Icinga\Module\Director\Exception\JsonException;
use Icinga\Module\Director\Hook\ImportSourceHook;
use Icinga\Module\Director\Web\Form\QuickForm;
use Icinga\Module\Fileshipper\Xlsx\Workbook;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class ImportSource extends ImportSourceHook
{
    protected $db;

    protected $haveSymfonyYaml;

    public function getName()
    {
        return 'Import from files (fileshipper)';
    }

    /**
     * @return object[]
     * @throws ConfigurationError
     * @throws IcingaException
     */
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

    /**
     * @return array
     * @throws ConfigurationError
     * @throws IcingaException
     */
    public function listColumns()
    {
        return array_keys((array) current($this->fetchData()));
    }

    /**
     * @param QuickForm $form
     * @return \Icinga\Module\Director\Forms\ImportSourceForm|QuickForm
     * @throws \Zend_Form_Exception
     */
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

        /** @var \Icinga\Module\Director\Forms\ImportSourceForm $form */
        $format = $form->getSentOrObjectSetting('file_format');

        try {
            $configFile = Config::module('fileshipper', 'imports')->getConfigFile();
            $directories = static::listBaseDirectories();
            $ignored = static::listIgnoredBaseDirectories();
            $e = null;
        } catch (\Throwable $e) {
            $configFile = null;
            $directories = [];
            $ignored = [];
        } catch (\Exception $e) {
            $configFile = null;
            $directories = [];
            $ignored = [];
        }
        $form->addElement('select', 'basedir', array(
            'label'        => $form->translate('Base directory'),
            'description'  => sprintf(
                $form->translate(
                    'This import rule will only work with files relative to this'
                    . ' directory. The content of this list depends on your'
                    . ' configuration in "%s"'
                ),
                $configFile
            ),
            'required'     => true,
            'class'        => 'autosubmit',
            'multiOptions' => $form->optionalEnum($directories),
        ));
        if ($configFile === null) {
            if ($e) {
                $form->getElement('basedir')->addError(sprintf(
                    $form->translate(
                        'Failed to get directories from Fileshipper configuration: %s'
                    ),
                    $e->getMessage()
                ));
            }
        } elseif (empty($directories)) {
            $dirElement = $form->getElement('basedir');
            if (! @file_exists($configFile)) {
                $dirElement->addError(\sprintf(
                    'The file "%s" does not exist or is not accessible',
                    $configFile
                ));
            }
        }

        if (! empty($ignored)) {
            $list = [];
            foreach ($ignored as $ignoredDirName => $section) {
                $list[] = "$section: $ignoredDirName";
            }
            $ignoredString = \implode(', ', $list);
            if (count($list) === 1) {
                $errorString = 'The following directory has been ignored: %s';
            } else {
                $errorString = 'The following directories have been ignored: %s';
            }
            $form->addHtmlHint(\sprintf($errorString, $ignoredString));
        }

        if (! ($basedir = $form->getSentOrObjectSetting('basedir'))) {
            return $form;
        }

        $form->addElement('select', 'file_name', array(
            'label'        => $form->translate('File name'),
            'description'  => $form->translate(
                'Choose a file from the above directory or * to import all files'
                . ' from there at once'
            ),
            'required' => true,
            'class'    => 'autosubmit',
            'multiOptions' => $form->optionalEnum(self::enumFiles($basedir, $form)),
        ));

        $basedir = $form->getSentOrObjectSetting('basedir');
        $basename = $form->getSentOrObjectSetting('file_name');
        if ($basedir === null || $basename === null) {
            return $form;
        }

        $filename = sprintf('%s/%s', $basedir, $basename);
        switch ($format) {
            case 'csv':
                static::addCsvElements($form);
                break;

            case 'xslx':
                static::addXslxElements($form, $filename);
                break;
        }

        return $form;
    }

    /**
     * @param QuickForm $form
     * @throws \Zend_Form_Exception
     */
    protected static function addCsvElements(QuickForm $form)
    {
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

    /**
     * @param QuickForm $form
     * @param $filename
     * @throws \Zend_Form_Exception
     */
    protected static function addXslxElements(QuickForm $form, $filename)
    {
        $form->addElement('select', 'worksheet_addressing', array(
            'label'        => $form->translate('Choose worksheet'),
            'description'  => $form->translate('How to choose a worksheet'),
            'multiOptions' => array(
                'by_position' => $form->translate('by position'),
                'by_name'     => $form->translate('by name'),
            ),
            'value'    => 'by_position',
            'class'    => 'autosubmit',
            'required' => true,
        ));

        /** @var \Icinga\Module\Director\Forms\ImportSourceForm $form */
        $addressing = $form->getSentOrObjectSetting('worksheet_addressing');
        switch ($addressing) {
            case 'by_name':
                $file = static::loadXslxFile($filename);
                $names = $file->getSheetNames();
                $names = array_combine($names, $names);
                $form->addElement('select', 'worksheet_name', array(
                    'label'    => $form->translate('Name'),
                    'required' => true,
                    'value'    => $file->getFirstSheetName(),
                    'multiOptions' => $names,
                ));
                break;

            case 'by_position':
            default:
                $form->addElement('text', 'worksheet_position', array(
                    'label'    => $form->translate('Position'),
                    'required' => true,
                    'value'    => '1',
                ));
                break;
        }
    }

    /**
     * @param $basedir
     * @param $format
     * @return array
     * @throws ConfigurationError
     * @throws IcingaException
     */
    protected function fetchFiles($basedir, $format)
    {
        $result = array();
        foreach (static::listFiles($basedir) as $file) {
            $result[$file] = (object) $this->fetchFile($basedir, $file, $format);
        }

        return $result;
    }

    /**
     * @param $basedir
     * @param $file
     * @param $format
     * @return object[]
     * @throws ConfigurationError
     * @throws IcingaException
     */
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
            case 'xslx':
                return $this->readXslxFile($filename);
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

    /**
     * @param $filename
     * @return Workbook
     */
    protected static function loadXslxFile($filename)
    {
        return new Workbook($filename);
    }

    /**
     * @param $filename
     * @return array
     */
    protected function readXslxFile($filename)
    {
        $xlsx = new Workbook($filename);
        if ($this->getSetting('worksheet_addressing') === 'by_name') {
            $sheet = $xlsx->getSheetByName($this->getSetting('worksheet_name'));
        } else {
            $sheet = $xlsx->getSheet((int) $this->getSetting('worksheet_position'));
        }

        $data = $sheet->getData();

        $headers = null;
        $result = [];
        foreach ($data as $line) {
            if ($headers === null) {
                $hasValue = false;
                foreach ($line as $value) {
                    if ($value !== null) {
                        $hasValue = true;
                        break;
                    }
                    // For now, no value in the first column means this is no header
                    break;
                }
                if ($hasValue) {
                    $headers = $line;
                }

                continue;
            }

            $row = [];
            foreach ($line as $key => $val) {
                if (empty($headers[$key])) {
                    continue;
                }
                $row[$headers[$key]] = $val;
            }

            $result[] = (object) $row;
        }

        return $result;
    }

    /**
     * @param $filename
     * @return object[]
     */
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
                throw new RuntimeException(sprintf(
                    'Column count in row %d does not match columns in header row',
                    $row
                ));
            }

            $line = array_combine($headers, $line);
            foreach ($line as $key => & $value) {
                if ($value === '') {
                    $value = null;
                }
            }
            unset($value);
            $lines[] = (object) $line;

            $row ++;
        }
        fclose($fh);

        return $lines;
    }

    /**
     * @param $filename
     * @return object[]
     */
    protected function readJsonFile($filename)
    {
        $content = @file_get_contents($filename);
        if ($content === false) {
            throw new RuntimeException(sprintf(
                'Unable to read JSON file "%s"',
                $filename
            ));
        }

        $data = @json_decode($content);
        if ($data === null) {
            throw JsonException::forLastJsonError('Unable to load JSON data');
        }

        return $data;
    }

    /**
     * @param $file
     * @return object[]
     */
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

    /**
     * @param $object
     * @return object
     */
    protected function normalizeSimpleXML($object)
    {
        $data = $object;
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

    /**
     * @param $file
     * @return object[]
     */
    protected function readYamlFile($file)
    {
        return $this->fixYamlObjects(
            yaml_parse_file($file)
        );
    }

    /**
     * @param $what
     * @return object[]
     */
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

    /**
     * @param QuickForm $form
     * @return array
     */
    protected static function listAvailableFormats(QuickForm $form)
    {
        $formats = array(
            'csv'  => $form->translate('CSV (Comma Separated Value)'),
            'json' => $form->translate('JSON (JavaScript Object Notation)'),
        );

        if (class_exists('\\ZipArchive')) {
            $formats['xslx'] = $form->translate('XSLX (Microsoft Excel 2007+)');
        }

        if (function_exists('simplexml_load_file')) {
            $formats['xml'] = $form->translate('XML (Extensible Markup Language)');
        }

        if (function_exists('yaml_parse_file')) {
            $formats['yaml'] = $form->translate('YAML (Ain\'t Markup Language)');
        }

        return $formats;
    }

    /**
     * @return array
     */
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

    /**
     * @return array
     */
    protected static function listIgnoredBaseDirectories()
    {
        $dirs = array();

        foreach (Config::module('fileshipper', 'imports') as $key => $section) {
            if (($dir = $section->get('basedir')) && @is_dir($dir)) {
                // Ignore them
            } else {
                $dirs[$dir] = $key;
            }
        }

        return $dirs;
    }

    /**
     * @param $basedir
     * @param QuickForm $form
     * @return array
     */
    protected static function enumFiles($basedir, QuickForm $form)
    {
        return array_merge(
            array(
                '*' => sprintf('* (%s)', $form->translate('all files'))
            ),
            static::listFiles($basedir)
        );
    }

    /**
     * @param $basedir
     * @return array
     */
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

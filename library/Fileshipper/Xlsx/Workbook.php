<?php

namespace Icinga\Module\Fileshipper\Xlsx;

use Icinga\Exception\IcingaException;
use ZipArchive;

/**
 * Classes in this namespace have been built roughly based on various OSS
 * XLSXReader implementations
 */
class Workbook
{
    // XML schemas
    const SCHEMA_OFFICEDOCUMENT  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument';
    const SCHEMA_RELATIONSHIP    = 'http://schemas.openxmlformats.org/package/2006/relationships';
    const SCHEMA_OFFICEDOCUMENT_RELATIONSHIP = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    const SCHEMA_SHAREDSTRINGS     = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings';
    const SCHEMA_WORKSHEETRELATION = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet';

    protected static $zipErrors = [
        ZipArchive::ER_EXISTS => 'File already exists',
        ZipArchive::ER_INCONS => 'Zip archive inconsistent',
        ZipArchive::ER_INVAL  => 'Invalid argument',
        ZipArchive::ER_MEMORY => 'Malloc failure',
        ZipArchive::ER_NOENT  => 'No such file',
        ZipArchive::ER_NOZIP  => 'Not a zip archive',
        ZipArchive::ER_OPEN   => 'Can\'t open file',
        ZipArchive::ER_READ   => 'Read error',
        ZipArchive::ER_SEEK   => 'Seek error',
    ];

    /** @var Worksheet[] */
    protected $sheets = [];

    public $sharedStrings = [];

    protected $sheetInfo;

    /** @var ZipArchive */
    protected $zip;

    public $config = [
        'removeTrailingRows' => true
    ];

    protected $mainRelation;

    public function __construct($filename, $config = [])
    {
        $this->config = array_merge($this->config, $config);
        $this->initialize($filename);
    }

    protected function initialize($filename)
    {
        $this->zip = new ZipArchive();
        if (true === ($result = $this->zip->open($filename))) {
            $this->parse();
        } else {
            throw new IcingaException(
                'Failed to open %s : %s',
                $filename,
                $this->getZipErrorString($result)
            );
        }
    }

    protected function getZipErrorString($errorCode)
    {
        if (array_key_exists($errorCode, self::$zipErrors)) {
            return self::$zipErrors[$errorCode];
        } else {
            return "Unknown ZIP error code $errorCode";
        }
    }

    // get a file from the zip
    protected function extractFile($name)
    {
        $data = $this->zip->getFromName($name);
        if($data === false) {
            throw new IcingaException(
                "File %s does not exist in the Excel file",
                $name
            );
        } else {
            return $data;
        }
    }

    protected function loadPackageRelationshipXml()
    {
        return simplexml_load_string($this->extractFile('_rels/.rels'));
    }

    /**
     * @return \SimpleXMLElement[]
     */
    protected function getPackageRelationships()
    {
        return $this->loadPackageRelationshipXml()->Relationship;
    }

    // workbookXML
    protected function getMainDocumentRelation()
    {
        if ($this->mainRelation === null) {
            foreach($this->getPackageRelationships() as $relation) {
                if ($relation['Type'] == self::SCHEMA_OFFICEDOCUMENT) {
                    $this->mainRelation = $relation;
                    break;
                }
            }

            if ($this->mainRelation === null) {
                throw new IcingaException(
                    'Got invalid Excel file, found no main document'
                );
            }
        }

        return $this->mainRelation;
    }

    protected function getWorkbookXml()
    {
        return simplexml_load_string(
            $this->extractFile(
                $this->getMainDocumentRelation()['Target']
            )
        );
    }

    protected function getWorkbookDir()
    {
        return dirname($this->getMainDocumentRelation()['Target']);
    }

    /**
     * @return \SimpleXMLElement[]
     */
    protected function getWorkbookRelationShips()
    {
        $wbDir = $this->getWorkbookDir();
        $target = basename($this->getMainDocumentRelation()['Target']);

        return simplexml_load_string(
            $this->extractFile("$wbDir/_rels/$target.rels")
        )->Relationship;
    }

    // extract the shared string and the list of sheets
    protected function parse()
    {
        $sheets = [];
        /** @var \SimpleXMLElement $sheet */
        foreach ($this->getWorkbookXml()->sheets->sheet as $sheet) {
            $r = $sheet->attributes('r', true);
            $sheets[(string)$r->id] = [
                'sheetId' => (int)$sheet['sheetId'],
                'name'    => (string)$sheet['name']
            ];
        }

        $workbookDir = $this->getWorkbookDir() . '/';
        foreach ($this->getWorkbookRelationShips() as $relation) {
            switch ($relation['Type']) {
                case self::SCHEMA_WORKSHEETRELATION:
                    $sheets[(string) $relation['Id']]['path'] = $workbookDir . (string)$relation['Target'];
                    break;

                case self::SCHEMA_SHAREDSTRINGS:
                    $sharedStringsXML = simplexml_load_string(
                        $this->extractFile($workbookDir . $relation['Target'])
                    );

                    foreach($sharedStringsXML->si as $val) {
                        if (isset($val->t)) {
                            $this->sharedStrings[] = (string)$val->t;
                        } elseif (isset($val->r)) {
                            $this->sharedStrings[] = Utils::parseRichText($val);
                        }
                    }

                    break;
            }
        }

        $this->sheetInfo = [];
        foreach ($sheets as $rid=>$info) {
            $this->sheetInfo[$info['name']] = [
                'sheetId' => $info['sheetId'],
                'rid'     => $rid,
                'path'    => $info['path']
            ];
        }
    }

    // returns an array of sheet names, indexed by sheetId
    public function getSheetNames()
    {
        $res = array();
        foreach($this->sheetInfo as $sheetName=>$info) {
            $res[$info['sheetId']] = $sheetName;
        }

        return $res;
    }

    public function getSheetCount()
    {
        return count($this->sheetInfo);
    }

    // instantiates a sheet object (if needed) and returns an array of its data
    public function getSheetData($sheetNameOrId)
    {
        $sheet = $this->getSheet($sheetNameOrId);

        return $sheet->getData();
    }

    // instantiates a sheet object (if needed) and returns the sheet object
    public function getSheet($sheet)
    {
        if(is_numeric($sheet)) {
            $sheet = $this->getSheetNameById($sheet);
        } elseif(!is_string($sheet)) {
            throw new IcingaException("Sheet must be a string or a sheet Id");
        }
        if(!array_key_exists($sheet, $this->sheets)) {
            $this->sheets[$sheet] = new Worksheet($this->getSheetXML($sheet), $sheet, $this);
        }

        return $this->sheets[$sheet];
    }

    public function getSheetNameById($sheetId)
    {
        foreach($this->sheetInfo as $sheetName=>$sheetInfo) {
            if($sheetInfo['sheetId'] === $sheetId) {
                return $sheetName;
            }
        }

        throw new IcingaException(
            "Sheet ID %s does not exist in the Excel file",
            $sheetId
        );
    }

    protected function getSheetXML($name)
    {
        return simplexml_load_string(
            $this->extractFile($this->sheetInfo[$name]['path'])
        );
    }
}

<?php

namespace Icinga\Module\Fileshipper\Xlsx;

use RuntimeException;

class Worksheet
{
    /** @var Workbook */
    protected $workbook;

    /** @var string */
    public $name;

    /** @var array */
    protected $data;

    /** @var int */
    public $rowCount;

    /** @var int */
    public $colCount;

    /** @var array */
    protected $config;

    /** @var array */
    protected $mergeTarget;

    public function __construct($xml, $sheetName, Workbook $workbook)
    {
        $this->config = $workbook->config;
        $this->name = $sheetName;
        $this->workbook = $workbook;
        $this->parse($xml);
    }

    // returns an array of the data from the sheet
    public function getData()
    {
        return $this->data;
    }

    protected function parse($xml)
    {
        $this->parseDimensions($xml->dimension);
        $this->parseMergeCells($xml->mergeCells);
        $this->parseData($xml->sheetData);
    }

    protected function parseDimensions($dimensions)
    {
        $range = (string) $dimensions['ref'];
        $cells = explode(':', $range);
        $maxValues = $this->getColumnIndex($cells[1]);
        $this->colCount = $maxValues[0] + 1;
        $this->rowCount = $maxValues[1] + 1;
    }

    protected function parseMergeCells($merges)
    {
        $result = [];

        if ($merges->mergeCell === null) {
            $this->mergeTarget = $result;
            return;
        }

        foreach ($merges->mergeCell as $merge) {
            $range = (string) $merge['ref'];
            $cells = explode(':', $range);
            $fromName = $cells[0];
            list($fromCol, $fromRow) = $this->getColumnIndex($fromName);
            list($toCol, $toRow) = $this->getColumnIndex($cells[1]);
            for ($i = $fromCol; $i <= $toCol; $i++) {
                for ($j = $fromRow; $j <= $toRow; $j++) {
                    if ($i !== $fromCol || $j !== $fromRow) {
                        $result[$j][$i] = [$fromRow, $fromCol];
                    }
                }
            }
        }

        $this->mergeTarget = $result;
    }

    protected function parseData($sheetData)
    {
        $rows = [];
        $curR = 0;
        $lastDataRow = -1;

        foreach ($sheetData->row as $row) {
            $rowNum = (int) $row['r'];
            if ($rowNum != ($curR + 1)) {
                $missingRows = $rowNum - ($curR + 1);
                for ($i = 0; $i < $missingRows; $i++) {
                    $rows[$curR] = array_pad([], $this->colCount, null);
                    $curR++;
                }
            }
            $curC = 0;
            $rowData = [];

            foreach ($row->c as $c) {
                list($cellIndex,) = $this->getColumnIndex((string) $c['r']);
                if ($cellIndex !== $curC) {
                    $missingCols = $cellIndex - $curC;
                    for ($i = 0; $i < $missingCols; $i++) {
                        $rowData[$curC] = null;
                        $curC++;
                    }
                }
                $val = $this->parseCellValue($c);

                if (!is_null($val)) {
                    $lastDataRow = $curR;
                }
                $rowData[$curC] = $val;
                $curC++;
            }
            $rows[$curR] = array_pad($rowData, $this->colCount, null);

            // We clone merged cells, all of them will return the same value
            // This behavior might eventually become optional with a related
            // Config flag
            if (array_key_exists($curR, $this->mergeTarget)) {
                foreach ($this->mergeTarget[$curR] as $col => $cell) {
                    if ($rowData[$col] === null) {
                        $rows[$curR][$col] = $rows[$cell[0]][$cell[1]];
                    } else {
                        throw new RuntimeException(sprintf(
                            '%s should merge into %s, but %s already has a value: %s',
                            $this->makeCellName($cell[0], $cell[1]),
                            $this->makeCellName($curR, $col),
                            $this->makeCellName($curR, $col),
                            $rowData[$col]
                        ));
                    }
                }
            }

            $curR++;
        }

        if ($this->config['removeTrailingRows']) {
            $this->data = array_slice($rows, 0, $lastDataRow + 1);
            $this->rowCount = count($this->data);
        } else {
            $this->data = $rows;
        }
    }

    protected function getColumnIndex($cell = 'A1')
    {
        if (preg_match('/([A-Z]+)(\d+)/', $cell, $matches)) {
            $col = $matches[1];
            $row = $matches[2];
            $colLen = strlen($col);
            $index = 0;

            for ($i = $colLen-1; $i >= 0; $i--) {
                $index += (ord($col{$i}) - 64) * pow(26, $colLen - $i - 1);
            }

            return [$index - 1, $row - 1];
        }

        throw new RuntimeException(sprintf('Invalid cell index %s', $cell));
    }

    protected function makeCellName($column, $row)
    {
        $str = '';

        $rem = $column + 1;
        while ($rem > 0) {
            $mod = $rem % 26;
            $str = chr($mod + 64) . $str;
            $rem = ($rem - $mod) / 26;
        }

        return $str . (string) ($row + 1);
    }

    protected function parseCellValue($cell)
    {
        // t is the cell type
        switch ((string) $cell['t']) {
            // Shared string
            case 's':
                if ((string) $cell->v != '') {
                    $value = $this->workbook->sharedStrings[intval($cell->v)];
                } else {
                    $value = '';
                }
                break;

            // Boolean
            case 'b':
                $value = (string)$cell->v;
                if ($value === '0') {
                    $value = false;
                } elseif ($value == '1') {
                    $value = true;
                } else {
                    $value = (bool) $cell->v;
                }
                break;

            // Inline rich text
            case 'inlineStr':
                $value = Utils::parseRichText($cell->is);
                break;

            // Error message
            case 'e':
                if ((string)$cell->v != '') {
                    $value = (string)$cell->v;
                } else {
                    $value = '';
                }
                break;

            default:
                if (!isset($cell->v)) {
                    return null;
                }
                $value = (string) $cell->v;

                // Check for numeric values
                if (is_numeric($value)) {
                    if ($value == (int) $value) {
                        $value = (int) $value;
                    } elseif ($value == (float) $value) {
                        $value = (float) $value;
                    } elseif ($value == (double) $value) {
                        $value = (double) $value;
                    }
                }
        }

        return $value;
    }
}

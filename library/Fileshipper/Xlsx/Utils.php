<?php

namespace Icinga\Module\Fileshipper\Xlsx;

class Utils
{
    /**
     * Extract text content from a rich text or inline string field
     * @param null $is
     * @return string
     */
    public static function parseRichText($is = null)
    {
        $value = array();
        if (isset($is->t)) {
            $value[] = (string)$is->t;
        } else {
            foreach ($is->r as $run) {
                $value[] = (string)$run->t;
            }
        }

        return implode(' ', $value);
    }

    // converts an Excel date field (a number) to a unix timestamp (granularity: seconds)
    public static function toUnixTimeStamp($excelDateTime)
    {
        if(!is_numeric($excelDateTime)) {
            return $excelDateTime;
        }
        $d = floor($excelDateTime); // seconds since 1900
        $t = $excelDateTime - $d;
        return ($d > 0) ? ( $d - 25569 ) * 86400 + $t * 86400 : $t * 86400;
    }
}

<?php

namespace Icinga\Module\Fileshipper\ProvidedHook\Director;

use Exception;
use Icinga\Application\Config;
use Icinga\Module\Director\Hook\ShipConfigFilesHook;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

class ShipConfigFiles extends ShipConfigFilesHook
{
    public function fetchFiles()
    {
        $files = array();
        foreach ($this->getDirectories() as $key => $cfg) {

            try {

                foreach ($this->listFiles($cfg->get('source'), $cfg->get('extensions')) as $file) {
                    try {
                        $files[$cfg->target . '/' . $file] = file_get_contents($cfg->get('source') . '/' . $file);
                    } catch (Exception $e) {
                        $files[$cfg->target . '/' . $file] = '/* ' . $e->getMessage() . ' */';
                    }
                }

            } catch (Exception $e) {
                $files[$cfg->target . '/ERROR.txt'] = '/* ' . $e->getMessage() . ' */';
            }
        }

        return $files;
    }

    protected function listFiles($folder, $extensions)
    {
        if (! $extensions) {
            $pattern = '/^[^\.].+\.conf$/';
        } else {
            $exts = array();
            foreach (preg_split('/\s+/', $extensions, -1, PREG_SPLIT_NO_EMPTY) as $ext) {
                $exts[] = preg_quote($ext, '/');
            }

            $pattern = '/^[^\.].+(?:' . implode('|', $exts) . ')$/';
        }

        $dir = new RecursiveDirectoryIterator($folder);
        $ite = new RecursiveIteratorIterator($dir);
        $files = new RegexIterator($ite, $pattern, RegexIterator::GET_MATCH);
        $fileList = array();
        $start = strlen($folder) + 1;

        foreach($files as $file) {
            foreach ($file as $f) {
                $fileList[] =  substr($f, $start);
            }
        }
        return $fileList;
    }

    protected function getDirectories()
    {
        return Config::module('fileshipper', 'directories');
        $config = Config::module('fileshipper', 'directories');
        $dirs = array();
        foreach ($config as $key => $c) {
            $dirs[$key] = (object) $c->toArray();
        }

        return $dirs;
    }
}

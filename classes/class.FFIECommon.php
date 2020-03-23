<?php

namespace FFIExport;

use Spatie\Regex\MatchResult;
use Spatie\Regex\Regex;
use Spatie\Regex\RegexFailed;

class FFIECommon
{
    /**
     * Validate and create directory
     *
     * @param $path
     * @return bool
     */
    public static function checkDirectory($path)
    {
        if (!is_dir($path)) {
            return mkdir($path, 0777, true);
        }
        return true;
    }

    /**
     *
     * @param array $item
     * @param int $pad = 5
     * @return string
     */
    public static function getExportFilename($item, $pad = 5)
    {
        if (getenv('EXPORT.NAME_BY_TYPE') == 'directory') {
            if ($item['type'] === 'articles') {
                $filename = getenv('PATH.EXPORT.ARTICLES') . DIRECTORY_SEPARATOR;
            } elseif ($item['type'] === "news") {
                $filename = getenv('PATH.EXPORT.NEWS') . DIRECTORY_SEPARATOR;
            } elseif ($item['type'] === 'page') {
                $filename = getenv('PATH.EXPORT.PAGES') . DIRECTORY_SEPARATOR;
            } elseif ($item['type'] === 'place') {
                $filename = getenv('PATH.EXPORT.PLACES') . DIRECTORY_SEPARATOR;
            } elseif ($item['type'] === 'district') {
                $filename = getenv('PATH.EXPORT.DISTRICTS') . DIRECTORY_SEPARATOR;
            } else {
                $filename = getenv('PATH.EXPORT.ALL') . DIRECTORY_SEPARATOR;
            }
        } elseif (getenv('EXPORT.NAME_BY_TYPE') == 'file') {
            $filename = getenv('PATH.EXPORT.ALL') . DIRECTORY_SEPARATOR . $item['type'] . '-';
        } else {
            $filename = getenv('PATH.EXPORT.ALL') . DIRECTORY_SEPARATOR . 'item-';
        }
        $filename .= str_pad($item['id'], $pad, '0', STR_PAD_LEFT) . '.json';

        return $filename;
    }

    /**
     * @param string $filename
     * @param array $data
     * @return false|int
     */
    public static function exportJSON($filename, $data)
    {
        return file_put_contents($filename, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * Форматирует дату в ISO
     *
     * @param $date_string
     * @return false|string
     */
    public static function _date_format($date_string)
    {
        return date('c', strtotime($date_string));
    }


    /**
     * @param $filepath
     * @return bool
     */
    public static function _is_file_present($filepath)
    {
        return
                getenv('EXPORT.MEDIA.ONLY_PRESENT_FILES')
                ? is_file($filepath)
                : true;
    }

    /**
     * @param $target
     * @param $filename
     * @param $path_storage
     * @param $path_full
     * @return bool
     */
    public static function _get_file_info(&$target, $filename, $path_storage, $path_full)
    {
        if (self::_is_file_present($path_full . $filename)) {
            $target = [
                'file'  =>  $path_storage . $filename,
                'size'  =>  @filesize($path_full . $filename),
                'mime'  =>  @mime_content_type($path_full . $filename)
            ];
            return true;
        }
        return false;
    }

    /**
     *
     * @param $string
     * @return bool
     */
    public static function _isBase64($string)
    {
        return (base64_decode($string, true) !== false);
    }

    /**
     * Применяет trim если установлена соотв. переменная конфига
     *
     * @param $value
     * @param $env
     * @return string
     */
    public static function _trim($value, $env)
    {
        return getenv($env) ? trim($value) : $value;
    }

    /**
     *
     * @param $html
     * @return bool|string
     * @throws RegexFailed
     */
    public static function checkExternalLink($html)
    {
        $pattern = '/\<meta\shttp-equiv="refresh"\scontent=";url=((https?):\/\/(-\.)?([^\s\/?\.#-]+\.?)+(\/[^\s]*)?)\"\>/';
        /**
         * @param MatchResult $match
         */
        $match = Regex::match($pattern, $html);

        if ($match->hasMatch()) {
            return $match->group(1);
        }

        return false;
    }

} // class

# -eof-

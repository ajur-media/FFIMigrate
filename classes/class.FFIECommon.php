<?php

use Spatie\Regex\Regex;

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
     * @param $article
     * @return string
     */
    public static function getExportFilename($article)
    {
        $filename = '';
        if (getenv('EXPORT.NAME_BY_TYPE') == 'directory') {
            if ($article['type'] === 'articles') {
                $filename = getenv('PATH.EXPORT.ARTICLES') . DIRECTORY_SEPARATOR;
            } elseif ($article['type'] === "news") {
                $filename = getenv('PATH.EXPORT.NEWS') . DIRECTORY_SEPARATOR;
            } else {
                $filename = getenv('PATH.EXPORT.ALL') . DIRECTORY_SEPARATOR;
            }
        } elseif (getenv('EXPORT.NAME_BY_TYPE') == 'file') {
            $filename = getenv('PATH.EXPORT.ALL') . DIRECTORY_SEPARATOR . $article['type'] . '-';
        } else {
            $filename = getenv('PATH.EXPORT.ALL') . DIRECTORY_SEPARATOR . 'item-';
        }
        $filename .= str_pad($article['id'], 5, '0', STR_PAD_LEFT) . '.json';

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
     * Проверяет существование файла и если он существует - записывает его в соотв поле target
     * @param $target
     * @param $filepath
     */
    public static function _check_file(&$target, $filepath)
    {
        if (getenv('EXPORT.MEDIA.ONLY_PRESENT_FILES')) {
            if (is_file($filepath)) {
                $target = $filepath;
            };
        } else {
            $target = $filepath;
        }

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
     * @throws \Spatie\Regex\RegexFailed
     */
    public static function checkExternalLink($html)
    {
        $pattern = '/\<meta\shttp-equiv="refresh"\scontent=";url=((https?):\/\/(-\.)?([^\s\/?\.#-]+\.?)+(\/[^\s]*)?)\"\>/';
        /**
         * @param \Spatie\Regex\MatchResult $match
         */
        $match = Regex::match($pattern, $html);

        if ($match->hasMatch()) {
            return $match->group(1);
        }

        return false;
    }

}
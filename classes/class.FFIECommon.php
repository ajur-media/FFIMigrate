<?php


class FFIECommon
{
    public static $query_get_article = "
SELECT
	a.*,
    adm.login AS author_login
FROM 
	articles AS a
LEFT JOIN admin AS adm ON a.author_id = adm.id 	
WHERE
	a.id = :id
";


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

    public static function getExportFilename($article)
    {
        $filename = '';
        if (getenv('EXPORT.SEPARATE_BY_TYPE')) {
            if ($article['type'] === 'articles') {
                $filename = getenv('PATH.EXPORT.ARTICLES') . DIRECTORY_SEPARATOR;
            } elseif ($article['type'] === "news") {
                $filename = getenv('PATH.EXPORT.NEWS') . DIRECTORY_SEPARATOR;
            } else {
                $filename = getenv('PATH.EXPORT.ALL') . DIRECTORY_SEPARATOR;
            }
        } else {
            $filename = getenv('PATH.EXPORT.ALL') . DIRECTORY_SEPARATOR . 'item-';
        }
        $filename .= str_pad($article['id'], 5, '0', STR_PAD_LEFT) . '.json';

        return $filename;
    }

    public static function exportJSON($filename, $article_export)
    {
        return file_put_contents($filename, json_encode($article_export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
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

}
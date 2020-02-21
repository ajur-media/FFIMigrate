#!/usr/bin/php
<?php
ini_set('memory_limit','256M');

use Arris\AppLogger;
use Arris\CLIConsole;
use Arris\DB;
use Monolog\Logger;
use Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/classes/class.ArticleExporter.php';
require_once __DIR__ . '/classes/class.FFIECommon.php';

Dotenv::create(__DIR__, '_env')->load();

AppLogger::init('FFI_EXPORT', basename(__FILE__));
AppLogger::addScope('main', [
    [ 'export.log', Logger::DEBUG ]
]);

try {
    $export_directory = getenv('PATH.EXPORT.ALL');
    FFIECommon::checkDirectory(__DIR__ . DIRECTORY_SEPARATOR . getenv('PATH.EXPORT.ALL'));
    if (getenv('EXPORT.SEPARATE_BY_TYPE')) {
        FFIECommon::checkDirectory(__DIR__ . DIRECTORY_SEPARATOR . getenv('PATH.EXPORT.ARTICLES'));
        FFIECommon::checkDirectory(__DIR__ . DIRECTORY_SEPARATOR . getenv('PATH.EXPORT.NEWS'));
    }

    DB::init(NULL, [
        'database'  =>  getenv('DB_DATABASE'),
        'username'  =>  getenv('DB_USERNAME'),
        'password'  =>  getenv('DB_PASSWORD'),
        'charset'   =>  'UTF8'
    ], AppLogger::scope('mysql'));

    // запрос
    // $select_query = "SELECT id FROM articles WHERE id IN (16108,19899,27927,29940,31181,31717,32441,33830,34591,34662,35442,36138,37384,38294) ORDER BY id";
    // $select_query = "SELECT id FROM articles  ORDER BY id LIMIT 1000";
    $select_query = "SELECT id FROM articles  ORDER BY id";

    // получаем список ID статей
    $articles_ids_list = DB::query($select_query)->fetchAll(PDO::FETCH_COLUMN);
    $count_curr  = 0;
    $count_total = count($articles_ids_list);

    $media_inline = []; // коллекция медиа-файлов в тексте
    $media_titles = []; // коллекция медиа-файлов в тайтле статьи

    // перебираем статьи
    foreach ($articles_ids_list as $id) {
        $count_curr++;

        $query_get_article = "
SELECT
	a.*,
    adm.login AS author_login
FROM 
	articles AS a
LEFT JOIN admin AS adm ON a.author_id = adm.id 	
WHERE
	a.id = {$id}
";
        /**
         * @param ArticleExporter $article
         */
        $article = DB::query($query_get_article)->fetchObject('ArticleExporter');

        $article_export = $article->exportArticle();
        $article_id = $article_export['id'];

        // экспортируем inline медиафайлы (array_merge не сохраняет ключи),
        // ???: $media_inline = $article->exportMediaFiles() + $media_inline;
        foreach ($article->exportInlineMediaCollection() as $fid => $finfo) {
            $media_inline[ $fid ] = $finfo;
        }

        // экспортируем тайтловые медиафайлы
        $media_titles[ $article_id ] = $article->exportTitleMediaCollection();

        // генерируем имя файла для записи
        $filename = FFIECommon::getExportFilename($article_export);
        FFIECommon::exportJSON($filename, $article_export);

        // сообщение
        $message
            = "[" . str_pad($count_curr, 6, ' ', STR_PAD_LEFT)
            . " / " . str_pad($count_total, 6, ' ', STR_PAD_RIGHT) . ']' .
            " Item id = <font color='green'>{$id}</font> exported to file <font color='yellow'>{$filename}</font>";

        CLIConsole::say($message);
        unset($article);
    } // foreach article

    CLIConsole::say("Exporting <font color='yellow'>{$export_directory}/media-inline.json</font>...");
    ksort($media_inline, SORT_NATURAL);
    FFIECommon::exportJSON("{$export_directory}/media-inline.json", $media_inline);

    CLIConsole::say("Exporting <font color='yellow'>{$export_directory}/media-title.json</font>...");
    ksort($media_titles, SORT_NATURAL);
    FFIECommon::exportJSON("{$export_directory}/media-title.json", $media_titles);

    CLIConsole::say();
    CLIConsole::say("Memory consumed: " . memory_get_peak_usage());
    CLIConsole::say('<hr>');

} catch (Exception $e) {
    dd($e->getMessage());
}

#!/usr/bin/php
<?php
ini_set('memory_limit','256M');

use Arris\AppLogger;
use Arris\CLIConsole;
use Arris\DB;
use Monolog\Logger;
use Dotenv\Dotenv;

use FFIExport\FFIECommon;
use FFIExport\ExportArticle;
use FFIExport\ExportDistrict;
use FFIExport\ExportPage;
use FFIExport\ExportPlace;

require_once __DIR__ . '/vendor/autoload.php';

try {
    Dotenv::create(__DIR__, '_env')->load();

    AppLogger::init('FFI_EXPORT', basename(__FILE__));
    AppLogger::addScope('main', [
        [ 'export.log', Logger::DEBUG ]
    ]);

    /* =================================================================================================================*/
    /* ============================        INIT                      ===================================================*/
    /* =================================================================================================================*/

    $export_directory = getenv('PATH.EXPORT.ALL');
    FFIECommon::checkDirectory(__DIR__ . DIRECTORY_SEPARATOR . getenv('PATH.EXPORT.ALL'));

    DB::init(NULL, [
        'database'  =>  getenv('DB_DATABASE'),
        'username'  =>  getenv('DB_USERNAME'),
        'password'  =>  getenv('DB_PASSWORD'),
        'charset'   =>  'UTF8'
    ], AppLogger::scope('mysql'));

    $media_inline = []; // коллекция медиа-файлов в тексте
    $media_titles = []; // коллекция медиа-файлов в тайтле статьи

    /* =================================================================================================================*/
    /* ============================        EXPORT ARTICLES           ===================================================*/
    /* =================================================================================================================*/
    if (getenv('EXPORT.ARTICLES')) {
        if (getenv('EXPORT.NAME_BY_TYPE') == 'directory') {
            FFIECommon::checkDirectory(__DIR__ . DIRECTORY_SEPARATOR . getenv('PATH.EXPORT.ARTICLES'));
            FFIECommon::checkDirectory(__DIR__ . DIRECTORY_SEPARATOR . getenv('PATH.EXPORT.NEWS'));
        }

        $sql_source_file = "get-articles.sql";
        $select_query
            = is_file($sql_source_file)
            ? file_get_contents($sql_source_file)
            : "SELECT id FROM articles WHERE s_hidden = 0 AND s_draft = 0 AND cdate IS NOT NULL ORDER BY id";
        dd($select_query);

        // получаем список ID статей
        $articles_ids_list = DB::query($select_query)->fetchAll(PDO::FETCH_COLUMN);
        $count_curr  = 0;
        $count_total = count($articles_ids_list);

        $articles_list = [];   // список итемов
        $pages_list = [];

        $sth_articles = DB::C()->prepare(ExportArticle::sql_query_get_article_by_id);

        // перебираем статьи
        foreach ($articles_ids_list as $id) {
            $count_curr++;

            $sth_articles->execute(['id' => $id]);
            $article = $sth_articles->fetchObject(ExportArticle::class);

            /**
             * @var $article ExportArticle
             */
            $article_export = $article->exportArticle();
            $article_id = $article_export['id'];

            foreach ($article->exportInlineMediaCollection() as $fid => $finfo) {
                $media_inline[ $fid ] = $finfo;
            }

            $media_titles[ $article_id ] = $article->exportTitleMediaCollection();

            $filename = FFIECommon::getExportFilename($article_export, 5);
            FFIECommon::exportJSON($filename, $article_export);

            // сообщение
            $message
                = "[" . str_pad($count_curr, 6, ' ', STR_PAD_LEFT)
                . " / " . str_pad($count_total, 6, ' ', STR_PAD_RIGHT) . ']' .
                " Item id = <font color='green'>{$id}</font> exported to file <font color='yellow'>{$filename}</font>";

            CLIConsole::say($message);

            $articles_list[ $article_id ] = [
                'id'    =>  $article_id,
                'type'  =>  $article_export['type'],
                'title' =>  $article_export['content']['title'],
                'json'  =>  $filename
            ];

            unset($article);
        } // foreach article

        CLIConsole::say("Exporting <font color='yellow'>{$export_directory}/list-items.json</font>");
        ksort($articles_list, SORT_NATURAL);
        FFIECommon::exportJSON("{$export_directory}/list-items.json", $articles_list);
    }

    /* =================================================================================================================*/
    /* ============================        EXPORT PAGES           ===================================================*/
    /* =================================================================================================================*/
    if (getenv('EXPORT.PAGES')) {
        if (getenv('EXPORT.NAME_BY_TYPE') == 'directory') {
            FFIECommon::checkDirectory(__DIR__ . DIRECTORY_SEPARATOR . getenv('PATH.EXPORT.PAGES'));
        }

        // перебираем страницы
        $sth_pages = DB::C()->query(ExportPage::sql_query_get_pages_all);
        /**
         * @var ExportPage $a_page
         */
        while ($a_page = $sth_pages->fetchObject(ExportPage::class)) {
            $export = $a_page->export();

            foreach ($a_page->exportInlineMediaCollection() as $fid => $finfo) {
                $media_inline[ $fid ] = $finfo;
            }

            $filename = FFIECommon::getExportFilename($export, -1);
            FFIECommon::exportJSON($filename, $export);

            $pages_list[ $export['id'] ] = [
                'id'    =>  $export['id'],
                'type'  =>  'page',
                'title' =>  $export['content']['title'],
                'json'  =>  $filename
            ];

            // сообщение
            $message
                = "[ Page id = <font color='green'>"
                . str_pad($export['id'], 6, ' ', STR_PAD_LEFT)
                . "</font>] exported to file <font color='yellow'>{$filename}</font>";

            CLIConsole::say($message);

            unset($a_page);
        }

        CLIConsole::say("Exporting <font color='yellow'>{$export_directory}/list-pages.json</font>");
        ksort($pages_list, SORT_NATURAL);
        FFIECommon::exportJSON("{$export_directory}/list-pages.json", $pages_list);
    }

    /* =================================================================================================================*/
    /* ============================        EXPORT PLACES           ===================================================*/
    /* =================================================================================================================*/

    if (getenv('EXPORT.PLACES')) {
        if (getenv('EXPORT.NAME_BY_TYPE') == 'directory') {
            FFIECommon::checkDirectory(__DIR__ . DIRECTORY_SEPARATOR . getenv('PATH.EXPORT.PLACES'));
        }

        $select_query = ExportPlace::QUERY_FETCH_PLACES;

        $sth_places = DB::C()->query($select_query);

        /**
         * @var $place ExportPlace
         */
        while ($place = $sth_places->fetchObject(ExportPlace::class)) {

            $exported_place = $place->export();

            $filename = FFIECommon::getExportFilename($exported_place, 5);
            FFIECommon::exportJSON($filename, $exported_place);

            CLIConsole::say(" Place id = <font color='green'>{$exported_place['id']}</font> exported to file <font color='yellow'>{$filename}</font>");
        }
    }

    /* =================================================================================================================*/
    /* ============================        EXPORT DISTRICTS          ===================================================*/
    /* =================================================================================================================*/

    if (getenv('EXPORT.DISTRICTS')) {
        if (getenv('EXPORT.NAME_BY_TYPE') == 'directory') {
            FFIECommon::checkDirectory(__DIR__ . DIRECTORY_SEPARATOR . getenv('PATH.EXPORT.DISTRICTS'));
        }

        $select_query  = ExportDistrict::QUERY_FETCH_DISTRICTS;

        $sth = DB::C()->query($select_query);

        /**
         * @var $district ExportDistrict
         */

        while ($district = $sth->fetchObject(ExportDistrict::class)) {

            $exported_district = $district->export();

            $filename = FFIECommon::getExportFilename($exported_district, 3);
            FFIECommon::exportJSON($filename, $exported_district);

            CLIConsole::say(" District id = <font color='green'>{$exported_district['id']}</font> exported to file <font color='yellow'>{$filename}</font>");
        }
    }



    /* =================================================================================================================*/
    /* ============================        EXPORT dictionaries       ===================================================*/
    /* =================================================================================================================*/

    CLIConsole::say("Exporting <font color='yellow'>{$export_directory}/dictionary-media-inline.json</font>...");
    ksort($media_inline, SORT_NATURAL);
    FFIECommon::exportJSON("{$export_directory}/dictionary-media-inline.json", $media_inline);

    CLIConsole::say("Exporting <font color='yellow'>{$export_directory}/dictionary-media-title.json</font>...");
    ksort($media_titles, SORT_NATURAL);
    FFIECommon::exportJSON("{$export_directory}/dictionary-media-title.json", $media_titles);

    CLIConsole::say();
    CLIConsole::say("Memory consumed: " . memory_get_peak_usage());
    CLIConsole::say('<hr>');

} catch (Exception $e) {
    dd($e->getMessage());
}

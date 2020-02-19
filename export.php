#!/usr/bin/php
<?php
ini_set('memory_limit','256M');

use Arris\AppLogger;
use Arris\CLIConsole;
use Arris\DB;
use Monolog\Logger;
use Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/class.article_exporter.php';

Dotenv::create(__DIR__, '_env')->load();

AppLogger::init('FFI_EXPORT', basename(__FILE__));
AppLogger::addScope('main', [
    [ 'export.log', Logger::DEBUG ]
]);

try {
    if (!is_dir(__DIR__ . '/export')) mkdir(__DIR__ . '/export', 0777, true);

    DB::init(NULL, [
        'database'  =>  getenv('DB_DATABASE'),
        'username'  =>  getenv('DB_USERNAME'),
        'password'  =>  getenv('DB_PASSWORD'),
        'charset'   =>  'UTF8'
    ], AppLogger::scope('mysql'));

    $select_query = "SELECT id FROM articles ORDER BY id LIMIT 10";

    $articles_ids_list = DB::query($select_query)->fetchAll(PDO::FETCH_COLUMN);
    $count_curr  = 0;
    $count_total = count($articles_ids_list);

    $media_collection = [];

    // each article
    foreach ($articles_ids_list as $id) {
        $filename = "export/article-" . str_pad($id, 5, '0', STR_PAD_LEFT) . '.json';
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

        $article_json = $article->exportArticle();

        // export embedded mediafiles
        $article_mediafiles = $article->exportMediaFiles();
        foreach ($article_mediafiles as $fid => $finfo) {
            $media_collection[ $fid ] = $finfo;
        }

        // store item-NNNN.json
        file_put_contents($filename, json_encode($article_json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        $message
            = "[" . str_pad($count_curr, 6, ' ', STR_PAD_LEFT)
            . " / " . str_pad($count_total, 6, ' ', STR_PAD_RIGHT) . ']' .
            " Article id = <font color='green'>{$id}</font> exported to file <font color='yellow'>{$filename}</font>";

        CLIConsole::say($message);
        unset($article);
    }

    // сортируем массив медиа-данных по FID
    asort($media_collection);
    file_put_contents("export/mediafiles.json", json_encode($media_collection, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    CLIConsole::say();
    CLIConsole::say("Memory consumed: " . memory_get_peak_usage());
    CLIConsole::say('<hr>');

} catch (Exception $e) {
    dd($e->getMessage());
}

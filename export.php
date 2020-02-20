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

    // запрос
    $select_query = "SELECT id FROM articles WHERE id IN (16108,19899,27927,29940,31181,31717,32441,33830,34591,34662,35442,36138,37384,38294) ORDER BY id";
    // $select_query = "SELECT id FROM articles  ORDER BY id LIMIT 100";

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
        $article = DB::query($query_get_article)->fetchObject('ArticleExporter', [ $id ]);

        $article_export = $article->exportArticle();

        // экспортируем inline медиафайлы (array_merge не сохраняет ключи),
        // ???: $media_inline = $article->exportMediaFiles() + $media_inline;
        foreach ($article->exportInlineMediaCollection() as $fid => $finfo) {
            $media_inline[ $fid ] = $finfo;
        }

        // экспортируем тайтловые медиафайлы
        $media_titles[ $article_export['oldid'] ] = $article->exportTitleMediaCollection();

        // пишем данные в файл
        $filename = "export/article-" . str_pad($id, 5, '0', STR_PAD_LEFT) . '.json';
        file_put_contents($filename, json_encode($article_export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        // сообщение
        $message
            = "[" . str_pad($count_curr, 6, ' ', STR_PAD_LEFT)
            . " / " . str_pad($count_total, 6, ' ', STR_PAD_RIGHT) . ']' .
            " Article id = <font color='green'>{$id}</font> exported to file <font color='yellow'>{$filename}</font>";

        CLIConsole::say($message);
        unset($article);
    } // foreach article

    CLIConsole::say("Exporting <font color='yellow'>media-inline.json</font>...");
    asort($media_inline);
    file_put_contents("export/media-inline.json", json_encode($media_inline, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    CLIConsole::say("Exporting <font color='yellow'>media-title.json</font>...");
    asort($media_titles);
    file_put_contents("export/media-title.json", json_encode($media_titles, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    CLIConsole::say();
    CLIConsole::say("Memory consumed: " . memory_get_peak_usage());
    CLIConsole::say('<hr>');

} catch (Exception $e) {
    dd($e->getMessage());
}

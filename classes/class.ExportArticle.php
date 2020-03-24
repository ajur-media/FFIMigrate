<?php

namespace FFIExport;

use Exception;
use Arris\DB;
use PDO;
use Spatie\Regex;


class ExportArticle extends Export
{
    const QUERY_FETCH_ARTICLES = "SELECT
	a.*,
    adm.login AS adm_author_login, 
    adm.id as adm_author_id
FROM 
	articles AS a
LEFT JOIN admin AS adm ON a.author_id = adm.id 	
WHERE s_draft = 0 AND cdate IS NOT NULL ORDER BY id";

    const QUERY_FETCH_ARTICLES_COUNT = "SELECT COUNT(id) FROM articles WHERE s_draft = 0 AND cdate IS NOT NULL ORDER BY id";

    /*
     * Autoloaded fields
     */
    private
        $id, $cdate, $type,
        $author, $author_id,
        $adm_author_login, $adm_author_id,
        $s_hidden, $s_draft,
        $title, $short, $text_bb,
        $districts, $rubrics,
        $photo, $html,
        $s_bold, $s_own,
        $meta_keywords, $meta_descr, $meta_title;

    /**
     * @var array Датасет для экспорта
     */
    private $_dataset = [];

    /**
     * @var array Сет медиаресурсов
     */
    private $_media_collection_inline = [];

    private $_articles_related = [];

    /**
     * @var array
     */
    private $_article_media_title = [], $_article_media_html = [];

    /**
     * @var array
     */
    private $_article_media_inline = [], $_article_media_reports = [];

    /**
     * @var mixed  - Признак того, что итем содержал JS-код перехода на внешний материал
     * FALSE или СТРОКА
     */
    public $is_external = false;

    /**
     * ArticleExporter constructor.
     *
     * @throws Exception
     */
    public function __construct()
    {
        $this->_article_media_inline = parent::parseMediaInline($this->id, $this->_media_collection_inline, 'articles_files');
        $this->_article_media_title = parent::parseMediaTitle($this->photo);
        $this->_article_media_html = parent::parseHTMLWidgets($this->html);

        $this->_articles_related = $this->getRelatedArticles();
        // $this->_article_media_html = $this->parseHTMLWidgets();
        $this->_article_media_reports = $this->parseMediaReports();

        $this->_dataset = [
            'id'        =>  (int)$this->id,                                     // id статьи
            'cdate'     =>  FFIECommon::_date_format($this->cdate),     // ISO Date
            'type'      =>  $this->type,                                    // тип
            'creator'   =>  [                                               // информация об авторе
                'id'        =>  (int)$this->author_id,                           // ID
                'adm_id'    =>  $this->adm_author_id,
                'adm_login' =>  $this->adm_author_login,                        // логин
                'sign'      =>  FFIECommon::_trim($this->author, 'TRIM.AUTHOR')                               // подпись
            ],
            'status'    =>  [                                               // статус статьи в базе
                'is_hidden' =>  (int)$this->s_hidden,                            // установлен флаг "скрытая"
                'is_draft'  =>  (int)$this->s_draft,                             // установлен флаг "черновик"
                'is_bold'    =>  (int)$this->s_bold,                        // тайтл жирно
                'is_own'        =>  (int)$this->s_own,                      //  с копирайтом © Fontanka.Fi
            ],
            'content'   =>  [
                'title'     =>  FFIECommon::_trim($this->title, 'TRIM.TITLE'),
                'lead'      =>  FFIECommon::_trim($this->short, 'TRIM.LEAD'),
                'text_length'   =>  mb_strlen(trim($this->text_bb)),
                'text_bb'   =>  FFIECommon::_trim($this->text_bb, 'TRIM.TEXT'),   // исходный текст, размеченный BB-кодами
            ],
            'media'     =>  [
                'title'     =>  $this->_article_media_title,
                'html'      =>  $this->_article_media_html,
                'media'     =>  $this->_article_media_inline,
                'reports'   =>  $this->_article_media_reports,
            ],
            'view_also'  =>  [
                '_'     =>  count($this->_articles_related),
                'list'  =>  $this->_articles_related,
            ],
            'meta'    =>  [
                'title'     =>  trim($this->meta_title),
                'keywords'  =>  trim($this->meta_keywords),
                'description'=> trim($this->meta_descr),
            ],
            'relations' =>  [
                'districts' =>  $this->exportArticleDistricts(),
                'rubrics'   =>  $this->exportArticleRubrics(),
                'tags'      =>  $this->exportArticleTags()
            ]
        ];

        if ($this->is_external !== false) {
            $this->_dataset['content']['external'] = $this->is_external;
        }
    }

    /**
     * Экспортирует статью в JSON
     *
     * @return array
     * @throws Exception
     */
    public function export()
    {
        return $this->_dataset;
    }

    /**
     * Возвращает коллекцию инлайт-медиаданных
     *
     * @return array
     */
    public function exportInlineMediaCollection()
    {
        return $this->_media_collection_inline;
    }

    /**
     * Возвращает коллекцию тайтл-фотографий (из 1 элемента)
     *
     * @return array
     */
    public function exportTitleMediaCollection()
    {
        return $this->_article_media_title;
    }


    /**
     * Возвращает фоторепортажи
     *
     * @return array
     * @throws Exception
     */
    private function parseMediaReports()
    {
        $id = $this->id;
        $_reports = [];

        $fetch_data = DB::query("
SELECT 
     id, title, short, cdate
FROM
     reports
WHERE id IN (
    SELECT bind FROM articles_reports WHERE item = {$id}
) ORDER BY id 
        ")->fetchAll();

        $_reports['_'] = 0;

        foreach ($fetch_data as $report) {
            $rid = (int)$report['id'];
            $report['id'] = $rid;
            $report['cdate'] = FFIECommon::_date_format($report['cdate']);

            // теперь получаем файлы, относящиеся к фоторепортажу

            $query_get_files = "
SELECT 
	rf.item AS report_id,
	rf.fid AS mediafile_id,
	rf.descr AS report_description,
	f.name AS mediafile_sourcename,
	f.cdate AS mediafile_cdate,
	f.type AS mediafile_type,
	f.file AS mediafile_filename,
	f.wm AS mediafile_wmposition,
	f.source AS mediafile_source,
	f.link AS mediafile_link
FROM reports_files as rf 
LEFT JOIN files AS f ON f.id = rf.fid 
WHERE rf.item = {$rid}            
            ";
            $photoreport_files = DB::query($query_get_files)->fetchAll();
            // тут можно сделать FETCH_FUNC, но придется писать функцию с огромным списком параметром
            // а создавать отдельный класс для экспорта (и писать PDO::FETCH_OBJ, "export_report_files" - излишество.
            // поэтому обработку просто выносим наружу, в foreach

            // Фотографии в фоторепортаже упорядочены, и порядок имеет значение.
            // Из-за особенностей PERL-а в трактовании json-структур типа {}
            // мы передаем фотографии не как ассоциативный массив, а как линейный массив, в котором фото перечислены \
            // по порядку, а не по ключам.
            // поэтому оставляем в массиве только однозначно приводящиеся к числам ключи, не добавляем ключ '_' (число фоток)
            //

            foreach ($photoreport_files as $rf) {
                $fid = (int)$rf['mediafile_id'];
                $rf['mediafile_id'] = $fid;
                $rf['mediafile_cdate'] = FFIECommon::_date_format($rf['mediafile_cdate']);

                $media_type = $rf['mediafile_type'];

                $basepath_storage = $media_type . '/' . date('Y/m', strtotime($rf['mediafile_cdate'])) . '/';
                $basepath_full = getenv('PATH.STORAGE') . $basepath_storage;

                FFIECommon::_get_file_info($rf['preview'],
                    '150x100_' . $rf['mediafile_filename'], $basepath_storage, $basepath_full);

                FFIECommon::_get_file_info($rf['full'],
                    '1280x1024_' . $rf['mediafile_filename'], $basepath_storage, $basepath_full);

                FFIECommon::_get_file_info($rf['original'],
                    $rf['mediafile_filename'], $basepath_storage, $basepath_full);

                // по просьбе Лёши отдаем как линейный массив без счетчика элементов (закомментируем индекс)
                $report['media'][/*$fid*/] = $rf;
            } // each photoreport

            // дополняем $report данными из files по связи report_files
            $_reports[ $rid ] = $report;
            $_reports['_']++;
            // по просьбе Лёши передаем просто линейный массив
        }
        if ($_reports['_'] == 0) unset($_reports['_']);

        return $_reports;

    }

    /**
     * Экспортирует список рубрик
     *
     * @return array
     * @throws Exception
     */
    private function exportArticleRubrics()
    {
        $id = $this->id;
        $query = "SELECT 
    ra.id AS rubric_id,
    ra.name AS rubric_name,
    ra.sort AS rubric_order
FROM 
     articles_rubrics AS ar
LEFT JOIN rubrics_articles AS ra ON ra.id = ar.bind
WHERE item = {$id}
ORDER BY ra.sort DESC  ";

        $data = DB::query($query)->fetchAll();
        return $data === false ? [] : $data;

    }

    /**
     * Экспортирует список районов без десериализации, из базы
     *
     * @return array
     * @throws Exception
     */
    private function exportArticleDistricts()
    {
        $id = $this->id;
        $query = "SELECT 
    d.id AS district_id,
    d.name AS district_name
FROM 
     articles_districts AS ad
LEFT JOIN districts AS d ON d.id = ad.bind
WHERE item = {$id}";

        $data = DB::query($query)->fetchAll();
        return $data === false ? [] : $data;
    }

    /**
     * Экспортирует список тегов из базы (если есть)
     *
     * @return array
     * @throws Exception
     */
    private function exportArticleTags()
    {
        $id = $this->id;

        $query = "
SELECT 
    ta.id AS tag_id,
    ta.name AS tag_text,
    ta.sort AS tag_order
FROM 
     articles_tags 
LEFT JOIN tags_articles AS ta ON ta.id = articles_tags.bind
WHERE item = {$id}
ORDER BY ta.sort DESC         
        ";

        $data = DB::query($query)->fetchAll();
        return $data === false ? [] : $data;
    }

    /**
     * Возвращает массив статей "по теме"
     *
     * @return array
     * @throws Exception
     */
    private function getRelatedArticles()
    {
        return
            DB::query("
SELECT id, cdate, title FROM articles
WHERE id IN (SELECT bind FROM articles_related WHERE item = {$this->id})
ORDER BY cdate ")
                ->fetchAll(PDO::FETCH_FUNC, function ($id, $cdate, $title) {
                    return [
                        'id'    =>  (int)$id,
                        'cdate' =>  FFIECommon::_date_format($cdate),
                        'title' =>  $title
                    ];
                });
    }


} // class

# -eof-

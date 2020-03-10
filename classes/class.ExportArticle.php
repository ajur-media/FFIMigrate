<?php

use Arris\DB;
use Spatie\Regex;

class ExportArticle
{
    const sql_query_get_article_by_id = "
SELECT
	a.*,
    adm.login AS adm_author_login, 
    adm.id as adm_author_id
FROM 
	articles AS a
LEFT JOIN admin AS adm ON a.author_id = adm.id 	
WHERE
	a.id = :id
";

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
    private $is_external = false;

    /**
     * ArticleExporter constructor.
     *
     * @throws Exception
     */
    public function __construct()
    {
        $this->_articles_related = $this->getRelatedArticles();

        $this->_article_media_title = $this->parseMediaTitle();
        $this->_article_media_html = $this->parseHTMLWidgets();

        $this->_article_media_inline = $this->parseMediaInline();
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
                'text_bb'   =>  $this->text_bb,                                 // исходный текст, размеченный BB-кодами
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
                'title'     =>  $this->meta_title,
                'keywords'  =>  $this->meta_keywords,
                'description'=> $this->meta_descr,
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
    public function exportArticle()
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
     * Возвращает MEDIA типа 'titleimage'
     *
     * @return array
     */
    private function parseMediaTitle()
    {
        $u_photo = ($this->photo != '') ? @unserialize($this->photo) : [];

        $_media_title = [];

        $is_present = false;

        if (!empty($u_photo) && is_array($u_photo)) {

            if (array_key_exists('file', $u_photo) && array_key_exists('path', $u_photo) && $u_photo['file'] && $u_photo['path']) {

                FFIECommon::_check_file($_media_title['uri'], stripslashes($u_photo['path']) . '/' . $u_photo['file']);
                $is_present = true;

            } elseif (array_key_exists('file', $u_photo) && array_key_exists('cdate', $u_photo) && $u_photo['file'] && $u_photo['cdate']) {

                $basepath = getenv('PATH.STORAGE') . 'photos/' . $u_photo['cdate'] . '/';
                FFIECommon::_check_file($_media_title['predicted'], $basepath . $u_photo['file']);
                $is_present = true;
            }

            if ($is_present && array_key_exists('descr', $u_photo)) {
                $_media_title['titles'] = $u_photo['descr'];
            }
        }

        if (getenv('MEDIA.TITLE.EXPORT_RAW')) {
            $_media_title['raw'] = $u_photo;
        }

        return $_media_title;
    }

    /**
     *
     * @return array
     * @throws Exception
     */
    private function parseMediaInline()
    {
        $query = "
SELECT
       af.id AS embed_id, 
       af.fid AS mediafile_id,
       af.descr AS media_description,
       f.source AS media_source,
       f.link AS media_link,
       f.type AS media_type,
       f.cdate AS media_cdate,
       f.file AS mediafile_filename,
       f.name AS mediafile_originalfilename
FROM 
     articles_files AS af 
LEFT JOIN files AS f ON f.id = af.fid 
WHERE af.item = {$this->id}
        ";

        $sth = DB::query($query);

        $_data = [];
        $_data['_'] = 0;

        while ($resource = $sth->fetch()) {
            $media_fid = (int)$resource['mediafile_id'];
            $media_type = $resource['media_type'];

            $info_media = [
                'fid'   =>  $media_fid,
                'type'  =>  $media_type,
                'descr' =>  $resource['media_description'],
                // по требованию Лёши передаем в инфомедиа всю информацию
            ];

            if (getenv('MEDIA.MEDIA.EXPORT_RAW')) {
                $info_media['raw'] = $resource;
            }

            $info_file = [
                'fid'       =>  $media_fid,
                'type'      =>  $media_type,
                'from'      =>  [
                    'source'    =>  $resource['media_source'],
                    'link'      =>  $resource['media_link'],
                ],
                'cdate'     =>  FFIECommon::_date_format($resource['media_cdate']),
                'original_name' =>  $resource['mediafile_originalfilename'],
                'paths'     =>  []
            ];

            $basepath = getenv('PATH.STORAGE') . $media_type . '/' . date('Y/m', strtotime($resource['media_cdate'])) . '/';

            $paths = [];
            switch ($media_type) {
                case 'photos': {
                    FFIECommon::_check_file($paths['preview'], $basepath . '650x486_' . $resource['mediafile_filename']);
                    FFIECommon::_check_file($paths['full'], $basepath . '1280x1024_' . $resource['mediafile_filename']);
                    FFIECommon::_check_file($paths['original'], $basepath . '' . $resource['mediafile_filename']);

                    break;
                }
                case 'files':
                case 'audios':
                case 'videos': {
                FFIECommon::_check_file($paths['full'], $basepath . $resource['mediafile_filename']);

                    break;
                }

            } // switch
            $info_file['paths'] = $paths;

            // по просьбе Лёши передаем инфо о файле прямо тут
            $info_media['file'] = $info_file;

            $_data[ (int)$resource['embed_id'] ] = $info_media;
            $_data['_']++;
            unset($info_media);

            $this->_media_collection_inline[ $media_fid ] = $info_file;
        }

        // Если медиаданных этого типа нет - возвращаем пустой массив
        if ($_data['_'] === 0)
            $_data = [];

        return $_data;
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
            //@todo: env?
            /*$report['media'] = [
                '_' =>  count($photoreport_files)
            ];*/

            // порядок фоток внутри фоторепортажа существенен

            foreach ($photoreport_files as $rf) {
                $fid = (int)$rf['mediafile_id'];
                $rf['mediafile_id'] = $fid;
                $rf['mediafile_cdate'] = FFIECommon::_date_format($rf['mediafile_cdate']);

                $media_type = $rf['mediafile_type'];

                $basepath = getenv('PATH.STORAGE') . $media_type . '/' . date('Y/m', strtotime($rf['mediafile_cdate'])) . '/';

                $paths = [];

                FFIECommon::_check_file($paths['preview'], $basepath . '150x100_' . $rf['mediafile_filename']);
                FFIECommon::_check_file($paths['full'], $basepath . '1280x1024_' . $rf['mediafile_filename']);
                FFIECommon::_check_file($paths['original'], $basepath . '' . $rf['mediafile_filename']);

                $rf['paths'] = $paths;

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
     * Добавляет информацию по HTML-виджетам
     */
    private function parseHTMLWidgets()
    {
        $_data = [];

        // 'YTowOnt9' - это закодированное 'a:0:{}'
        // 'N;' - null
        // 'Tjs=' - эквивалентно 'N;'
        // чаще всего данные будут base64
        /*if ($this->html === ''
            || $this->html === 'YTowOnt9'
            || $this->html === 'a:0:{}'
            || $this->html === 'Tjs='
            || $this->html === 'N;'
            || $this->html === ''
        ) return false; // виджетов нет
        */

        $html = FFIECommon::_isBase64($this->html) ? base64_decode($this->html) : $this->html;
        $html = unserialize($html);

        if (!$html) return [];

        $_data['_'] = count($html);

        foreach ($html as $id => $code) {
            // проверяем [noindex canonical redirect -> longread]
            $is_external_href = FFIECommon::checkExternalLink($code);

            if ($is_external_href !== false) {
                $this->is_external = $is_external_href;
            }

            $set = [];

            if (getenv('MEDIA.HTML.EXPORT_STRING'))
                $set['html'] = $code;

            if (getenv('MEDIA.HTML.EXPORT_BASE64'))
                $set['base64'] = base64_encode($code);

            $_data[$id] = $set;
        }

        if (getenv('MEDIA.HTML.SAVE_DEBUG')) {
            $this->_dataset['debug:html'] = $html;
        }

        return $_data;
    }

    /**
     * Экспортирует список рубрик
     *
     * @return array
     * @throws Exception
     */
    private function exportArticleRubrics()
    {
        /*$rubrics = @unserialize($this->rubrics);
        $data = [];
        if (is_array($rubrics)) {
            foreach ($rubrics as $id => $r) {
                $data[ (int)$id ] = $r['name'];
            }
        }
        return $data;*/
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
        /*$districs = @unserialize($this->districts);
        $data = [];
        if (is_array($districs)) {
            $this->districts = [];
            foreach ($districs as $id => $d) {
                $data[ (int)$id ] = $d['name'];
            }
        }
        return $data;
        */
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


}
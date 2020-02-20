<?php

use Arris\DB;
use Spatie\Regex\Regex;

class ArticleExporter
{
    private
        $id, $cdate, $type,
        $author, $author_id, $author_login,
        $s_hidden, $s_draft,
        $title, $short, $text_bb,
        $districts, $rubrics,
        $photo, $html;

    private
        $meta_keywords, $meta_descr, $meta_title;

    /**
     * @var array Датасет для экспорта
     */
    private $_dataset = [];

    /**
     * @var array Сет медиаресурсов
     */
    private $_media_collection_inline = [];

    private $_media_collection_title = [];

    private $_articles_related = [];

    /**
     * @var array
     */
    private $_article_media_title, $_article_media_html;

    /**
     * @var array
     */
    private $_article_media_inline, $_article_media_reports;

    /**
     * ArticleExporter constructor.
     *
     * @param $id
     * @throws Exception
     */
    public function __construct($id)
    {
        // загружаем список статей VIEW ALSO
        $this->_articles_related = $this->getRelatedArticles();

        $this->prepareData();
    }

    /**
     * Возвращает массив статей "по теме"
     *
     * @param $id
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
                    'cdate' =>  self::_date_format($cdate),
                    'title' =>  $title
                ];
            });
    }

    /**
     * Подготавливает данные для экспорта
     *
     * @throws Exception
     */
    private function prepareData()
    {
        $this->_article_media_title = $this->parseMediaTitle();
        $this->_article_media_html = $this->parseHTMLWidgets();

        $this->_article_media_inline = $this->parseMediaInline();
        $this->_article_media_reports = $this->parseMediaReports();

        $this->_dataset = [
            'oldid'     =>  $this->id,                                     // id статьи
            'cdate'     =>  self::_date_format($this->cdate),     // ISO Date
            'type'      =>  $this->type,                                    // тип
            'creator'   =>  [                                               // информация об авторе
                'id'        =>  $this->author_id,                           // ID
                'login'     =>  $this->author_login,                        // логин
                'sign'      =>  self::_trim($this->author, 'TRIM.AUTHOR')                               // подпись
            ],
            'status'    =>  [                                               // статус статьи в базе
                'is_hidden' =>  $this->s_hidden,                            // установлен флаг "скрытая"
                'is_draft'  =>  $this->s_draft,                             // установлен флаг "черновик"
            ],
            'title'     =>  self::_trim($this->title, 'TRIM.TITLE'),
            'media'     =>  [
                'title'     =>  $this->_article_media_title,
                'html'      =>  $this->_article_media_html,
                'media'     =>  $this->_article_media_inline,
                'reports'   =>  $this->_article_media_reports,
            ],
            'lead'      =>  self::_trim($this->short, 'TRIM.LEAD'),
            'text_bb'   =>  $this->text_bb,                                 // исходный текст, размеченный BB-кодами
            'view_also'  =>  [
                '_'     =>  count($this->_articles_related),
                'data'  =>  $this->_articles_related,
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
            if (array_key_exists('file', $u_photo) && array_key_exists('path', $u_photo)) {
                $_media_title['uri'] = stripslashes($u_photo['path']) . '/' . $u_photo['file'];

                $is_present = true;
            }

            if ($is_present && array_key_exists('descr', $u_photo)) {
                $_media_title['titles'] = $u_photo['descr'];
            }

            if (array_key_exists('file', $u_photo)) {
                $basepath = getenv('PATH.STORAGE') . 'photos/' . date('Y/m', strtotime($this->cdate)) . '/';

                $_media_title['realfile']
                    = is_file($basepath . $u_photo['file'])
                    ? $basepath . $u_photo['file']
                    : '';
            }
        }

        if (getenv('MEDIA.TITLE.EXPORT_RAW')) {
            $_media_title['raw'] = $u_photo;
        }

        if (getenv('MEDIA.TITLE.EXPORT_ALWAYS') == 0 && !$is_present) {
            $_media_title = [];
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
        $_data = [];

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
        $_data['_'] = 0;

        while ($resource = $sth->fetch()) {
            $media_type = $resource['media_type'];
            $media_fid = $resource['mediafile_id'];

            $info_media = [
                'fid'   =>  $media_fid,
                'type'  =>  $media_type,
                'descr' =>  $resource['media_description'],
            ];

            if (getenv('MEDIA.MEDIA.EXPORT_RAW')) {
                $info_media['raw'] = $resource;
            }

            $_data[$resource['embed_id']] = $info_media;

            $_data['_']++;
            unset($info_media);

            $info_file = [
                'fid'       =>  $media_fid,
                'type'      =>  $media_type,
                'from'      =>  [
                    'source'    =>  $resource['media_source'],
                    'link'      =>  $resource['media_link'],
                ],
                'cdate'     =>  $resource['media_cdate'],
                'original_name' =>  $resource['mediafile_originalfilename'],
                'paths'     =>  []
            ];

            $basepath = getenv('PATH.STORAGE') . $media_type . '/' . date('Y/m', strtotime($resource['media_cdate'])) . '/';

            $paths = [];
            switch ($media_type) {
                case 'photos': {

                    self::_check_file($paths['preview'], $basepath . '650x486_' . $resource['mediafile_filename']);
                    self::_check_file($paths['full'], $basepath . '1280x1024_' . $resource['mediafile_filename']);
                    self::_check_file($paths['original'], $basepath . '' . $resource['mediafile_filename']);

                    /*if (is_file($basepath . '650x486_' . $resource['mediafile_filename'])) {
                        $paths['preview'] = $basepath . '650x486_' . $resource['mediafile_filename'];
                    }

                    if (is_file($basepath . '1280x1024_' . $resource['mediafile_filename'])) {
                        $paths['full'] = $basepath . '1280x1024_' . $resource['mediafile_filename'];
                    }

                    if (is_file($basepath . '' . $resource['mediafile_filename'])) {
                        $paths['original'] = $basepath . '' . $resource['mediafile_filename'];
                    }*/

                    break;
                }
                case 'files':
                case 'audios':
                case 'videos': {
                    if (is_file($basepath . $resource['mediafile_filename'])) {
                        $paths['full'] = $basepath . $resource['mediafile_filename'];
                    }

                    break;
                }

            } // switch
            $info_file['paths'] = $paths;

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

        foreach ($fetch_data as $report) {
            $rid = (int)$report['id'];
            $report['id'] = $rid;
            $report['cdate'] = self::_date_format($report['cdate']);

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

            $report['media'] = [
                '_' =>  count($photoreport_files)
            ];

            foreach ($photoreport_files as $rf) {
                $fid = (int)$rf['mediafile_id'];
                $rf['mediafile_id'] = $fid;
                $rf['mediafile_cdate'] = self::_date_format($rf['mediafile_cdate']);

                $media_type = $rf['mediafile_type'];

                $basepath = getenv('PATH.STORAGE') . $media_type . '/' . date('Y/m', strtotime($rf['mediafile_cdate'])) . '/';

                $paths = [];

                self::_check_file($paths['preview'], $basepath . '150x100_' . $rf['mediafile_filename']);
                self::_check_file($paths['full'], $basepath . '1280x1024_' . $rf['mediafile_filename']);
                self::_check_file($paths['original'], $basepath . '' . $rf['mediafile_filename']);

                $rf['paths'] = $paths;

                $report['media'][$fid] = $rf;
            } // each photoreport

            // дополняем $report данными из files по связи report_files
            $_reports[ $rid ] = $report;
        }

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

        $html = self::_isBase64($this->html) ? base64_decode($this->html) : $this->html;
        $html = unserialize($html);

        if (!$html) return [];

        $_data['_'] = count($html);

        foreach ($html as $id => $code) {
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
     * Десериализует список рубрик
     *
     * @return array
     */
    private function exportArticleRubrics()
    {
        $rubrics = @unserialize($this->rubrics);
        $data = [];
        if (is_array($rubrics)) {
            foreach ($rubrics as $id => $r) {
                $data[ (int)$id ] = $r['name'];
            }
        }
        return $data;
    }

    /**
     * Десериализует список районов
     *
     * @return array
     */
    private function exportArticleDistricts()
    {
        $districs = @unserialize($this->districts);
        $data = [];
        if (is_array($districs)) {
            $this->districts = [];
            foreach ($districs as $id => $d) {
                $data[ (int)$id ] = $d['name'];
            }
        }
        return $data;
    }

    /**
     * Экспортирует список тегов из базы (если есть)
     *
     * @return array
     * @throws Exception
     */
    private function exportArticleTags()
    {
        $data = [];
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

    /* ================================ STATIC METHODS ================================== */

    private static function _isBase64($string)
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
    private static function _trim($value, $env)
    {
        return getenv($env) ? trim($value) : $value;
    }

    /**
     * Форматирует дату в ISO
     *
     * @param $date_string
     * @return false|string
     */
    private static function _date_format($date_string)
    {
        return date('c', strtotime($date_string));
    }

    /**
     * Проверяет существование файла и если он существует - записывает его в соотв поле target
     * @param $target
     * @param $filepath
     */
    private static function _check_file(&$target, $filepath)
    {
        if (getenv('MEDIA.EXPORT_ONLY_PRESENT_FILES')) {
            if (is_file($filepath)) {
                $target = $filepath;
            };
        } else {
            $target = $filepath;
        }

    }




}
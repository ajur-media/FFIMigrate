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
        $photo, $html;

    /**
     * @var array Датасет для экспорта
     */
    private $_dataset = [];

    /**
     * @var array Сет медиаресурсов
     */
    private $_media = [];

    public function __construct()
    {
    }

    /**
     * Экспортирует статью в JSON
     *
     * @return array
     * @throws Exception
     */
    public function exportArticle()
    {
        $this->_dataset = [
            'oldid'     =>  $this->id,                                     // id статьи
            'cdate'     =>  date('c', strtotime($this->cdate)),     // ISO Date
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
            'lead'      =>  self::_trim($this->short, 'TRIM.LEAD'),
            'media'     =>  [
                'title'     =>  $this->parseMediaTitle(),
                'html'      =>  $this->parseHTMLWidgets(),
                'photos'    =>  $this->parseMediaPhotos(),
                // 'reports'   =>  $this->parseMediaReports(), // их может быть несколько
                // возвращает массив с перечислениями фоторепортажа
            ],
            'text_bb'   =>  $this->text_bb,                                 // исходный текст, размеченный BB-кодами
        ];

        return $this->_dataset;
    }

    public function exportMediaFiles()
    {
        return $this->_media;
    }

    /**
     * Возвращает MEDIA типа 'titleimage'
     *
     * @return array
     */
    private function parseMediaTitle()
    {
        $u_photo = ($this->photo != '') ? @unserialize($this->photo) : [];

        $_media_title = [
            'type'  =>  'title'
        ];

        $is_present = false;

        if (!empty($u_photo) && is_array($u_photo)) {
            if (array_key_exists('file', $u_photo) && array_key_exists('path', $u_photo)) {

                $_media_title['path'] = stripslashes($u_photo['path']) . '/' . $u_photo['file'];

                /*$_media_title['paths'] = [
                    stripslashes($u_photo['path']) . '/' . $u_photo['file']
                ];*/
                $is_present = true;
            }

            if ($is_present && array_key_exists('descr', $u_photo)) {
                // $_media_title['titles'] = [ $u_photo['descr'] ];
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
    private function parseMediaPhotos()
    {
        $_data = [];

        // А может быть запрос надо разджойнить и разбить на два?
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

                    if (is_file($basepath . '650x486_' . $resource['mediafile_filename'])) {
                        $paths['preview'] = $basepath . '650x486_' . $resource['mediafile_filename'];
                    }

                    if (is_file($basepath . '1280x1024_' . $resource['mediafile_filename'])) {
                        $paths['full'] = $basepath . '1280x1024_' . $resource['mediafile_filename'];
                    }

                    if (is_file($basepath . '' . $resource['mediafile_filename'])) {
                        $paths['original'] = $basepath . '' . $resource['mediafile_filename'];
                    }

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

            $this->_media[ $media_fid ] = $info_file;
        }

        // Если медиаданных этого типа нет - возвращаем пустой массив
        if ($_data['_'] === 0)
            $_data = [];

        return $_data;



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

}
<?php

use Arris\DB;

class ArticleExporter
{
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

    public function export()
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
                'media'     =>  $this->parseMediaPhotos(),
                // 'reports'   =>  [],
                'html'      =>  $this->parseHTMLWidgets()
            ],
            'text_bb'   =>  $this->text_bb,                                 // исходный текст, размеченный BB-кодами
        ];

        return $this->_dataset;
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
                $_media_title['paths'] = [
                    stripslashes($u_photo['path']) . '/' . $u_photo['file']
                ];
                $is_present = true;
            }

            if ($is_present && array_key_exists('descr', $u_photo)) {
                $_media_title['titles'] = [ $u_photo['descr'] ];
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

    private function parseMediaPhotos()
    {
        $_data = [];

        /*
        select af.*, files.*
from articles_files as af
left join files on files.id = af.fid
where af.item = 119
         */

        $query = "
SELECT 
       af.id AS embed_id,
       af.fid AS mediafile_id,
       af.descr AS media_title,
       files.name AS media_alttext,
       files.cdate,
       files.type,
       files.file,
       files.wm,
       files.source AS media_source,
       files.link AS media_link,
       files.status,
       files.status_size
FROM 
     articles_files AS af 
LEFT JOIN files ON files.id = af.fid 
WHERE af.item = {$this->id}
        ";

        $sth = DB::query($query);
        $_data['_'] = 0;

        while ($photo = $sth->fetch()) {
            // $photo['filepath'] = getenv('PATH.STORAGE') . date('Y/m/', strtotime($photo['cdate'])) . $photo['file'];

            $set = [
                'id'    =>  $photo['embed_id'],
                'fid'   =>  $photo['mediafile_id'],
                'title' =>  $photo['media_title'],
                'alt'   =>  $photo['media_alttext'],
                'source'=>  $photo['media_source'],
                'path'  =>  getenv('PATH.STORAGE') . date('Y/m/', strtotime($photo['cdate'])) . $photo['file'],
            ];

            if (getenv('MEDIA.MEDIA.EXPORT_RAW'))
                $set['raw'] = $photo;

            $_data[$photo['embed_id']] = $set;

            $_data['_']++;
            unset($set);
        }

        // Если медиаданных этого типа нет - возвращаем пустой массив
        if ($_data['_'] === 0)
            $_data = [];

        return $_data;
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

}
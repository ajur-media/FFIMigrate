<?php

namespace FFIExport;

use Arris\DB;
use Exception;

class Export
{
    /**
     * Парсит и экспортирует тайтловую фотографию на основе CDATE + Filename
     *
     * @param $photo
     * @return array
     */
    public function parseMediaTitle($photo)
    {
        $u_photo = ($photo != '') ? @unserialize($photo) : [];

        $_media_title = [
            '_'     =>  'not_found'
        ];

        $is_present = false;

        if (!empty($u_photo) && is_array($u_photo)) {
            $_file = array_key_exists('file', $u_photo) ? stripslashes($u_photo['file']) : null;
            $_cdate = array_key_exists('cdate', $u_photo) ? $u_photo['cdate'] : null;
            $_descr = array_key_exists('descr', $u_photo) ? $u_photo['descr'] : null;

            if ($_file && $_cdate) {
                $_storage_filepath = getenv('PATH.STORAGE') . 'photos/' . date('Y/m', strtotime($_cdate));

                if (FFIECommon::_is_file_present($_storage_filepath . '/' . $_file)) {
                    $_media_title = [
                        '_'     =>  'path/cdate',
                        'uri'   =>  'photos/' . date('Y/m', strtotime($_cdate)) . '/' . $_file,
                        'size'  =>  @filesize($_storage_filepath . '/' . $_file),
                        'mime'  =>  @mime_content_type($_storage_filepath . '/' . $_file)
                    ];

                    $is_present = true;
                }
            }

            if ($is_present && $_descr) {
                $_media_title['titles'] = trim($u_photo['descr']);
            }

        }

        if (getenv('MEDIA.TITLE.EXPORT_RAW') && $u_photo) {
            $_media_title['raw'] = $u_photo;
        }

        return $_media_title;
    }

    /**
     * Экспортирует набор инлайт-медиа-файлов
     *
     * @param $id
     * @param $media_collection_inline
     * @param $relation_table - таблица связи КОЛЛЕКЦИЯ_ИТЕМОВ <-- ?? --> FILES (pages_files, articles_files, etc)
     * @return array
     * @throws Exception
     */
    public function parseMediaInline($id, &$media_collection_inline, $relation_table)
    {
        $query = "
SELECT
       J.id AS embed_id, 
       J.fid AS mediafile_id,
       J.descr AS media_description,
       f.source AS media_source,
       f.link AS media_link,
       f.type AS media_type,
       f.cdate AS media_cdate,
       f.file AS mediafile_filename,
       f.name AS mediafile_originalfilename
FROM 
     {$relation_table} AS J 
LEFT JOIN files AS f ON f.id = J.fid 
WHERE J.item = {$id}
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
                'descr' =>  trim($resource['media_description']),
            ];

            if (getenv('MEDIA.MEDIA.EXPORT_RAW')) {
                $info_media['raw'] = $resource;
            }

            $info_file = [
                'fid'       =>  $media_fid,
                'type'      =>  $media_type,
                'from'      =>  [
                    'source'    =>  trim($resource['media_source']),
                    'link'      =>  trim($resource['media_link']),
                ],
                'cdate'     =>  FFIECommon::_date_format($resource['media_cdate']),
                'original_name' =>  trim($resource['mediafile_originalfilename']),
            ];

            $basepath_storage = $media_type . '/' . date('Y/m', strtotime($resource['media_cdate'])) . '/';
            $basepath_full = getenv('PATH.STORAGE') . $basepath_storage;

            $paths = [];
            switch ($media_type) {
                case 'photos': {
                    $fn_preview     = '650x486_' . $resource['mediafile_filename'];
                    $fn_full        = '1280x1024_' . $resource['mediafile_filename'];
                    $fn_original    = $resource['mediafile_filename'];

                    FFIECommon::_get_file_info($info_file['preview'], $fn_preview, $basepath_storage, $basepath_full);
                    FFIECommon::_get_file_info($info_file['full'], $fn_full, $basepath_storage, $basepath_full);
                    FFIECommon::_get_file_info($info_file['original'], $fn_original, $basepath_storage, $basepath_full);

                    break;
                }
                case 'files':
                case 'audios':
                case 'videos': {
                    $fn_full = $resource['mediafile_filename'];
                    FFIECommon::_get_file_info($info_file['full'], $fn_full, $basepath_storage, $basepath_full);

                    break;
                }

            } // switch
            $info_file['paths'] = $paths;

            $info_media['file'] = $info_file;

            $_data[ (int)$resource['embed_id'] ] = $info_media;

            $_data['_']++;

            $media_collection_inline[ $media_fid ] = $info_file;

            unset($info_media);
        }

        // Если медиаданных этого типа нет - возвращаем пустой массив
        if ($_data['_'] === 0)
            $_data = [];

        return $_data;
    }

    public function parseCoords($raw)
    {
        $coords = explode(',', $raw, 2) ?? [0, 0];
        if (count($coords) < 2) $coords = [0, 0];
        return [
            'lat'       =>  @round($coords[0], 5),
            'lon'       =>  @round($coords[1], 5),
            'raw'       =>  trim($raw)
        ];
    }

    /**
     *
     * @param $source_html
     * @return array
     * @throws \Spatie\Regex\RegexFailed
     */
    public function parseHTMLWidgets($source_html)
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

        $html = FFIECommon::_isBase64($source_html) ? base64_decode($source_html) : $source_html;
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

} // class

# -eof-

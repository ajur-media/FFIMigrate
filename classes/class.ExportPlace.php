<?php


use Arris\DB;

class ExportPlace
{
    const QUERY_FETCH_PLACES = "
SELECT 
p.id, p.rubric_id, rp.name AS rubric_name,
p.district_id, d.name AS district_name,
p.location_id, 
p.s_lang_ru, p.s_lang_en, p.s_lang_fi, 
p.website, p.phone, p.email, p.worktime, 
p.cdate, p.photo, 
p.title, p.short, 
p.text, p.text_bb, p.address,
p.tags, 
p.coords
FROM places AS p
LEFT JOIN rubrics_places AS rp ON (rp.id = p.rubric_id )
LEFT JOIN districts AS d ON (d.id = p.district_id )
WHERE p.s_hidden = 0
    ";


    public $_dataset = [];

    private $id, $cdate;
    private $rubric_id, $rubric_name;
    private $district_id, $district_name;
    private $title, $short, $text, $text_bb;
    private $s_lang_en, $s_lang_ru, $s_lang_fi;
    private $website, $phone, $email, $worktime, $address, $coords;
    private $tags;
    private $photo;

    private $_media_collection_inline = [];

    public function __construct()
    {
        $coords = explode(',', $this->coords, 2) ?? [0, 0];
        if (count($coords) < 2) $coords = [0, 0];

        $this->_article_media_title = $this->parseMediaTitle();
        $this->_article_media_inline = $this->parseMediaInline();

        $this->_dataset = [
            'id'        =>  (int)$this->id,
            'cdate'     =>  FFIECommon::_date_format($this->cdate),
            'type'      =>  'place',
            'media'     =>  [
                'title'     =>  $this->_article_media_title,
                'media'     =>  $this->_article_media_inline,
            ],
            'content'   =>  [
                'title'     =>  $this->title,
                'lead'      =>  $this->short,
                'text_bb'   =>  $this->text_bb,
            ],
            'rubric'    =>  [
                'id'        =>  $this->rubric_id,
                'name'      =>  $this->rubric_name
            ],
            'district'  =>  [
                'id'        =>  $this->district_id,
                'name'      =>  $this->district_name
            ],
            'langs'     =>  [
                'en'        =>  $this->s_lang_en,
                'ru'        =>  $this->s_lang_ru,
                'fi'        =>  $this->s_lang_fi
            ],
            'contacts'  =>  [
                'website'   =>  $this->website,
                'phone'     =>  $this->phone,
                'email'     =>  $this->email,
                'worktime'  =>  $this->worktime,
            ],
            'location'  =>  [
                'address'   =>  $this->address,
                'coords'    =>  [
                    'lat'       =>  @round($coords[0], 4),
                    'lon'       =>  @round($coords[1], 4)
                ],
                'coords_raw'=>  $this->coords
            ],
            'tags'  =>  @unserialize($this->tags)
        ];
    }

    public function export()
    {
        return $this->_dataset;
    }

    private function parseMediaTitle()
    {
        $u_photo = ($this->photo != '') ? @unserialize($this->photo) : [];

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
                $_media_title['titles'] = $u_photo['descr'];
            }

        }

        if (getenv('MEDIA.TITLE.EXPORT_RAW') && $u_photo) {
            $_media_title['raw'] = $u_photo;
        }

        return $_media_title;
    }

    private function parseMediaInline()
    {
        $query = "
       SELECT
       pf.id AS embed_id, 
       pf.fid AS mediafile_id,
       pf.descr AS media_description,
       f.source AS media_source,
       f.link AS media_link,
       f.type AS media_type,
       f.cdate AS media_cdate,
       f.file AS mediafile_filename,
       f.name AS mediafile_originalfilename
FROM 
     places_files AS pf 
LEFT JOIN files AS f ON f.id = pf.fid 
WHERE pf.item = {$this->id}
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
            ];

            $basepath_storage = $media_type . '/' . date('Y/m', strtotime($resource['media_cdate'])) . '/';
            $basepath_full = getenv('PATH.STORAGE') . $basepath_storage;

            $paths = [];
            $storage = [];
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
            $info_file['storage'] = $storage;

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

}
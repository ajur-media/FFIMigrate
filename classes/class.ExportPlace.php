<?php

namespace FFIExport;

use Exception;

class ExportPlace extends Export
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
    /**
     * @var array
     */
    private $_article_media_title = [];
    private $_article_media_inline = [];

    /**
     * ExportPlace constructor.
     *
     * @throws Exception
     */
    public function __construct()
    {
        $this->_article_media_title = parent::parseMediaTitle($this->photo);
        $this->_article_media_inline = parent::parseMediaInline($this->id, $this->_media_collection_inline, 'places_files');

        $this->_dataset = [
            'id'        =>  (int)$this->id,
            'cdate'     =>  FFIECommon::_date_format($this->cdate),
            'type'      =>  'place',
            'media'     =>  [
                'title'     =>  $this->_article_media_title,
                'media'     =>  $this->_article_media_inline,
            ],
            'content'   =>  [
                'title'     =>  FFIECommon::_trim($this->title, 'TRIM.TITLE'),
                'lead'      =>  FFIECommon::_trim($this->short, 'TRIM.LEAD'),
                'text_bb'   =>  FFIECommon::_trim($this->text_bb, 'TRIM.TEXT'),
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
                'website'   =>  FFIECommon::_trim($this->website),
                'phone'     =>  FFIECommon::_trim($this->phone),
                'email'     =>  FFIECommon::_trim($this->email),
                'worktime'  =>  FFIECommon::_trim($this->worktime),
            ],
            'location'  =>  [
                'address'   =>  FFIECommon::_trim($this->address),
                'coords'    =>  parent::parseCoords($this->coords)
            ],
            'tags'  =>  @unserialize($this->tags)
        ];
    }

    public function export()
    {
        return $this->_dataset;
    }

} // class

# -eof-

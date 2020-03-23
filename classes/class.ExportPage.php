<?php

namespace FFIExport;

use Arris\DB;

class ExportPage
{
    const sql_query_get_pages_all = "
SELECT 
       p.id, p.old_url, 
       p.rubric_id, rp.name AS rubric_name, 
       p.title, p.title2, 
       p.`text`, p.text_bb, 
       p.i_question, p.i_answer, 
       p.`system` AS page_is_system,
       p.s_top AS page_is_top,
p.meta_title, p.meta_keywords, p.meta_descr
FROM pages AS p
LEFT JOIN rubrics_pages AS rp ON rp.id = p.rubric_id
ORDER BY p.id    
    ";

    private
        $id, $old_url,
        $rubric_id, $rubric_name,
        $title, $title2,
        $text, $text_bb,
        $i_question, $i_answer, $meta_descr,
        $meta_title, $meta_keywords,
        $page_is_system, $page_is_top;

    private $_media_collection_inline = [];

    /**
     *
     * ExportPage constructor.
     * @throws Exception
     */
    public function __construct()
    {
        $this->_related = $this->getRelated();

        $this->_media_inline = $this->parseMediaInline();

        $this->_dataset = [
            'id'        =>  (int)$this->id,
            'type'      =>  'page',
            'status'    =>  [
                'is_top'    =>  $this->page_is_top,
                'is_system' =>  $this->page_is_system
            ],
            'content'   =>  [
                'title'     =>  FFIECommon::_trim($this->title, 'TRIM.TITLE'),
                'title2'    =>  FFIECommon::_trim($this->title2, 'TRIM.TITLE'),
                'text_length'   =>  mb_strlen(trim($this->text_bb)),
                'text_bb'   =>  $this->text_bb,
            ],
            'old_url'   =>  $this->old_url,
            'media'     =>  [
                'media'     =>  $this->_media_inline,
            ],
            'rubric'    =>  [
                'id'        =>  $this->rubric_id,
                'name'      =>  $this->rubric_name
            ],
            'faq'       =>  [
                'question'  =>  $this->i_question,
                'answer'    =>  $this->i_answer
            ],
            'view_also'  =>  [
                '_'     =>  count($this->_related),
                'list'  =>  $this->_related,
            ],
            'meta'    =>  [
                'title'     =>  $this->meta_title,
                'keywords'  =>  $this->meta_keywords,
                'description'=> $this->meta_descr,
            ],
        ];

        /*if ($this->is_external !== false) {
            $this->_dataset['content']['external'] = $this->is_external;
        }*/
    }

    public function export()
    {
        return $this->_dataset;
    }

    /**
     * Articles related to page
     *
     * @return array
     * @throws Exception
     */
    private function getRelated()
    {
        return
            DB::query("
SELECT id, cdate, title FROM articles
WHERE id IN (SELECT bind FROM pages_related WHERE item = {$this->id})
ORDER BY cdate DESC")
                ->fetchAll(PDO::FETCH_FUNC, function ($id, $cdate, $title) {
                    return [
                        'id'    =>  (int)$id,
                        'cdate' =>  FFIECommon::_date_format($cdate),
                        'title' =>  $title
                    ];
                });
    }

    /**
     * @return array
     * @throws Exception
     */
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
     pages_files AS pf 
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
            switch ($media_type) {
                case 'photos': {
                    $fn_preview     = '650x486_' . $resource['mediafile_filename'];
                    $fn_full        = '1280x1024_' . $resource['mediafile_filename'];
                    $fn_original    = $resource['mediafile_filename'];

                    FFIECommon::_get_file_info($info_file['preview'], $fn_preview, $basepath_storage, $basepath_full);
                    FFIECommon::_get_file_info($info_file['full'], $fn_full, $basepath_storage, $basepath_full);
                    FFIECommon::_get_file_info($info_file['original'], $fn_original, $basepath_storage, $basepath_full);

                    /*
                    if (FFIECommon::_is_file_present($basepath_full . '650x486_' . $resource['mediafile_filename'])) {
                        $paths['preview'] = $basepath_storage . '650x486_' . $resource['mediafile_filename'];
                    }

                    if (FFIECommon::_is_file_present($basepath_full . '1280x1024_' . $resource['mediafile_filename'])) {
                        $paths['full'] = $basepath_storage . '1280x1024_' . $resource['mediafile_filename'];
                    }

                    if (FFIECommon::_is_file_present($basepath_full . $resource['mediafile_filename'])) {
                        $paths['original'] = $basepath_storage . $resource['mediafile_filename'];
                    }*/

                    break;
                }
                case 'files':
                case 'audios':
                case 'videos': {
                    $fn_full = $resource['mediafile_filename'];
                    FFIECommon::_get_file_info($info_file['full'], $fn_full, $basepath_storage, $basepath_full);

                    /*if (FFIECommon::_is_file_present($basepath_full . $resource['mediafile_filename'])) {
                        $paths['full'] = $basepath_storage . $resource['mediafile_filename'];
                    }*/

                    break;
                }

            } // switch
            $info_file['paths'] = $paths;

            $info_media['file'] = $info_file;

            $_data[ (int)$resource['embed_id'] ] = $info_media;

            $_data['_']++;

            $this->_media_collection_inline[ $media_fid ] = $info_file;

            unset($info_media);
        }

        // Если медиаданных этого типа нет - возвращаем пустой массив
        if ($_data['_'] === 0)
            $_data = [];

        return $_data;
    }

    public function exportInlineMediaCollection()
    {
        return $this->_media_collection_inline;
    }


}
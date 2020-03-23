<?php

namespace FFIExport;

use Exception;
use Arris\DB;
use PDO;

class ExportPage extends Export
{
    const QUERY_FETCH_PAGES = "
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
     * @var array
     */
    private $_media_inline = [];
    /**
     * @var array
     */
    private $_related = [];
    /**
     * @var array
     */
    private $_dataset = [];

    /**
     *
     * ExportPage constructor.
     * @throws Exception
     */
    public function __construct()
    {
        $this->_related = $this->getRelatedPages();

        $this->_media_inline = parent::parseMediaInline($this->id, $this->_media_collection_inline);
        // $this->_media_inline = FFIECommon::parseMediaInline($this->id, $this->_media_collection_inline);

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
    private function getRelatedPages()
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

    public function exportInlineMediaCollection()
    {
        return $this->_media_collection_inline;
    }


} // class

# -eof-

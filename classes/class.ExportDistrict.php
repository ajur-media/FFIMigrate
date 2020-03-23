<?php

namespace FFIExport;

use Arris\DB;
use Exception;

class ExportDistrict extends Export
{
    const QUERY_FETCH_DISTRICTS = "SELECT * FROM districts WHERE hidden = 0";

    private $_dataset = [];

    private $_content = [];

    private $id, $name, $text_bb, $photo, $coords;

    /**
     * ExportDistrict constructor.
     *
     * @throws Exception
     */
    public function __construct()
    {
        // Список рубрик, присоединенных к району
        $rubrics_of_district = $this->getRubricsOfDistrict($this->id);

        foreach ($rubrics_of_district as $rod) {
            $this->_content[] = [
                'id'    =>  $rod['id'],
                'title' =>  $rod['name'],
                'items' =>  $this->getRubricContent($rod['id'])
            ];
        }

        // Список антиресных страниц
        $pages_interesting = $this->getInterestingPages();
        $this->_content[] = [
            'id'    =>  "{$this->id}",
            'title' =>  "Интересные места",
            'items' =>  $pages_interesting
        ];

        $html = $this->convertDatasetToHTML($this->_content);

        $this->_dataset = [
            'id'        =>  $this->id,
            'type'      =>  'district',
            'content'   =>  [
                'title'     =>  $this->name,
                'lead'      =>  trim(strip_tags($this->text_bb)),
                'text'      =>  $html,
                'raw'       =>  $this->_content
            ],
            'media'     =>  [
                // 'title'     =>  FFIECommon::parseMediaTitle($this->photo)
                'title'     =>  parent::parseMediaTitle($this->photo)
            ],
            'coords'    =>  parent::parseCoords($this->coords)
        ];
    }

    public function export()
    {
        return $this->_dataset;
    }

    /**
     * Получаем список рубрик, присоединённых к району
     *
     * @param $id - id района
     *
     * @return array
     * @throws Exception
     */
    private function getRubricsOfDistrict($id)
    {
        $sql = "
        SELECT ra.*, d.name as district_name FROM rubrics_pages as ra
 LEFT JOIN districts as d ON (d.id = ra.rid) WHERE ra.rid = {$id} ORDER BY rid, sort ASC 
        ";

        $sth = DB::C()->query($sql);

        return $sth->fetchAll();
    }

    /**
     * Получаем список статей в рубрике, присоединённой к району
     *
     * @param $id - id рубрики
     * @return array
     * @throws Exception
     */
    private function getRubricContent($id)
    {
        $sql = "SELECT id, title FROM pages WHERE rubric_id = {$id} ORDER BY sorder";
        return DB::C()->query($sql)->fetchAll();
    }

    /**
     * Экспорт "интересных мест"
     *
     * @return array
     * @throws Exception
     */
    private function getInterestingPages()
    {
        $sql = "SELECT id, title FROM places WHERE s_hidden = 0 AND s_draft = 0 AND district_id = {$this->id}";

        return DB::C()->query($sql)->fetchAll();
    }

    /**
     * @param array $_content
     * @return string
     */
    private function convertDatasetToHTML(array $_content)
    {
        $html = "<style type='text/css'>ul.ffi { list-style: none; } ul.ffi li:before { content: '»'; margin-right: 5px; }</style>" . PHP_EOL;

        foreach ($_content as $rubric_content) {
            $html .= PHP_EOL . "<h1>{$rubric_content['title']}</h1>" . PHP_EOL . "<p>" . PHP_EOL;

            $html .= "  <ul class='ffi'>" . PHP_EOL;

            foreach ($rubric_content['items'] as $links) {
                $html .= "    <li><a href='/places/{$links['id']}/'>{$links['title']}</a></li>" . PHP_EOL;
            }

            $html .= "  </ul>" . PHP_EOL . "</p>" . PHP_EOL;
        } // rubrics

        return $html;
    }


} // class

# -eof-
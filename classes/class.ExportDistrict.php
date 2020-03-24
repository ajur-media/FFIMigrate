<?php

namespace FFIExport;

use Arris\DB;
use Exception;

class ExportDistrict extends Export
{
    const QUERY_FETCH_DISTRICTS = "SELECT * FROM districts WHERE hidden = 0";

    private $_dataset = [];

    private $_content_pages = [];
    private $_content_places = [];

    private $id, $name, $text_bb, $photo, $coords;

    /**
     * ExportDistrict constructor.
     *
     * @throws Exception
     */
    public function __construct()
    {
        $html
            = getenv('FORCE.DISTRICTS.STYLING') == 1
            ? '<style type="text/css">ul.ffi { list-style: none; } ul.ffi li:before { content: "»"; margin-right: 5px; }</style> '
            : '';


        // Список рубрик, присоединенных к району
        // они ведут на http://fontankafi.ru/pages/*/
        $rubrics_of_district = $this->getRubricsOfDistrict($this->id);
        foreach ($rubrics_of_district as $rod) {
            $this->_content_pages[] = [
                'id'    =>  $rod['id'],
                'title' =>  $rod['name'],
                'type'  =>  'page',
                'items' =>  $this->getRubricContent($rod['id'])
            ];
        }

        $html .= $this->convertDatasetToHTML($this->_content_pages, 'pages');

        // Список антиресных страниц
        // они ведут на http://fontankafi.ru/places/116/
        $pages_interesting = $this->getInterestingPages();
        $this->_content_places[] = [
            'id'    =>  "{$this->id}",
            'title' =>  "Интересные места",
            'type'  =>  'place',
            'items' =>  $pages_interesting
        ];

        $html .= $this->convertDatasetToHTML($this->_content_places, 'places');

        $this->_dataset = [
            'id'        =>  $this->id,
            'type'      =>  'district',
            'content'   =>  [
                'title'     =>  FFIECommon::_trim($this->name, 'TRIM.TITLE'),
                'lead'      =>  FFIECommon::_trim(strip_tags($this->text_bb), 'TRIM.LEAD'),
                'text_bb'   =>  $html,
            ],
            'media'     =>  [
                'title'     =>  parent::parseMediaTitle($this->photo)
            ],
            'coords'    =>  parent::parseCoords($this->coords),
            'debug'     =>  [
                'content_pages'     =>  $this->_content_pages,
                'content_places'    =>  $this->_content_places
            ],
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
     * @param string $type -- тип ссылки - places или pages
     * @return string
     */
    private function convertDatasetToHTML(array $_content, $type)
    {
        $ffi_url = getenv('PATH.DOMAIN');

        $html = '';

        foreach ($_content as $rubric_content) {
            $html .= "<h1>{$rubric_content['title']}</h1>";
            $html .= "<p>";

            $html .= "  <ul class=\"ffi\">";

            foreach ($rubric_content['items'] as $links) {
                $html .= "    <li><a href=\"{$ffi_url}/{$type}/{$links['id']}/\">{$links['title']}</a></li>";
            }

            $html .= "  </ul>";
            $html .= "</p>";
        } // rubrics

        return $html;
    }


} // class

# -eof-

<?php

declare(strict_types=1);

namespace PhpMyAdmin\Display;

use PhpMyAdmin\Config;
use PhpMyAdmin\Config\SpecialSchemaLinks;
use PhpMyAdmin\ConfigStorage\Relation;
use PhpMyAdmin\Core;
use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\Dbal\ResultInterface;
use PhpMyAdmin\FieldMetadata;
use PhpMyAdmin\Html\Generator;
use PhpMyAdmin\Index;
use PhpMyAdmin\Message;
use PhpMyAdmin\Plugins\Transformations\Output\Text_Octetstream_Sql;
use PhpMyAdmin\Plugins\Transformations\Output\Text_Plain_Json;
use PhpMyAdmin\Plugins\Transformations\Output\Text_Plain_Sql;
use PhpMyAdmin\Plugins\Transformations\Text_Plain_Link;
use PhpMyAdmin\Plugins\TransformationsPlugin;
use PhpMyAdmin\ResponseRenderer;
use PhpMyAdmin\Sanitize;
use PhpMyAdmin\Sql;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Utils\Query;
use PhpMyAdmin\StatementInfo;
use PhpMyAdmin\Table;
use PhpMyAdmin\Template;
use PhpMyAdmin\Theme\Theme;
use PhpMyAdmin\Transformations;
use PhpMyAdmin\Url;
use PhpMyAdmin\Util;
use PhpMyAdmin\Utils\Gis;

use function __;
use function array_filter;
use function array_keys;
use function array_merge;
use function array_shift;
use function bin2hex;
use function ceil;
use function class_exists;
use function count;
use function explode;
use function file_exists;
use function floor;
use function htmlspecialchars;
use function implode;
use function in_array;
use function intval;
use function is_array;
use function is_int;
use function is_numeric;
use function json_encode;
use function max;
use function mb_check_encoding;
use function mb_strlen;
use function mb_strpos;
use function mb_strtolower;
use function mb_strtoupper;
use function mb_substr;
use function md5;
use function mt_getrandmax;
use function pack;
use function preg_match;
use function preg_replace;
use function random_int;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strcasecmp;
use function strip_tags;
use function stripos;
use function strlen;
use function strpos;
use function strtoupper;
use function substr;
use function trim;

/**
 * Handle all the functionalities related to displaying results
 * of sql queries, stored procedure, browsing sql processes or
 * displaying binary log.
 */
class Results
{
    public const POSITION_LEFT = 'left';
    public const POSITION_RIGHT = 'right';
    public const POSITION_BOTH = 'both';
    public const POSITION_NONE = 'none';

    public const DISPLAY_FULL_TEXT = 'F';
    public const DISPLAY_PARTIAL_TEXT = 'P';

    public const RELATIONAL_KEY = 'K';
    public const RELATIONAL_DISPLAY_COLUMN = 'D';

    public const GEOMETRY_DISP_GEOM = 'GEOM';
    public const GEOMETRY_DISP_WKT = 'WKT';
    public const GEOMETRY_DISP_WKB = 'WKB';

    public const SMART_SORT_ORDER = 'SMART';
    public const ASCENDING_SORT_DIR = 'ASC';
    public const DESCENDING_SORT_DIR = 'DESC';

    public const TABLE_TYPE_INNO_DB = 'InnoDB';
    public const ALL_ROWS = 'all';
    public const QUERY_TYPE_SELECT = 'SELECT';

    public const ACTION_LINK_CONTENT_ICONS = 'icons';
    public const ACTION_LINK_CONTENT_TEXT = 'text';

    /**
     * @psalm-var array{
     *   server: int,
     *   db: string,
     *   table: string,
     *   goto: string,
     *   sql_query: string,
     *   unlim_num_rows: int|numeric-string|false,
     *   fields_meta: FieldMetadata[],
     *   is_count: bool|null,
     *   is_export: bool|null,
     *   is_func: bool|null,
     *   is_analyse: bool|null,
     *   num_rows: int|numeric-string,
     *   fields_cnt: int,
     *   querytime: float|null,
     *   text_dir: string|null,
     *   is_maint: bool|null,
     *   is_explain: bool|null,
     *   is_show: bool|null,
     *   is_browse_distinct: bool|null,
     *   showtable: array<string, mixed>|null,
     *   printview: bool,
     *   highlight_columns: array|null,
     *   display_params: array|null,
     *   mime_map: array|null,
     *   editable: bool|null,
     *   unique_id: int,
     *   whereClauseMap: array,
     * }
     */
    public $properties = [
        /* server id */
        'server' => 0,

        /* Database name */
        'db' => '',

        /* Table name */
        'table' => '',

        /* the URL to go back in case of errors */
        'goto' => '',

        /* the SQL query */
        'sql_query' => '',

        /* the total number of rows returned by the SQL query without any appended "LIMIT" clause programmatically */
        'unlim_num_rows' => 0,

        /* meta information about fields */
        'fields_meta' => [],

        'is_count' => null,

        'is_export' => null,

        'is_func' => null,

        'is_analyse' => null,

        /* the total number of rows returned by the SQL query */
        'num_rows' => 0,

        /* the total number of fields returned by the SQL query */
        'fields_cnt' => 0,

        /* time taken for execute the SQL query */
        'querytime' => null,

        'text_dir' => null,

        'is_maint' => null,

        'is_explain' => null,

        'is_show' => null,

        'is_browse_distinct' => null,

        /* table definitions */
        'showtable' => null,

        'printview' => false,

        /* column names to highlight */
        'highlight_columns' => null,

        /* display information */
        'display_params' => null,

        /* mime types information of fields */
        'mime_map' => null,

        'editable' => null,

        /* random unique ID to distinguish result set */
        'unique_id' => 0,

        /* where clauses for each row, each table in the row */
        'whereClauseMap' => [],
    ];

    /**
     * This variable contains the column transformation information
     * for some of the system databases.
     * One element of this array represent all relevant columns in all tables in
     * one specific database
     *
     * @var array<string, array<string, array<string, string[]>>>
     * @psalm-var array<string, array<string, array<string, array{string, class-string, string}>>> $transformationInfo
     */
    public array $transformationInfo = [];

    private Relation $relation;

    private Transformations $transformations;

    public Template $template;

    /**
     * @param string $db       the database name
     * @param string $table    the table name
     * @param int    $server   the server id
     * @param string $goto     the URL to go back in case of errors
     * @param string $sqlQuery the SQL query
     */
    public function __construct(
        private DatabaseInterface $dbi,
        string $db,
        string $table,
        int $server,
        string $goto,
        string $sqlQuery,
    ) {
        $this->relation = new Relation($this->dbi);
        $this->transformations = new Transformations();
        $this->template = new Template();

        $this->setDefaultTransformations();

        $this->properties['db'] = $db;
        $this->properties['table'] = $table;
        $this->properties['server'] = $server;
        $this->properties['goto'] = $goto;
        $this->properties['sql_query'] = $sqlQuery;
        $this->properties['unique_id'] = random_int(0, mt_getrandmax());
    }

    /**
     * Sets default transformations for some columns
     */
    private function setDefaultTransformations(): void
    {
        $jsonHighlightingData = [
            'src/Plugins/Transformations/Output/Text_Plain_Json.php',
            Text_Plain_Json::class,
            'Text_Plain',
        ];
        $sqlHighlightingData = [
            'src/Plugins/Transformations/Output/Text_Plain_Sql.php',
            Text_Plain_Sql::class,
            'Text_Plain',
        ];
        $blobSqlHighlightingData = [
            'src/Plugins/Transformations/Output/Text_Octetstream_Sql.php',
            Text_Octetstream_Sql::class,
            'Text_Octetstream',
        ];
        $linkData = [
            'src/Plugins/Transformations/Text_Plain_Link.php',
            Text_Plain_Link::class,
            'Text_Plain',
        ];
        $this->transformationInfo = [
            'information_schema' => [
                'events' => ['event_definition' => $sqlHighlightingData],
                'processlist' => ['info' => $sqlHighlightingData],
                'routines' => ['routine_definition' => $sqlHighlightingData],
                'triggers' => ['action_statement' => $sqlHighlightingData],
                'views' => ['view_definition' => $sqlHighlightingData],
            ],
            'mysql' => [
                'event' => ['body' => $blobSqlHighlightingData, 'body_utf8' => $blobSqlHighlightingData],
                'general_log' => ['argument' => $sqlHighlightingData],
                'help_category' => ['url' => $linkData],
                'help_topic' => ['example' => $sqlHighlightingData, 'url' => $linkData],
                'proc' => [
                    'param_list' => $blobSqlHighlightingData,
                    'returns' => $blobSqlHighlightingData,
                    'body' => $blobSqlHighlightingData,
                    'body_utf8' => $blobSqlHighlightingData,
                ],
                'slow_log' => ['sql_text' => $sqlHighlightingData],
            ],
        ];

        $relationParameters = $this->relation->getRelationParameters();
        if ($relationParameters->db === null) {
            return;
        }

        $relDb = [];
        if ($relationParameters->sqlHistoryFeature !== null) {
            $relDb[$relationParameters->sqlHistoryFeature->history->getName()] = ['sqlquery' => $sqlHighlightingData];
        }

        if ($relationParameters->bookmarkFeature !== null) {
            $relDb[$relationParameters->bookmarkFeature->bookmark->getName()] = ['query' => $sqlHighlightingData];
        }

        if ($relationParameters->trackingFeature !== null) {
            $relDb[$relationParameters->trackingFeature->tracking->getName()] = [
                'schema_sql' => $sqlHighlightingData,
                'data_sql' => $sqlHighlightingData,
            ];
        }

        if ($relationParameters->favoriteTablesFeature !== null) {
            $table = $relationParameters->favoriteTablesFeature->favorite->getName();
            $relDb[$table] = ['tables' => $jsonHighlightingData];
        }

        if ($relationParameters->recentlyUsedTablesFeature !== null) {
            $table = $relationParameters->recentlyUsedTablesFeature->recent->getName();
            $relDb[$table] = ['tables' => $jsonHighlightingData];
        }

        if ($relationParameters->savedQueryByExampleSearchesFeature !== null) {
            $table = $relationParameters->savedQueryByExampleSearchesFeature->savedSearches->getName();
            $relDb[$table] = ['search_data' => $jsonHighlightingData];
        }

        if ($relationParameters->databaseDesignerSettingsFeature !== null) {
            $table = $relationParameters->databaseDesignerSettingsFeature->designerSettings->getName();
            $relDb[$table] = ['settings_data' => $jsonHighlightingData];
        }

        if ($relationParameters->uiPreferencesFeature !== null) {
            $table = $relationParameters->uiPreferencesFeature->tableUiPrefs->getName();
            $relDb[$table] = ['prefs' => $jsonHighlightingData];
        }

        if ($relationParameters->userPreferencesFeature !== null) {
            $table = $relationParameters->userPreferencesFeature->userConfig->getName();
            $relDb[$table] = ['config_data' => $jsonHighlightingData];
        }

        if ($relationParameters->exportTemplatesFeature !== null) {
            $table = $relationParameters->exportTemplatesFeature->exportTemplates->getName();
            $relDb[$table] = ['template_data' => $jsonHighlightingData];
        }

        $this->transformationInfo[$relationParameters->db->getName()] = $relDb;
    }

    /**
     * Set properties which were not initialized at the constructor
     *
     * @param int|string                $unlimNumRows     the total number of rows returned by the SQL query without
     *                                                    any appended "LIMIT" clause programmatically
     * @param FieldMetadata[]           $fieldsMeta       meta information about fields
     * @param bool                      $isCount          statement is SELECT COUNT
     * @param bool                      $isExport         statement contains INTO OUTFILE
     * @param bool                      $isFunction       statement contains a function like SUM()
     * @param bool                      $isAnalyse        statement contains PROCEDURE ANALYSE
     * @param int|string                $numRows          total no. of rows returned by SQL query
     * @param float                     $queryTime        time taken for execute the SQL query
     * @param string                    $textDirection    text direction
     * @param bool                      $isMaintenance    statement contains a maintenance command
     * @param bool                      $isExplain        statement contains EXPLAIN
     * @param bool                      $isShow           statement contains SHOW
     * @param array<string, mixed>|null $showTable        table definitions
     * @param bool                      $printView        print view was requested
     * @param bool                      $editable         whether the results set is editable
     * @param bool                      $isBrowseDistinct whether browsing distinct values
     * @psalm-param int|numeric-string $unlimNumRows
     * @psalm-param int|numeric-string $numRows
     */
    public function setProperties(
        int|string $unlimNumRows,
        array $fieldsMeta,
        bool $isCount,
        bool $isExport,
        bool $isFunction,
        bool $isAnalyse,
        int|string $numRows,
        float $queryTime,
        string $textDirection,
        bool $isMaintenance,
        bool $isExplain,
        bool $isShow,
        array|null $showTable,
        bool $printView,
        bool $editable,
        bool $isBrowseDistinct,
    ): void {
        $this->properties['unlim_num_rows'] = $unlimNumRows;
        $this->properties['fields_meta'] = $fieldsMeta;
        $this->properties['is_count'] = $isCount;
        $this->properties['is_export'] = $isExport;
        $this->properties['is_func'] = $isFunction;
        $this->properties['is_analyse'] = $isAnalyse;
        $this->properties['num_rows'] = $numRows;
        $this->properties['fields_cnt'] = count($fieldsMeta);
        $this->properties['querytime'] = $queryTime;
        $this->properties['text_dir'] = $textDirection;
        $this->properties['is_maint'] = $isMaintenance;
        $this->properties['is_explain'] = $isExplain;
        $this->properties['is_show'] = $isShow;
        $this->properties['showtable'] = $showTable;
        $this->properties['printview'] = $printView;
        $this->properties['editable'] = $editable;
        $this->properties['is_browse_distinct'] = $isBrowseDistinct;
    }

    /**
     * Defines the parts to display for a print view
     */
    private function setDisplayPartsForPrintView(DisplayParts $displayParts): DisplayParts
    {
        return $displayParts->with([
            'hasEditLink' => false,
            'deleteLink' => DeleteLinkEnum::NO_DELETE,
            'hasSortLink' => false,
            'hasNavigationBar' => false,
            'hasBookmarkForm' => false,
            'hasTextButton' => false,
            'hasPrintLink' => false,
        ]);
    }

    /**
     * Defines the parts to display for a SHOW statement
     */
    private function setDisplayPartsForShow(DisplayParts $displayParts): DisplayParts
    {
        preg_match(
            '@^SHOW[[:space:]]+(VARIABLES|(FULL[[:space:]]+)?'
            . 'PROCESSLIST|STATUS|TABLE|GRANTS|CREATE|LOGS|DATABASES|FIELDS'
            . ')@i',
            $this->properties['sql_query'],
            $which,
        );

        $bIsProcessList = isset($which[1]);
        if ($bIsProcessList) {
            $str = ' ' . strtoupper($which[1]);
            $bIsProcessList = strpos($str, 'PROCESSLIST') > 0;
        }

        return $displayParts->with([
            'hasEditLink' => false,
            'deleteLink' => $bIsProcessList ? DeleteLinkEnum::KILL_PROCESS : DeleteLinkEnum::NO_DELETE,
            'hasSortLink' => false,
            'hasNavigationBar' => false,
            'hasBookmarkForm' => true,
            'hasTextButton' => true,
            'hasPrintLink' => true,
        ]);
    }

    /**
     * Defines the parts to display for statements not related to data
     */
    private function setDisplayPartsForNonData(DisplayParts $displayParts): DisplayParts
    {
        // Statement is a "SELECT COUNT", a
        // "CHECK/ANALYZE/REPAIR/OPTIMIZE/CHECKSUM", an "EXPLAIN" one or
        // contains a "PROC ANALYSE" part
        return $displayParts->with([
            'hasEditLink' => false,
            'deleteLink' => DeleteLinkEnum::NO_DELETE,
            'hasSortLink' => false,
            'hasNavigationBar' => false,
            'hasBookmarkForm' => true,
            'hasTextButton' => (bool) $this->properties['is_maint'],
            'hasPrintLink' => true,
        ]);
    }

    /**
     * Defines the parts to display for other statements (probably SELECT).
     */
    private function setDisplayPartsForSelect(DisplayParts $displayParts): DisplayParts
    {
        $fieldsMeta = $this->properties['fields_meta'];
        $previousTable = '';
        $numberOfColumns = $this->properties['fields_cnt'];
        $hasEditLink = $displayParts->hasEditLink;
        $deleteLink = $displayParts->deleteLink;
        $hasPrintLink = $displayParts->hasPrintLink;

        /** @infection-ignore-all */
        for ($i = 0; $i < $numberOfColumns; $i++) {
            $isLink = $hasEditLink || $deleteLink !== DeleteLinkEnum::NO_DELETE || $displayParts->hasSortLink;

            // Displays edit/delete/sort/insert links?
            if (
                $isLink
                && $previousTable != ''
                && $fieldsMeta[$i]->table != ''
                && $fieldsMeta[$i]->table !== $previousTable
            ) {
                // don't display links
                $hasEditLink = false;
                $deleteLink = DeleteLinkEnum::NO_DELETE;
                break;
            }

            // Always display print view link
            $hasPrintLink = true;
            if ($fieldsMeta[$i]->table == '') {
                continue;
            }

            $previousTable = $fieldsMeta[$i]->table;
        }

        if ($previousTable == '') { // no table for any of the columns
            // don't display links
            $hasEditLink = false;
            $deleteLink = DeleteLinkEnum::NO_DELETE;
        }

        return $displayParts->with([
            'hasEditLink' => $hasEditLink,
            'deleteLink' => $deleteLink,
            'hasTextButton' => true,
            'hasPrintLink' => $hasPrintLink,
        ]);
    }

    /**
     * Defines the parts to display for the results of a SQL query
     * and the total number of rows
     *
     * @see     getTable()
     *
     * @return array<int, DisplayParts|int|mixed> the first element is a {@see DisplayParts} object
     *               the second element is the total number of rows returned
     *               by the SQL query without any programmatically appended
     *               LIMIT clause (just a copy of $unlim_num_rows if it exists,
     *               else computed inside this function)
     * @psalm-return array{DisplayParts, int}
     */
    private function setDisplayPartsAndTotal(DisplayParts $displayParts): array
    {
        $theTotal = 0;

        // 1. Following variables are needed for use in isset/empty or
        //    use with array indexes or safe use in foreach
        $db = $this->properties['db'];
        $table = $this->properties['table'];
        $unlimNumRows = $this->properties['unlim_num_rows'];
        $numRows = $this->properties['num_rows'];

        // 2. Updates the display parts
        if ($this->properties['printview']) {
            $displayParts = $this->setDisplayPartsForPrintView($displayParts);
        } elseif (
            $this->properties['is_count'] || $this->properties['is_analyse']
            || $this->properties['is_maint'] || $this->properties['is_explain']
        ) {
            $displayParts = $this->setDisplayPartsForNonData($displayParts);
        } elseif ($this->properties['is_show']) {
            $displayParts = $this->setDisplayPartsForShow($displayParts);
        } else {
            $displayParts = $this->setDisplayPartsForSelect($displayParts);
        }

        // 3. Gets the total number of rows if it is unknown
        if ($unlimNumRows > 0) {
            $theTotal = $unlimNumRows;
        } elseif (
            $displayParts->hasNavigationBar
            || $displayParts->hasSortLink
            && $db !== '' && $table !== ''
        ) {
            $theTotal = $this->dbi->getTable($db, $table)->countRecords();
        }

        // if for COUNT query, number of rows returned more than 1
        // (may be being used GROUP BY)
        if ($this->properties['is_count'] && $numRows > 1) {
            $displayParts = $displayParts->with(['hasNavigationBar' => true, 'hasSortLink' => true]);
        }

        // 4. If navigation bar or sorting fields names URLs should be
        //    displayed but there is only one row, change these settings to
        //    false
        if ($displayParts->hasNavigationBar || $displayParts->hasSortLink) {
            // - Do not display sort links if less than 2 rows.
            // - For a VIEW we (probably) did not count the number of rows
            //   so don't test this number here, it would remove the possibility
            //   of sorting VIEW results.
            $tableObject = new Table($table, $db, $this->dbi);
            if ($unlimNumRows < 2 && ! $tableObject->isView()) {
                $displayParts = $displayParts->with(['hasSortLink' => false]);
            }
        }

        return [$displayParts, (int) $theTotal];
    }

    /**
     * Return true if we are executing a query in the form of
     * "SELECT * FROM <a table> ..."
     *
     * @see getTableHeaders(), getColumnParams()
     */
    private function isSelect(StatementInfo $statementInfo): bool
    {
        return ! ($this->properties['is_count']
                || $this->properties['is_export']
                || $this->properties['is_func']
                || $this->properties['is_analyse'])
            && $statementInfo->selectFrom
            && ! empty($statementInfo->statement->from)
            && (count($statementInfo->statement->from) === 1)
            && ! empty($statementInfo->statement->from[0]->table);
    }

    /**
     * Possibly return a page selector for table navigation
     *
     * @return array{string, int} ($output, $nbTotalPage)
     */
    private function getHtmlPageSelector(): array
    {
        $pageNow = (int) floor($_SESSION['tmpval']['pos'] / $_SESSION['tmpval']['max_rows']) + 1;

        $nbTotalPage = (int) ceil((int) $this->properties['unlim_num_rows'] / $_SESSION['tmpval']['max_rows']);

        $output = '';
        if ($nbTotalPage > 1) {
            $urlParams = [
                'db' => $this->properties['db'],
                'table' => $this->properties['table'],
                'sql_query' => $this->properties['sql_query'],
                'goto' => $this->properties['goto'],
                'is_browse_distinct' => $this->properties['is_browse_distinct'],
            ];

            $output = $this->template->render('display/results/page_selector', [
                'url_params' => $urlParams,
                'page_selector' => Util::pageselector(
                    'pos',
                    $_SESSION['tmpval']['max_rows'],
                    $pageNow,
                    $nbTotalPage,
                ),
            ]);
        }

        return [$output, $nbTotalPage];
    }

    /**
     * Get a navigation bar to browse among the results of a SQL query
     *
     * @see getTable()
     *
     * @param int     $posNext       the offset for the "next" page
     * @param int     $posPrevious   the offset for the "previous" page
     * @param bool    $isInnodb      whether its InnoDB or not
     * @param mixed[] $sortByKeyData the sort by key dialog
     *
     * @return mixed[]
     */
    private function getTableNavigation(
        int $posNext,
        int $posPrevious,
        bool $isInnodb,
        array $sortByKeyData,
    ): array {
        $isShowingAll = $_SESSION['tmpval']['max_rows'] === self::ALL_ROWS;

        $pageSelector = '';
        $numberTotalPage = 1;
        if (! $isShowingAll) {
            [$pageSelector, $numberTotalPage] = $this->getHtmlPageSelector();
        }

        $isLastPage = $this->properties['unlim_num_rows'] !== -1 && $this->properties['unlim_num_rows'] !== false
            && ($isShowingAll
                || intval($_SESSION['tmpval']['pos']) + intval($_SESSION['tmpval']['max_rows'])
                >= $this->properties['unlim_num_rows']
                || $this->properties['num_rows'] < $_SESSION['tmpval']['max_rows']);

        $onsubmit = ' onsubmit="return '
            . (intval($_SESSION['tmpval']['pos'])
            + intval($_SESSION['tmpval']['max_rows'])
            < $this->properties['unlim_num_rows']
            && $this->properties['num_rows'] >= intval($_SESSION['tmpval']['max_rows'])
                ? 'true'
                : 'false') . ';"';

        $config = Config::getInstance();
        $hasRealEndInput = $isInnodb && $this->properties['unlim_num_rows'] > $config->settings['MaxExactCount'];
        $posLast = 0;
        if (is_numeric($_SESSION['tmpval']['max_rows'])) {
            $posLast = @((int) ceil(
                (int) $this->properties['unlim_num_rows'] / $_SESSION['tmpval']['max_rows'],
            ) - 1) * intval($_SESSION['tmpval']['max_rows']);
        }

        $hiddenFields = [
            'db' => $this->properties['db'],
            'table' => $this->properties['table'],
            'server' => $this->properties['server'],
            'sql_query' => $this->properties['sql_query'],
            'is_browse_distinct' => $this->properties['is_browse_distinct'],
            'goto' => $this->properties['goto'],
        ];

        return [
            'page_selector' => $pageSelector,
            'number_total_page' => $numberTotalPage,
            'has_show_all' => $config->settings['ShowAll'] || ($this->properties['unlim_num_rows'] <= 500),
            'hidden_fields' => $hiddenFields,
            'session_max_rows' => $isShowingAll ? $config->settings['MaxRows'] : 'all',
            'is_showing_all' => $isShowingAll,
            'max_rows' => $_SESSION['tmpval']['max_rows'],
            'pos' => $_SESSION['tmpval']['pos'],
            'sort_by_key' => $sortByKeyData,
            'pos_previous' => $posPrevious,
            'pos_next' => $posNext,
            'pos_last' => $posLast,
            'is_last_page' => $isLastPage,
            'is_last_page_known' => $this->properties['unlim_num_rows'] !== false,
            'has_real_end_input' => $hasRealEndInput,
            'onsubmit' => $onsubmit,
        ];
    }

    /**
     * Get the headers of the results table, for all of the columns
     *
     * @see getTableHeaders()
     *
     * @param mixed[]            $sortExpression            sort expression
     * @param array<int, string> $sortExpressionNoDirection sort expression
     *                                                        without direction
     * @param mixed[]            $sortDirection             sort direction
     * @param bool               $isLimitedDisplay          with limited operations
     *                                                        or not
     * @param string             $unsortedSqlQuery          query without the sort part
     *
     * @return string html content
     */
    private function getTableHeadersForColumns(
        bool $hasSortLink,
        StatementInfo $statementInfo,
        array $sortExpression,
        array $sortExpressionNoDirection,
        array $sortDirection,
        bool $isLimitedDisplay,
        string $unsortedSqlQuery,
    ): string {
        // required to generate sort links that will remember whether the
        // "Show all" button has been clicked
        $sqlMd5 = md5($this->properties['server'] . $this->properties['db'] . $this->properties['sql_query']);
        $sessionMaxRows = $isLimitedDisplay
            ? 0
            : (int) $_SESSION['tmpval']['query'][$sqlMd5]['max_rows'];

        // Following variable are needed for use in isset/empty or
        // use with array indexes/safe use in the for loop
        $highlightColumns = $this->properties['highlight_columns'];
        $fieldsMeta = $this->properties['fields_meta'];

        // Prepare Display column comments if enabled
        // (\PhpMyAdmin\Config::getInstance()->settings['ShowBrowseComments']).
        $commentsMap = $this->getTableCommentsArray($statementInfo);

        [$colOrder, $colVisib] = $this->getColumnParams($statementInfo);

        // optimize: avoid calling a method on each iteration
        $numberOfColumns = $this->properties['fields_cnt'];

        $columns = [];

        for ($j = 0; $j < $numberOfColumns; $j++) {
            // PHP 7.4 fix for accessing array offset on bool
            $colVisibCurrent = $colVisib[$j] ?? null;

            // assign $i with the appropriate column order
            $i = $colOrder ? $colOrder[$j] : $j;

            //  See if this column should get highlight because it's used in the
            //  where-query.
            $name = $fieldsMeta[$i]->name;
            $conditionField = isset($highlightColumns[$name])
                || isset($highlightColumns[Util::backquote($name)]);

            // Prepare comment-HTML-wrappers for each row, if defined/enabled.
            $comments = $this->getCommentForRow($commentsMap, $fieldsMeta[$i]);
            $displayParams = $this->properties['display_params'] ?? [];

            if ($hasSortLink && ! $isLimitedDisplay) {
                $sortedHeaderData = $this->getOrderLinkAndSortedHeaderHtml(
                    $fieldsMeta[$i],
                    $sortExpression,
                    $sortExpressionNoDirection,
                    $unsortedSqlQuery,
                    $sessionMaxRows,
                    $comments,
                    $sortDirection,
                    $colVisib,
                    $colVisibCurrent,
                );

                $orderLink = $sortedHeaderData['order_link'];
                $columns[] = $sortedHeaderData;

                $displayParams['desc'][] = '    <th '
                    . 'class="draggable'
                    . ($conditionField ? ' condition' : '')
                    . '" data-column="' . htmlspecialchars($fieldsMeta[$i]->name)
                    . '">' . "\n" . $orderLink . $comments . '    </th>' . "\n";
            } else {
                // Results can't be sorted
                // Prepare columns to draggable effect for non sortable columns
                $columns[] = [
                    'column_name' => $fieldsMeta[$i]->name,
                    'comments' => $comments,
                    'is_column_hidden' => $colVisib && ! $colVisibCurrent,
                    'is_column_numeric' => $this->isColumnNumeric($fieldsMeta[$i]),
                    'has_condition' => $conditionField,
                ];

                $displayParams['desc'][] = '    <th '
                    . 'class="draggable'
                    . ($conditionField ? ' condition"' : '')
                    . '" data-column="' . htmlspecialchars($fieldsMeta[$i]->name)
                    . '">        '
                    . htmlspecialchars($fieldsMeta[$i]->name)
                    . $comments . '    </th>';
            }

            $this->properties['display_params'] = $displayParams;
        }

        return $this->template->render('display/results/table_headers_for_columns', [
            'is_sortable' => $hasSortLink && ! $isLimitedDisplay,
            'columns' => $columns,
        ]);
    }

    /**
     * Get the headers of the results table
     *
     * @see getTable()
     *
     * @param string             $unsortedSqlQuery          the unsorted sql query
     * @param mixed[]            $sortExpression            sort expression
     * @param array<int, string> $sortExpressionNoDirection sort expression without direction
     * @param mixed[]            $sortDirection             sort direction
     * @param bool               $isLimitedDisplay          with limited operations or not
     *
     * @psalm-return array{
     *   column_order: array,
     *   options: array,
     *   has_bulk_actions_form: bool,
     *   button: string,
     *   table_headers_for_columns: string,
     *   column_at_right_side: string,
     * }
     */
    private function getTableHeaders(
        DisplayParts $displayParts,
        StatementInfo $statementInfo,
        string $unsortedSqlQuery,
        array $sortExpression = [],
        array $sortExpressionNoDirection = [],
        array $sortDirection = [],
        bool $isLimitedDisplay = false,
    ): array {
        // Needed for use in isset/empty or
        // use with array indexes/safe use in foreach
        $printView = $this->properties['printview'];
        $displayParams = $this->properties['display_params'];

        // Output data needed for column reordering and show/hide column
        $columnOrder = $this->getDataForResettingColumnOrder($statementInfo);

        $displayParams['emptypre'] = 0;
        $displayParams['emptyafter'] = 0;
        $displayParams['textbtn'] = '';
        $fullOrPartialTextLink = '';

        $this->properties['display_params'] = $displayParams;

        // Display options (if we are not in print view)
        $optionsBlock = [];
        if (! $printView && ! $isLimitedDisplay) {
            $optionsBlock = $this->getOptionsBlock();

            // prepare full/partial text button or link
            $fullOrPartialTextLink = $this->getFullOrPartialTextButtonOrLink();
        }

        // 1. Set $colspan and generate html with full/partial
        // text button or link
        $colspan = $displayParts->hasEditLink
            && $displayParts->deleteLink !== DeleteLinkEnum::NO_DELETE ? ' colspan="4"' : '';
        $buttonHtml = $this->getFieldVisibilityParams($displayParts, $fullOrPartialTextLink, $colspan);

        // 2. Displays the fields' name
        // 2.0 If sorting links should be used, checks if the query is a "JOIN"
        //     statement (see 2.1.3)

        // See if we have to highlight any header fields of a WHERE query.
        // Uses SQL-Parser results.
        $this->setHighlightedColumnGlobalField($statementInfo);

        // Get the headers for all of the columns
        $tableHeadersForColumns = $this->getTableHeadersForColumns(
            $displayParts->hasSortLink,
            $statementInfo,
            $sortExpression,
            $sortExpressionNoDirection,
            $sortDirection,
            $isLimitedDisplay,
            $unsortedSqlQuery,
        );

        // Display column at rightside - checkboxes or empty column
        $columnAtRightSide = '';
        if (! $printView) {
            $columnAtRightSide = $this->getColumnAtRightSide($displayParts, $fullOrPartialTextLink, $colspan);
        }

        return [
            'column_order' => $columnOrder,
            'options' => $optionsBlock,
            'has_bulk_actions_form' => $displayParts->deleteLink === DeleteLinkEnum::DELETE_ROW
                || $displayParts->deleteLink === DeleteLinkEnum::KILL_PROCESS,
            'button' => $buttonHtml,
            'table_headers_for_columns' => $tableHeadersForColumns,
            'column_at_right_side' => $columnAtRightSide,
        ];
    }

    /**
     * Prepare sort by key dropdown - html code segment
     *
     * @see getTableHeaders()
     *
     * @param mixed[]|null $sortExpression   the sort expression
     * @param string       $unsortedSqlQuery the unsorted sql query
     *
     * @return mixed[][]
     * @psalm-return array{hidden_fields?:array, options?:array}
     */
    private function getSortByKeyDropDown(
        array|null $sortExpression,
        string $unsortedSqlQuery,
    ): array {
        // grab indexes data:
        $indexes = Index::getFromTable($this->dbi, $this->properties['table'], $this->properties['db']);

        // do we have any index?
        if ($indexes === []) {
            return [];
        }

        $hiddenFields = [
            'db' => $this->properties['db'],
            'table' => $this->properties['table'],
            'server' => $this->properties['server'],
            'sort_by_key' => '1',
        ];

        // Keep the number of rows (25, 50, 100, ...) when changing sort key value
        if (isset($_SESSION['tmpval']) && isset($_SESSION['tmpval']['max_rows'])) {
            $hiddenFields['session_max_rows'] = $_SESSION['tmpval']['max_rows'];
        }

        $isIndexUsed = false;
        $localOrder = is_array($sortExpression) ? implode(', ', $sortExpression) : '';

        $options = [];
        foreach ($indexes as $index) {
            $ascSort = '`'
                . implode('` ASC, `', array_keys($index->getColumns()))
                . '` ASC';

            $descSort = '`'
                . implode('` DESC, `', array_keys($index->getColumns()))
                . '` DESC';

            $isIndexUsed = $isIndexUsed
                || $localOrder === $ascSort
                || $localOrder === $descSort;

            $unsortedSqlQueryFirstPart = $unsortedSqlQuery;
            $unsortedSqlQuerySecondPart = '';
            if (
                preg_match(
                    '@(.*)([[:space:]](LIMIT (.*)|PROCEDURE (.*)|FOR UPDATE|LOCK IN SHARE MODE))@is',
                    $unsortedSqlQuery,
                    $myReg,
                )
            ) {
                $unsortedSqlQueryFirstPart = $myReg[1];
                $unsortedSqlQuerySecondPart = $myReg[2];
            }

            $options[] = [
                'value' => $unsortedSqlQueryFirstPart . ' ORDER BY '
                    . $ascSort . $unsortedSqlQuerySecondPart,
                'content' => $index->getName() . ' (ASC)',
                'is_selected' => $localOrder === $ascSort,
            ];
            $options[] = [
                'value' => $unsortedSqlQueryFirstPart . ' ORDER BY '
                    . $descSort . $unsortedSqlQuerySecondPart,
                'content' => $index->getName() . ' (DESC)',
                'is_selected' => $localOrder === $descSort,
            ];
        }

        $options[] = ['value' => $unsortedSqlQuery, 'content' => __('None'), 'is_selected' => ! $isIndexUsed];

        return ['hidden_fields' => $hiddenFields, 'options' => $options];
    }

    /**
     * Set column span, row span and prepare html with full/partial
     * text button or link
     *
     * @see getTableHeaders()
     *
     * @param string $fullOrPartialTextLink full/partial link or text button
     * @param string $colspan               column span of table header
     *
     * @return string html with full/partial text button or link
     */
    private function getFieldVisibilityParams(
        DisplayParts $displayParts,
        string $fullOrPartialTextLink,
        string $colspan,
    ): string {
        $displayParams = $this->properties['display_params'];

        // 1. Displays the full/partial text button (part 1)...
        $buttonHtml = '';

        $emptyPreCondition = $displayParts->hasEditLink && $displayParts->deleteLink !== DeleteLinkEnum::NO_DELETE;

        $config = Config::getInstance();
        $leftOrBoth = $config->settings['RowActionLinks'] === self::POSITION_LEFT
                   || $config->settings['RowActionLinks'] === self::POSITION_BOTH;

        //     ... before the result table
        if (
            ! $displayParts->hasEditLink
            && $displayParts->deleteLink === DeleteLinkEnum::NO_DELETE
            && $displayParts->hasTextButton
        ) {
            $displayParams['emptypre'] = 0;
        } elseif ($leftOrBoth && $displayParts->hasTextButton) {
            //     ... at the left column of the result table header if possible
            //     and required

            $displayParams['emptypre'] = $emptyPreCondition ? 4 : 0;

            $buttonHtml .= '<th class="column_action position-sticky bg-body d-print-none"' . $colspan
                . '>' . $fullOrPartialTextLink . '</th>';
        } elseif (
            $leftOrBoth
            && ($displayParts->hasEditLink || $displayParts->deleteLink !== DeleteLinkEnum::NO_DELETE)
        ) {
            //     ... elseif no button, displays empty(ies) col(s) if required

            $displayParams['emptypre'] = $emptyPreCondition ? 4 : 0;

            $buttonHtml .= '<td' . $colspan . '></td>';
        } elseif ($config->settings['RowActionLinks'] === self::POSITION_NONE) {
            // ... elseif display an empty column if the actions links are
            //  disabled to match the rest of the table
            $buttonHtml .= '<th class="column_action position-sticky bg-body"></th>';
        }

        $this->properties['display_params'] = $displayParams;

        return $buttonHtml;
    }

    /**
     * Get table comments as array
     *
     * @see getTableHeaders()
     *
     * @return mixed[] table comments
     */
    private function getTableCommentsArray(StatementInfo $statementInfo): array
    {
        if (! Config::getInstance()->settings['ShowBrowseComments'] || empty($statementInfo->statement->from)) {
            return [];
        }

        $ret = [];
        foreach ($statementInfo->statement->from as $field) {
            if (empty($field->table)) {
                continue;
            }

            $ret[$field->table] = $this->relation->getComments(
                empty($field->database) ? $this->properties['db'] : $field->database,
                $field->table,
            );
        }

        return $ret;
    }

    /**
     * Set global array for store highlighted header fields
     *
     * @see getTableHeaders()
     */
    private function setHighlightedColumnGlobalField(StatementInfo $statementInfo): void
    {
        $highlightColumns = [];

        if (! empty($statementInfo->statement->where)) {
            foreach ($statementInfo->statement->where as $expr) {
                foreach ($expr->identifiers as $identifier) {
                    $highlightColumns[$identifier] = 'true';
                }
            }
        }

        $this->properties['highlight_columns'] = $highlightColumns;
    }

    /**
     * Prepare data for column restoring and show/hide
     *
     * @see getTableHeaders()
     *
     * @return mixed[]
     */
    private function getDataForResettingColumnOrder(StatementInfo $statementInfo): array
    {
        if (! $this->isSelect($statementInfo)) {
            return [];
        }

        [$columnOrder, $columnVisibility] = $this->getColumnParams($statementInfo);

        $tableCreateTime = '';
        $table = new Table($this->properties['table'], $this->properties['db'], $this->dbi);
        if (! $table->isView()) {
            $tableCreateTime = $this->dbi->getTable(
                $this->properties['db'],
                $this->properties['table'],
            )->getStatusInfo('Create_time');
        }

        return [
            'order' => $columnOrder,
            'visibility' => $columnVisibility,
            'is_view' => $table->isView(),
            'table_create_time' => $tableCreateTime,
        ];
    }

    /**
     * Prepare option fields block
     *
     * @see getTableHeaders()
     *
     * @return mixed[]
     */
    private function getOptionsBlock(): array
    {
        if (
            isset($_SESSION['tmpval']['possible_as_geometry'])
            && $_SESSION['tmpval']['possible_as_geometry'] == false
            && $_SESSION['tmpval']['geoOption'] === self::GEOMETRY_DISP_GEOM
        ) {
            $_SESSION['tmpval']['geoOption'] = self::GEOMETRY_DISP_WKT;
        }

        return [
            'geo_option' => $_SESSION['tmpval']['geoOption'],
            'hide_transformation' => $_SESSION['tmpval']['hide_transformation'],
            'display_blob' => $_SESSION['tmpval']['display_blob'],
            'display_binary' => $_SESSION['tmpval']['display_binary'],
            'relational_display' => $_SESSION['tmpval']['relational_display'],
            'possible_as_geometry' => $_SESSION['tmpval']['possible_as_geometry'],
            'pftext' => $_SESSION['tmpval']['pftext'],
        ];
    }

    /**
     * Get full/partial text button or link
     *
     * @see getTableHeaders()
     *
     * @return string html content
     */
    private function getFullOrPartialTextButtonOrLink(): string
    {
        $GLOBALS['theme'] ??= null;

        $urlParamsFullText = [
            'db' => $this->properties['db'],
            'table' => $this->properties['table'],
            'sql_query' => $this->properties['sql_query'],
            'goto' => $this->properties['goto'],
            'full_text_button' => 1,
        ];

        if ($_SESSION['tmpval']['pftext'] === self::DISPLAY_FULL_TEXT) {
            // currently in fulltext mode so show the opposite link
            $tmpImageFile = 's_partialtext.png';
            $tmpTxt = __('Partial texts');
            $urlParamsFullText['pftext'] = self::DISPLAY_PARTIAL_TEXT;
        } else {
            $tmpImageFile = 's_fulltext.png';
            $tmpTxt = __('Full texts');
            $urlParamsFullText['pftext'] = self::DISPLAY_FULL_TEXT;
        }

        $tmpImage = '<img class="fulltext" src="'
            . ($GLOBALS['theme'] instanceof Theme ? $GLOBALS['theme']->getImgPath($tmpImageFile) : '')
            . '" alt="' . $tmpTxt . '" title="' . $tmpTxt . '">';

        return Generator::linkOrButton(Url::getFromRoute('/sql'), $urlParamsFullText, $tmpImage);
    }

    /**
     * Get comment for row
     *
     * @see getTableHeaders()
     *
     * @param mixed[]       $commentsMap comments array
     * @param FieldMetadata $fieldsMeta  set of field properties
     *
     * @return string html content
     */
    private function getCommentForRow(array $commentsMap, FieldMetadata $fieldsMeta): string
    {
        return $this->template->render('display/results/comment_for_row', [
            'comments_map' => $commentsMap,
            'column_name' => $fieldsMeta->name,
            'table_name' => $fieldsMeta->table,
            'limit_chars' => Config::getInstance()->settings['LimitChars'],
        ]);
    }

    /**
     * Prepare parameters and html for sorted table header fields
     *
     * @see getTableHeaders()
     *
     * @param FieldMetadata      $fieldsMeta                set of field properties
     * @param mixed[]            $sortExpression            sort expression
     * @param array<int, string> $sortExpressionNoDirection sort expression without direction
     * @param string             $unsortedSqlQuery          the unsorted sql query
     * @param int                $sessionMaxRows            maximum rows resulted by sql
     * @param string             $comments                  comment for row
     * @param mixed[]            $sortDirection             sort direction
     * @param bool|mixed[]       $colVisib                  column is visible(false)
     *                                                      or column isn't visible(string array)
     * @param int|string|null    $colVisibElement           element of $col_visib array
     *
     * @return mixed[]   2 element array - $orderLink, $sortedHeaderHtml
     * @psalm-return array{
     *   column_name: string,
     *   order_link: string,
     *   comments: string,
     *   is_browse_pointer_enabled: bool,
     *   is_browse_marker_enabled: bool,
     *   is_column_hidden: bool,
     *   is_column_numeric: bool,
     * }
     */
    private function getOrderLinkAndSortedHeaderHtml(
        FieldMetadata $fieldsMeta,
        array $sortExpression,
        array $sortExpressionNoDirection,
        string $unsortedSqlQuery,
        int $sessionMaxRows,
        string $comments,
        array $sortDirection,
        bool|array $colVisib,
        int|string|null $colVisibElement,
    ): array {
        // Checks if the table name is required; it's the case
        // for a query with a "JOIN" statement and if the column
        // isn't aliased, or in queries like
        // SELECT `1`.`master_field` , `2`.`master_field`
        // FROM `PMA_relation` AS `1` , `PMA_relation` AS `2`

        $sortTable = $fieldsMeta->table !== ''
            && $fieldsMeta->orgname === $fieldsMeta->name
            ? Util::backquote($fieldsMeta->table) . '.'
            : '';

        // Generates the orderby clause part of the query which is part
        // of URL
        [$singleSortOrder, $multiSortOrder, $orderImg] = $this->getSingleAndMultiSortUrls(
            $sortExpression,
            $sortExpressionNoDirection,
            $sortTable,
            $fieldsMeta->name,
            $sortDirection,
            $fieldsMeta,
        );

        if (
            preg_match(
                '@(.*)([[:space:]](LIMIT (.*)|PROCEDURE (.*)|FOR UPDATE|LOCK IN SHARE MODE))@is',
                $unsortedSqlQuery,
                $regs3,
            )
        ) {
            $singleSortedSqlQuery = $regs3[1] . $singleSortOrder . $regs3[2];
            $multiSortedSqlQuery = $regs3[1] . $multiSortOrder . $regs3[2];
        } else {
            $singleSortedSqlQuery = $unsortedSqlQuery . $singleSortOrder;
            $multiSortedSqlQuery = $unsortedSqlQuery . $multiSortOrder;
        }

        $singleUrlParams = [
            'db' => $this->properties['db'],
            'table' => $this->properties['table'],
            'sql_query' => $singleSortedSqlQuery,
            'sql_signature' => Core::signSqlQuery($singleSortedSqlQuery),
            'session_max_rows' => $sessionMaxRows,
            'is_browse_distinct' => $this->properties['is_browse_distinct'],
        ];

        $multiUrlParams = [
            'db' => $this->properties['db'],
            'table' => $this->properties['table'],
            'sql_query' => $multiSortedSqlQuery,
            'sql_signature' => Core::signSqlQuery($multiSortedSqlQuery),
            'session_max_rows' => $sessionMaxRows,
            'is_browse_distinct' => $this->properties['is_browse_distinct'],
        ];

        // Displays the sorting URL
        // enable sort order swapping for image
        $orderLink = $this->getSortOrderLink($orderImg, $fieldsMeta, $singleUrlParams, $multiUrlParams);

        $orderLink .= $this->getSortOrderHiddenInputs($multiUrlParams, $fieldsMeta->name);

        $config = Config::getInstance();

        return [
            'column_name' => $fieldsMeta->name,
            'order_link' => $orderLink,
            'comments' => $comments,
            'is_browse_pointer_enabled' => $config->settings['BrowsePointerEnable'] === true,
            'is_browse_marker_enabled' => $config->settings['BrowseMarkerEnable'] === true,
            'is_column_hidden' => $colVisib && ! $colVisibElement,
            'is_column_numeric' => $this->isColumnNumeric($fieldsMeta),
        ];
    }

    /**
     * Prepare parameters and html for sorted table header fields
     *
     * @param mixed[]            $sortExpression            sort expression
     * @param array<int, string> $sortExpressionNoDirection sort expression without direction
     * @param string             $sortTable                 The name of the table to which
     *                                                      the current column belongs to
     * @param string             $nameToUseInSort           The current column under
     *                                                      consideration
     * @param string[]           $sortDirection             sort direction
     * @param FieldMetadata      $fieldsMeta                set of field properties
     *
     * @return string[]   3 element array - $single_sort_order, $sort_order, $order_img
     */
    private function getSingleAndMultiSortUrls(
        array $sortExpression,
        array $sortExpressionNoDirection,
        string $sortTable,
        string $nameToUseInSort,
        array $sortDirection,
        FieldMetadata $fieldsMeta,
    ): array {
        // Check if the current column is in the order by clause
        $isInSort = $this->isInSorted($sortExpression, $sortExpressionNoDirection, $sortTable, $nameToUseInSort);
        $currentName = $nameToUseInSort;
        if ($sortExpressionNoDirection[0] == '' || ! $isInSort) {
            $specialIndex = $sortExpressionNoDirection[0] == ''
                ? 0
                : count($sortExpressionNoDirection);
            $sortExpressionNoDirection[$specialIndex] = Util::backquote($currentName);
            $config = Config::getInstance();
            // Set the direction to the config value
            $sortDirection[$specialIndex] = $config->settings['Order'];
            // Or perform SMART mode
            if ($config->settings['Order'] === self::SMART_SORT_ORDER) {
                $isTimeOrDate = $fieldsMeta->isType(FieldMetadata::TYPE_TIME)
                    || $fieldsMeta->isType(FieldMetadata::TYPE_DATE)
                    || $fieldsMeta->isType(FieldMetadata::TYPE_DATETIME)
                    || $fieldsMeta->isType(FieldMetadata::TYPE_TIMESTAMP);
                $sortDirection[$specialIndex] = $isTimeOrDate ? self::DESCENDING_SORT_DIR : self::ASCENDING_SORT_DIR;
            }
        }

        $sortExpressionNoDirection = array_filter($sortExpressionNoDirection);
        $singleSortOrder = '';
        $sortOrderColumns = [];
        foreach ($sortExpressionNoDirection as $index => $expression) {
            $sortOrder = '';
            // check if this is the first clause,
            // if it is then we have to add "order by"
            $isFirstClause = ($index === 0);
            $nameToUseInSort = $expression;
            $sortTableNew = $sortTable;
            // Test to detect if the column name is a standard name
            // Standard name has the table name prefixed to the column name
            if (str_contains($nameToUseInSort, '.') && ! str_contains($nameToUseInSort, '(')) {
                $matches = explode('.', $nameToUseInSort);
                // Matches[0] has the table name
                // Matches[1] has the column name
                $nameToUseInSort = $matches[1];
                $sortTableNew = $matches[0];
            }

            // $name_to_use_in_sort might contain a space due to
            // formatting of function expressions like "COUNT(name )"
            // so we remove the space in this situation
            $nameToUseInSort = str_replace([' )', '``'], [')', '`'], $nameToUseInSort);
            $nameToUseInSort = trim($nameToUseInSort, '`');

            // If this the first column name in the order by clause add
            // order by clause to the  column name
            $sortOrder .= $isFirstClause ? "\nORDER BY " : '';

            // Again a check to see if the given column is a aggregate column
            if (str_contains($nameToUseInSort, '(')) {
                $sortOrder .= $nameToUseInSort;
            } else {
                if ($sortTableNew !== '' && ! str_ends_with($sortTableNew, '.')) {
                    $sortTableNew .= '.';
                }

                $sortOrder .= $sortTableNew . Util::backquote($nameToUseInSort);
            }

            // Incase this is the current column save $single_sort_order
            if ($currentName === $nameToUseInSort) {
                $singleSortOrder = "\n" . 'ORDER BY ';

                if (! str_contains($currentName, '(')) {
                    $singleSortOrder .= $sortTable;
                }

                $singleSortOrder .= Util::backquote($currentName) . ' ';

                if ($isInSort) {
                    [$singleSortOrder, $orderImg] = $this->getSortingUrlParams(
                        $sortDirection[$index],
                        $singleSortOrder,
                    );
                } else {
                    $singleSortOrder .= strtoupper($sortDirection[$index]);
                }
            }

            $sortOrder .= ' ';
            if ($currentName === $nameToUseInSort && $isInSort) {
                // We need to generate the arrow button and related html
                [$sortOrder, $orderImg] = $this->getSortingUrlParams($sortDirection[$index], $sortOrder);
                $orderImg .= ' <small>' . ($index + 1) . '</small>';
            } else {
                $sortOrder .= strtoupper($sortDirection[$index]);
            }

            // Separate columns by a comma
            $sortOrderColumns[] = $sortOrder;
        }

        return [$singleSortOrder, implode(', ', $sortOrderColumns), $orderImg ?? ''];
    }

    /**
     * Check whether the column is sorted
     *
     * @see getTableHeaders()
     *
     * @param mixed[] $sortExpression            sort expression
     * @param mixed[] $sortExpressionNoDirection sort expression without direction
     * @param string  $sortTable                 the table name
     * @param string  $nameToUseInSort           the sorting column name
     */
    private function isInSorted(
        array $sortExpression,
        array $sortExpressionNoDirection,
        string $sortTable,
        string $nameToUseInSort,
    ): bool {
        $indexInExpression = 0;

        foreach ($sortExpressionNoDirection as $index => $clause) {
            if (str_contains($clause, '.')) {
                $fragments = explode('.', $clause);
                $clause2 = $fragments[0] . '.' . str_replace('`', '', $fragments[1]);
            } else {
                $clause2 = $sortTable . str_replace('`', '', $clause);
            }

            if ($clause2 === $sortTable . $nameToUseInSort) {
                $indexInExpression = $index;
                break;
            }
        }

        if (empty($sortExpression[$indexInExpression])) {
            return false;
        }

        // Field name may be preceded by a space, or any number
        // of characters followed by a dot (tablename.fieldname)
        // so do a direct comparison for the sort expression;
        // this avoids problems with queries like
        // "SELECT id, count(id)..." and clicking to sort
        // on id or on count(id).
        // Another query to test this:
        // SELECT p.*, FROM_UNIXTIME(p.temps) FROM mytable AS p
        // (and try clicking on each column's header twice)
        $noSortTable = $sortTable === '' || mb_strpos(
            $sortExpressionNoDirection[$indexInExpression],
            $sortTable,
        ) === false;
        $noOpenParenthesis = mb_strpos($sortExpressionNoDirection[$indexInExpression], '(') === false;
        if ($sortTable !== '' && $noSortTable && $noOpenParenthesis) {
            $newSortExpressionNoDirection = $sortTable
                . $sortExpressionNoDirection[$indexInExpression];
        } else {
            $newSortExpressionNoDirection = $sortExpressionNoDirection[$indexInExpression];
        }

        //Back quotes are removed in next comparison, so remove them from value
        //to compare.
        $nameToUseInSort = str_replace('`', '', $nameToUseInSort);

        $sortName = str_replace('`', '', $sortTable) . $nameToUseInSort;

        return $sortName == str_replace('`', '', $newSortExpressionNoDirection)
            || $sortName == str_replace('`', '', $sortExpressionNoDirection[$indexInExpression]);
    }

    /**
     * Get sort url parameters - sort order and order image
     *
     * @see     getSingleAndMultiSortUrls()
     *
     * @param string $sortDirection the sort direction
     * @param string $sortOrder     the sorting order
     *
     * @return string[]             2 element array - $sort_order, $order_img
     */
    private function getSortingUrlParams(string $sortDirection, string $sortOrder): array
    {
        if (strtoupper(trim($sortDirection)) === self::DESCENDING_SORT_DIR) {
            $sortOrder .= self::ASCENDING_SORT_DIR;
            $orderImg = ' ' . Generator::getImage(
                's_desc',
                __('Descending'),
                ['class' => 'soimg', 'title' => ''],
            );
            $orderImg .= ' ' . Generator::getImage(
                's_asc',
                __('Ascending'),
                ['class' => 'soimg hide', 'title' => ''],
            );
        } else {
            $sortOrder .= self::DESCENDING_SORT_DIR;
            $orderImg = ' ' . Generator::getImage(
                's_asc',
                __('Ascending'),
                ['class' => 'soimg', 'title' => ''],
            );
            $orderImg .= ' ' . Generator::getImage(
                's_desc',
                __('Descending'),
                ['class' => 'soimg hide', 'title' => ''],
            );
        }

        return [$sortOrder, $orderImg];
    }

    /**
     * Get sort order link
     *
     * @see getTableHeaders()
     *
     * @param string                   $orderImg            the sort order image
     * @param FieldMetadata            $fieldsMeta          set of field properties
     * @param array<int|string, mixed> $orderUrlParams      the url params for sort
     * @param array<int|string, mixed> $multiOrderUrlParams the url params for sort
     *
     * @return string the sort order link
     */
    private function getSortOrderLink(
        string $orderImg,
        FieldMetadata $fieldsMeta,
        array $orderUrlParams,
        array $multiOrderUrlParams,
    ): string {
        $urlPath = Url::getFromRoute('/sql');
        $innerLinkContent = htmlspecialchars($fieldsMeta->name) . $orderImg
            . '<input type="hidden" value="'
            . $urlPath
            . Url::getCommon($multiOrderUrlParams, str_contains($urlPath, '?') ? '&' : '?', false)
            . '">';

        return Generator::linkOrButton(
            Url::getFromRoute('/sql'),
            $orderUrlParams,
            $innerLinkContent,
            ['class' => 'sortlink'],
        );
    }

    /** @param mixed[] $multipleUrlParams */
    private function getSortOrderHiddenInputs(
        array $multipleUrlParams,
        string $nameToUseInSort,
    ): string {
        $sqlQuery = $multipleUrlParams['sql_query'];
        $sqlQueryAdd = $sqlQuery;
        $sqlQueryRemove = null;
        $parser = new Parser($sqlQuery);

        $firstStatement = $parser->statements[0] ?? null;
        $numberOfClausesFound = null;
        if ($firstStatement instanceof SelectStatement) {
            $orderClauses = $firstStatement->order ?? [];
            foreach ($orderClauses as $key => $order) {
                // If this is the column name, then remove it from the order clause
                if ($order->expr->column !== $nameToUseInSort) {
                    continue;
                }

                // remove the order clause for this column and from the counted array
                unset($firstStatement->order[$key], $orderClauses[$key]);
            }

            $numberOfClausesFound = count($orderClauses);
            $sqlQueryRemove = $firstStatement->build();
        }

        $multipleUrlParams['sql_query'] = $sqlQueryRemove ?? $sqlQuery;
        $multipleUrlParams['sql_signature'] = Core::signSqlQuery($multipleUrlParams['sql_query']);

        $urlRemoveOrder = Url::getFromRoute('/sql', $multipleUrlParams);
        if ($numberOfClausesFound === 0) {
            $urlRemoveOrder .= '&discard_remembered_sort=1';
        }

        $multipleUrlParams['sql_query'] = $sqlQueryAdd;
        $multipleUrlParams['sql_signature'] = Core::signSqlQuery($multipleUrlParams['sql_query']);

        $urlAddOrder = Url::getFromRoute('/sql', $multipleUrlParams);

        return '<input type="hidden" name="url-remove-order" value="' . $urlRemoveOrder . '">' . "\n"
             . '<input type="hidden" name="url-add-order" value="' . $urlAddOrder . '">';
    }

    /**
     * Check if the column contains numeric data
     *
     * @param FieldMetadata $fieldsMeta set of field properties
     */
    private function isColumnNumeric(FieldMetadata $fieldsMeta): bool
    {
        // This was defined in commit b661cd7c9b31f8bc564d2f9a1b8527e0eb966de8
        // For issue https://github.com/phpmyadmin/phpmyadmin/issues/4746
        return $fieldsMeta->isType(FieldMetadata::TYPE_REAL)
            || $fieldsMeta->isMappedTypeBit
            || $fieldsMeta->isType(FieldMetadata::TYPE_INT);
    }

    /**
     * Prepare column to show at right side - check boxes or empty column
     *
     * @see getTableHeaders()
     *
     * @param string $fullOrPartialTextLink full/partial link or text button
     * @param string $colspan               column span of table header
     *
     * @return string  html content
     */
    private function getColumnAtRightSide(
        DisplayParts $displayParts,
        string $fullOrPartialTextLink,
        string $colspan,
    ): string {
        $rightColumnHtml = '';
        $displayParams = $this->properties['display_params'];

        $config = Config::getInstance();
        // Displays the needed checkboxes at the right
        // column of the result table header if possible and required...
        if (
            ($config->settings['RowActionLinks'] === self::POSITION_RIGHT)
            || ($config->settings['RowActionLinks'] === self::POSITION_BOTH)
            && ($displayParts->hasEditLink || $displayParts->deleteLink !== DeleteLinkEnum::NO_DELETE)
            && $displayParts->hasTextButton
        ) {
            $displayParams['emptyafter'] = $displayParts->hasEditLink
                && $displayParts->deleteLink !== DeleteLinkEnum::NO_DELETE ? 4 : 1;

            $rightColumnHtml .= "\n"
                . '<th class="column_action position-sticky bg-body d-print-none"' . $colspan . '>'
                . $fullOrPartialTextLink
                . '</th>';
        } elseif (
            ($config->settings['RowActionLinks'] === self::POSITION_LEFT)
            || ($config->settings['RowActionLinks'] === self::POSITION_BOTH)
            && (! $displayParts->hasEditLink
            && $displayParts->deleteLink === DeleteLinkEnum::NO_DELETE)
            && (! isset($GLOBALS['is_header_sent']) || ! $GLOBALS['is_header_sent'])
        ) {
            //     ... elseif no button, displays empty columns if required
            // (unless coming from Browse mode print view)

            $displayParams['emptyafter'] = $displayParts->hasEditLink
                && $displayParts->deleteLink !== DeleteLinkEnum::NO_DELETE ? 4 : 1;

            $rightColumnHtml .= "\n" . '<td class="position-sticky bg-body d-print-none"' . $colspan
                . '></td>';
        }

        $this->properties['display_params'] = $displayParams;

        return $rightColumnHtml;
    }

    /**
     * Prepares the display for a value
     *
     * @see     getDataCellForGeometryColumns(),
     *          getDataCellForNonNumericColumns()
     *
     * @param string $class          class of table cell
     * @param bool   $conditionField whether to add CSS class condition
     * @param string $value          value to display
     *
     * @return string  the td
     */
    private function buildValueDisplay(string $class, bool $conditionField, string $value): string
    {
        return $this->template->render('display/results/value_display', [
            'class' => $class,
            'condition_field' => $conditionField,
            'value' => $value,
        ]);
    }

    /**
     * Prepares the display for a null value
     *
     * @see     getDataCellForNumericColumns(),
     *          getDataCellForGeometryColumns(),
     *          getDataCellForNonNumericColumns()
     *
     * @param string        $class          class of table cell
     * @param bool          $conditionField whether to add CSS class condition
     * @param FieldMetadata $meta           the meta-information about this field
     *
     * @return string  the td
     */
    private function buildNullDisplay(string $class, bool $conditionField, FieldMetadata $meta): string
    {
        $classes = $this->addClass($class, $conditionField, $meta, '');

        return $this->template->render('display/results/null_display', [
            'data_decimals' => $meta->decimals,
            'data_type' => $meta->getMappedType(),
            'classes' => $classes,
        ]);
    }

    /**
     * Prepares the display for an empty value
     *
     * @see     getDataCellForNumericColumns(),
     *          getDataCellForGeometryColumns(),
     *          getDataCellForNonNumericColumns()
     *
     * @param string        $class          class of table cell
     * @param bool          $conditionField whether to add CSS class condition
     * @param FieldMetadata $meta           the meta-information about this field
     *
     * @return string  the td
     */
    private function buildEmptyDisplay(string $class, bool $conditionField, FieldMetadata $meta): string
    {
        $classes = $this->addClass($class, $conditionField, $meta, 'text-nowrap');

        return $this->template->render('display/results/empty_display', ['classes' => $classes]);
    }

    /**
     * Adds the relevant classes.
     *
     * @see buildNullDisplay(), getRowData()
     *
     * @param string        $class            class of table cell
     * @param bool          $conditionField   whether to add CSS class condition
     * @param FieldMetadata $meta             the meta-information about the field
     * @param string        $nowrap           avoid wrapping
     * @param bool          $isFieldTruncated is field truncated (display ...)
     *
     * @return string the list of classes
     */
    private function addClass(
        string $class,
        bool $conditionField,
        FieldMetadata $meta,
        string $nowrap,
        bool $isFieldTruncated = false,
        bool $hasTransformationPlugin = false,
    ): string {
        $classes = array_filter([$class, $nowrap]);

        if ($meta->internalMediaType !== null) {
            $classes[] = preg_replace('/\//', '_', $meta->internalMediaType);
        }

        if ($conditionField) {
            $classes[] = 'condition';
        }

        if ($isFieldTruncated) {
            $classes[] = 'truncated';
        }

        $mediaTypeMap = $this->properties['mime_map'];
        $orgFullColName = $this->properties['db'] . '.' . $meta->orgtable
            . '.' . $meta->orgname;
        if ($hasTransformationPlugin || ! empty($mediaTypeMap[$orgFullColName]['input_transformation'])) {
            $classes[] = 'transformed';
        }

        // Define classes to be added to this data field based on the type of data

        if ($meta->isEnum()) {
            $classes[] = 'enum';
        }

        if ($meta->isSet()) {
            $classes[] = 'set';
        }

        if ($meta->isMappedTypeBit) {
            $classes[] = 'bit';
        }

        if ($meta->isBinary()) {
            $classes[] = 'hex';
        }

        return implode(' ', $classes);
    }

    /**
     * Prepare the body of the results table
     *
     * @see     getTable()
     *
     * @param ResultInterface          $dtResult         the link id associated to the query
     *                                                                     which results have to be displayed
     * @param ForeignKeyRelatedTable[] $map              the list of relations
     * @param bool                     $isLimitedDisplay with limited operations or not
     *
     * @return string  html content
     *
     * @global array  $row                  current row data
     */
    private function getTableBody(
        ResultInterface $dtResult,
        DisplayParts $displayParts,
        array $map,
        StatementInfo $statementInfo,
        bool $isLimitedDisplay = false,
    ): string {
        // Mostly because of browser transformations, to make the row-data accessible in a plugin.

        $GLOBALS['row'] ??= null;

        $tableBodyHtml = '';

        // query without conditions to shorten URLs when needed, 200 is just
        // guess, it should depend on remaining URL length
        $urlSqlQuery = $this->getUrlSqlQuery($statementInfo);

        $displayParams = $this->properties['display_params'];

        $rowNumber = 0;
        $displayParams['edit'] = [];
        $displayParams['copy'] = [];
        $displayParams['delete'] = [];
        $displayParams['data'] = [];
        $displayParams['row_delete'] = [];
        $this->properties['display_params'] = $displayParams;

        $gridEditConfig = 'double-click';
        $config = Config::getInstance();
        // If we don't have all the columns of a unique key in the result set, do not permit grid editing.
        if ($isLimitedDisplay || ! $this->properties['editable'] || $config->settings['GridEditing'] === 'disabled') {
            $gridEditConfig = 'disabled';
        } elseif ($config->settings['GridEditing'] === 'click') {
            $gridEditConfig = 'click';
        }

        // prepare to get the column order, if available
        [$colOrder, $colVisib] = $this->getColumnParams($statementInfo);

        // Correction University of Virginia 19991216 in the while below
        // Previous code assumed that all tables have keys, specifically that
        // the phpMyAdmin GUI should support row delete/edit only for such
        // tables.
        // Although always using keys is arguably the prescribed way of
        // defining a relational table, it is not required. This will in
        // particular be violated by the novice.
        // We want to encourage phpMyAdmin usage by such novices. So the code
        // below has been changed to conditionally work as before when the
        // table being displayed has one or more keys; but to display
        // delete/edit options correctly for tables without keys.

        $whereClauseMap = $this->properties['whereClauseMap'];
        while ($GLOBALS['row'] = $dtResult->fetchRow()) {
            // add repeating headers
            if (
                ($rowNumber !== 0) && ($_SESSION['tmpval']['repeat_cells'] > 0)
                && ($rowNumber % $_SESSION['tmpval']['repeat_cells']) === 0
            ) {
                $tableBodyHtml .= $this->getRepeatingHeaders(
                    $displayParams['emptypre'],
                    $displayParams['desc'],
                    $displayParams['emptyafter'],
                );
            }

            $trClass = [];
            if ($config->settings['BrowsePointerEnable'] != true) {
                $trClass[] = 'nopointer';
            }

            if ($config->settings['BrowseMarkerEnable'] != true) {
                $trClass[] = 'nomarker';
            }

            // pointer code part
            $tableBodyHtml .= '<tr' . ($trClass === [] ? '' : ' class="' . implode(' ', $trClass) . '"') . '>';

            // 1. Prepares the row

            // In print view these variable needs to be initialized
            $deleteUrl = null;
            $deleteString = null;
            $editString = null;
            $jsConf = null;
            $copyUrl = null;
            $copyString = null;
            $editUrl = null;
            $editCopyUrlParams = [];
            $delUrlParams = null;

            // 1.2 Defines the URLs for the modify/delete link(s)

            if (
                $displayParts->hasEditLink
                || ($displayParts->deleteLink !== DeleteLinkEnum::NO_DELETE)
            ) {
                $expressions = [];

                if (
                    isset($statementInfo->statement)
                    && $statementInfo->statement instanceof SelectStatement
                ) {
                    $expressions = $statementInfo->statement->expr;
                }

                // Results from a "SELECT" statement -> builds the
                // WHERE clause to use in links (a unique key if possible)
                /**
                 * @todo $where_clause could be empty, for example a table
                 *       with only one field and it's a BLOB; in this case,
                 *       avoid to display the delete and edit links
                 */
                [$whereClause, $clauseIsUnique, $conditionArray] = Util::getUniqueCondition(
                    $this->properties['fields_cnt'],
                    $this->properties['fields_meta'],
                    $GLOBALS['row'],
                    false,
                    $this->properties['table'],
                    $expressions,
                );
                $whereClauseMap[$rowNumber][$this->properties['table']] = $whereClause;
                $this->properties['whereClauseMap'] = $whereClauseMap;

                // 1.2.1 Modify link(s) - update row case
                if ($displayParts->hasEditLink) {
                    [
                        $editUrl,
                        $copyUrl,
                        $editString,
                        $copyString,
                        $editCopyUrlParams,
                    ] = $this->getModifiedLinks($whereClause, $clauseIsUnique, $urlSqlQuery);
                }

                // 1.2.2 Delete/Kill link(s)
                [$deleteUrl, $deleteString, $jsConf, $delUrlParams] = $this->getDeleteAndKillLinks(
                    $whereClause,
                    $clauseIsUnique,
                    $urlSqlQuery,
                    $displayParts->deleteLink,
                    (int) $GLOBALS['row'][0],
                );

                // 1.3 Displays the links at left if required
                if (
                    ($config->settings['RowActionLinks'] === self::POSITION_LEFT)
                    || ($config->settings['RowActionLinks'] === self::POSITION_BOTH)
                ) {
                    $tableBodyHtml .= $this->template->render('display/results/checkbox_and_links', [
                        'position' => self::POSITION_LEFT,
                        'has_checkbox' => $deleteUrl && $displayParts->deleteLink !== DeleteLinkEnum::KILL_PROCESS,
                        'edit' => [
                            'url' => $editUrl,
                            'params' => $editCopyUrlParams + ['default_action' => 'update'],
                            'string' => $editString,
                            'clause_is_unique' => $clauseIsUnique,
                        ],
                        'copy' => [
                            'url' => $copyUrl,
                            'params' => $editCopyUrlParams + ['default_action' => 'insert'],
                            'string' => $copyString,
                        ],
                        'delete' => ['url' => $deleteUrl, 'params' => $delUrlParams, 'string' => $deleteString],
                        'row_number' => $rowNumber,
                        'where_clause' => $whereClause,
                        'condition' => json_encode($conditionArray),
                        'is_ajax' => ResponseRenderer::getInstance()->isAjax(),
                        'js_conf' => $jsConf ?? '',
                        'grid_edit_config' => $gridEditConfig,
                    ]);
                } elseif ($config->settings['RowActionLinks'] === self::POSITION_NONE) {
                    $tableBodyHtml .= $this->template->render('display/results/checkbox_and_links', [
                        'position' => self::POSITION_NONE,
                        'has_checkbox' => $deleteUrl && $displayParts->deleteLink !== DeleteLinkEnum::KILL_PROCESS,
                        'edit' => [
                            'url' => $editUrl,
                            'params' => $editCopyUrlParams + ['default_action' => 'update'],
                            'string' => $editString,
                            'clause_is_unique' => $clauseIsUnique,
                        ],
                        'copy' => [
                            'url' => $copyUrl,
                            'params' => $editCopyUrlParams + ['default_action' => 'insert'],
                            'string' => $copyString,
                        ],
                        'delete' => ['url' => $deleteUrl, 'params' => $delUrlParams, 'string' => $deleteString],
                        'row_number' => $rowNumber,
                        'where_clause' => $whereClause,
                        'condition' => json_encode($conditionArray),
                        'is_ajax' => ResponseRenderer::getInstance()->isAjax(),
                        'js_conf' => $jsConf ?? '',
                        'grid_edit_config' => $gridEditConfig,
                    ]);
                }
            }

            // 2. Displays the rows' values
            if ($this->properties['mime_map'] === null) {
                $this->setMimeMap();
            }

            $tableBodyHtml .= $this->getRowValues(
                $GLOBALS['row'],
                $rowNumber,
                $colOrder,
                $map,
                $gridEditConfig,
                $colVisib,
                $urlSqlQuery,
                $statementInfo,
            );

            // 3. Displays the modify/delete links on the right if required
            if (
                ($displayParts->hasEditLink
                    || $displayParts->deleteLink !== DeleteLinkEnum::NO_DELETE)
                && ($config->settings['RowActionLinks'] === self::POSITION_RIGHT
                    || $config->settings['RowActionLinks'] === self::POSITION_BOTH)
            ) {
                $tableBodyHtml .= $this->template->render('display/results/checkbox_and_links', [
                    'position' => self::POSITION_RIGHT,
                    'has_checkbox' => $deleteUrl && $displayParts->deleteLink !== DeleteLinkEnum::KILL_PROCESS,
                    'edit' => [
                        'url' => $editUrl,
                        'params' => $editCopyUrlParams + ['default_action' => 'update'],
                        'string' => $editString,
                        'clause_is_unique' => $clauseIsUnique ?? true,
                    ],
                    'copy' => [
                        'url' => $copyUrl,
                        'params' => $editCopyUrlParams + ['default_action' => 'insert'],
                        'string' => $copyString,
                    ],
                    'delete' => ['url' => $deleteUrl, 'params' => $delUrlParams, 'string' => $deleteString],
                    'row_number' => $rowNumber,
                    'where_clause' => $whereClause ?? '',
                    'condition' => json_encode($conditionArray ?? []),
                    'is_ajax' => ResponseRenderer::getInstance()->isAjax(),
                    'js_conf' => $jsConf ?? '',
                    'grid_edit_config' => $gridEditConfig,
                ]);
            }

            $tableBodyHtml .= '</tr>';
            $tableBodyHtml .= "\n";
            $rowNumber++;
        }

        return $tableBodyHtml;
    }

    /**
     * Sets the MIME details of the columns in the results set
     */
    private function setMimeMap(): void
    {
        $fieldsMeta = $this->properties['fields_meta'];
        $mediaTypeMap = [];
        $added = [];
        $relationParameters = $this->relation->getRelationParameters();

        /** @infection-ignore-all */
        for ($currentColumn = 0; $currentColumn < $this->properties['fields_cnt']; ++$currentColumn) {
            $meta = $fieldsMeta[$currentColumn];
            $orgFullTableName = $this->properties['db'] . '.' . $meta->orgtable;

            if (
                $relationParameters->columnCommentsFeature === null
                || $relationParameters->browserTransformationFeature === null
                || ! Config::getInstance()->settings['BrowseMIME']
                || $_SESSION['tmpval']['hide_transformation']
                || ! empty($added[$orgFullTableName])
            ) {
                continue;
            }

            $mediaTypeMap = array_merge(
                $mediaTypeMap,
                $this->transformations->getMime($this->properties['db'], $meta->orgtable, false, true) ?? [],
            );
            $added[$orgFullTableName] = true;
        }

        // special browser transformation for some SHOW statements
        if ($this->properties['is_show'] && ! $_SESSION['tmpval']['hide_transformation']) {
            preg_match(
                '@^SHOW[[:space:]]+(VARIABLES|(FULL[[:space:]]+)?'
                . 'PROCESSLIST|STATUS|TABLE|GRANTS|CREATE|LOGS|DATABASES|FIELDS'
                . ')@i',
                $this->properties['sql_query'],
                $which,
            );

            if (isset($which[1])) {
                $str = ' ' . strtoupper($which[1]);
                $isShowProcessList = strpos($str, 'PROCESSLIST') > 0;
                if ($isShowProcessList) {
                    $mediaTypeMap['..Info'] = [
                        'mimetype' => 'Text_Plain',
                        'transformation' => 'output/Text_Plain_Sql.php',
                    ];
                }

                $isShowCreateTable = preg_match('@CREATE[[:space:]]+TABLE@i', $this->properties['sql_query']);
                if ($isShowCreateTable) {
                    $mediaTypeMap['..Create Table'] = [
                        'mimetype' => 'Text_Plain',
                        'transformation' => 'output/Text_Plain_Sql.php',
                    ];
                }
            }
        }

        $this->properties['mime_map'] = $mediaTypeMap;
    }

    /**
     * Get the values for one data row
     *
     * @see     getTableBody()
     *
     * @param mixed[]                  $row         current row data
     * @param int                      $rowNumber   the index of current row
     * @param mixed[]|false            $colOrder    the column order false when
     *                                             a property not found false
     *                                             when a property not found
     * @param ForeignKeyRelatedTable[] $map         the list of relations
     * @param bool|mixed[]|string      $colVisib    column is visible(false);
     *                                             column isn't visible(string
     *                                             array)
     * @param string                   $urlSqlQuery the analyzed sql query
     * @psalm-param 'double-click'|'click'|'disabled' $gridEditConfig
     *
     * @return string  html content
     */
    private function getRowValues(
        array $row,
        int $rowNumber,
        array|false $colOrder,
        array $map,
        string $gridEditConfig,
        bool|array|string $colVisib,
        string $urlSqlQuery,
        StatementInfo $statementInfo,
    ): string {
        $rowValuesHtml = '';

        // Following variable are needed for use in isset/empty or
        // use with array indexes/safe use in foreach
        $sqlQuery = $this->properties['sql_query'];
        $fieldsMeta = $this->properties['fields_meta'];
        $highlightColumns = $this->properties['highlight_columns'];
        $mediaTypeMap = $this->properties['mime_map'];

        $rowInfo = $this->getRowInfoForSpecialLinks($row, $colOrder);

        $whereClauseMap = $this->properties['whereClauseMap'];

        $columnCount = $this->properties['fields_cnt'];

        // Load SpecialSchemaLinks for all rows
        $specialSchemaLinks = SpecialSchemaLinks::get();
        $relationParameters = $this->relation->getRelationParameters();

        for ($currentColumn = 0; $currentColumn < $columnCount; ++$currentColumn) {
            // assign $i with appropriate column order
            $i = is_array($colOrder) ? $colOrder[$currentColumn] : $currentColumn;

            $meta = $fieldsMeta[$i];
            $orgFullColName = $this->properties['db'] . '.' . $meta->orgtable . '.' . $meta->orgname;

            $notNullClass = $meta->isNotNull() ? 'not_null' : '';
            $relationClass = isset($map[$meta->name]) ? 'relation' : '';
            $hideClass = is_array($colVisib) && isset($colVisib[$currentColumn]) && ! $colVisib[$currentColumn]
                ? 'hide'
                : '';

            $gridEdit = '';
            if ($meta->orgtable != '' && $gridEditConfig !== 'disabled') {
                $gridEdit = $gridEditConfig === 'click' ? 'grid_edit click1' : 'grid_edit click2';
            }

            // handle datetime-related class, for grid editing
            $fieldTypeClass = $this->getClassForDateTimeRelatedFields($meta);

            // combine all the classes applicable to this column's value
            $class = implode(' ', array_filter([
                'data',
                $gridEdit,
                $notNullClass,
                $relationClass,
                $hideClass,
                $fieldTypeClass,
            ]));

            //  See if this column should get highlight because it's used in the
            //  where-query.
            $conditionField = isset($highlightColumns)
                && (isset($highlightColumns[$meta->name])
                || isset($highlightColumns[Util::backquote($meta->name)]));

            // Wrap MIME-transformations. [MIME]
            $transformationPlugin = null;
            $transformOptions = [];

            if (
                $relationParameters->browserTransformationFeature !== null
                && Config::getInstance()->settings['BrowseMIME']
                && isset($mediaTypeMap[$orgFullColName]['mimetype'])
                && ! empty($mediaTypeMap[$orgFullColName]['transformation'])
            ) {
                $file = $mediaTypeMap[$orgFullColName]['transformation'];
                $includeFile = 'src/Plugins/Transformations/' . $file;

                if (@file_exists(ROOT_PATH . $includeFile)) {
                    $className = $this->transformations->getClassName($includeFile);
                    if (class_exists($className)) {
                        $plugin = new $className();
                        if ($plugin instanceof TransformationsPlugin) {
                            $transformationPlugin = $plugin;
                            $transformOptions = $this->transformations->getOptions(
                                $mediaTypeMap[$orgFullColName]['transformation_options'] ?? '',
                            );

                            $meta->internalMediaType = str_replace(
                                '_',
                                '/',
                                $mediaTypeMap[$orgFullColName]['mimetype'],
                            );
                        }
                    }
                }
            }

            // Check whether the field needs to display with syntax highlighting

            $dbLower = mb_strtolower($this->properties['db']);
            $tblLower = mb_strtolower($meta->orgtable);
            $nameLower = mb_strtolower($meta->orgname);
            if (
                ! empty($this->transformationInfo[$dbLower][$tblLower][$nameLower])
                && isset($row[$i])
                && (trim($row[$i]) != '')
                && ! $_SESSION['tmpval']['hide_transformation']
            ) {
                /** @psalm-suppress UnresolvableInclude */
                include_once ROOT_PATH . $this->transformationInfo[$dbLower][$tblLower][$nameLower][0];
                $plugin = new $this->transformationInfo[$dbLower][$tblLower][$nameLower][1]();
                if ($plugin instanceof TransformationsPlugin) {
                    $transformationPlugin = $plugin;
                    $transformOptions = $this->transformations->getOptions(
                        $mediaTypeMap[$orgFullColName]['transformation_options'] ?? '',
                    );

                    $orgTable = mb_strtolower($meta->orgtable);
                    $orgName = mb_strtolower($meta->orgname);

                    $meta->internalMediaType = str_replace(
                        '_',
                        '/',
                        $this->transformationInfo[$dbLower][$orgTable][$orgName][2],
                    );
                }
            }

            // Check for the predefined fields need to show as link in schemas
            if (! empty($specialSchemaLinks[$dbLower][$tblLower][$nameLower])) {
                $linkingUrl = $this->getSpecialLinkUrl(
                    $specialSchemaLinks[$dbLower][$tblLower][$nameLower],
                    $row[$i],
                    $rowInfo,
                );
                $transformationPlugin = new Text_Plain_Link();

                $transformOptions = [0 => $linkingUrl, 2 => true];

                $meta->internalMediaType = str_replace('_', '/', 'Text/Plain');
            }

            $expressions = [];

            if (
                isset($statementInfo->statement)
                && $statementInfo->statement instanceof SelectStatement
            ) {
                $expressions = $statementInfo->statement->expr;
            }

            /**
             * The result set can have columns from more than one table,
             * this is why we have to check for the unique conditions
             * related to this table; however getUniqueCondition() is
             * costly and does not need to be called if we already know
             * the conditions for the current table.
             */
            if (! isset($whereClauseMap[$rowNumber][$meta->orgtable])) {
                [$uniqueConditions] = Util::getUniqueCondition(
                    $this->properties['fields_cnt'],
                    $this->properties['fields_meta'],
                    $row,
                    false,
                    $meta->orgtable,
                    $expressions,
                );
                $whereClauseMap[$rowNumber][$meta->orgtable] = $uniqueConditions;
            }

            $urlParams = [
                'db' => $this->properties['db'],
                'table' => $meta->orgtable,
                'where_clause_sign' => Core::signSqlQuery($whereClauseMap[$rowNumber][$meta->orgtable]),
                'where_clause' => $whereClauseMap[$rowNumber][$meta->orgtable],
                'transform_key' => $meta->orgname,
            ];

            if ($sqlQuery !== '') {
                $urlParams['sql_query'] = $urlSqlQuery;
            }

            $transformOptions['wrapper_link'] = Url::getCommon($urlParams);
            $transformOptions['wrapper_params'] = $urlParams;

            $displayParams = $this->properties['display_params'] ?? [];

            if ($meta->isNumeric) {
                // n u m e r i c

                $displayParams['data'][$rowNumber][$i] = $this->getDataCellForNumericColumns(
                    $row[$i] === null ? null : (string) $row[$i],
                    'text-end ' . $class,
                    $conditionField,
                    $meta,
                    $map,
                    $statementInfo,
                    $transformationPlugin,
                    $transformOptions,
                );
            } elseif ($meta->isMappedTypeGeometry) {
                // g e o m e t r y

                // Remove 'grid_edit' from $class as we do not allow to
                // inline-edit geometry data.
                $class = str_replace('grid_edit', '', $class);

                $displayParams['data'][$rowNumber][$i] = $this->getDataCellForGeometryColumns(
                    $row[$i] === null ? null : (string) $row[$i],
                    $class,
                    $meta,
                    $map,
                    $urlParams,
                    $conditionField,
                    $transformationPlugin,
                    $transformOptions,
                    $statementInfo,
                );
            } else {
                // n o t   n u m e r i c

                $displayParams['data'][$rowNumber][$i] = $this->getDataCellForNonNumericColumns(
                    $row[$i] === null ? null : (string) $row[$i],
                    $class,
                    $meta,
                    $map,
                    $urlParams,
                    $conditionField,
                    $transformationPlugin,
                    $transformOptions,
                    $statementInfo,
                );
            }

            // output stored cell
            $rowValuesHtml .= $displayParams['data'][$rowNumber][$i];

            if (isset($displayParams['rowdata'][$i][$rowNumber])) {
                $displayParams['rowdata'][$i][$rowNumber] .= $displayParams['data'][$rowNumber][$i];
            } else {
                $displayParams['rowdata'][$i][$rowNumber] = $displayParams['data'][$rowNumber][$i];
            }

            $this->properties['display_params'] = $displayParams;
        }

        return $rowValuesHtml;
    }

    /**
     * Get link for display special schema links
     *
     * @param array<string,array<int,array<string,string>>|string> $linkRelations
     * @param string                                               $columnValue   column value
     * @param mixed[]                                              $rowInfo       information about row
     * @phpstan-param array{
     *                         'link_param': string,
     *                         'link_dependancy_params'?: array<
     *                                                      int,
     *                                                      array{'param_info': string, 'column_name': string}
     *                                                     >,
     *                         'default_page': string
     *                     } $linkRelations
     *
     * @return string generated link
     */
    private function getSpecialLinkUrl(
        array $linkRelations,
        string $columnValue,
        array $rowInfo,
    ): string {
        $linkingUrlParams = [];

        $linkingUrlParams[$linkRelations['link_param']] = $columnValue;

        $divider = strpos($linkRelations['default_page'], '?') ? '&' : '?';
        if (empty($linkRelations['link_dependancy_params'])) {
            return $linkRelations['default_page']
                . Url::getCommonRaw($linkingUrlParams, $divider);
        }

        foreach ($linkRelations['link_dependancy_params'] as $newParam) {
            $columnName = mb_strtolower($newParam['column_name']);

            // If there is a value for this column name in the rowInfo provided
            if (isset($rowInfo[$columnName])) {
                $linkingUrlParams[$newParam['param_info']] = $rowInfo[$columnName];
            }

            // Special case 1 - when executing routines, according
            // to the type of the routine, url param changes
            if (empty($rowInfo['routine_type'])) {
                continue;
            }
        }

        return $linkRelations['default_page']
            . Url::getCommonRaw($linkingUrlParams, $divider);
    }

    /**
     * Prepare row information for display special links
     *
     * @param mixed[]      $row      current row data
     * @param mixed[]|bool $colOrder the column order
     *
     * @return array<string, mixed> associative array with column nama -> value
     */
    private function getRowInfoForSpecialLinks(array $row, array|bool $colOrder): array
    {
        $rowInfo = [];
        $fieldsMeta = $this->properties['fields_meta'];

        for ($n = 0; $n < $this->properties['fields_cnt']; ++$n) {
            $m = is_array($colOrder) ? $colOrder[$n] : $n;
            $rowInfo[mb_strtolower($fieldsMeta[$m]->orgname)] = $row[$m];
        }

        return $rowInfo;
    }

    /**
     * Get url sql query without conditions to shorten URLs
     *
     * @see     getTableBody()
     *
     * @return string analyzed sql query
     */
    private function getUrlSqlQuery(StatementInfo $statementInfo): string
    {
        if (
            $statementInfo->queryType !== 'SELECT'
            || mb_strlen($this->properties['sql_query']) < 200
            || $statementInfo->statement === null
            || $statementInfo->parser === null
        ) {
            return $this->properties['sql_query'];
        }

        $query = 'SELECT ' . Query::getClause($statementInfo->statement, $statementInfo->parser->list, 'SELECT');

        $fromClause = Query::getClause($statementInfo->statement, $statementInfo->parser->list, 'FROM');

        if ($fromClause !== '') {
            $query .= ' FROM ' . $fromClause;
        }

        return $query;
    }

    /**
     * Get column order and column visibility
     *
     * @see    getTableBody()
     *
     * @return mixed[] 2 element array - $col_order, $col_visib
     */
    private function getColumnParams(StatementInfo $statementInfo): array
    {
        if ($this->isSelect($statementInfo)) {
            $pmatable = new Table($this->properties['table'], $this->properties['db'], $this->dbi);
            $colOrder = $pmatable->getUiProp(Table::PROP_COLUMN_ORDER);
            $fieldsCount = $this->properties['fields_cnt'];
            /* Validate the value */
            if (is_array($colOrder)) {
                foreach ($colOrder as $value) {
                    if ($value < $fieldsCount) {
                        continue;
                    }

                    $pmatable->removeUiProp(Table::PROP_COLUMN_ORDER);
                    break;
                }

                if ($fieldsCount !== count($colOrder)) {
                    $pmatable->removeUiProp(Table::PROP_COLUMN_ORDER);
                    $colOrder = false;
                }
            }

            $colVisib = $pmatable->getUiProp(Table::PROP_COLUMN_VISIB);
            if (is_array($colVisib) && $fieldsCount !== count($colVisib)) {
                $pmatable->removeUiProp(Table::PROP_COLUMN_VISIB);
                $colVisib = false;
            }
        } else {
            $colOrder = false;
            $colVisib = false;
        }

        return [$colOrder, $colVisib];
    }

    /**
     * Get HTML for repeating headers
     *
     * @see    getTableBody()
     *
     * @param int      $numEmptyColumnsBefore The number of blank columns before this one
     * @param string[] $descriptions          A list of descriptions
     * @param int      $numEmptyColumnsAfter  The number of blank columns after this one
     *
     * @return string html content
     */
    private function getRepeatingHeaders(
        int $numEmptyColumnsBefore,
        array $descriptions,
        int $numEmptyColumnsAfter,
    ): string {
        $headerHtml = '<tr>' . "\n";

        if ($numEmptyColumnsBefore > 0) {
            $headerHtml .= '    <th colspan="'
                . $numEmptyColumnsBefore . '">'
                . "\n" . '        &nbsp;</th>' . "\n";
        } elseif (Config::getInstance()->settings['RowActionLinks'] === self::POSITION_NONE) {
            $headerHtml .= '    <th></th>' . "\n";
        }

        $headerHtml .= implode($descriptions);

        if ($numEmptyColumnsAfter > 0) {
            $headerHtml .= '    <th colspan="' . $numEmptyColumnsAfter
                . '">'
                . "\n" . '        &nbsp;</th>' . "\n";
        }

        $headerHtml .= '</tr>' . "\n";

        return $headerHtml;
    }

    /**
     * Get modified links
     *
     * @see     getTableBody()
     *
     * @param string $whereClause    the where clause of the sql
     * @param bool   $clauseIsUnique the unique condition of clause
     * @param string $urlSqlQuery    the analyzed sql query
     *
     * @return array<int,string|array<string, bool|string>>
     */
    private function getModifiedLinks(
        string $whereClause,
        bool $clauseIsUnique,
        string $urlSqlQuery,
    ): array {
        $urlParams = [
            'db' => $this->properties['db'],
            'table' => $this->properties['table'],
            'where_clause' => $whereClause,
            'where_clause_signature' => Core::signSqlQuery($whereClause),
            'clause_is_unique' => $clauseIsUnique,
            'sql_query' => $urlSqlQuery,
            'sql_signature' => Core::signSqlQuery($urlSqlQuery),
            'goto' => Url::getFromRoute('/sql'),
        ];

        $editUrl = Url::getFromRoute('/table/change');

        $copyUrl = Url::getFromRoute('/table/change');

        $editStr = $this->getActionLinkContent(
            'b_edit',
            __('Edit'),
        );
        $copyStr = $this->getActionLinkContent(
            'b_insrow',
            __('Copy'),
        );

        return [$editUrl, $copyUrl, $editStr, $copyStr, $urlParams];
    }

    /**
     * Get delete and kill links
     *
     * @see     getTableBody()
     *
     * @param string $whereClause    the where clause of the sql
     * @param bool   $clauseIsUnique the unique condition of clause
     * @param string $urlSqlQuery    the analyzed sql query
     * @param int    $processId      Process ID
     *
     * @return mixed[]  $del_url, $del_str, $js_conf
     * @psalm-return array{?string, ?string, ?string}
     */
    private function getDeleteAndKillLinks(
        string $whereClause,
        bool $clauseIsUnique,
        string $urlSqlQuery,
        DeleteLinkEnum $deleteLink,
        int $processId,
    ): array {
        $goto = $this->properties['goto'];

        if ($deleteLink === DeleteLinkEnum::DELETE_ROW) { // delete row case
            $urlParams = [
                'db' => $this->properties['db'],
                'table' => $this->properties['table'],
                'sql_query' => $urlSqlQuery,
                'message_to_show' => __('The row has been deleted.'),
                'goto' => $goto ?: Url::getFromRoute('/table/sql'),
            ];

            $linkGoto = Url::getFromRoute('/sql', $urlParams);

            $deleteQuery = 'DELETE FROM '
                . Util::backquote($this->properties['table'])
                . ' WHERE ' . $whereClause
                . ($clauseIsUnique ? '' : ' LIMIT 1');

            $urlParams = [
                'db' => $this->properties['db'],
                'table' => $this->properties['table'],
                'sql_query' => $deleteQuery,
                'message_to_show' => __('The row has been deleted.'),
                'goto' => $linkGoto,
            ];
            $deleteUrl = Url::getFromRoute('/sql');

            $jsConf = 'DELETE FROM ' . $this->properties['table']
                . ' WHERE ' . $whereClause
                . ($clauseIsUnique ? '' : ' LIMIT 1');

            $deleteString = $this->getActionLinkContent('b_drop', __('Delete'));
        } elseif ($deleteLink === DeleteLinkEnum::KILL_PROCESS) { // kill process case
            $urlParams = [
                'db' => $this->properties['db'],
                'table' => $this->properties['table'],
                'sql_query' => $urlSqlQuery,
                'goto' => Url::getFromRoute('/'),
            ];

            $linkGoto = Url::getFromRoute('/sql', $urlParams);

            $kill = $this->dbi->getKillQuery($processId);

            $urlParams = ['db' => 'mysql', 'sql_query' => $kill, 'goto' => $linkGoto];

            $deleteUrl = Url::getFromRoute('/sql');
            $jsConf = $kill;
            $deleteString = Generator::getIcon(
                'b_drop',
                __('Kill'),
            );
        } else {
            $deleteUrl = $deleteString = $jsConf = $urlParams = null;
        }

        return [$deleteUrl, $deleteString, $jsConf, $urlParams];
    }

    /**
     * Get content inside the table row action links (Edit/Copy/Delete)
     *
     * @see     getModifiedLinks(), getDeleteAndKillLinks()
     *
     * @param string $icon        The name of the file to get
     * @param string $displayText The text displaying after the image icon
     */
    private function getActionLinkContent(string $icon, string $displayText): string
    {
        $config = Config::getInstance();
        if (
            isset($config->settings['RowActionType'])
            && $config->settings['RowActionType'] === self::ACTION_LINK_CONTENT_ICONS
        ) {
            return '<span class="text-nowrap">'
                . Generator::getImage($icon, $displayText)
                . '</span>';
        }

        if (
            isset($config->settings['RowActionType'])
            && $config->settings['RowActionType'] === self::ACTION_LINK_CONTENT_TEXT
        ) {
            return '<span class="text-nowrap">' . $displayText . '</span>';
        }

        return Generator::getIcon($icon, $displayText);
    }

    /**
     * Get class for datetime related fields
     *
     * @see    getTableBody()
     *
     * @param FieldMetadata $meta the type of the column field
     *
     * @return string   the class for the column
     */
    private function getClassForDateTimeRelatedFields(FieldMetadata $meta): string
    {
        $fieldTypeClass = '';

        if ($meta->isMappedTypeTimestamp || $meta->isType(FieldMetadata::TYPE_DATETIME)) {
            $fieldTypeClass = 'datetimefield';
        } elseif ($meta->isType(FieldMetadata::TYPE_DATE)) {
            $fieldTypeClass = 'datefield';
        } elseif ($meta->isType(FieldMetadata::TYPE_TIME)) {
            $fieldTypeClass = 'timefield';
        } elseif ($meta->isType(FieldMetadata::TYPE_STRING)) {
            $fieldTypeClass = 'text';
        }

        return $fieldTypeClass;
    }

    /**
     * Prepare data cell for numeric type fields
     *
     * @see    getTableBody()
     *
     * @param string|null              $column           the column's value
     * @param string                   $class            the html class for column
     * @param bool                     $conditionField   the column should highlighted or not
     * @param FieldMetadata            $meta             the meta-information about this field
     * @param ForeignKeyRelatedTable[] $map              the list of relations
     * @param mixed[]                  $transformOptions the transformation parameters
     *
     * @return string the prepared cell, html content
     */
    private function getDataCellForNumericColumns(
        string|null $column,
        string $class,
        bool $conditionField,
        FieldMetadata $meta,
        array $map,
        StatementInfo $statementInfo,
        TransformationsPlugin|null $transformationPlugin,
        array $transformOptions,
    ): string {
        if ($column === null) {
            return $this->buildNullDisplay($class, $conditionField, $meta);
        }

        if ($column === '') {
            return $this->buildEmptyDisplay($class, $conditionField, $meta);
        }

        $whereComparison = ' = ' . $column;

        return $this->getRowData(
            $class,
            $conditionField,
            $statementInfo,
            $meta,
            $map,
            $column,
            $column,
            $transformationPlugin,
            'text-nowrap',
            $whereComparison,
            $transformOptions,
        );
    }

    /**
     * Get data cell for geometry type fields
     *
     * @see     getTableBody()
     *
     * @param string|null              $column           the relevant column in data row
     * @param string                   $class            the html class for column
     * @param FieldMetadata            $meta             the meta-information about this field
     * @param ForeignKeyRelatedTable[] $map              the list of relations
     * @param mixed[]                  $urlParams        the parameters for generate url
     * @param bool                     $conditionField   the column should highlighted or not
     * @param mixed[]                  $transformOptions the transformation parameters
     *
     * @return string the prepared data cell, html content
     */
    private function getDataCellForGeometryColumns(
        string|null $column,
        string $class,
        FieldMetadata $meta,
        array $map,
        array $urlParams,
        bool $conditionField,
        TransformationsPlugin|null $transformationPlugin,
        array $transformOptions,
        StatementInfo $statementInfo,
    ): string {
        if ($column === null) {
            return $this->buildNullDisplay($class, $conditionField, $meta);
        }

        if ($column === '') {
            return $this->buildEmptyDisplay($class, $conditionField, $meta);
        }

        // Display as [GEOMETRY - (size)]
        if ($_SESSION['tmpval']['geoOption'] === self::GEOMETRY_DISP_GEOM) {
            $geometryText = $this->handleNonPrintableContents(
                'GEOMETRY',
                $column,
                $transformationPlugin,
                $transformOptions,
                $meta,
                $urlParams,
            );

            return $this->buildValueDisplay($class, $conditionField, $geometryText);
        }

        if ($_SESSION['tmpval']['geoOption'] === self::GEOMETRY_DISP_WKT) {
            // Prepare in Well Known Text(WKT) format.
            $whereComparison = ' = ' . $column;

            // Convert to WKT format
            $wktval = Gis::convertToWellKnownText($column);
            [
                $isFieldTruncated,
                $displayedColumn,
                // skip 3rd param
            ] = $this->getPartialText($wktval);

            return $this->getRowData(
                $class,
                $conditionField,
                $statementInfo,
                $meta,
                $map,
                $wktval,
                $displayedColumn,
                $transformationPlugin,
                '',
                $whereComparison,
                $transformOptions,
                $isFieldTruncated,
            );
        }

        // Prepare in  Well Known Binary (WKB) format.

        if ($_SESSION['tmpval']['display_binary']) {
            $whereComparison = ' = ' . $column;

            $wkbval = substr(bin2hex($column), 8);
            [
                $isFieldTruncated,
                $displayedColumn,
                // skip 3rd param
            ] = $this->getPartialText($wkbval);

            return $this->getRowData(
                $class,
                $conditionField,
                $statementInfo,
                $meta,
                $map,
                $wkbval,
                $displayedColumn,
                $transformationPlugin,
                '',
                $whereComparison,
                $transformOptions,
                $isFieldTruncated,
            );
        }

        $wkbval = $this->handleNonPrintableContents(
            'BINARY',
            $column,
            $transformationPlugin,
            $transformOptions,
            $meta,
            $urlParams,
        );

        return $this->buildValueDisplay($class, $conditionField, $wkbval);
    }

    /**
     * Get data cell for non numeric type fields
     *
     * @see    getTableBody()
     *
     * @param string|null              $column           the relevant column in data row
     * @param string                   $class            the html class for column
     * @param FieldMetadata            $meta             the meta-information about the field
     * @param ForeignKeyRelatedTable[] $map              the list of relations
     * @param mixed[]                  $urlParams        the parameters for generate url
     * @param bool                     $conditionField   the column should highlighted or not
     * @param mixed[]                  $transformOptions the transformation parameters
     *
     * @return string the prepared data cell, html content
     */
    private function getDataCellForNonNumericColumns(
        string|null $column,
        string $class,
        FieldMetadata $meta,
        array $map,
        array $urlParams,
        bool $conditionField,
        TransformationsPlugin|null $transformationPlugin,
        array $transformOptions,
        StatementInfo $statementInfo,
    ): string {
        $originalLength = 0;

        $isAnalyse = $this->properties['is_analyse'];

        $bIsText = $transformationPlugin !== null && ! str_contains($transformationPlugin->getMIMEType(), 'Text');

        // disable inline grid editing
        // if binary fields are protected
        // or transformation plugin is of non text type
        // such as image
        $isTypeBlob = $meta->isType(FieldMetadata::TYPE_BLOB);
        $cfgProtectBinary = Config::getInstance()->settings['ProtectBinary'];
        if (
            ($meta->isBinary()
            && (
                $cfgProtectBinary === 'all'
                || ($cfgProtectBinary === 'noblob' && ! $isTypeBlob)
                || ($cfgProtectBinary === 'blob' && $isTypeBlob)
                )
            ) || $bIsText
        ) {
            $class = str_replace('grid_edit', '', $class);
        }

        if ($column === null) {
            return $this->buildNullDisplay($class, $conditionField, $meta);
        }

        if ($column === '') {
            return $this->buildEmptyDisplay($class, $conditionField, $meta);
        }

        // Cut all fields to \PhpMyAdmin\Config::getInstance()->settings['LimitChars']
        // (unless it's a link-type transformation or binary)
        $originalDataForWhereClause = $column;
        $displayedColumn = $column;
        $isFieldTruncated = false;
        if (
            ! ($transformationPlugin !== null
            && str_contains($transformationPlugin->getName(), 'Link'))
            && ! $meta->isBinary()
        ) {
            [$isFieldTruncated, $column, $originalLength] = $this->getPartialText($column);
        }

        if ($meta->isMappedTypeBit) {
            $displayedColumn = Util::printableBitValue((int) $displayedColumn, $meta->length);

            // some results of PROCEDURE ANALYSE() are reported as
            // being BINARY but they are quite readable,
            // so don't treat them as BINARY
        } elseif ($meta->isBinary() && $isAnalyse !== true) {
            // we show the BINARY or BLOB message and field's size
            // (or maybe use a transformation)
            $binaryOrBlob = 'BLOB';
            if ($meta->isType(FieldMetadata::TYPE_STRING)) {
                $binaryOrBlob = 'BINARY';
            }

            $displayedColumn = $this->handleNonPrintableContents(
                $binaryOrBlob,
                $displayedColumn,
                $transformationPlugin,
                $transformOptions,
                $meta,
                $urlParams,
                $isFieldTruncated,
            );
            $class = $this->addClass(
                $class,
                $conditionField,
                $meta,
                '',
                $isFieldTruncated,
                $transformationPlugin !== null,
            );
            $result = strip_tags($column);
            // disable inline grid editing
            // if binary or blob data is not shown
            if (stripos($result, $binaryOrBlob) !== false) {
                $class = str_replace('grid_edit', '', $class);
            }

            return $this->buildValueDisplay($class, $conditionField, $displayedColumn);
        }

        // transform functions may enable no-wrapping:
        $boolNoWrap = $transformationPlugin !== null
            && $transformationPlugin->applyTransformationNoWrap($transformOptions);

        // do not wrap if date field type or if no-wrapping enabled by transform functions
        // otherwise, preserve whitespaces and wrap
        $nowrap = $meta->isDateTimeType() || $boolNoWrap ? 'text-nowrap' : 'pre_wrap';

        $whereComparison = ' = ' . $this->dbi->quoteString($originalDataForWhereClause);

        return $this->getRowData(
            $class,
            $conditionField,
            $statementInfo,
            $meta,
            $map,
            $column,
            $displayedColumn,
            $transformationPlugin,
            $nowrap,
            $whereComparison,
            $transformOptions,
            $isFieldTruncated,
            (string) $originalLength,
        );
    }

    /**
     * Checks the posted options for viewing query results
     * and sets appropriate values in the session.
     *
     * @param StatementInfo $analyzedSqlResults the analyzed query results
     *
     * @todo    make maximum remembered queries configurable
     * @todo    move/split into SQL class!?
     * @todo    currently this is called twice unnecessary
     * @todo    ignore LIMIT and ORDER in query!?
     */
    public function setConfigParamsForDisplayTable(StatementInfo $analyzedSqlResults): void
    {
        $sqlMd5 = md5($this->properties['server'] . $this->properties['db'] . $this->properties['sql_query']);
        $query = [];
        if (isset($_SESSION['tmpval']['query'][$sqlMd5])) {
            $query = $_SESSION['tmpval']['query'][$sqlMd5];
        }

        $query['sql'] = $this->properties['sql_query'];

        $config = Config::getInstance();
        if (empty($query['repeat_cells'])) {
            $query['repeat_cells'] = $config->settings['RepeatCells'];
        }

        // The value can also be from _GET as described on issue #16146 when sorting results
        $sessionMaxRows = $_GET['session_max_rows'] ?? $_POST['session_max_rows'] ?? '';

        if (is_numeric($sessionMaxRows)) {
            $query['max_rows'] = (int) $sessionMaxRows;
            unset($_GET['session_max_rows'], $_POST['session_max_rows']);
        } elseif ($sessionMaxRows === self::ALL_ROWS) {
            $query['max_rows'] = self::ALL_ROWS;
            unset($_GET['session_max_rows'], $_POST['session_max_rows']);
        } elseif (empty($query['max_rows'])) {
            $query['max_rows'] = intval($config->settings['MaxRows']);
        }

        if (isset($_REQUEST['pos']) && is_numeric($_REQUEST['pos'])) {
            $query['pos'] = (int) $_REQUEST['pos'];
            unset($_REQUEST['pos']);
        } elseif (empty($query['pos'])) {
            $query['pos'] = 0;
        }

        // Full text is needed in case of explain statements, if not specified.
        $fullText = $analyzedSqlResults->isExplain;

        if (
            isset($_REQUEST['pftext']) && in_array(
                $_REQUEST['pftext'],
                [self::DISPLAY_PARTIAL_TEXT, self::DISPLAY_FULL_TEXT],
            )
        ) {
            $query['pftext'] = $_REQUEST['pftext'];
            unset($_REQUEST['pftext']);
        } elseif ($fullText) {
            $query['pftext'] = self::DISPLAY_FULL_TEXT;
        } elseif (empty($query['pftext'])) {
            $query['pftext'] = self::DISPLAY_PARTIAL_TEXT;
        }

        if (
            isset($_REQUEST['relational_display']) && in_array(
                $_REQUEST['relational_display'],
                [self::RELATIONAL_KEY, self::RELATIONAL_DISPLAY_COLUMN],
            )
        ) {
            $query['relational_display'] = $_REQUEST['relational_display'];
            unset($_REQUEST['relational_display']);
        } elseif (empty($query['relational_display'])) {
            // The current session value has priority over a
            // change via Settings; this change will be apparent
            // starting from the next session
            $query['relational_display'] = $config->settings['RelationalDisplay'];
        }

        if (
            isset($_REQUEST['geoOption']) && in_array(
                $_REQUEST['geoOption'],
                [self::GEOMETRY_DISP_WKT, self::GEOMETRY_DISP_WKB, self::GEOMETRY_DISP_GEOM],
            )
        ) {
            $query['geoOption'] = $_REQUEST['geoOption'];
            unset($_REQUEST['geoOption']);
        } elseif (empty($query['geoOption'])) {
            $query['geoOption'] = self::GEOMETRY_DISP_GEOM;
        }

        if (isset($_REQUEST['display_binary'])) {
            $query['display_binary'] = true;
            unset($_REQUEST['display_binary']);
        } elseif (isset($_REQUEST['display_options_form'])) {
            // we know that the checkbox was unchecked
            unset($query['display_binary']);
        } elseif (! isset($_REQUEST['full_text_button'])) {
            // selected by default because some operations like OPTIMIZE TABLE
            // and all queries involving functions return "binary" contents,
            // according to low-level field flags
            $query['display_binary'] = true;
        }

        if (isset($_REQUEST['display_blob'])) {
            $query['display_blob'] = true;
            unset($_REQUEST['display_blob']);
        } elseif (isset($_REQUEST['display_options_form'])) {
            // we know that the checkbox was unchecked
            unset($query['display_blob']);
        }

        if (isset($_REQUEST['hide_transformation'])) {
            $query['hide_transformation'] = true;
            unset($_REQUEST['hide_transformation']);
        } elseif (isset($_REQUEST['display_options_form'])) {
            // we know that the checkbox was unchecked
            unset($query['hide_transformation']);
        }

        // move current query to the last position, to be removed last
        // so only least executed query will be removed if maximum remembered
        // queries limit is reached
        unset($_SESSION['tmpval']['query'][$sqlMd5]);
        $_SESSION['tmpval']['query'][$sqlMd5] = $query;

        // do not exceed a maximum number of queries to remember
        if (count($_SESSION['tmpval']['query']) > 10) {
            array_shift($_SESSION['tmpval']['query']);
            //echo 'deleting one element ...';
        }

        // populate query configuration
        $_SESSION['tmpval']['pftext'] = $query['pftext'];
        $_SESSION['tmpval']['relational_display'] = $query['relational_display'];
        $_SESSION['tmpval']['geoOption'] = $query['geoOption'];
        $_SESSION['tmpval']['display_binary'] = isset($query['display_binary']);
        $_SESSION['tmpval']['display_blob'] = isset($query['display_blob']);
        $_SESSION['tmpval']['hide_transformation'] = isset($query['hide_transformation']);
        $_SESSION['tmpval']['pos'] = $query['pos'];
        $_SESSION['tmpval']['max_rows'] = $query['max_rows'];
        $_SESSION['tmpval']['repeat_cells'] = $query['repeat_cells'];
    }

    /**
     * Prepare a table of results returned by a SQL query.
     *
     * @param ResultInterface $dtResult         the link id associated to the query
     *                                 which results have to be displayed
     * @param bool            $isLimitedDisplay With limited operations or not
     *
     * @return string   Generated HTML content for resulted table
     */
    public function getTable(
        ResultInterface $dtResult,
        DisplayParts $displayParts,
        StatementInfo $statementInfo,
        bool $isLimitedDisplay = false,
    ): string {
        // The statement this table is built for.
        if (isset($statementInfo->statement)) {
            /** @var SelectStatement $statement */
            $statement = $statementInfo->statement;
        } else {
            $statement = null;
        }

        // Following variable are needed for use in isset/empty or
        // use with array indexes/safe use in foreach
        $fieldsMeta = $this->properties['fields_meta'];
        $showTable = $this->properties['showtable'];
        $printView = $this->properties['printview'];

        /**
         * @todo move this to a central place
         * @todo for other future table types
         */
        $isInnodb = (isset($showTable['Type'])
            && $showTable['Type'] === self::TABLE_TYPE_INNO_DB);

        if ($isInnodb && Sql::isJustBrowsing($statementInfo, true)) {
            $preCount = '~';
            $afterCount = Generator::showHint(
                Sanitize::sanitizeMessage(
                    __('May be approximate. See [doc@faq3-11]FAQ 3.11[/doc].'),
                ),
            );
        } else {
            $preCount = '';
            $afterCount = '';
        }

        // 1. ----- Prepares the work -----

        // 1.1 Gets the information about which functionalities should be
        //     displayed

        [$displayParts, $total] = $this->setDisplayPartsAndTotal($displayParts);

        // 1.2 Defines offsets for the next and previous pages
        $posNext = 0;
        $posPrev = 0;
        if ($displayParts->hasNavigationBar) {
            [$posNext, $posPrev] = $this->getOffsets();
        }

        // 1.3 Extract sorting expressions.
        //     we need $sort_expression and $sort_expression_nodirection
        //     even if there are many table references
        $sortExpression = [];
        $sortExpressionNoDirection = [];
        $sortDirection = [];

        if ($statement !== null && ! empty($statement->order)) {
            foreach ($statement->order as $o) {
                $sortExpression[] = $o->expr->expr . ' ' . $o->type;
                $sortExpressionNoDirection[] = $o->expr->expr;
                $sortDirection[] = $o->type;
            }
        } else {
            $sortExpression[] = '';
            $sortExpressionNoDirection[] = '';
            $sortDirection[] = '';
        }

        // 1.4 Prepares display of first and last value of the sorted column
        $sortedColumnMessage = '';
        foreach ($sortExpressionNoDirection as $expression) {
            $sortedColumnMessage .= $this->getSortedColumnMessage($dtResult, $expression);
        }

        // 2. ----- Prepare to display the top of the page -----

        // 2.1 Prepares a messages with position information
        $sqlQueryMessage = '';
        if ($displayParts->hasNavigationBar) {
            $message = $this->setMessageInformation(
                $sortedColumnMessage,
                $statementInfo,
                $total,
                $posNext,
                $preCount,
                $afterCount,
            );

            $sqlQueryMessage = Generator::getMessage($message, $this->properties['sql_query'], 'success');
        } elseif (! $printView && ! $isLimitedDisplay) {
            $sqlQueryMessage = Generator::getMessage(
                __('Your SQL query has been executed successfully.'),
                $this->properties['sql_query'],
                'success',
            );
        }

        // 2.3 Prepare the navigation bars
        if ($this->properties['table'] === '' && $statementInfo->queryType === 'SELECT') {
            // table does not always contain a real table name,
            // for example in MySQL 5.0.x, the query SHOW STATUS
            // returns STATUS as a table name
            $this->properties['table'] = $fieldsMeta[0]->table;
        }

        $unsortedSqlQuery = '';
        $sortByKeyData = [];
        // can the result be sorted?
        if (
            $displayParts->hasSortLink
            && $statementInfo->statement !== null
            && $statementInfo->parser !== null
        ) {
            $unsortedSqlQuery = Query::replaceClause(
                $statementInfo->statement,
                $statementInfo->parser->list,
                'ORDER BY',
                '',
            );

            // Data is sorted by indexes only if there is only one table.
            if ($this->isSelect($statementInfo)) {
                $sortByKeyData = $this->getSortByKeyDropDown($sortExpression, $unsortedSqlQuery);
            }
        }

        $navigation = [];
        if ($displayParts->hasNavigationBar && $statement !== null && empty($statement->limit)) {
            $navigation = $this->getTableNavigation($posNext, $posPrev, $isInnodb, $sortByKeyData);
        }

        // 2b ----- Get field references from Database -----
        // (see the 'relation' configuration variable)

        // initialize map
        $map = [];

        if ($this->properties['table'] !== '') {
            // This method set the values for $map array
            $map = $this->getForeignKeyRelatedTables();

            // Coming from 'Distinct values' action of structure page
            // We manipulate relations mechanism to show a link to related rows.
            if ($this->properties['is_browse_distinct']) {
                $map[$fieldsMeta[1]->name] = new ForeignKeyRelatedTable(
                    $this->properties['table'],
                    $fieldsMeta[1]->name,
                    '',
                    $this->properties['db'],
                );
            }
        }

        // end 2b

        // 3. ----- Prepare the results table -----
        $headers = $this->getTableHeaders(
            $displayParts,
            $statementInfo,
            $unsortedSqlQuery,
            $sortExpression,
            $sortExpressionNoDirection,
            $sortDirection,
            $isLimitedDisplay,
        );

        $body = $this->getTableBody($dtResult, $displayParts, $map, $statementInfo, $isLimitedDisplay);

        $this->properties['display_params'] = null;

        // 4. ----- Prepares the link for multi-fields edit and delete
        $isClauseUnique = $this->isClauseUnique($dtResult, $statementInfo, $displayParts->deleteLink);

        // 5. ----- Prepare "Query results operations"
        $operations = [];
        if (! $printView && ! $isLimitedDisplay) {
            $operations = $this->getResultsOperations($displayParts->hasPrintLink, $statementInfo);
        }

        $relationParameters = $this->relation->getRelationParameters();

        $config = Config::getInstance();

        return $this->template->render('display/results/table', [
            'sql_query_message' => $sqlQueryMessage,
            'navigation' => $navigation,
            'headers' => $headers,
            'body' => $body,
            'has_bulk_links' => $displayParts->deleteLink === DeleteLinkEnum::DELETE_ROW,
            'has_export_button' => $this->hasExportButton($statementInfo, $displayParts->deleteLink),
            'clause_is_unique' => $isClauseUnique,
            'operations' => $operations,
            'db' => $this->properties['db'],
            'table' => $this->properties['table'],
            'unique_id' => $this->properties['unique_id'],
            'sql_query' => $this->properties['sql_query'],
            'goto' => $this->properties['goto'],
            'unlim_num_rows' => $this->properties['unlim_num_rows'],
            'displaywork' => $relationParameters->displayFeature !== null,
            'relwork' => $relationParameters->relationFeature !== null,
            'save_cells_at_once' => $config->settings['SaveCellsAtOnce'],
            'default_sliders_state' => $config->settings['InitialSlidersState'],
            'text_dir' => $this->properties['text_dir'],
            'is_browse_distinct' => $this->properties['is_browse_distinct'],
        ]);
    }

    /**
     * Gets offsets for next page and previous page.
     *
     * @return array<int, int>
     * @psalm-return array{int, int}
     */
    private function getOffsets(): array
    {
        $tempVal = isset($_SESSION['tmpval']) && is_array($_SESSION['tmpval']) ? $_SESSION['tmpval'] : [];
        if (isset($tempVal['max_rows']) && $tempVal['max_rows'] === self::ALL_ROWS) {
            return [0, 0];
        }

        $pos = isset($tempVal['pos']) && is_int($tempVal['pos']) ? $tempVal['pos'] : 0;
        $maxRows = isset($tempVal['max_rows']) && is_int($tempVal['max_rows']) ? $tempVal['max_rows'] : 25;

        return [$pos + $maxRows, max(0, $pos - $maxRows)];
    }

    /**
     * Prepare sorted column message
     *
     * @see     getTable()
     *
     * @param ResultInterface $dtResult                  the link id associated to the query
     *                                                   which results have to be displayed
     * @param string|null     $sortExpressionNoDirection sort expression without direction
     */
    private function getSortedColumnMessage(
        ResultInterface $dtResult,
        string|null $sortExpressionNoDirection,
    ): string {
        $fieldsMeta = $this->properties['fields_meta']; // To use array indexes

        if ($sortExpressionNoDirection === null || $sortExpressionNoDirection === '') {
            return '';
        }

        if (! str_contains($sortExpressionNoDirection, '.')) {
            $sortTable = $this->properties['table'];
            $sortColumn = $sortExpressionNoDirection;
        } else {
            [$sortTable, $sortColumn] = explode('.', $sortExpressionNoDirection);
        }

        $sortTable = Util::unQuote($sortTable);
        $sortColumn = Util::unQuote($sortColumn);

        // find the sorted column index in row result
        // (this might be a multi-table query)
        $sortedColumnIndex = false;

        foreach ($fieldsMeta as $key => $meta) {
            if (($meta->table === $sortTable) && ($meta->name === $sortColumn)) {
                $sortedColumnIndex = $key;
                break;
            }
        }

        if ($sortedColumnIndex === false) {
            return '';
        }

        // fetch first row of the result set
        $row = $dtResult->fetchRow();

        // check for non printable sorted row data
        $meta = $fieldsMeta[$sortedColumnIndex];

        $isBlobOrGeometryOrBinary = $meta->isType(FieldMetadata::TYPE_BLOB)
                                    || $meta->isMappedTypeGeometry || $meta->isBinary;

        if ($isBlobOrGeometryOrBinary) {
            $columnForFirstRow = $this->handleNonPrintableContents(
                $meta->getMappedType(),
                $row !== [] ? $row[$sortedColumnIndex] : '',
                null,
                [],
                $meta,
            );
        } else {
            $columnForFirstRow = $row !== [] ? $row[$sortedColumnIndex] : '';
        }

        $config = Config::getInstance();
        $columnForFirstRow = mb_strtoupper(
            mb_substr(
                (string) $columnForFirstRow,
                0,
                $config->settings['LimitChars'],
            ) . '...',
        );

        // fetch last row of the result set
        $dtResult->seek($this->properties['num_rows'] > 0 ? $this->properties['num_rows'] - 1 : 0);
        $row = $dtResult->fetchRow();

        // check for non printable sorted row data
        $meta = $fieldsMeta[$sortedColumnIndex];
        if ($isBlobOrGeometryOrBinary) {
            $columnForLastRow = $this->handleNonPrintableContents(
                $meta->getMappedType(),
                $row !== [] ? $row[$sortedColumnIndex] : '',
                null,
                [],
                $meta,
            );
        } else {
            $columnForLastRow = $row !== [] ? $row[$sortedColumnIndex] : '';
        }

        $columnForLastRow = mb_strtoupper(
            mb_substr(
                (string) $columnForLastRow,
                0,
                $config->settings['LimitChars'],
            ) . '...',
        );

        // reset to first row for the loop in getTableBody()
        $dtResult->seek(0);

        // we could also use here $sort_expression_nodirection
        return ' [' . htmlspecialchars($sortColumn)
            . ': <strong>' . htmlspecialchars($columnForFirstRow) . ' - '
            . htmlspecialchars($columnForLastRow) . '</strong>]';
    }

    /**
     * Set the content that needs to be shown in message
     *
     * @see     getTable()
     *
     * @param string $sortedColumnMessage the message for sorted column
     * @param int    $total               the total number of rows returned by
     *                                    the SQL query without any
     *                                    programmatically appended LIMIT clause
     * @param int    $posNext             the offset for next page
     * @param string $preCount            the string renders before row count
     * @param string $afterCount          the string renders after row count
     *
     * @return Message an object of Message
     */
    private function setMessageInformation(
        string $sortedColumnMessage,
        StatementInfo $statementInfo,
        int $total,
        int $posNext,
        string $preCount,
        string $afterCount,
    ): Message {
        $unlimNumRows = $this->properties['unlim_num_rows']; // To use in isset()

        if (! empty($statementInfo->statement->limit)) {
            $firstShownRec = $statementInfo->statement->limit->offset;
            $rowCount = $statementInfo->statement->limit->rowCount;

            if ($rowCount < $total) {
                $lastShownRec = $firstShownRec + $rowCount - 1;
            } else {
                $lastShownRec = $firstShownRec + $total - 1;
            }
        } elseif (($_SESSION['tmpval']['max_rows'] === self::ALL_ROWS) || ($posNext > $total)) {
            $firstShownRec = $_SESSION['tmpval']['pos'];
            $lastShownRec = $total - 1;
        } else {
            $firstShownRec = $_SESSION['tmpval']['pos'];
            $lastShownRec = $posNext - 1;
        }

        $messageViewWarning = false;
        $table = new Table($this->properties['table'], $this->properties['db'], $this->dbi);
        if ($table->isView() && ($total == Config::getInstance()->settings['MaxExactCountViews'])) {
            $message = Message::notice(
                __(
                    'This view has at least this number of rows. Please refer to %sdocumentation%s.',
                ),
            );

            $message->addParam('[doc@cfg_MaxExactCount]');
            $message->addParam('[/doc]');
            $messageViewWarning = Generator::showHint($message->getMessage());
        }

        $message = Message::success(__('Showing rows %1s - %2s'));
        $message->addParam($firstShownRec);

        if ($messageViewWarning !== false) {
            $message->addParamHtml('... ' . $messageViewWarning);
        } else {
            $message->addParam($lastShownRec);
        }

        $message->addText('(');

        if ($messageViewWarning === false) {
            if ($unlimNumRows != $total) {
                $messageTotal = Message::notice(
                    $preCount . __('%1$s total, %2$s in query'),
                );
                $messageTotal->addParam(Util::formatNumber($total, 0));
                $messageTotal->addParam(Util::formatNumber($unlimNumRows, 0));
            } else {
                $messageTotal = Message::notice($preCount . __('%s total'));
                $messageTotal->addParam(Util::formatNumber($total, 0));
            }

            if ($afterCount !== '') {
                $messageTotal->addHtml($afterCount);
            }

            $message->addMessage($messageTotal, '');

            $message->addText(', ', '');
        }

        $messageQueryTime = Message::notice(__('Query took %01.4f seconds.') . ')');
        $messageQueryTime->addParam($this->properties['querytime']);

        $message->addMessage($messageQueryTime, '');
        $message->addHtml($sortedColumnMessage, '');

        return $message;
    }

    /**
     * Set the value of $map array for linking foreign key related tables
     *
     * @return ForeignKeyRelatedTable[]
     */
    private function getForeignKeyRelatedTables(): array
    {
        // To be able to later display a link to the related table,
        // we verify both types of relations: either those that are
        // native foreign keys or those defined in the phpMyAdmin
        // configuration storage. If no PMA storage, we won't be able
        // to use the "column to display" notion (for example show
        // the name related to a numeric id).
        $existRel = $this->relation->getForeigners(
            $this->properties['db'],
            $this->properties['table'],
            '',
            self::POSITION_BOTH,
        );

        if ($existRel === []) {
            return [];
        }

        $map = [];
        foreach ($existRel as $masterField => $rel) {
            if ($masterField !== 'foreign_keys_data') {
                $displayField = $this->relation->getDisplayField($rel['foreign_db'], $rel['foreign_table']);
                $map[$masterField] = new ForeignKeyRelatedTable(
                    $rel['foreign_table'],
                    $rel['foreign_field'],
                    $displayField,
                    $rel['foreign_db'],
                );
            } else {
                foreach ($rel as $oneKey) {
                    foreach ($oneKey['index_list'] as $index => $oneField) {
                        $displayField = $this->relation->getDisplayField(
                            $oneKey['ref_db_name'] ?? $GLOBALS['db'],
                            $oneKey['ref_table_name'],
                        );

                        $map[$oneField] = new ForeignKeyRelatedTable(
                            $oneKey['ref_table_name'],
                            $oneKey['ref_index_list'][$index],
                            $displayField,
                            $oneKey['ref_db_name'] ?? $GLOBALS['db'],
                        );
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Prepare multi field edit/delete links
     *
     * @see     getTable()
     *
     * @param ResultInterface $dtResult the link id associated to the query which results have to be displayed
     */
    private function isClauseUnique(
        ResultInterface $dtResult,
        StatementInfo $statementInfo,
        DeleteLinkEnum $deleteLink,
    ): bool {
        if ($deleteLink !== DeleteLinkEnum::DELETE_ROW) {
            return false;
        }

        // fetch last row of the result set
        $dtResult->seek($this->properties['num_rows'] > 0 ? $this->properties['num_rows'] - 1 : 0);
        $row = $dtResult->fetchRow();

        $expressions = [];

        if ($statementInfo->statement instanceof SelectStatement) {
            $expressions = $statementInfo->statement->expr;
        }

        /**
         * $clause_is_unique is needed by getTable() to generate the proper param
         * in the multi-edit and multi-delete form
         */
        [, $clauseIsUnique] = Util::getUniqueCondition(
            $this->properties['fields_cnt'],
            $this->properties['fields_meta'],
            $row,
            false,
            false,
            $expressions,
        );

        // reset to first row for the loop in getTableBody()
        $dtResult->seek(0);

        return $clauseIsUnique;
    }

    private function hasExportButton(StatementInfo $statementInfo, DeleteLinkEnum $deleteLink): bool
    {
        return $deleteLink === DeleteLinkEnum::DELETE_ROW && $statementInfo->queryType === 'SELECT';
    }

    /**
     * Get operations that are available on results.
     *
     * @see     getTable()
     *
     * @psalm-return array{
     *   has_export_link: bool,
     *   has_geometry: bool,
     *   has_print_link: bool,
     *   has_procedure: bool,
     *   url_params: array{
     *     db: string,
     *     table: string,
     *     printview: "1",
     *     sql_query: string,
     *     single_table?: "true",
     *     raw_query?: "true",
     *     unlim_num_rows?: int|numeric-string|false
     *   }
     * }
     */
    private function getResultsOperations(
        bool $hasPrintLink,
        StatementInfo $statementInfo,
    ): array {
        $urlParams = [
            'db' => $this->properties['db'],
            'table' => $this->properties['table'],
            'printview' => '1',
            'sql_query' => $this->properties['sql_query'],
        ];

        $geometryFound = false;

        // Export link
        // (the single_table parameter is used in \PhpMyAdmin\Export\Export->getDisplay()
        //  to hide the SQL and the structure export dialogs)
        // If the parser found a PROCEDURE clause
        // (most probably PROCEDURE ANALYSE()) it makes no sense to
        // display the Export link).
        if (
            ($statementInfo->queryType === self::QUERY_TYPE_SELECT)
            && ! $statementInfo->isProcedure
        ) {
            if (count($statementInfo->selectTables) === 1) {
                $urlParams['single_table'] = 'true';
            }

            // In case this query doesn't involve any tables,
            // implies only raw query is to be exported
            if ($statementInfo->selectTables === []) {
                $urlParams['raw_query'] = 'true';
            }

            $urlParams['unlim_num_rows'] = $this->properties['unlim_num_rows'];

            /**
             * At this point we don't know the table name; this can happen
             * for example with a query like
             * SELECT bike_code FROM (SELECT bike_code FROM bikes) tmp
             * As a workaround we set in the table parameter the name of the
             * first table of this database, so that /table/export and
             * the script it calls do not fail
             */
            if ($urlParams['table'] === '' && $urlParams['db'] !== '') {
                $urlParams['table'] = (string) $this->dbi->fetchValue('SHOW TABLES');
            }

            $fieldsMeta = $this->properties['fields_meta'];
            foreach ($fieldsMeta as $meta) {
                if ($meta->isMappedTypeGeometry) {
                    $geometryFound = true;
                    break;
                }
            }
        }

        return [
            'has_procedure' => $statementInfo->isProcedure,
            'has_geometry' => $geometryFound,
            'has_print_link' => $hasPrintLink,
            'has_export_link' => $statementInfo->queryType === self::QUERY_TYPE_SELECT,
            'url_params' => $urlParams,
        ];
    }

    /**
     * Verifies what to do with non-printable contents (binary or BLOB)
     * in Browse mode.
     *
     * @see getDataCellForGeometryColumns(), getDataCellForNonNumericColumns(), getSortedColumnMessage()
     *
     * @param string        $category         BLOB|BINARY|GEOMETRY
     * @param string|null   $content          the binary content
     * @param mixed[]       $transformOptions transformation parameters
     * @param FieldMetadata $meta             the meta-information about the field
     * @param mixed[]       $urlParams        parameters that should go to the download link
     * @param bool          $isTruncated      the result is truncated or not
     */
    private function handleNonPrintableContents(
        string $category,
        string|null $content,
        TransformationsPlugin|null $transformationPlugin,
        array $transformOptions,
        FieldMetadata $meta,
        array $urlParams = [],
        bool &$isTruncated = false,
    ): string {
        $isTruncated = false;
        $result = '[' . $category;

        if ($content !== null) {
            $size = strlen($content);
            $displaySize = Util::formatByteDown($size, 3, 1);
            $result .= ' - ' . $displaySize[0] . ' ' . $displaySize[1];
        } else {
            $result .= ' - NULL';
            $size = 0;
            $content = '';
        }

        $result .= ']';

        // if we want to use a text transformation on a BLOB column
        if ($transformationPlugin !== null) {
            $posMimeOctetstream = strpos(
                $transformationPlugin->getMIMESubtype(),
                'Octetstream',
            );
            $posMimeText = strpos($transformationPlugin->getMIMEType(), 'Text');
            if ($posMimeOctetstream || $posMimeText !== false) {
                // Applying Transformations on hex string of binary data
                // seems more appropriate
                $result = pack('H*', bin2hex($content));
            }
        }

        if ($size <= 0) {
            return $result;
        }

        if ($transformationPlugin !== null) {
            return $transformationPlugin->applyTransformation($result, $transformOptions, $meta);
        }

        $result = Core::mimeDefaultFunction($result);
        if (
            ($_SESSION['tmpval']['display_binary']
            && $meta->isType(FieldMetadata::TYPE_STRING))
            || ($_SESSION['tmpval']['display_blob']
            && $meta->isType(FieldMetadata::TYPE_BLOB))
        ) {
            // in this case, restart from the original $content
            if (
                mb_check_encoding($content, 'utf-8')
                && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\x9F]/u', $content)
            ) {
                // show as text if it's valid utf-8
                $result = htmlspecialchars($content);
            } else {
                $result = '0x' . bin2hex($content);
            }

            [
                $isTruncated,
                $result,
                // skip 3rd param
            ] = $this->getPartialText($result);
        }

        /* Create link to download */

        if ($urlParams !== [] && $this->properties['db'] !== '' && $meta->orgtable !== '') {
            $urlParams['where_clause_sign'] = Core::signSqlQuery($urlParams['where_clause']);
            $result = '<a href="'
                . Url::getFromRoute('/table/get-field', $urlParams)
                . '" class="disableAjax">'
                . $result . '</a>';
        }

        return $result;
    }

    /**
     * Retrieves the associated foreign key info for a data cell
     *
     * @param ForeignKeyRelatedTable $fieldInfo       the relation
     * @param string                 $whereComparison data for the where clause
     *
     * @return string|null  formatted data
     */
    private function getFromForeign(ForeignKeyRelatedTable $fieldInfo, string $whereComparison): string|null
    {
        $dispsql = 'SELECT '
            . Util::backquote($fieldInfo->displayField)
            . ' FROM '
            . Util::backquote($fieldInfo->database)
            . '.'
            . Util::backquote($fieldInfo->table)
            . ' WHERE '
            . Util::backquote($fieldInfo->field)
            . $whereComparison;

        $dispval = $this->dbi->fetchValue($dispsql);
        if ($dispval === false) {
            return __('Link not found!');
        }

        if ($dispval === null) {
            return null;
        }

        // Truncate values that are too long, see: #17902
        [, $dispval] = $this->getPartialText($dispval);

        return $dispval;
    }

    /**
     * Prepares the displayable content of a data cell in Browse mode,
     * taking into account foreign key description field and transformations
     *
     * @see     getDataCellForNumericColumns(), getDataCellForGeometryColumns(),
     *          getDataCellForNonNumericColumns(),
     *
     * @param string                   $class            css classes for the td element
     * @param bool                     $conditionField   whether the column is a part of the where clause
     * @param FieldMetadata            $meta             the meta-information about the field
     * @param ForeignKeyRelatedTable[] $map              the list of relations
     * @param string                   $data             data
     * @param string                   $displayedData    data that will be displayed (maybe be chunked)
     * @param string                   $nowrap           'nowrap' if the content should not be wrapped
     * @param string                   $whereComparison  data for the where clause
     * @param mixed[]                  $transformOptions options for transformation
     * @param bool                     $isFieldTruncated whether the field is truncated
     * @param string                   $originalLength   of a truncated column, or ''
     *
     * @return string  formatted data
     */
    private function getRowData(
        string $class,
        bool $conditionField,
        StatementInfo $statementInfo,
        FieldMetadata $meta,
        array $map,
        string $data,
        string $displayedData,
        TransformationsPlugin|null $transformationPlugin,
        string $nowrap,
        string $whereComparison,
        array $transformOptions,
        bool $isFieldTruncated = false,
        string $originalLength = '',
    ): string {
        $relationalDisplay = $_SESSION['tmpval']['relational_display'];
        $value = '';
        $tableDataCellClass = $this->addClass(
            $class,
            $conditionField,
            $meta,
            $nowrap,
            $isFieldTruncated,
            $transformationPlugin !== null,
        );

        if (! empty($statementInfo->statement->expr)) {
            foreach ($statementInfo->statement->expr as $expr) {
                if (empty($expr->alias) || empty($expr->column)) {
                    continue;
                }

                if (strcasecmp($meta->name, $expr->alias) !== 0) {
                    continue;
                }

                $meta->name = $expr->column;
            }
        }

        if (isset($map[$meta->name])) {
            $relation = $map[$meta->name];
            // Field to display from the foreign table?
            $dispval = '';

            // Check that we have a valid column name
            if ($relation->displayField !== '') {
                $dispval = $this->getFromForeign($relation, $whereComparison);
            }

            if ($this->properties['printview']) {
                if ($transformationPlugin !== null) {
                    $value .= $transformationPlugin->applyTransformation($data, $transformOptions, $meta);
                } else {
                    $value .= Core::mimeDefaultFunction($data);
                }

                $value .= ' <code>[-&gt;' . $dispval . ']</code>';
            } else {
                $sqlQuery = 'SELECT * FROM '
                    . Util::backquote($relation->database) . '.'
                    . Util::backquote($relation->table)
                    . ' WHERE '
                    . Util::backquote($relation->field)
                    . $whereComparison;

                $urlParams = [
                    'db' => $relation->database,
                    'table' => $relation->table,
                    'pos' => '0',
                    'sql_signature' => Core::signSqlQuery($sqlQuery),
                    'sql_query' => $sqlQuery,
                ];

                if ($transformationPlugin !== null) {
                    // always apply a transformation on the real data,
                    // not on the display field
                    $displayedData = $transformationPlugin->applyTransformation($data, $transformOptions, $meta);
                } elseif ($relationalDisplay === self::RELATIONAL_DISPLAY_COLUMN && $relation->displayField !== '') {
                    // user chose "relational display field" in the
                    // display options, so show display field in the cell
                    $displayedData = $dispval === null ? '<em>NULL</em>' : Core::mimeDefaultFunction($dispval);
                } else {
                    // otherwise display data in the cell
                    $displayedData = Core::mimeDefaultFunction($displayedData);
                }

                if ($relationalDisplay === self::RELATIONAL_KEY) {
                    // user chose "relational key" in the display options, so
                    // the title contains the display field
                    $title = $dispval ?? '';
                } else {
                    $title = $data;
                }

                $tagParams = ['title' => $title];
                if (str_contains($class, 'grid_edit')) {
                    $tagParams['class'] = 'ajax';
                }

                $value .= Generator::linkOrButton(
                    Url::getFromRoute('/sql'),
                    $urlParams,
                    $displayedData,
                    $tagParams,
                );
            }
        } elseif ($transformationPlugin !== null) {
            $value .= $transformationPlugin->applyTransformation($data, $transformOptions, $meta);
        } else {
            $value .= Core::mimeDefaultFunction($data);
        }

        return $this->template->render('display/results/row_data', [
            'value' => $value,
            'td_class' => $tableDataCellClass,
            'decimals' => $meta->decimals,
            'type' => $meta->getMappedType(),
            'original_length' => $originalLength,
        ]);
    }

    /**
     * Truncates given string based on LimitChars configuration
     * and Session pftext variable
     * (string is truncated only if necessary)
     *
     * @see handleNonPrintableContents(), getDataCellForGeometryColumns(), getDataCellForNonNumericColumns
     *
     * @param string $str string to be truncated
     *
     * @return mixed[]
     * @psalm-return array{bool, string, int}
     */
    private function getPartialText(string $str): array
    {
        $originalLength = mb_strlen($str);
        $config = Config::getInstance();
        if (
            $originalLength > $config->settings['LimitChars']
            && $_SESSION['tmpval']['pftext'] === self::DISPLAY_PARTIAL_TEXT
        ) {
            $str = mb_substr($str, 0, $config->settings['LimitChars']) . '...';
            $truncated = true;
        } else {
            $truncated = false;
        }

        return [$truncated, $str, $originalLength];
    }
}

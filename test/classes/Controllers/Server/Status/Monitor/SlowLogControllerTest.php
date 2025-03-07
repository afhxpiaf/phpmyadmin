<?php

declare(strict_types=1);

namespace PhpMyAdmin\Tests\Controllers\Server\Status\Monitor;

use PhpMyAdmin\Config;
use PhpMyAdmin\Controllers\Server\Status\Monitor\SlowLogController;
use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\Http\ServerRequest;
use PhpMyAdmin\Server\Status\Data;
use PhpMyAdmin\Server\Status\Monitor;
use PhpMyAdmin\Template;
use PhpMyAdmin\Tests\AbstractTestCase;
use PhpMyAdmin\Tests\Stubs\DbiDummy;
use PhpMyAdmin\Tests\Stubs\ResponseRenderer;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SlowLogController::class)]
class SlowLogControllerTest extends AbstractTestCase
{
    protected DatabaseInterface $dbi;

    protected DbiDummy $dummyDbi;

    private Data $data;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['text_dir'] = 'ltr';

        parent::setGlobalConfig();

        parent::setTheme();

        $this->dummyDbi = $this->createDbiDummy();
        $this->dbi = $this->createDatabaseInterface($this->dummyDbi);
        DatabaseInterface::$instance = $this->dbi;

        $GLOBALS['server'] = 1;
        $GLOBALS['db'] = 'db';
        $GLOBALS['table'] = 'table';
        $config = Config::getInstance();
        $config->selectedServer['DisableIS'] = false;
        $config->selectedServer['host'] = 'localhost';

        $this->data = new Data($this->dbi, $config);
    }

    public function testSlowLog(): void
    {
        $response = new ResponseRenderer();

        $dbi = DatabaseInterface::getInstance();
        $controller = new SlowLogController(
            $response,
            new Template(),
            $this->data,
            new Monitor($dbi),
            $dbi,
        );

        $request = $this->createStub(ServerRequest::class);
        $request->method('isAjax')->willReturn(true);
        $request->method('getParsedBodyParam')->willReturnMap([['time_start', null, '0'], ['time_end', null, '10']]);

        $this->dummyDbi->addSelectDb('mysql');
        $controller($request);
        $this->dummyDbi->assertAllSelectsConsumed();
        $ret = $response->getJSONResult();

        $resultRows = [['sql_text' => 'insert sql_text', '#' => 11], ['sql_text' => 'update sql_text', '#' => 10]];
        $resultSum = ['insert' => 11, 'TOTAL' => 21, 'update' => 10];
        $this->assertEquals(2, $ret['message']['numRows']);
        $this->assertEquals($resultRows, $ret['message']['rows']);
        $this->assertEquals($resultSum, $ret['message']['sum']);
    }
}

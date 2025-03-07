<?php

declare(strict_types=1);

namespace PhpMyAdmin\Tests\Controllers\Server\Status\Monitor;

use PhpMyAdmin\Config;
use PhpMyAdmin\Controllers\Server\Status\Monitor\LogVarsController;
use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\Http\ServerRequest;
use PhpMyAdmin\Server\Status\Data;
use PhpMyAdmin\Server\Status\Monitor;
use PhpMyAdmin\Template;
use PhpMyAdmin\Tests\AbstractTestCase;
use PhpMyAdmin\Tests\Stubs\DbiDummy;
use PhpMyAdmin\Tests\Stubs\ResponseRenderer;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(LogVarsController::class)]
class LogVarsControllerTest extends AbstractTestCase
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

    public function testLogVars(): void
    {
        $value = [
            'general_log' => 'OFF',
            'log_output' => 'FILE',
            'long_query_time' => '10.000000',
            'slow_query_log' => 'OFF',
        ];

        $response = new ResponseRenderer();

        $dbi = DatabaseInterface::getInstance();
        $controller = new LogVarsController(
            $response,
            new Template(),
            $this->data,
            new Monitor($dbi),
            $dbi,
        );

        $request = $this->createStub(ServerRequest::class);
        $request->method('isAjax')->willReturn(true);
        $request->method('getParsedBodyParam')->willReturnMap([['varName', null, 'varName']]);

        $this->dummyDbi->addSelectDb('mysql');
        $controller($request);
        $this->dummyDbi->assertAllSelectsConsumed();
        $ret = $response->getJSONResult();

        $this->assertEquals($value, $ret['message']);
    }
}

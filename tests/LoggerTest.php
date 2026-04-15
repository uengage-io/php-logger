<?php

namespace Uengage\Logger\Tests;

use Uengage\Logger\Logger;
use Uengage\Logger\Transporters\FileTransporter;

/**
 * Test suite for uengage/php-logger package.
 *
 * Mirrors the Node.js test suite (test/index.test.js) 1-to-1.
 */
class LoggerTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{
    /** @var string Temp directory created fresh for each test */
    private $_tmpDir;

    /** @var string Expected log file path inside $_tmpDir */
    private $_logFile;

    protected function set_up()
    {
        $this->_tmpDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                          . 'uengage-logger-test-' . uniqid('', true);
        mkdir($this->_tmpDir, 0777, true);
        $this->_logFile = $this->_tmpDir . DIRECTORY_SEPARATOR . 'edge-ordering.log';
    }

    protected function tear_down()
    {
        $this->_rrmdir($this->_tmpDir);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function _rrmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), array('.', '..'));
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->_rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Return a valid file-transport config, with optional overrides.
     * Uses minLevel='debug' so all log calls reach the file during tests.
     * @param array $overrides
     * @return array
     */
    private function _fileConfig(array $overrides = array())
    {
        return array_merge(array(
            'product'     => 'edge',
            'service'     => 'ordering',
            'component'   => 'mobile-app',
            'version'     => '1.4.2',
            'environment' => 'production',
            'source'      => 'server',
            'transport'   => array('type' => 'file', 'config' => array('basePath' => $this->_tmpDir)),
            'minLevel'    => 'debug',
        ), $overrides);
    }

    private function _readLog()
    {
        if (!file_exists($this->_logFile)) {
            return array();
        }
        $lines   = explode("\n", trim(file_get_contents($this->_logFile)));
        $entries = array();
        foreach ($lines as $line) {
            if ($line !== '') {
                $entries[] = json_decode($line, true);
            }
        }
        return $entries;
    }

    // ─── Group 1: Constructor validation ─────────────────────────────────────

    public function test_throwsWhenProductMissing()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('product');
        new Logger($this->_fileConfig(array('product' => '')));
    }

    public function test_throwsWhenServiceMissing()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('service');
        new Logger($this->_fileConfig(array('service' => '')));
    }

    public function test_throwsWhenComponentMissing()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('component');
        new Logger($this->_fileConfig(array('component' => '')));
    }

    public function test_throwsWhenVersionMissing()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('version');
        new Logger($this->_fileConfig(array('version' => '')));
    }

    public function test_throwsWhenEnvironmentMissing()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('environment');
        new Logger($this->_fileConfig(array('environment' => '')));
    }

    public function test_throwsWhenSourceMissing()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('source');
        new Logger($this->_fileConfig(array('source' => '')));
    }

    public function test_throwsWhenTransportMissing()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('transport');
        $config = $this->_fileConfig();
        unset($config['transport']);
        new Logger($config);
    }

    public function test_throwsWhenTransportTypeInvalid()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('transport.type');
        new Logger($this->_fileConfig(array(
            'transport' => array('type' => 'syslog'),
        )));
    }

    // ─── Group 2: File path derivation ───────────────────────────────────────

    public function test_createsLogFileNamedProductDashServiceDotLog()
    {
        $logger = new Logger($this->_fileConfig());
        $logger->warn('hello');
        $this->assertFileExists($this->_logFile);
    }

    // ─── Group 3: Auto directory creation ────────────────────────────────────

    public function test_autoCreatesDirectoryIfNotExists()
    {
        $nestedDir = $this->_tmpDir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'sub';
        $this->assertFalse(is_dir($nestedDir), 'nested dir should not exist yet');

        $logger = new Logger($this->_fileConfig(array(
            'transport' => array('type' => 'file', 'config' => array('basePath' => $nestedDir)),
        )));
        $logger->warn('autocreated');

        $this->assertTrue(is_dir($nestedDir), 'nested dir created automatically');
        $this->assertFileExists($nestedDir . DIRECTORY_SEPARATOR . 'edge-ordering.log');
    }

    // ─── Group 4: Log entry shape ─────────────────────────────────────────────

    public function test_exactSchemaShapeForErrorLog()
    {
        $logger = new Logger($this->_fileConfig());
        $logger->error('Payment webhook timeout', array(
            'trace_id' => 'abc-123-def-456',
            'user_id'  => 'usr_7x9k2m',
            'tenant'   => array('business_id' => '456', 'parent_id' => '123'),
            'error'    => array(
                'code'     => 'PAYMENT_WEBHOOK_TIMEOUT',
                'category' => 'engineering',
                'stack'    => 'TimeoutError: timed out after 30000ms',
                'upstream' => 'razorpay',
            ),
            'context'  => array('order_id' => 'ord_8x2k', 'amount' => 450.00, 'latency_ms' => 30012),
        ));

        $entries = $this->_readLog();
        $this->assertCount(1, $entries);
        $e = $entries[0];

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $e['timestamp']);
        $this->assertEquals('ERROR',                      $e['level']);
        $this->assertEquals('edge',                       $e['product']);
        $this->assertEquals('ordering',                   $e['service']);
        $this->assertEquals('mobile-app',                 $e['component']);
        $this->assertEquals('1.4.2',                      $e['version']);
        $this->assertEquals('production',                 $e['environment']);
        $this->assertEquals('abc-123-def-456',            $e['trace_id']);
        $this->assertEquals('server',                     $e['source']);
        $this->assertEquals('Payment webhook timeout',    $e['message']);
        $this->assertEquals(array('business_id' => '456', 'parent_id' => '123'), $e['tenant']);
        $this->assertEquals('usr_7x9k2m',                $e['user_id']);
        $this->assertEquals('PAYMENT_WEBHOOK_TIMEOUT',   $e['error']['code']);
        $this->assertEquals('engineering',               $e['error']['category']);
        $this->assertStringContainsString('TimeoutError', $e['error']['stack']);
        $this->assertEquals('razorpay',                  $e['error']['upstream']);
        $this->assertEquals('ord_8x2k',                  $e['context']['order_id']);
        $this->assertEquals(450.00,                      $e['context']['amount']);
        $this->assertEquals(30012,                       $e['context']['latency_ms']);
    }

    public function test_levelIsUppercaseForAllMethods()
    {
        $logger = new Logger($this->_fileConfig());
        $logger->debug('d');
        $logger->info('i');
        $logger->warn('w');
        $logger->error('e');

        $entries = $this->_readLog();
        $this->assertCount(4, $entries);
        $this->assertEquals('DEBUG', $entries[0]['level']);
        $this->assertEquals('INFO',  $entries[1]['level']);
        $this->assertEquals('WARN',  $entries[2]['level']);
        $this->assertEquals('ERROR', $entries[3]['level']);
    }

    public function test_omitsUserIdWhenNotProvided()
    {
        $logger = new Logger($this->_fileConfig());
        $logger->warn('hello');

        $entries = $this->_readLog();
        $this->assertArrayNotHasKey('user_id', $entries[0]);
    }

    public function test_omitsErrorWhenNotProvided()
    {
        $logger = new Logger($this->_fileConfig());
        $logger->error('something broke');

        $entries = $this->_readLog();
        $this->assertArrayNotHasKey('error', $entries[0]);
    }

    public function test_omitsContextWhenNotProvided()
    {
        $logger = new Logger($this->_fileConfig());
        $logger->warn('no context');

        $entries = $this->_readLog();
        $this->assertArrayNotHasKey('context', $entries[0]);
    }

    public function test_autoGeneratesTraceIdAsUuid()
    {
        $logger = new Logger($this->_fileConfig());
        $logger->warn('no trace_id passed');

        $entries = $this->_readLog();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $entries[0]['trace_id']
        );
    }

    // ─── Group 5: minLevel filtering ─────────────────────────────────────────

    public function test_defaultMinLevelIsWarn()
    {
        // No minLevel specified — default is 'warn', so debug and info are dropped.
        $logger = new Logger(array_merge(
            $this->_fileConfig(),
            array('minLevel' => null) // unset by overriding with null then re-building
        ));
        // Rebuild without minLevel key to test true default
        $config = array(
            'product' => 'edge', 'service' => 'ordering', 'component' => 'mobile-app',
            'version' => '1.4.2', 'environment' => 'production', 'source' => 'server',
            'transport' => array('type' => 'file', 'config' => array('basePath' => $this->_tmpDir)),
        );
        $logger = new Logger($config);
        $logger->debug('dropped');
        $logger->info('dropped');
        $logger->warn('kept');
        $logger->error('kept');

        $entries = $this->_readLog();
        $this->assertCount(2, $entries);
        $this->assertEquals('WARN',  $entries[0]['level']);
        $this->assertEquals('ERROR', $entries[1]['level']);
    }

    public function test_minLevelDebugPassesAllFourLevels()
    {
        $logger = new Logger($this->_fileConfig(array('minLevel' => 'debug')));
        $logger->debug('d');
        $logger->info('i');
        $logger->warn('w');
        $logger->error('e');

        $entries = $this->_readLog();
        $this->assertCount(4, $entries);
    }

    // ─── Group 6: Context isolation (deep-clone) ──────────────────────────────

    public function test_mutatingContextAfterLogDoesNotAffectEntry()
    {
        $logger  = new Logger($this->_fileConfig());
        $context = array('order_id' => 'ord_original');
        $logger->warn('placed', array('context' => $context));
        $context['order_id'] = 'ord_mutated';

        $entries = $this->_readLog();
        $this->assertEquals('ord_original', $entries[0]['context']['order_id']);
    }

    public function test_mutatingErrorAfterLogDoesNotAffectEntry()
    {
        $logger = new Logger($this->_fileConfig());
        $error  = array('code' => 'ORIGINAL', 'category' => 'engineering');
        $logger->error('broke', array('error' => $error));
        $error['code'] = 'MUTATED';

        $entries = $this->_readLog();
        $this->assertEquals('ORIGINAL', $entries[0]['error']['code']);
    }

    // ─── Group 7: File rotation ───────────────────────────────────────────────

    public function test_rotatesFileWhenSizeExceedsMax()
    {
        $filePath = $this->_tmpDir . DIRECTORY_SEPARATOR . 'rotate-test.log';

        $transporter = new FileTransporter(array(
            'filePath'         => $filePath,
            'maxFileSizeBytes' => 50,
            'maxRotations'     => 3,
        ));

        for ($i = 0; $i < 5; $i++) {
            $transporter->send(array('message' => 'entry-' . $i, 'level' => 'INFO'));
        }

        $this->assertFileExists($filePath . '.1');
        $this->assertFileDoesNotExist($filePath . '.4');
    }

    // ─── Group 8: UUID format ────────────────────────────────────────────────

    public function test_uuidV4Format()
    {
        $method = new \ReflectionMethod('Uengage\Logger\Logger', '_generateUuid');
        $method->setAccessible(true);

        for ($i = 0; $i < 50; $i++) {
            $uuid = $method->invoke(null);
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $uuid,
                'UUID does not match v4 format: ' . $uuid
            );
        }
    }
}

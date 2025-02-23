<?php
/**
 * ApiTest class.
 */

namespace Friendica\Test\legacy;

use Friendica\App;
use Friendica\Core\Config\IConfig;
use Friendica\Core\PConfig\IPConfig;
use Friendica\Core\Protocol;
use Friendica\DI;
use Friendica\Network\HTTPException;
use Friendica\Test\FixtureTest;
use Friendica\Util\Temporal;
use Monolog\Handler\TestHandler;

require_once __DIR__ . '/../../include/api.php';

/**
 * Tests for the API functions.
 *
 * Functions that use header() need to be tested in a separate process.
 * @see https://phpunit.de/manual/5.7/en/appendixes.annotations.html#appendixes.annotations.runTestsInSeparateProcesses
 *
 * @backupGlobals enabled
 */
class ApiTest extends FixtureTest
{
	/**
	 * @var TestHandler Can handle log-outputs
	 */
	protected $logOutput;

	/** @var array */
	protected $selfUser;
	/** @var array */
	protected $friendUser;
	/** @var array */
	protected $otherUser;

	protected $wrongUserId;

	/** @var App */
	protected $app;

	/** @var IConfig */
	protected $config;

	/**
	 * Create variables used by tests.
	 */
	protected function setUp() : void
	{
		global $API, $called_api;
		$API = [];
		$called_api = [];

		parent::setUp();

		/** @var IConfig $config */
		$this->config = $this->dice->create(IConfig::class);

		$this->config->set('system', 'url', 'http://localhost');
		$this->config->set('system', 'hostname', 'localhost');
		$this->config->set('system', 'worker_dont_fork', true);

		// Default config
		$this->config->set('config', 'hostname', 'localhost');
		$this->config->set('system', 'throttle_limit_day', 100);
		$this->config->set('system', 'throttle_limit_week', 100);
		$this->config->set('system', 'throttle_limit_month', 100);
		$this->config->set('system', 'theme', 'system_theme');


		/** @var App app */
		$this->app = DI::app();

		$this->app->argc = 1;
		$this->app->argv = [''];

		// User data that the test database is populated with
		$this->selfUser   = [
			'id'   => 42,
			'name' => 'Self contact',
			'nick' => 'selfcontact',
			'nurl' => 'http://localhost/profile/selfcontact'
		];
		$this->friendUser = [
			'id'   => 44,
			'name' => 'Friend contact',
			'nick' => 'friendcontact',
			'nurl' => 'http://localhost/profile/friendcontact'
		];
		$this->otherUser  = [
			'id'   => 43,
			'name' => 'othercontact',
			'nick' => 'othercontact',
			'nurl' => 'http://localhost/profile/othercontact'
		];

		// User ID that we know is not in the database
		$this->wrongUserId = 666;

		DI::session()->start();

		// Most API require login so we force the session
		$_SESSION = [
			'allow_api'     => true,
			'authenticated' => true,
			'uid'           => $this->selfUser['id']
		];
	}

	/**
	 * Assert that an user array contains expected keys.
	 *
	 * @param array $user User array
	 *
	 * @return void
	 */
	private function assertSelfUser(array $user)
	{
		self::assertEquals($this->selfUser['id'], $user['uid']);
		self::assertEquals($this->selfUser['id'], $user['cid']);
		self::assertEquals(1, $user['self']);
		self::assertEquals('DFRN', $user['location']);
		self::assertEquals($this->selfUser['name'], $user['name']);
		self::assertEquals($this->selfUser['nick'], $user['screen_name']);
		self::assertEquals('dfrn', $user['network']);
		self::assertTrue($user['verified']);
	}

	/**
	 * Assert that an user array contains expected keys.
	 *
	 * @param array $user User array
	 *
	 * @return void
	 */
	private function assertOtherUser(array $user = [])
	{
		self::assertEquals($this->otherUser['id'], $user['id']);
		self::assertEquals($this->otherUser['id'], $user['id_str']);
		self::assertEquals(0, $user['self']);
		self::assertEquals($this->otherUser['name'], $user['name']);
		self::assertEquals($this->otherUser['nick'], $user['screen_name']);
		self::assertFalse($user['verified']);
	}

	/**
	 * Assert that a status array contains expected keys.
	 *
	 * @param array $status Status array
	 *
	 * @return void
	 */
	private function assertStatus(array $status = [])
	{
		self::assertIsString($status['text'] ?? '');
		self::assertIsInt($status['id'] ?? '');
		// We could probably do more checks here.
	}

	/**
	 * Assert that a list array contains expected keys.
	 *
	 * @param array $list List array
	 *
	 * @return void
	 */
	private function assertList(array $list = [])
	{
		self::assertIsString($list['name']);
		self::assertIsInt($list['id']);
		self::assertIsString('string', $list['id_str']);
		self::assertContains($list['mode'], ['public', 'private']);
		// We could probably do more checks here.
	}

	/**
	 * Assert that the string is XML and contain the root element.
	 *
	 * @param string $result       XML string
	 * @param string $root_element Root element name
	 *
	 * @return void
	 */
	private function assertXml($result = '', $root_element = '')
	{
		self::assertStringStartsWith('<?xml version="1.0"?>', $result);
		self::assertStringContainsString('<' . $root_element, $result);
		// We could probably do more checks here.
	}

	/**
	 * Get the path to a temporary empty PNG image.
	 *
	 * @return string Path
	 */
	private function getTempImage()
	{
		$tmpFile = tempnam(sys_get_temp_dir(), 'tmp_file');
		file_put_contents(
			$tmpFile,
			base64_decode(
			// Empty 1x1 px PNG image
				'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg=='
			)
		);

		return $tmpFile;
	}

	/**
	 * Test the api_user() function.
	 *
	 * @return void
	 */
	public function testApiUser()
	{
		self::assertEquals($this->selfUser['id'], api_user());
	}

	/**
	 * Test the api_user() function with an unallowed user.
	 *
	 * @return void
	 */
	public function testApiUserWithUnallowedUser()
	{
		$_SESSION = ['allow_api' => false];
		self::assertEquals(false, api_user());
	}

	/**
	 * Test the api_source() function.
	 *
	 * @return void
	 */
	public function testApiSource()
	{
		self::assertEquals('api', api_source());
	}

	/**
	 * Test the api_source() function with a Twidere user agent.
	 *
	 * @return void
	 */
	public function testApiSourceWithTwidere()
	{
		$_SERVER['HTTP_USER_AGENT'] = 'Twidere';
		self::assertEquals('Twidere', api_source());
	}

	/**
	 * Test the api_source() function with a GET parameter.
	 *
	 * @return void
	 */
	public function testApiSourceWithGet()
	{
		$_GET['source'] = 'source_name';
		self::assertEquals('source_name', api_source());
	}

	/**
	 * Test the api_date() function.
	 *
	 * @return void
	 */
	public function testApiDate()
	{
		self::assertEquals('Wed Oct 10 00:00:00 +0000 1990', api_date('1990-10-10'));
	}

	/**
	 * Test the api_register_func() function.
	 *
	 * @return void
	 */
	public function testApiRegisterFunc()
	{
		global $API;
		self::assertNull(
			api_register_func(
				'api_path',
				function () {
				},
				true,
				'method'
			)
		);
		self::assertTrue($API['api_path']['auth']);
		self::assertEquals('method', $API['api_path']['method']);
		self::assertTrue(is_callable($API['api_path']['func']));
	}

	/**
	 * Test the api_login() function without any login.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @preserveGlobalState disabled
	 */
	public function testApiLoginWithoutLogin()
	{
		$this->expectException(\Friendica\Network\HTTPException\UnauthorizedException::class);
		api_login($this->app);
	}

	/**
	 * Test the api_login() function with a bad login.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @preserveGlobalState disabled
	 */
	public function testApiLoginWithBadLogin()
	{
		$this->expectException(\Friendica\Network\HTTPException\UnauthorizedException::class);
		$_SERVER['PHP_AUTH_USER'] = 'user@server';
		api_login($this->app);
	}

	/**
	 * Test the api_login() function with oAuth.
	 *
	 * @return void
	 */
	public function testApiLoginWithOauth()
	{
		$this->markTestIncomplete('Can we test this easily?');
	}

	/**
	 * Test the api_login() function with authentication provided by an addon.
	 *
	 * @return void
	 */
	public function testApiLoginWithAddonAuth()
	{
		$this->markTestIncomplete('Can we test this easily?');
	}

	/**
	 * Test the api_login() function with a correct login.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @doesNotPerformAssertions
	 */
	public function testApiLoginWithCorrectLogin()
	{
		$_SERVER['PHP_AUTH_USER'] = 'Test user';
		$_SERVER['PHP_AUTH_PW']   = 'password';
		api_login($this->app);
	}

	/**
	 * Test the api_login() function with a remote user.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiLoginWithRemoteUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\UnauthorizedException::class);
		$_SERVER['REDIRECT_REMOTE_USER'] = '123456dXNlcjpwYXNzd29yZA==';
		api_login($this->app);
	}

	/**
	 * Test the api_check_method() function.
	 *
	 * @return void
	 */
	public function testApiCheckMethod()
	{
		self::assertFalse(api_check_method('method'));
	}

	/**
	 * Test the api_check_method() function with a correct method.
	 *
	 * @return void
	 */
	public function testApiCheckMethodWithCorrectMethod()
	{
		$_SERVER['REQUEST_METHOD'] = 'method';
		self::assertTrue(api_check_method('method'));
	}

	/**
	 * Test the api_check_method() function with a wildcard.
	 *
	 * @return void
	 */
	public function testApiCheckMethodWithWildcard()
	{
		self::assertTrue(api_check_method('*'));
	}

	/**
	 * Test the api_call() function.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiCall()
	{
		global $API;
		$API['api_path']           = [
			'method' => 'method',
			'func'   => function () {
				return ['data' => ['some_data']];
			}
		];
		$_SERVER['REQUEST_METHOD'] = 'method';
		$_SERVER['QUERY_STRING'] = 'pagename=api_path';
		$_GET['callback']          = 'callback_name';

		$args = DI::args()->determine($_SERVER, $_GET);

		self::assertEquals(
			'callback_name(["some_data"])',
			api_call($this->app, $args)
		);
	}

	/**
	 * Test the api_call() function with the profiled enabled.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiCallWithProfiler()
	{
		global $API;
		$API['api_path']           = [
			'method' => 'method',
			'func'   => function () {
				return ['data' => ['some_data']];
			}
		];

		$_SERVER['REQUEST_METHOD'] = 'method';
		$_SERVER['QUERY_STRING'] = 'pagename=api_path';

		$args = DI::args()->determine($_SERVER, $_GET);

		$this->config->set('system', 'profiler', true);
		$this->config->set('rendertime', 'callstack', true);
		$this->app->callstack = [
			'database'       => ['some_function' => 200],
			'database_write' => ['some_function' => 200],
			'cache'          => ['some_function' => 200],
			'cache_write'    => ['some_function' => 200],
			'network'        => ['some_function' => 200]
		];

		self::assertEquals(
			'["some_data"]',
			api_call($this->app, $args)
		);
	}

	/**
	 * Test the api_call() function without any result.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiCallWithNoResult()
	{
		global $API;
		$API['api_path']           = [
			'method' => 'method',
			'func'   => function () {
				return false;
			}
		];
		$_SERVER['REQUEST_METHOD'] = 'method';
		$_SERVER['QUERY_STRING'] = 'pagename=api_path';

		$args = DI::args()->determine($_SERVER, $_GET);

		self::assertEquals(
			'{"status":{"error":"Internal Server Error","code":"500 Internal Server Error","request":"api_path"}}',
			api_call($this->app, $args)
		);
	}

	/**
	 * Test the api_call() function with an unimplemented API.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiCallWithUninplementedApi()
	{
		self::assertEquals(
			'{"status":{"error":"Not Found","code":"404 Not Found","request":""}}',
			api_call($this->app)
		);
	}

	/**
	 * Test the api_call() function with a JSON result.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiCallWithJson()
	{
		global $API;
		$API['api_path']           = [
			'method' => 'method',
			'func'   => function () {
				return ['data' => ['some_data']];
			}
		];
		$_SERVER['REQUEST_METHOD'] = 'method';
		$_SERVER['QUERY_STRING'] = 'pagename=api_path.json';

		$args = DI::args()->determine($_SERVER, $_GET);

		self::assertEquals(
			'["some_data"]',
			api_call($this->app, $args)
		);
	}

	/**
	 * Test the api_call() function with an XML result.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiCallWithXml()
	{
		global $API;
		$API['api_path']           = [
			'method' => 'method',
			'func'   => function () {
				return 'some_data';
			}
		];
		$_SERVER['REQUEST_METHOD'] = 'method';
		$_SERVER['QUERY_STRING'] = 'pagename=api_path.xml';

		$args = DI::args()->determine($_SERVER, $_GET);

		self::assertEquals(
			'some_data',
			api_call($this->app, $args)
		);
	}

	/**
	 * Test the api_call() function with an RSS result.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiCallWithRss()
	{
		global $API;
		$API['api_path']           = [
			'method' => 'method',
			'func'   => function () {
				return 'some_data';
			}
		];
		$_SERVER['REQUEST_METHOD'] = 'method';
		$_SERVER['QUERY_STRING'] = 'pagename=api_path.rss';

		$args = DI::args()->determine($_SERVER, $_GET);

		self::assertEquals(
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
			'some_data',
			api_call($this->app, $args)
		);
	}

	/**
	 * Test the api_call() function with an Atom result.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiCallWithAtom()
	{
		global $API;
		$API['api_path']           = [
			'method' => 'method',
			'func'   => function () {
				return 'some_data';
			}
		];
		$_SERVER['REQUEST_METHOD'] = 'method';
		$_SERVER['QUERY_STRING'] = 'pagename=api_path.atom';

		$args = DI::args()->determine($_SERVER, $_GET);

		self::assertEquals(
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
			'some_data',
			api_call($this->app, $args)
		);
	}

	/**
	 * Test the api_call() function with an unallowed method.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiCallWithWrongMethod()
	{
		global $API;
		$API['api_path'] = ['method' => 'method'];

		$_SERVER['QUERY_STRING'] = 'pagename=api_path';

		$args = DI::args()->determine($_SERVER, $_GET);

		self::assertEquals(
			'{"status":{"error":"Method Not Allowed","code":"405 Method Not Allowed","request":"api_path"}}',
			api_call($this->app, $args)
		);
	}

	/**
	 * Test the api_call() function with an unauthorized user.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiCallWithWrongAuth()
	{
		global $API;
		$API['api_path']           = [
			'method' => 'method',
			'auth'   => true
		];
		$_SESSION['authenticated'] = false;
		$_SERVER['REQUEST_METHOD'] = 'method';
		$_SERVER['QUERY_STRING'] = 'pagename=api_path';

		$args = DI::args()->determine($_SERVER, $_GET);

		self::assertEquals(
			'{"status":{"error":"This API requires login","code":"401 Unauthorized","request":"api_path"}}',
			api_call($this->app, $args)
		);
	}

	/**
	 * Test the api_error() function with a JSON result.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiErrorWithJson()
	{
		self::assertEquals(
			'{"status":{"error":"error_message","code":"200 OK","request":""}}',
			api_error('json', new HTTPException\OKException('error_message'), DI::args())
		);
	}

	/**
	 * Test the api_error() function with an XML result.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiErrorWithXml()
	{
		self::assertEquals(
			'<?xml version="1.0"?>' . "\n" .
			'<status xmlns="http://api.twitter.com" xmlns:statusnet="http://status.net/schema/api/1/" ' .
			'xmlns:friendica="http://friendi.ca/schema/api/1/" ' .
			'xmlns:georss="http://www.georss.org/georss">' . "\n" .
			'  <error>error_message</error>' . "\n" .
			'  <code>200 OK</code>' . "\n" .
			'  <request/>' . "\n" .
			'</status>' . "\n",
			api_error('xml', new HTTPException\OKException('error_message'), DI::args())
		);
	}

	/**
	 * Test the api_error() function with an RSS result.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiErrorWithRss()
	{
		self::assertEquals(
			'<?xml version="1.0"?>' . "\n" .
			'<status xmlns="http://api.twitter.com" xmlns:statusnet="http://status.net/schema/api/1/" ' .
			'xmlns:friendica="http://friendi.ca/schema/api/1/" ' .
			'xmlns:georss="http://www.georss.org/georss">' . "\n" .
			'  <error>error_message</error>' . "\n" .
			'  <code>200 OK</code>' . "\n" .
			'  <request/>' . "\n" .
			'</status>' . "\n",
			api_error('rss', new HTTPException\OKException('error_message'), DI::args())
		);
	}

	/**
	 * Test the api_error() function with an Atom result.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiErrorWithAtom()
	{
		self::assertEquals(
			'<?xml version="1.0"?>' . "\n" .
			'<status xmlns="http://api.twitter.com" xmlns:statusnet="http://status.net/schema/api/1/" ' .
			'xmlns:friendica="http://friendi.ca/schema/api/1/" ' .
			'xmlns:georss="http://www.georss.org/georss">' . "\n" .
			'  <error>error_message</error>' . "\n" .
			'  <code>200 OK</code>' . "\n" .
			'  <request/>' . "\n" .
			'</status>' . "\n",
			api_error('atom', new HTTPException\OKException('error_message'), DI::args())
		);
	}

	/**
	 * Test the api_rss_extra() function.
	 *
	 * @return void
	 */
	public function testApiRssExtra()
	{
		$user_info = ['url' => 'user_url', 'lang' => 'en'];
		$result    = api_rss_extra($this->app, [], $user_info);
		self::assertEquals($user_info, $result['$user']);
		self::assertEquals($user_info['url'], $result['$rss']['alternate']);
		self::assertArrayHasKey('self', $result['$rss']);
		self::assertArrayHasKey('base', $result['$rss']);
		self::assertArrayHasKey('updated', $result['$rss']);
		self::assertArrayHasKey('atom_updated', $result['$rss']);
		self::assertArrayHasKey('language', $result['$rss']);
		self::assertArrayHasKey('logo', $result['$rss']);
	}

	/**
	 * Test the api_rss_extra() function without any user info.
	 *
	 * @return void
	 */
	public function testApiRssExtraWithoutUserInfo()
	{
		$result = api_rss_extra($this->app, [], null);
		self::assertIsArray($result['$user']);
		self::assertArrayHasKey('alternate', $result['$rss']);
		self::assertArrayHasKey('self', $result['$rss']);
		self::assertArrayHasKey('base', $result['$rss']);
		self::assertArrayHasKey('updated', $result['$rss']);
		self::assertArrayHasKey('atom_updated', $result['$rss']);
		self::assertArrayHasKey('language', $result['$rss']);
		self::assertArrayHasKey('logo', $result['$rss']);
	}

	/**
	 * Test the api_unique_id_to_nurl() function.
	 *
	 * @return void
	 */
	public function testApiUniqueIdToNurl()
	{
		self::assertFalse(api_unique_id_to_nurl($this->wrongUserId));
	}

	/**
	 * Test the api_unique_id_to_nurl() function with a correct ID.
	 *
	 * @return void
	 */
	public function testApiUniqueIdToNurlWithCorrectId()
	{
		self::assertEquals($this->otherUser['nurl'], api_unique_id_to_nurl($this->otherUser['id']));
	}

	/**
	 * Test the api_get_user() function.
	 *
	 * @return void
	 */
	public function testApiGetUser()
	{
		$user = api_get_user($this->app);
		self::assertSelfUser($user);
		self::assertEquals('708fa0', $user['profile_sidebar_fill_color']);
		self::assertEquals('6fdbe8', $user['profile_link_color']);
		self::assertEquals('ededed', $user['profile_background_color']);
	}

	/**
	 * Test the api_get_user() function with a Frio schema.
	 *
	 * @return void
	 */
	public function testApiGetUserWithFrioSchema()
	{
		$pConfig = $this->dice->create(IPConfig::class);
		$pConfig->set($this->selfUser['id'], 'frio', 'schema', 'red');
		$user = api_get_user($this->app);
		self::assertSelfUser($user);
		self::assertEquals('708fa0', $user['profile_sidebar_fill_color']);
		self::assertEquals('6fdbe8', $user['profile_link_color']);
		self::assertEquals('ededed', $user['profile_background_color']);
	}

	/**
	 * Test the api_get_user() function with an empty Frio schema.
	 *
	 * @return void
	 */
	public function testApiGetUserWithEmptyFrioSchema()
	{
		$pConfig = $this->dice->create(IPConfig::class);
		$pConfig->set($this->selfUser['id'], 'frio', 'schema', '---');
		$user = api_get_user($this->app);
		self::assertSelfUser($user);
		self::assertEquals('708fa0', $user['profile_sidebar_fill_color']);
		self::assertEquals('6fdbe8', $user['profile_link_color']);
		self::assertEquals('ededed', $user['profile_background_color']);
	}

	/**
	 * Test the api_get_user() function with a custom Frio schema.
	 *
	 * @return void
	 */
	public function testApiGetUserWithCustomFrioSchema()
	{
		$pConfig = $this->dice->create(IPConfig::class);
		$pConfig->set($this->selfUser['id'], 'frio', 'schema', '---');
		$pConfig->set($this->selfUser['id'], 'frio', 'nav_bg', '#123456');
		$pConfig->set($this->selfUser['id'], 'frio', 'link_color', '#123456');
		$pConfig->set($this->selfUser['id'], 'frio', 'background_color', '#123456');
		$user = api_get_user($this->app);
		self::assertSelfUser($user);
		self::assertEquals('123456', $user['profile_sidebar_fill_color']);
		self::assertEquals('123456', $user['profile_link_color']);
		self::assertEquals('123456', $user['profile_background_color']);
	}

	/**
	 * Test the api_get_user() function with an user that is not allowed to use the API.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiGetUserWithoutApiUser()
	{
		$_SERVER['PHP_AUTH_USER'] = 'Test user';
		$_SERVER['PHP_AUTH_PW']   = 'password';
		$_SESSION['allow_api']    = false;
		self::assertFalse(api_get_user($this->app));
	}

	/**
	 * Test the api_get_user() function with an user ID in a GET parameter.
	 *
	 * @return void
	 */
	public function testApiGetUserWithGetId()
	{
		$_GET['user_id'] = $this->otherUser['id'];
		self::assertOtherUser(api_get_user($this->app));
	}

	/**
	 * Test the api_get_user() function with a wrong user ID in a GET parameter.
	 *
	 * @return void
	 */
	public function testApiGetUserWithWrongGetId()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		$_GET['user_id'] = $this->wrongUserId;
		self::assertOtherUser(api_get_user($this->app));
	}

	/**
	 * Test the api_get_user() function with an user name in a GET parameter.
	 *
	 * @return void
	 */
	public function testApiGetUserWithGetName()
	{
		$_GET['screen_name'] = $this->selfUser['nick'];
		self::assertSelfUser(api_get_user($this->app));
	}

	/**
	 * Test the api_get_user() function with a profile URL in a GET parameter.
	 *
	 * @return void
	 */
	public function testApiGetUserWithGetUrl()
	{
		$_GET['profileurl'] = $this->selfUser['nurl'];
		self::assertSelfUser(api_get_user($this->app));
	}

	/**
	 * Test the api_get_user() function with an user ID in the API path.
	 *
	 * @return void
	 */
	public function testApiGetUserWithNumericCalledApi()
	{
		global $called_api;
		$called_api         = ['api_path'];
		$this->app->argv[1] = $this->otherUser['id'] . '.json';
		self::assertOtherUser(api_get_user($this->app));
	}

	/**
	 * Test the api_get_user() function with the $called_api global variable.
	 *
	 * @return void
	 */
	public function testApiGetUserWithCalledApi()
	{
		global $called_api;
		$called_api = ['api', 'api_path'];
		self::assertSelfUser(api_get_user($this->app));
	}

	/**
	 * Test the api_get_user() function with a valid user.
	 *
	 * @return void
	 */
	public function testApiGetUserWithCorrectUser()
	{
		self::assertOtherUser(api_get_user($this->app, $this->otherUser['id']));
	}

	/**
	 * Test the api_get_user() function with a wrong user ID.
	 *
	 * @return void
	 */
	public function testApiGetUserWithWrongUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		self::assertOtherUser(api_get_user($this->app, $this->wrongUserId));
	}

	/**
	 * Test the api_get_user() function with a 0 user ID.
	 *
	 * @return void
	 */
	public function testApiGetUserWithZeroUser()
	{
		self::assertSelfUser(api_get_user($this->app, 0));
	}

	/**
	 * Test the api_item_get_user() function.
	 *
	 * @return void
	 */
	public function testApiItemGetUser()
	{
		$users = api_item_get_user($this->app, []);
		self::assertSelfUser($users[0]);
	}

	/**
	 * Test the api_item_get_user() function with a different item parent.
	 *
	 * @return void
	 */
	public function testApiItemGetUserWithDifferentParent()
	{
		$users = api_item_get_user($this->app, ['thr-parent' => 'item_parent', 'uri' => 'item_uri']);
		self::assertSelfUser($users[0]);
		self::assertEquals($users[0], $users[1]);
	}

	/**
	 * Test the api_walk_recursive() function.
	 *
	 * @return void
	 */
	public function testApiWalkRecursive()
	{
		$array = ['item1'];
		self::assertEquals(
			$array,
			api_walk_recursive(
				$array,
				function () {
					// Should we test this with a callback that actually does something?
					return true;
				}
			)
		);
	}

	/**
	 * Test the api_walk_recursive() function with an array.
	 *
	 * @return void
	 */
	public function testApiWalkRecursiveWithArray()
	{
		$array = [['item1'], ['item2']];
		self::assertEquals(
			$array,
			api_walk_recursive(
				$array,
				function () {
					// Should we test this with a callback that actually does something?
					return true;
				}
			)
		);
	}

	/**
	 * Test the api_reformat_xml() function.
	 *
	 * @return void
	 */
	public function testApiReformatXml()
	{
		$item = true;
		$key  = '';
		self::assertTrue(api_reformat_xml($item, $key));
		self::assertEquals('true', $item);
	}

	/**
	 * Test the api_reformat_xml() function with a statusnet_api key.
	 *
	 * @return void
	 */
	public function testApiReformatXmlWithStatusnetKey()
	{
		$item = '';
		$key  = 'statusnet_api';
		self::assertTrue(api_reformat_xml($item, $key));
		self::assertEquals('statusnet:api', $key);
	}

	/**
	 * Test the api_reformat_xml() function with a friendica_api key.
	 *
	 * @return void
	 */
	public function testApiReformatXmlWithFriendicaKey()
	{
		$item = '';
		$key  = 'friendica_api';
		self::assertTrue(api_reformat_xml($item, $key));
		self::assertEquals('friendica:api', $key);
	}

	/**
	 * Test the api_create_xml() function.
	 *
	 * @return void
	 */
	public function testApiCreateXml()
	{
		self::assertEquals(
			'<?xml version="1.0"?>' . "\n" .
			'<root_element xmlns="http://api.twitter.com" xmlns:statusnet="http://status.net/schema/api/1/" ' .
			'xmlns:friendica="http://friendi.ca/schema/api/1/" ' .
			'xmlns:georss="http://www.georss.org/georss">' . "\n" .
			'  <data>some_data</data>' . "\n" .
			'</root_element>' . "\n",
			api_create_xml(['data' => ['some_data']], 'root_element')
		);
	}

	/**
	 * Test the api_create_xml() function without any XML namespace.
	 *
	 * @return void
	 */
	public function testApiCreateXmlWithoutNamespaces()
	{
		self::assertEquals(
			'<?xml version="1.0"?>' . "\n" .
			'<ok>' . "\n" .
			'  <data>some_data</data>' . "\n" .
			'</ok>' . "\n",
			api_create_xml(['data' => ['some_data']], 'ok')
		);
	}

	/**
	 * Test the api_format_data() function.
	 *
	 * @return void
	 */
	public function testApiFormatData()
	{
		$data = ['some_data'];
		self::assertEquals($data, api_format_data('root_element', 'json', $data));
	}

	/**
	 * Test the api_format_data() function with an XML result.
	 *
	 * @return void
	 */
	public function testApiFormatDataWithXml()
	{
		self::assertEquals(
			'<?xml version="1.0"?>' . "\n" .
			'<root_element xmlns="http://api.twitter.com" xmlns:statusnet="http://status.net/schema/api/1/" ' .
			'xmlns:friendica="http://friendi.ca/schema/api/1/" ' .
			'xmlns:georss="http://www.georss.org/georss">' . "\n" .
			'  <data>some_data</data>' . "\n" .
			'</root_element>' . "\n",
			api_format_data('root_element', 'xml', ['data' => ['some_data']])
		);
	}

	/**
	 * Test the api_account_verify_credentials() function.
	 *
	 * @return void
	 */
	public function testApiAccountVerifyCredentials()
	{
		self::assertArrayHasKey('user', api_account_verify_credentials('json'));
	}

	/**
	 * Test the api_account_verify_credentials() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiAccountVerifyCredentialsWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['authenticated'] = false;
		api_account_verify_credentials('json');
	}

	/**
	 * Test the requestdata() function.
	 *
	 * @return void
	 */
	public function testRequestdata()
	{
		self::assertNull(requestdata('variable_name'));
	}

	/**
	 * Test the requestdata() function with a POST parameter.
	 *
	 * @return void
	 */
	public function testRequestdataWithPost()
	{
		$_POST['variable_name'] = 'variable_value';
		self::assertEquals('variable_value', requestdata('variable_name'));
	}

	/**
	 * Test the requestdata() function with a GET parameter.
	 *
	 * @return void
	 */
	public function testRequestdataWithGet()
	{
		$_GET['variable_name'] = 'variable_value';
		self::assertEquals('variable_value', requestdata('variable_name'));
	}

	/**
	 * Test the api_statuses_mediap() function.
	 *
	 * @return void
	 */
	public function testApiStatusesMediap()
	{
		$this->app->argc = 2;

		$_FILES         = [
			'media' => [
				'id'       => 666,
				'size'     => 666,
				'width'    => 666,
				'height'   => 666,
				'tmp_name' => $this->getTempImage(),
				'name'     => 'spacer.png',
				'type'     => 'image/png'
			]
		];
		$_GET['status'] = '<b>Status content</b>';

		$result = api_statuses_mediap('json');
		self::assertStatus($result['status']);
	}

	/**
	 * Test the api_statuses_mediap() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiStatusesMediapWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['authenticated'] = false;
		api_statuses_mediap('json');
	}

	/**
	 * Test the api_statuses_update() function.
	 *
	 * @return void
	 */
	public function testApiStatusesUpdate()
	{
		$_GET['status']                = 'Status content #friendica';
		$_GET['in_reply_to_status_id'] = -1;
		$_GET['lat']                   = 48;
		$_GET['long']                  = 7;
		$_FILES                        = [
			'media' => [
				'id'       => 666,
				'size'     => 666,
				'width'    => 666,
				'height'   => 666,
				'tmp_name' => $this->getTempImage(),
				'name'     => 'spacer.png',
				'type'     => 'image/png'
			]
		];

		$result = api_statuses_update('json');
		self::assertStatus($result['status']);
	}

	/**
	 * Test the api_statuses_update() function with an HTML status.
	 *
	 * @return void
	 */
	public function testApiStatusesUpdateWithHtml()
	{
		$_GET['htmlstatus'] = '<b>Status content</b>';

		$result = api_statuses_update('json');
		self::assertStatus($result['status']);
	}

	/**
	 * Test the api_statuses_update() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiStatusesUpdateWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['authenticated'] = false;
		api_statuses_update('json');
	}

	/**
	 * Test the api_statuses_update() function with a parent status.
	 *
	 * @return void
	 */
	public function testApiStatusesUpdateWithParent()
	{
		$this->markTestIncomplete('This triggers an exit() somewhere and kills PHPUnit.');
	}

	/**
	 * Test the api_statuses_update() function with a media_ids parameter.
	 *
	 * @return void
	 */
	public function testApiStatusesUpdateWithMediaIds()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_statuses_update() function with the throttle limit reached.
	 *
	 * @return void
	 */
	public function testApiStatusesUpdateWithDayThrottleReached()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_media_upload() function.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testApiMediaUpload()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		api_media_upload();
	}

	/**
	 * Test the api_media_upload() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiMediaUploadWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['authenticated'] = false;
		api_media_upload();
	}

	/**
	 * Test the api_media_upload() function with an invalid uploaded media.
	 *
	 * @return void
	 */
	public function testApiMediaUploadWithMedia()
	{
		$this->expectException(\Friendica\Network\HTTPException\InternalServerErrorException::class);
		$_FILES = [
			'media' => [
				'id'       => 666,
				'tmp_name' => 'tmp_name'
			]
		];
		api_media_upload();
	}

	/**
	 * Test the api_media_upload() function with an valid uploaded media.
	 *
	 * @return void
	 */
	public function testApiMediaUploadWithValidMedia()
	{
		$_FILES    = [
			'media' => [
				'id'       => 666,
				'size'     => 666,
				'width'    => 666,
				'height'   => 666,
				'tmp_name' => $this->getTempImage(),
				'name'     => 'spacer.png',
				'type'     => 'image/png'
			]
		];
		$app       = DI::app();
		$app->argc = 2;

		$result = api_media_upload();
		self::assertEquals('image/png', $result['media']['image']['image_type']);
		self::assertEquals(1, $result['media']['image']['w']);
		self::assertEquals(1, $result['media']['image']['h']);
		self::assertNotEmpty($result['media']['image']['friendica_preview_url']);
	}

	/**
	 * Test the api_status_show() function.
	 */
	public function testApiStatusShowWithJson()
	{
		$result = api_status_show('json', 1);
		self::assertStatus($result['status']);
	}

	/**
	 * Test the api_status_show() function with an XML result.
	 */
	public function testApiStatusShowWithXml()
	{
		$result = api_status_show('xml', 1);
		self::assertXml($result, 'statuses');
	}

	/**
	 * Test the api_get_last_status() function
	 */
	public function testApiGetLastStatus()
	{
		$item = api_get_last_status($this->selfUser['id'], $this->selfUser['id']);

		self::assertNotNull($item);
	}

	/**
	 * Test the api_users_show() function.
	 *
	 * @return void
	 */
	public function testApiUsersShow()
	{
		$result = api_users_show('json');
		// We can't use assertSelfUser() here because the user object is missing some properties.
		self::assertEquals($this->selfUser['id'], $result['user']['cid']);
		self::assertEquals('DFRN', $result['user']['location']);
		self::assertEquals($this->selfUser['name'], $result['user']['name']);
		self::assertEquals($this->selfUser['nick'], $result['user']['screen_name']);
		self::assertEquals('dfrn', $result['user']['network']);
		self::assertTrue($result['user']['verified']);
	}

	/**
	 * Test the api_users_show() function with an XML result.
	 *
	 * @return void
	 */
	public function testApiUsersShowWithXml()
	{
		$result = api_users_show('xml');
		self::assertXml($result, 'statuses');
	}

	/**
	 * Test the api_users_search() function.
	 *
	 * @return void
	 */
	public function testApiUsersSearch()
	{
		$_GET['q'] = 'othercontact';
		$result    = api_users_search('json');
		self::assertOtherUser($result['users'][0]);
	}

	/**
	 * Test the api_users_search() function with an XML result.
	 *
	 * @return void
	 */
	public function testApiUsersSearchWithXml()
	{
		$_GET['q'] = 'othercontact';
		$result    = api_users_search('xml');
		self::assertXml($result, 'users');
	}

	/**
	 * Test the api_users_search() function without a GET q parameter.
	 *
	 * @return void
	 */
	public function testApiUsersSearchWithoutQuery()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		api_users_search('json');
	}

	/**
	 * Test the api_users_lookup() function.
	 *
	 * @return void
	 */
	public function testApiUsersLookup()
	{
		$this->expectException(\Friendica\Network\HTTPException\NotFoundException::class);
		api_users_lookup('json');
	}

	/**
	 * Test the api_users_lookup() function with an user ID.
	 *
	 * @return void
	 */
	public function testApiUsersLookupWithUserId()
	{
		$_REQUEST['user_id'] = $this->otherUser['id'];
		$result              = api_users_lookup('json');
		self::assertOtherUser($result['users'][0]);
	}

	/**
	 * Test the api_search() function.
	 *
	 * @return void
	 */
	public function testApiSearch()
	{
		$_REQUEST['q']      = 'reply';
		$_REQUEST['max_id'] = 10;
		$result             = api_search('json');
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
			self::assertStringContainsStringIgnoringCase('reply', $status['text'], '', true);
		}
	}

	/**
	 * Test the api_search() function a count parameter.
	 *
	 * @return void
	 */
	public function testApiSearchWithCount()
	{
		$_REQUEST['q']     = 'reply';
		$_REQUEST['count'] = 20;
		$result            = api_search('json');
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
			self::assertStringContainsStringIgnoringCase('reply', $status['text'], '', true);
		}
	}

	/**
	 * Test the api_search() function with an rpp parameter.
	 *
	 * @return void
	 */
	public function testApiSearchWithRpp()
	{
		$_REQUEST['q']   = 'reply';
		$_REQUEST['rpp'] = 20;
		$result          = api_search('json');
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
			self::assertStringContainsStringIgnoringCase('reply', $status['text'], '', true);
		}
	}

	/**
	 * Test the api_search() function with an q parameter contains hashtag.
	 * @doesNotPerformAssertions
	 */
	public function testApiSearchWithHashtag()
	{
		$_REQUEST['q'] = '%23friendica';
		$result        = api_search('json');
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
			self::assertStringContainsStringIgnoringCase('#friendica', $status['text'], '', true);
		}
	}

	/**
	 * Test the api_search() function with an exclude_replies parameter.
	 * @doesNotPerformAssertions
	 */
	public function testApiSearchWithExcludeReplies()
	{
		$_REQUEST['max_id']          = 10;
		$_REQUEST['exclude_replies'] = true;
		$_REQUEST['q']               = 'friendica';
		$result                      = api_search('json');
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
		}
	}

	/**
	 * Test the api_search() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiSearchWithUnallowedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['allow_api'] = false;
		$_GET['screen_name']   = $this->selfUser['nick'];
		api_search('json');
	}

	/**
	 * Test the api_search() function without any GET query parameter.
	 *
	 * @return void
	 */
	public function testApiSearchWithoutQuery()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		api_search('json');
	}

	/**
	 * Test the api_statuses_home_timeline() function.
	 *
	 * @return void
	 */
	public function testApiStatusesHomeTimeline()
	{
		$_REQUEST['max_id']          = 10;
		$_REQUEST['exclude_replies'] = true;
		$_REQUEST['conversation_id'] = 1;
		$result                      = api_statuses_home_timeline('json');
		self::assertNotEmpty($result['status']);
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
		}
	}

	/**
	 * Test the api_statuses_home_timeline() function with a negative page parameter.
	 *
	 * @return void
	 */
	public function testApiStatusesHomeTimelineWithNegativePage()
	{
		$_REQUEST['page'] = -2;
		$result           = api_statuses_home_timeline('json');
		self::assertNotEmpty($result['status']);
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
		}
	}

	/**
	 * Test the api_statuses_home_timeline() with an unallowed user.
	 *
	 * @return void
	 */
	public function testApiStatusesHomeTimelineWithUnallowedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['allow_api'] = false;
		$_GET['screen_name']   = $this->selfUser['nick'];
		api_statuses_home_timeline('json');
	}

	/**
	 * Test the api_statuses_home_timeline() function with an RSS result.
	 *
	 * @return void
	 */
	public function testApiStatusesHomeTimelineWithRss()
	{
		$result = api_statuses_home_timeline('rss');
		self::assertXml($result, 'statuses');
	}

	/**
	 * Test the api_statuses_public_timeline() function.
	 *
	 * @return void
	 */
	public function testApiStatusesPublicTimeline()
	{
		$_REQUEST['max_id']          = 10;
		$_REQUEST['conversation_id'] = 1;
		$result                      = api_statuses_public_timeline('json');
		self::assertNotEmpty($result['status']);
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
		}
	}

	/**
	 * Test the api_statuses_public_timeline() function with the exclude_replies parameter.
	 *
	 * @return void
	 */
	public function testApiStatusesPublicTimelineWithExcludeReplies()
	{
		$_REQUEST['max_id']          = 10;
		$_REQUEST['exclude_replies'] = true;
		$result                      = api_statuses_public_timeline('json');
		self::assertNotEmpty($result['status']);
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
		}
	}

	/**
	 * Test the api_statuses_public_timeline() function with a negative page parameter.
	 *
	 * @return void
	 */
	public function testApiStatusesPublicTimelineWithNegativePage()
	{
		$_REQUEST['page'] = -2;
		$result           = api_statuses_public_timeline('json');
		self::assertNotEmpty($result['status']);
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
		}
	}

	/**
	 * Test the api_statuses_public_timeline() function with an unallowed user.
	 *
	 * @return void
	 */
	public function testApiStatusesPublicTimelineWithUnallowedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['allow_api'] = false;
		$_GET['screen_name']   = $this->selfUser['nick'];
		api_statuses_public_timeline('json');
	}

	/**
	 * Test the api_statuses_public_timeline() function with an RSS result.
	 *
	 * @return void
	 */
	public function testApiStatusesPublicTimelineWithRss()
	{
		$result = api_statuses_public_timeline('rss');
		self::assertXml($result, 'statuses');
	}

	/**
	 * Test the api_statuses_networkpublic_timeline() function.
	 *
	 * @return void
	 */
	public function testApiStatusesNetworkpublicTimeline()
	{
		$_REQUEST['max_id'] = 10;
		$result             = api_statuses_networkpublic_timeline('json');
		self::assertNotEmpty($result['status']);
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
		}
	}

	/**
	 * Test the api_statuses_networkpublic_timeline() function with a negative page parameter.
	 *
	 * @return void
	 */
	public function testApiStatusesNetworkpublicTimelineWithNegativePage()
	{
		$_REQUEST['page'] = -2;
		$result           = api_statuses_networkpublic_timeline('json');
		self::assertNotEmpty($result['status']);
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
		}
	}

	/**
	 * Test the api_statuses_networkpublic_timeline() function with an unallowed user.
	 *
	 * @return void
	 */
	public function testApiStatusesNetworkpublicTimelineWithUnallowedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['allow_api'] = false;
		$_GET['screen_name']   = $this->selfUser['nick'];
		api_statuses_networkpublic_timeline('json');
	}

	/**
	 * Test the api_statuses_networkpublic_timeline() function with an RSS result.
	 *
	 * @return void
	 */
	public function testApiStatusesNetworkpublicTimelineWithRss()
	{
		$result = api_statuses_networkpublic_timeline('rss');
		self::assertXml($result, 'statuses');
	}

	/**
	 * Test the api_statuses_show() function.
	 *
	 * @return void
	 */
	public function testApiStatusesShow()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		api_statuses_show('json');
	}

	/**
	 * Test the api_statuses_show() function with an ID.
	 *
	 * @return void
	 */
	public function testApiStatusesShowWithId()
	{
		$this->app->argv[3] = 1;
		$result             = api_statuses_show('json');
		self::assertStatus($result['status']);
	}

	/**
	 * Test the api_statuses_show() function with the conversation parameter.
	 *
	 * @return void
	 */
	public function testApiStatusesShowWithConversation()
	{
		$this->app->argv[3]       = 1;
		$_REQUEST['conversation'] = 1;
		$result                   = api_statuses_show('json');
		self::assertNotEmpty($result['status']);
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
		}
	}

	/**
	 * Test the api_statuses_show() function with an unallowed user.
	 *
	 * @return void
	 */
	public function testApiStatusesShowWithUnallowedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['allow_api'] = false;
		$_GET['screen_name']   = $this->selfUser['nick'];
		api_statuses_show('json');
	}

	/**
	 * Test the api_conversation_show() function.
	 *
	 * @return void
	 */
	public function testApiConversationShow()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		api_conversation_show('json');
	}

	/**
	 * Test the api_conversation_show() function with an ID.
	 *
	 * @return void
	 */
	public function testApiConversationShowWithId()
	{
		$this->app->argv[3] = 1;
		$_REQUEST['max_id'] = 10;
		$_REQUEST['page']   = -2;
		$result             = api_conversation_show('json');
		self::assertNotEmpty($result['status']);
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
		}
	}

	/**
	 * Test the api_conversation_show() function with an unallowed user.
	 *
	 * @return void
	 */
	public function testApiConversationShowWithUnallowedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['allow_api'] = false;
		$_GET['screen_name']   = $this->selfUser['nick'];
		api_conversation_show('json');
	}

	/**
	 * Test the api_statuses_repeat() function.
	 *
	 * @return void
	 */
	public function testApiStatusesRepeat()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		api_statuses_repeat('json');
	}

	/**
	 * Test the api_statuses_repeat() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiStatusesRepeatWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['authenticated'] = false;
		api_statuses_repeat('json');
	}

	/**
	 * Test the api_statuses_repeat() function with an ID.
	 *
	 * @return void
	 */
	public function testApiStatusesRepeatWithId()
	{
		$this->app->argv[3] = 1;
		$result             = api_statuses_repeat('json');
		self::assertStatus($result['status']);

		// Also test with a shared status
		$this->app->argv[3] = 5;
		$result             = api_statuses_repeat('json');
		self::assertStatus($result['status']);
	}

	/**
	 * Test the api_statuses_destroy() function.
	 *
	 * @return void
	 */
	public function testApiStatusesDestroy()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		api_statuses_destroy('json');
	}

	/**
	 * Test the api_statuses_destroy() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiStatusesDestroyWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['authenticated'] = false;
		api_statuses_destroy('json');
	}

	/**
	 * Test the api_statuses_destroy() function with an ID.
	 *
	 * @return void
	 */
	public function testApiStatusesDestroyWithId()
	{
		$this->app->argv[3] = 1;
		$result             = api_statuses_destroy('json');
		self::assertStatus($result['status']);
	}

	/**
	 * Test the api_statuses_mentions() function.
	 *
	 * @return void
	 */
	public function testApiStatusesMentions()
	{
		$this->app->user    = ['nickname' => $this->selfUser['nick']];
		$_REQUEST['max_id'] = 10;
		$result             = api_statuses_mentions('json');
		self::assertEmpty($result['status']);
		// We should test with mentions in the database.
	}

	/**
	 * Test the api_statuses_mentions() function with a negative page parameter.
	 *
	 * @return void
	 */
	public function testApiStatusesMentionsWithNegativePage()
	{
		$_REQUEST['page'] = -2;
		$result           = api_statuses_mentions('json');
		self::assertEmpty($result['status']);
	}

	/**
	 * Test the api_statuses_mentions() function with an unallowed user.
	 *
	 * @return void
	 */
	public function testApiStatusesMentionsWithUnallowedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['allow_api'] = false;
		$_GET['screen_name']   = $this->selfUser['nick'];
		api_statuses_mentions('json');
	}

	/**
	 * Test the api_statuses_mentions() function with an RSS result.
	 *
	 * @return void
	 */
	public function testApiStatusesMentionsWithRss()
	{
		$result = api_statuses_mentions('rss');
		self::assertXml($result, 'statuses');
	}

	/**
	 * Test the api_statuses_user_timeline() function.
	 *
	 * @return void
	 */
	public function testApiStatusesUserTimeline()
	{
		$_REQUEST['max_id']          = 10;
		$_REQUEST['exclude_replies'] = true;
		$_REQUEST['conversation_id'] = 1;
		$result                      = api_statuses_user_timeline('json');
		self::assertNotEmpty($result['status']);
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
		}
	}

	/**
	 * Test the api_statuses_user_timeline() function with a negative page parameter.
	 *
	 * @return void
	 */
	public function testApiStatusesUserTimelineWithNegativePage()
	{
		$_REQUEST['page'] = -2;
		$result           = api_statuses_user_timeline('json');
		self::assertNotEmpty($result['status']);
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
		}
	}

	/**
	 * Test the api_statuses_user_timeline() function with an RSS result.
	 *
	 * @return void
	 */
	public function testApiStatusesUserTimelineWithRss()
	{
		$result = api_statuses_user_timeline('rss');
		self::assertXml($result, 'statuses');
	}

	/**
	 * Test the api_statuses_user_timeline() function with an unallowed user.
	 *
	 * @return void
	 */
	public function testApiStatusesUserTimelineWithUnallowedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['allow_api'] = false;
		$_GET['screen_name']   = $this->selfUser['nick'];
		api_statuses_user_timeline('json');
	}

	/**
	 * Test the api_favorites_create_destroy() function.
	 *
	 * @return void
	 */
	public function testApiFavoritesCreateDestroy()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		$this->app->argv = ['api', '1.1', 'favorites', 'create'];
		$this->app->argc = count($this->app->argv);
		api_favorites_create_destroy('json');
	}

	/**
	 * Test the api_favorites_create_destroy() function with an invalid ID.
	 *
	 * @return void
	 */
	public function testApiFavoritesCreateDestroyWithInvalidId()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		$this->app->argv = ['api', '1.1', 'favorites', 'create', '12.json'];
		$this->app->argc = count($this->app->argv);
		api_favorites_create_destroy('json');
	}

	/**
	 * Test the api_favorites_create_destroy() function with an invalid action.
	 *
	 * @return void
	 */
	public function testApiFavoritesCreateDestroyWithInvalidAction()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		$this->app->argv = ['api', '1.1', 'favorites', 'change.json'];
		$this->app->argc = count($this->app->argv);
		$_REQUEST['id']  = 1;
		api_favorites_create_destroy('json');
	}

	/**
	 * Test the api_favorites_create_destroy() function with the create action.
	 *
	 * @return void
	 */
	public function testApiFavoritesCreateDestroyWithCreateAction()
	{
		$this->app->argv = ['api', '1.1', 'favorites', 'create.json'];
		$this->app->argc = count($this->app->argv);
		$_REQUEST['id']  = 3;
		$result          = api_favorites_create_destroy('json');
		self::assertStatus($result['status']);
	}

	/**
	 * Test the api_favorites_create_destroy() function with the create action and an RSS result.
	 *
	 * @return void
	 */
	public function testApiFavoritesCreateDestroyWithCreateActionAndRss()
	{
		$this->app->argv = ['api', '1.1', 'favorites', 'create.rss'];
		$this->app->argc = count($this->app->argv);
		$_REQUEST['id']  = 3;
		$result          = api_favorites_create_destroy('rss');
		self::assertXml($result, 'status');
	}

	/**
	 * Test the api_favorites_create_destroy() function with the destroy action.
	 *
	 * @return void
	 */
	public function testApiFavoritesCreateDestroyWithDestroyAction()
	{
		$this->app->argv = ['api', '1.1', 'favorites', 'destroy.json'];
		$this->app->argc = count($this->app->argv);
		$_REQUEST['id']  = 3;
		$result          = api_favorites_create_destroy('json');
		self::assertStatus($result['status']);
	}

	/**
	 * Test the api_favorites_create_destroy() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiFavoritesCreateDestroyWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$this->app->argv           = ['api', '1.1', 'favorites', 'create.json'];
		$this->app->argc           = count($this->app->argv);
		$_SESSION['authenticated'] = false;
		api_favorites_create_destroy('json');
	}

	/**
	 * Test the api_favorites() function.
	 *
	 * @return void
	 */
	public function testApiFavorites()
	{
		$_REQUEST['page']   = -1;
		$_REQUEST['max_id'] = 10;
		$result             = api_favorites('json');
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
		}
	}

	/**
	 * Test the api_favorites() function with an RSS result.
	 *
	 * @return void
	 */
	public function testApiFavoritesWithRss()
	{
		$result = api_favorites('rss');
		self::assertXml($result, 'statuses');
	}

	/**
	 * Test the api_favorites() function with an unallowed user.
	 *
	 * @return void
	 */
	public function testApiFavoritesWithUnallowedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['allow_api'] = false;
		$_GET['screen_name']   = $this->selfUser['nick'];
		api_favorites('json');
	}

	/**
	 * Test the api_format_messages() function.
	 *
	 * @return void
	 */
	public function testApiFormatMessages()
	{
		$result = api_format_messages(
			['id' => 1, 'title' => 'item_title', 'body' => '[b]item_body[/b]'],
			['id' => 2, 'screen_name' => 'recipient_name'],
			['id' => 3, 'screen_name' => 'sender_name']
		);
		self::assertEquals('item_title' . "\n" . 'item_body', $result['text']);
		self::assertEquals(1, $result['id']);
		self::assertEquals(2, $result['recipient_id']);
		self::assertEquals(3, $result['sender_id']);
		self::assertEquals('recipient_name', $result['recipient_screen_name']);
		self::assertEquals('sender_name', $result['sender_screen_name']);
	}

	/**
	 * Test the api_format_messages() function with HTML.
	 *
	 * @return void
	 */
	public function testApiFormatMessagesWithHtmlText()
	{
		$_GET['getText'] = 'html';
		$result          = api_format_messages(
			['id' => 1, 'title' => 'item_title', 'body' => '[b]item_body[/b]'],
			['id' => 2, 'screen_name' => 'recipient_name'],
			['id' => 3, 'screen_name' => 'sender_name']
		);
		self::assertEquals('item_title', $result['title']);
		self::assertEquals('<strong>item_body</strong>', $result['text']);
	}

	/**
	 * Test the api_format_messages() function with plain text.
	 *
	 * @return void
	 */
	public function testApiFormatMessagesWithPlainText()
	{
		$_GET['getText'] = 'plain';
		$result          = api_format_messages(
			['id' => 1, 'title' => 'item_title', 'body' => '[b]item_body[/b]'],
			['id' => 2, 'screen_name' => 'recipient_name'],
			['id' => 3, 'screen_name' => 'sender_name']
		);
		self::assertEquals('item_title', $result['title']);
		self::assertEquals('item_body', $result['text']);
	}

	/**
	 * Test the api_format_messages() function with the getUserObjects GET parameter set to false.
	 *
	 * @return void
	 */
	public function testApiFormatMessagesWithoutUserObjects()
	{
		$_GET['getUserObjects'] = 'false';
		$result                 = api_format_messages(
			['id' => 1, 'title' => 'item_title', 'body' => '[b]item_body[/b]'],
			['id' => 2, 'screen_name' => 'recipient_name'],
			['id' => 3, 'screen_name' => 'sender_name']
		);
		self::assertTrue(!isset($result['sender']));
		self::assertTrue(!isset($result['recipient']));
	}

	/**
	 * Test the api_convert_item() function.
	 *
	 * @return void
	 */
	public function testApiConvertItem()
	{
		$result = api_convert_item(
			[
				'network' => 'feed',
				'title'   => 'item_title',
				'uri-id'  => 1,
				// We need a long string to test that it is correctly cut
				'body'    => 'perspiciatis impedit voluptatem quis molestiae ea qui ' .
				             'reiciendis dolorum aut ducimus sunt consequatur inventore dolor ' .
				             'officiis pariatur doloremque nemo culpa aut quidem qui dolore ' .
				             'laudantium atque commodi alias voluptatem non possimus aperiam ' .
				             'ipsum rerum consequuntur aut amet fugit quia aliquid praesentium ' .
				             'repellendus quibusdam et et inventore mollitia rerum sit autem ' .
				             'pariatur maiores ipsum accusantium perferendis vel sit possimus ' .
				             'veritatis nihil distinctio qui eum repellat officia illum quos ' .
				             'impedit quam iste esse unde qui suscipit aut facilis ut inventore ' .
				             'omnis exercitationem quo magnam consequatur maxime aut illum ' .
				             'soluta quaerat natus unde aspernatur et sed beatae nihil ullam ' .
				             'temporibus corporis ratione blanditiis perspiciatis impedit ' .
				             'voluptatem quis molestiae ea qui reiciendis dolorum aut ducimus ' .
				             'sunt consequatur inventore dolor officiis pariatur doloremque ' .
				             'nemo culpa aut quidem qui dolore laudantium atque commodi alias ' .
				             'voluptatem non possimus aperiam ipsum rerum consequuntur aut ' .
				             'amet fugit quia aliquid praesentium repellendus quibusdam et et ' .
				             'inventore mollitia rerum sit autem pariatur maiores ipsum accusantium ' .
				             'perferendis vel sit possimus veritatis nihil distinctio qui eum ' .
				             'repellat officia illum quos impedit quam iste esse unde qui ' .
				             'suscipit aut facilis ut inventore omnis exercitationem quo magnam ' .
				             'consequatur maxime aut illum soluta quaerat natus unde aspernatur ' .
				             'et sed beatae nihil ullam temporibus corporis ratione blanditiis',
				'plink'   => 'item_plink'
			]
		);
		self::assertStringStartsWith('item_title', $result['text']);
		self::assertStringStartsWith('<h4>item_title</h4><br>perspiciatis impedit voluptatem', $result['html']);
	}

	/**
	 * Test the api_convert_item() function with an empty item body.
	 *
	 * @return void
	 */
	public function testApiConvertItemWithoutBody()
	{
		$result = api_convert_item(
			[
				'network' => 'feed',
				'title'   => 'item_title',
				'uri-id'  => -1,
				'body'    => '',
				'plink'   => 'item_plink'
			]
		);
		self::assertEquals("item_title", $result['text']);
		self::assertEquals('<h4>item_title</h4><br>item_plink', $result['html']);
	}

	/**
	 * Test the api_convert_item() function with the title in the body.
	 *
	 * @return void
	 */
	public function testApiConvertItemWithTitleInBody()
	{
		$result = api_convert_item(
			[
				'title'  => 'item_title',
				'body'   => 'item_title item_body',
				'uri-id' => 1,
			]
		);
		self::assertEquals('item_title item_body', $result['text']);
		self::assertEquals('<h4>item_title</h4><br>item_title item_body', $result['html']);
	}

	/**
	 * Test the api_get_attachments() function.
	 *
	 * @return void
	 */
	public function testApiGetAttachments()
	{
		$body = 'body';
		self::assertEmpty(api_get_attachments($body));
	}

	/**
	 * Test the api_get_attachments() function with an img tag.
	 *
	 * @return void
	 */
	public function testApiGetAttachmentsWithImage()
	{
		$body = '[img]http://via.placeholder.com/1x1.png[/img]';
		self::assertIsArray(api_get_attachments($body));
	}

	/**
	 * Test the api_get_attachments() function with an img tag and an AndStatus user agent.
	 *
	 * @return void
	 */
	public function testApiGetAttachmentsWithImageAndAndStatus()
	{
		$_SERVER['HTTP_USER_AGENT'] = 'AndStatus';
		$body                       = '[img]http://via.placeholder.com/1x1.png[/img]';
		self::assertIsArray(api_get_attachments($body));
	}

	/**
	 * Test the api_get_entitities() function.
	 *
	 * @return void
	 */
	public function testApiGetEntitities()
	{
		$text = 'text';
		self::assertIsArray(api_get_entitities($text, 'bbcode'));
	}

	/**
	 * Test the api_get_entitities() function with the include_entities parameter.
	 *
	 * @return void
	 */
	public function testApiGetEntititiesWithIncludeEntities()
	{
		$_REQUEST['include_entities'] = 'true';
		$text                         = 'text';
		$result                       = api_get_entitities($text, 'bbcode');
		self::assertIsArray($result['hashtags']);
		self::assertIsArray($result['symbols']);
		self::assertIsArray($result['urls']);
		self::assertIsArray($result['user_mentions']);
	}

	/**
	 * Test the api_format_items_embeded_images() function.
	 *
	 * @return void
	 */
	public function testApiFormatItemsEmbededImages()
	{
		self::assertEquals(
			'text ' . DI::baseUrl() . '/display/item_guid',
			api_format_items_embeded_images(['guid' => 'item_guid'], 'text data:image/foo')
		);
	}

	/**
	 * Test the api_contactlink_to_array() function.
	 *
	 * @return void
	 */
	public function testApiContactlinkToArray()
	{
		self::assertEquals(
			[
				'name' => 'text',
				'url'  => '',
			],
			api_contactlink_to_array('text')
		);
	}

	/**
	 * Test the api_contactlink_to_array() function with an URL.
	 *
	 * @return void
	 */
	public function testApiContactlinkToArrayWithUrl()
	{
		self::assertEquals(
			[
				'name' => ['link_text'],
				'url'  => ['url'],
			],
			api_contactlink_to_array('text <a href="url">link_text</a>')
		);
	}

	/**
	 * Test the api_format_items_activities() function.
	 *
	 * @return void
	 */
	public function testApiFormatItemsActivities()
	{
		$item   = ['uid' => 0, 'uri' => ''];
		$result = api_format_items_activities($item);
		self::assertArrayHasKey('like', $result);
		self::assertArrayHasKey('dislike', $result);
		self::assertArrayHasKey('attendyes', $result);
		self::assertArrayHasKey('attendno', $result);
		self::assertArrayHasKey('attendmaybe', $result);
	}

	/**
	 * Test the api_format_items_activities() function with an XML result.
	 *
	 * @return void
	 */
	public function testApiFormatItemsActivitiesWithXml()
	{
		$item   = ['uid' => 0, 'uri' => ''];
		$result = api_format_items_activities($item, 'xml');
		self::assertArrayHasKey('friendica:like', $result);
		self::assertArrayHasKey('friendica:dislike', $result);
		self::assertArrayHasKey('friendica:attendyes', $result);
		self::assertArrayHasKey('friendica:attendno', $result);
		self::assertArrayHasKey('friendica:attendmaybe', $result);
	}

	/**
	 * Test the api_format_items() function.
	 * @doesNotPerformAssertions
	 */
	public function testApiFormatItems()
	{
		$items  = [
			[
				'item_network'   => 'item_network',
				'source'         => 'web',
				'coord'          => '5 7',
				'body'           => '',
				'verb'           => '',
				'author-id'      => 43,
				'author-network' => Protocol::DFRN,
				'author-link'    => 'http://localhost/profile/othercontact',
				'plink'          => '',
			]
		];
		$result = api_format_items($items, ['id' => 0], true);
		foreach ($result as $status) {
			self::assertStatus($status);
		}
	}

	/**
	 * Test the api_format_items() function with an XML result.
	 * @doesNotPerformAssertions
	 */
	public function testApiFormatItemsWithXml()
	{
		$items  = [
			[
				'coord'          => '5 7',
				'body'           => '',
				'verb'           => '',
				'author-id'      => 43,
				'author-network' => Protocol::DFRN,
				'author-link'    => 'http://localhost/profile/othercontact',
				'plink'          => '',
			]
		];
		$result = api_format_items($items, ['id' => 0], true, 'xml');
		foreach ($result as $status) {
			self::assertStatus($status);
		}
	}

	/**
	 * Test the api_format_items() function.
	 *
	 * @return void
	 */
	public function testApiAccountRateLimitStatus()
	{
		$result = api_account_rate_limit_status('json');
		self::assertEquals(150, $result['hash']['remaining_hits']);
		self::assertEquals(150, $result['hash']['hourly_limit']);
		self::assertIsInt($result['hash']['reset_time_in_seconds']);
	}

	/**
	 * Test the api_format_items() function with an XML result.
	 *
	 * @return void
	 */
	public function testApiAccountRateLimitStatusWithXml()
	{
		$result = api_account_rate_limit_status('xml');
		self::assertXml($result, 'hash');
	}

	/**
	 * Test the api_help_test() function.
	 *
	 * @return void
	 */
	public function testApiHelpTest()
	{
		$result = api_help_test('json');
		self::assertEquals(['ok' => 'ok'], $result);
	}

	/**
	 * Test the api_help_test() function with an XML result.
	 *
	 * @return void
	 */
	public function testApiHelpTestWithXml()
	{
		$result = api_help_test('xml');
		self::assertXml($result, 'ok');
	}

	/**
	 * Test the api_lists_list() function.
	 *
	 * @return void
	 */
	public function testApiListsList()
	{
		$result = api_lists_list('json');
		self::assertEquals(['lists_list' => []], $result);
	}

	/**
	 * Test the api_lists_ownerships() function.
	 *
	 * @return void
	 */
	public function testApiListsOwnerships()
	{
		$result = api_lists_ownerships('json');
		foreach ($result['lists']['lists'] as $list) {
			self::assertList($list);
		}
	}

	/**
	 * Test the api_lists_ownerships() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiListsOwnershipsWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['authenticated'] = false;
		api_lists_ownerships('json');
	}

	/**
	 * Test the api_lists_statuses() function.
	 *
	 * @return void
	 */
	public function testApiListsStatuses()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		api_lists_statuses('json');
	}

	/**
	 * Test the api_lists_statuses() function with a list ID.
	 * @doesNotPerformAssertions
	 */
	public function testApiListsStatusesWithListId()
	{
		$_REQUEST['list_id'] = 1;
		$_REQUEST['page']    = -1;
		$_REQUEST['max_id']  = 10;
		$result              = api_lists_statuses('json');
		foreach ($result['status'] as $status) {
			self::assertStatus($status);
		}
	}

	/**
	 * Test the api_lists_statuses() function with a list ID and a RSS result.
	 *
	 * @return void
	 */
	public function testApiListsStatusesWithListIdAndRss()
	{
		$_REQUEST['list_id'] = 1;
		$result              = api_lists_statuses('rss');
		self::assertXml($result, 'statuses');
	}

	/**
	 * Test the api_lists_statuses() function with an unallowed user.
	 *
	 * @return void
	 */
	public function testApiListsStatusesWithUnallowedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['allow_api'] = false;
		$_GET['screen_name']   = $this->selfUser['nick'];
		api_lists_statuses('json');
	}

	/**
	 * Test the api_statuses_f() function.
	 *
	 * @return void
	 */
	public function testApiStatusesFWithFriends()
	{
		$_GET['page'] = -1;
		$result       = api_statuses_f('friends');
		self::assertArrayHasKey('user', $result);
	}

	/**
	 * Test the api_statuses_f() function.
	 *
	 * @return void
	 */
	public function testApiStatusesFWithFollowers()
	{
		$result = api_statuses_f('followers');
		self::assertArrayHasKey('user', $result);
	}

	/**
	 * Test the api_statuses_f() function.
	 *
	 * @return void
	 */
	public function testApiStatusesFWithBlocks()
	{
		$result = api_statuses_f('blocks');
		self::assertArrayHasKey('user', $result);
	}

	/**
	 * Test the api_statuses_f() function.
	 *
	 * @return void
	 */
	public function testApiStatusesFWithIncoming()
	{
		$result = api_statuses_f('incoming');
		self::assertArrayHasKey('user', $result);
	}

	/**
	 * Test the api_statuses_f() function an undefined cursor GET variable.
	 *
	 * @return void
	 */
	public function testApiStatusesFWithUndefinedCursor()
	{
		$_GET['cursor'] = 'undefined';
		self::assertFalse(api_statuses_f('friends'));
	}

	/**
	 * Test the api_statuses_friends() function.
	 *
	 * @return void
	 */
	public function testApiStatusesFriends()
	{
		$result = api_statuses_friends('json');
		self::assertArrayHasKey('user', $result);
	}

	/**
	 * Test the api_statuses_friends() function an undefined cursor GET variable.
	 *
	 * @return void
	 */
	public function testApiStatusesFriendsWithUndefinedCursor()
	{
		$_GET['cursor'] = 'undefined';
		self::assertFalse(api_statuses_friends('json'));
	}

	/**
	 * Test the api_statuses_followers() function.
	 *
	 * @return void
	 */
	public function testApiStatusesFollowers()
	{
		$result = api_statuses_followers('json');
		self::assertArrayHasKey('user', $result);
	}

	/**
	 * Test the api_statuses_followers() function an undefined cursor GET variable.
	 *
	 * @return void
	 */
	public function testApiStatusesFollowersWithUndefinedCursor()
	{
		$_GET['cursor'] = 'undefined';
		self::assertFalse(api_statuses_followers('json'));
	}

	/**
	 * Test the api_blocks_list() function.
	 *
	 * @return void
	 */
	public function testApiBlocksList()
	{
		$result = api_blocks_list('json');
		self::assertArrayHasKey('user', $result);
	}

	/**
	 * Test the api_blocks_list() function an undefined cursor GET variable.
	 *
	 * @return void
	 */
	public function testApiBlocksListWithUndefinedCursor()
	{
		$_GET['cursor'] = 'undefined';
		self::assertFalse(api_blocks_list('json'));
	}

	/**
	 * Test the api_friendships_incoming() function.
	 *
	 * @return void
	 */
	public function testApiFriendshipsIncoming()
	{
		$result = api_friendships_incoming('json');
		self::assertArrayHasKey('id', $result);
	}

	/**
	 * Test the api_friendships_incoming() function an undefined cursor GET variable.
	 *
	 * @return void
	 */
	public function testApiFriendshipsIncomingWithUndefinedCursor()
	{
		$_GET['cursor'] = 'undefined';
		self::assertFalse(api_friendships_incoming('json'));
	}

	/**
	 * Test the api_statusnet_config() function.
	 *
	 * @return void
	 */
	public function testApiStatusnetConfig()
	{
		$result = api_statusnet_config('json');
		self::assertEquals('localhost', $result['config']['site']['server']);
		self::assertEquals('default', $result['config']['site']['theme']);
		self::assertEquals(DI::baseUrl() . '/images/friendica-64.png', $result['config']['site']['logo']);
		self::assertTrue($result['config']['site']['fancy']);
		self::assertEquals('en', $result['config']['site']['language']);
		self::assertEquals('UTC', $result['config']['site']['timezone']);
		self::assertEquals(200000, $result['config']['site']['textlimit']);
		self::assertEquals('false', $result['config']['site']['private']);
		self::assertEquals('false', $result['config']['site']['ssl']);
		self::assertEquals(30, $result['config']['site']['shorturllength']);
	}

	/**
	 * Test the api_statusnet_version() function.
	 *
	 * @return void
	 */
	public function testApiStatusnetVersion()
	{
		$result = api_statusnet_version('json');
		self::assertEquals('0.9.7', $result['version']);
	}

	/**
	 * Test the api_direct_messages_new() function.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesNew()
	{
		$result = api_direct_messages_new('json');
		self::assertNull($result);
	}

	/**
	 * Test the api_direct_messages_new() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesNewWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['authenticated'] = false;
		api_direct_messages_new('json');
	}

	/**
	 * Test the api_direct_messages_new() function with an user ID.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesNewWithUserId()
	{
		$_POST['text']    = 'message_text';
		$_POST['user_id'] = $this->otherUser['id'];
		$result           = api_direct_messages_new('json');
		self::assertEquals(['direct_message' => ['error' => -1]], $result);
	}

	/**
	 * Test the api_direct_messages_new() function with a screen name.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesNewWithScreenName()
	{
		$this->app->user    = ['nickname' => $this->selfUser['nick']];
		$_POST['text']        = 'message_text';
		$_POST['screen_name'] = $this->friendUser['nick'];
		$result               = api_direct_messages_new('json');
		self::assertStringContainsString('message_text', $result['direct_message']['text']);
		self::assertEquals('selfcontact', $result['direct_message']['sender_screen_name']);
		self::assertEquals(1, $result['direct_message']['friendica_seen']);
	}

	/**
	 * Test the api_direct_messages_new() function with a title.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesNewWithTitle()
	{
		$this->app->user    = ['nickname' => $this->selfUser['nick']];
		$_POST['text']        = 'message_text';
		$_POST['screen_name'] = $this->friendUser['nick'];
		$_REQUEST['title']    = 'message_title';
		$result               = api_direct_messages_new('json');
		self::assertStringContainsString('message_text', $result['direct_message']['text']);
		self::assertStringContainsString('message_title', $result['direct_message']['text']);
		self::assertEquals('selfcontact', $result['direct_message']['sender_screen_name']);
		self::assertEquals(1, $result['direct_message']['friendica_seen']);
	}

	/**
	 * Test the api_direct_messages_new() function with an RSS result.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesNewWithRss()
	{
		$this->app->user    = ['nickname' => $this->selfUser['nick']];
		$_POST['text']        = 'message_text';
		$_POST['screen_name'] = $this->friendUser['nick'];
		$result               = api_direct_messages_new('rss');
		self::assertXml($result, 'direct-messages');
	}

	/**
	 * Test the api_direct_messages_destroy() function.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesDestroy()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		api_direct_messages_destroy('json');
	}

	/**
	 * Test the api_direct_messages_destroy() function with the friendica_verbose GET param.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesDestroyWithVerbose()
	{
		$_GET['friendica_verbose'] = 'true';
		$result                    = api_direct_messages_destroy('json');
		self::assertEquals(
			[
				'$result' => [
					'result'  => 'error',
					'message' => 'message id or parenturi not specified'
				]
			],
			$result
		);
	}

	/**
	 * Test the api_direct_messages_destroy() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesDestroyWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['authenticated'] = false;
		api_direct_messages_destroy('json');
	}

	/**
	 * Test the api_direct_messages_destroy() function with a non-zero ID.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesDestroyWithId()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		$_REQUEST['id'] = 1;
		api_direct_messages_destroy('json');
	}

	/**
	 * Test the api_direct_messages_destroy() with a non-zero ID and the friendica_verbose GET param.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesDestroyWithIdAndVerbose()
	{
		$_REQUEST['id']                  = 1;
		$_REQUEST['friendica_parenturi'] = 'parent_uri';
		$_GET['friendica_verbose']       = 'true';
		$result                          = api_direct_messages_destroy('json');
		self::assertEquals(
			[
				'$result' => [
					'result'  => 'error',
					'message' => 'message id not in database'
				]
			],
			$result
		);
	}

	/**
	 * Test the api_direct_messages_destroy() function with a non-zero ID.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesDestroyWithCorrectId()
	{
		$this->markTestIncomplete('We need to add a dataset for this.');
	}

	/**
	 * Test the api_direct_messages_box() function.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesBoxWithSentbox()
	{
		$_REQUEST['page']   = -1;
		$_REQUEST['max_id'] = 10;
		$result             = api_direct_messages_box('json', 'sentbox', 'false');
		self::assertArrayHasKey('direct_message', $result);
	}

	/**
	 * Test the api_direct_messages_box() function.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesBoxWithConversation()
	{
		$result = api_direct_messages_box('json', 'conversation', 'false');
		self::assertArrayHasKey('direct_message', $result);
	}

	/**
	 * Test the api_direct_messages_box() function.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesBoxWithAll()
	{
		$result = api_direct_messages_box('json', 'all', 'false');
		self::assertArrayHasKey('direct_message', $result);
	}

	/**
	 * Test the api_direct_messages_box() function.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesBoxWithInbox()
	{
		$result = api_direct_messages_box('json', 'inbox', 'false');
		self::assertArrayHasKey('direct_message', $result);
	}

	/**
	 * Test the api_direct_messages_box() function.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesBoxWithVerbose()
	{
		$result = api_direct_messages_box('json', 'sentbox', 'true');
		self::assertEquals(
			[
				'$result' => [
					'result'  => 'error',
					'message' => 'no mails available'
				]
			],
			$result
		);
	}

	/**
	 * Test the api_direct_messages_box() function with a RSS result.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesBoxWithRss()
	{
		$result = api_direct_messages_box('rss', 'sentbox', 'false');
		self::assertXml($result, 'direct-messages');
	}

	/**
	 * Test the api_direct_messages_box() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesBoxWithUnallowedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['allow_api'] = false;
		$_GET['screen_name']   = $this->selfUser['nick'];
		api_direct_messages_box('json', 'sentbox', 'false');
	}

	/**
	 * Test the api_direct_messages_sentbox() function.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesSentbox()
	{
		$result = api_direct_messages_sentbox('json');
		self::assertArrayHasKey('direct_message', $result);
	}

	/**
	 * Test the api_direct_messages_inbox() function.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesInbox()
	{
		$result = api_direct_messages_inbox('json');
		self::assertArrayHasKey('direct_message', $result);
	}

	/**
	 * Test the api_direct_messages_all() function.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesAll()
	{
		$result = api_direct_messages_all('json');
		self::assertArrayHasKey('direct_message', $result);
	}

	/**
	 * Test the api_direct_messages_conversation() function.
	 *
	 * @return void
	 */
	public function testApiDirectMessagesConversation()
	{
		$result = api_direct_messages_conversation('json');
		self::assertArrayHasKey('direct_message', $result);
	}

	/**
	 * Test the api_oauth_request_token() function.
	 *
	 * @return void
	 */
	public function testApiOauthRequestToken()
	{
		$this->markTestIncomplete('exit() kills phpunit as well');
	}

	/**
	 * Test the api_oauth_access_token() function.
	 *
	 * @return void
	 */
	public function testApiOauthAccessToken()
	{
		$this->markTestIncomplete('exit() kills phpunit as well');
	}

	/**
	 * Test the api_fr_photoalbum_delete() function.
	 *
	 * @return void
	 */
	public function testApiFrPhotoalbumDelete()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		api_fr_photoalbum_delete('json');
	}

	/**
	 * Test the api_fr_photoalbum_delete() function with an album name.
	 *
	 * @return void
	 */
	public function testApiFrPhotoalbumDeleteWithAlbum()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		$_REQUEST['album'] = 'album_name';
		api_fr_photoalbum_delete('json');
	}

	/**
	 * Test the api_fr_photoalbum_delete() function with an album name.
	 *
	 * @return void
	 */
	public function testApiFrPhotoalbumDeleteWithValidAlbum()
	{
		$this->markTestIncomplete('We need to add a dataset for this.');
	}

	/**
	 * Test the api_fr_photoalbum_delete() function.
	 *
	 * @return void
	 */
	public function testApiFrPhotoalbumUpdate()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		api_fr_photoalbum_update('json');
	}

	/**
	 * Test the api_fr_photoalbum_delete() function with an album name.
	 *
	 * @return void
	 */
	public function testApiFrPhotoalbumUpdateWithAlbum()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		$_REQUEST['album'] = 'album_name';
		api_fr_photoalbum_update('json');
	}

	/**
	 * Test the api_fr_photoalbum_delete() function with an album name.
	 *
	 * @return void
	 */
	public function testApiFrPhotoalbumUpdateWithAlbumAndNewAlbum()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		$_REQUEST['album']     = 'album_name';
		$_REQUEST['album_new'] = 'album_name';
		api_fr_photoalbum_update('json');
	}

	/**
	 * Test the api_fr_photoalbum_update() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiFrPhotoalbumUpdateWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['authenticated'] = false;
		api_fr_photoalbum_update('json');
	}

	/**
	 * Test the api_fr_photoalbum_delete() function with an album name.
	 *
	 * @return void
	 */
	public function testApiFrPhotoalbumUpdateWithValidAlbum()
	{
		$this->markTestIncomplete('We need to add a dataset for this.');
	}

	/**
	 * Test the api_fr_photos_list() function.
	 *
	 * @return void
	 */
	public function testApiFrPhotosList()
	{
		$result = api_fr_photos_list('json');
		self::assertArrayHasKey('photo', $result);
	}

	/**
	 * Test the api_fr_photos_list() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiFrPhotosListWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['authenticated'] = false;
		api_fr_photos_list('json');
	}

	/**
	 * Test the api_fr_photo_create_update() function.
	 */
	public function testApiFrPhotoCreateUpdate()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		api_fr_photo_create_update('json');
	}

	/**
	 * Test the api_fr_photo_create_update() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiFrPhotoCreateUpdateWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['authenticated'] = false;
		api_fr_photo_create_update('json');
	}

	/**
	 * Test the api_fr_photo_create_update() function with an album name.
	 *
	 * @return void
	 */
	public function testApiFrPhotoCreateUpdateWithAlbum()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		$_REQUEST['album'] = 'album_name';
		api_fr_photo_create_update('json');
	}

	/**
	 * Test the api_fr_photo_create_update() function with the update mode.
	 *
	 * @return void
	 */
	public function testApiFrPhotoCreateUpdateWithUpdate()
	{
		$this->markTestIncomplete('We need to create a dataset for this');
	}

	/**
	 * Test the api_fr_photo_create_update() function with an uploaded file.
	 *
	 * @return void
	 */
	public function testApiFrPhotoCreateUpdateWithFile()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_fr_photo_delete() function.
	 *
	 * @return void
	 */
	public function testApiFrPhotoDelete()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		api_fr_photo_delete('json');
	}

	/**
	 * Test the api_fr_photo_delete() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiFrPhotoDeleteWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['authenticated'] = false;
		api_fr_photo_delete('json');
	}

	/**
	 * Test the api_fr_photo_delete() function with a photo ID.
	 *
	 * @return void
	 */
	public function testApiFrPhotoDeleteWithPhotoId()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		$_REQUEST['photo_id'] = 1;
		api_fr_photo_delete('json');
	}

	/**
	 * Test the api_fr_photo_delete() function with a correct photo ID.
	 *
	 * @return void
	 */
	public function testApiFrPhotoDeleteWithCorrectPhotoId()
	{
		$this->markTestIncomplete('We need to create a dataset for this.');
	}

	/**
	 * Test the api_fr_photo_detail() function.
	 *
	 * @return void
	 */
	public function testApiFrPhotoDetail()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		api_fr_photo_detail('json');
	}

	/**
	 * Test the api_fr_photo_detail() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiFrPhotoDetailWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['authenticated'] = false;
		api_fr_photo_detail('json');
	}

	/**
	 * Test the api_fr_photo_detail() function with a photo ID.
	 *
	 * @return void
	 */
	public function testApiFrPhotoDetailWithPhotoId()
	{
		$this->expectException(\Friendica\Network\HTTPException\NotFoundException::class);
		$_REQUEST['photo_id'] = 1;
		api_fr_photo_detail('json');
	}

	/**
	 * Test the api_fr_photo_detail() function with a correct photo ID.
	 *
	 * @return void
	 */
	public function testApiFrPhotoDetailCorrectPhotoId()
	{
		$this->markTestIncomplete('We need to create a dataset for this.');
	}

	/**
	 * Test the api_account_update_profile_image() function.
	 *
	 * @return void
	 */
	public function testApiAccountUpdateProfileImage()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		api_account_update_profile_image('json');
	}

	/**
	 * Test the api_account_update_profile_image() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiAccountUpdateProfileImageWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['authenticated'] = false;
		api_account_update_profile_image('json');
	}

	/**
	 * Test the api_account_update_profile_image() function with an uploaded file.
	 *
	 * @return void
	 */
	public function testApiAccountUpdateProfileImageWithUpload()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		$this->markTestIncomplete();
	}


	/**
	 * Test the api_account_update_profile() function.
	 *
	 * @return void
	 */
	public function testApiAccountUpdateProfile()
	{
		$_POST['name']        = 'new_name';
		$_POST['description'] = 'new_description';
		$result               = api_account_update_profile('json');
		// We can't use assertSelfUser() here because the user object is missing some properties.
		self::assertEquals($this->selfUser['id'], $result['user']['cid']);
		self::assertEquals('DFRN', $result['user']['location']);
		self::assertEquals($this->selfUser['nick'], $result['user']['screen_name']);
		self::assertEquals('dfrn', $result['user']['network']);
		self::assertEquals('new_name', $result['user']['name']);
		self::assertEquals('new_description', $result['user']['description']);
	}

	/**
	 * Test the check_acl_input() function.
	 *
	 * @return void
	 */
	public function testCheckAclInput()
	{
		$result = check_acl_input('<aclstring>');
		// Where does this result come from?
		self::assertEquals(1, $result);
	}

	/**
	 * Test the check_acl_input() function with an empty ACL string.
	 *
	 * @return void
	 */
	public function testCheckAclInputWithEmptyAclString()
	{
		$result = check_acl_input(' ');
		self::assertFalse($result);
	}

	/**
	 * Test the save_media_to_database() function.
	 *
	 * @return void
	 */
	public function testSaveMediaToDatabase()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the post_photo_item() function.
	 *
	 * @return void
	 */
	public function testPostPhotoItem()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the prepare_photo_data() function.
	 *
	 * @return void
	 */
	public function testPreparePhotoData()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_friendica_remoteauth() function.
	 *
	 * @return void
	 */
	public function testApiFriendicaRemoteauth()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		api_friendica_remoteauth();
	}

	/**
	 * Test the api_friendica_remoteauth() function with an URL.
	 *
	 * @return void
	 */
	public function testApiFriendicaRemoteauthWithUrl()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		$_GET['url']   = 'url';
		$_GET['c_url'] = 'url';
		api_friendica_remoteauth();
	}

	/**
	 * Test the api_friendica_remoteauth() function with a correct URL.
	 *
	 * @return void
	 */
	public function testApiFriendicaRemoteauthWithCorrectUrl()
	{
		$this->markTestIncomplete("We can't use an assertion here because of App->redirect().");
		$_GET['url']   = 'url';
		$_GET['c_url'] = $this->selfUser['nurl'];
		api_friendica_remoteauth();
	}

	/**
	 * Test the api_share_as_retweet() function.
	 *
	 * @return void
	 */
	public function testApiShareAsRetweet()
	{
		$item   = ['body' => '', 'author-id' => 1, 'owner-id' => 1];
		$result = api_share_as_retweet($item);
		self::assertFalse($result);
	}

	/**
	 * Test the api_share_as_retweet() function with a valid item.
	 *
	 * @return void
	 */
	public function testApiShareAsRetweetWithValidItem()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_in_reply_to() function.
	 *
	 * @return void
	 */
	public function testApiInReplyTo()
	{
		$result = api_in_reply_to(['id' => 0, 'parent' => 0, 'uri' => '', 'thr-parent' => '']);
		self::assertArrayHasKey('status_id', $result);
		self::assertArrayHasKey('user_id', $result);
		self::assertArrayHasKey('status_id_str', $result);
		self::assertArrayHasKey('user_id_str', $result);
		self::assertArrayHasKey('screen_name', $result);
	}

	/**
	 * Test the api_in_reply_to() function with a valid item.
	 *
	 * @return void
	 */
	public function testApiInReplyToWithValidItem()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_clean_plain_items() function.
	 *
	 * @return void
	 */
	public function testApiCleanPlainItems()
	{
		$_REQUEST['include_entities'] = 'true';
		$result                       = api_clean_plain_items('some_text [url="some_url"]some_text[/url]');
		self::assertEquals('some_text [url="some_url"]"some_url"[/url]', $result);
	}

	/**
	 * Test the api_best_nickname() function.
	 *
	 * @return void
	 */
	public function testApiBestNickname()
	{
		$contacts = [];
		$result   = api_best_nickname($contacts);
		self::assertNull($result);
	}

	/**
	 * Test the api_best_nickname() function with contacts.
	 *
	 * @return void
	 */
	public function testApiBestNicknameWithContacts()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_friendica_group_show() function.
	 *
	 * @return void
	 */
	public function testApiFriendicaGroupShow()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_friendica_group_delete() function.
	 *
	 * @return void
	 */
	public function testApiFriendicaGroupDelete()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_lists_destroy() function.
	 *
	 * @return void
	 */
	public function testApiListsDestroy()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the group_create() function.
	 *
	 * @return void
	 */
	public function testGroupCreate()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_friendica_group_create() function.
	 *
	 * @return void
	 */
	public function testApiFriendicaGroupCreate()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_lists_create() function.
	 *
	 * @return void
	 */
	public function testApiListsCreate()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_friendica_group_update() function.
	 *
	 * @return void
	 */
	public function testApiFriendicaGroupUpdate()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_lists_update() function.
	 *
	 * @return void
	 */
	public function testApiListsUpdate()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_friendica_activity() function.
	 *
	 * @return void
	 */
	public function testApiFriendicaActivity()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_friendica_notification() function.
	 *
	 * @return void
	 */
	public function testApiFriendicaNotification()
	{
		$this->expectException(\Friendica\Network\HTTPException\BadRequestException::class);
		api_friendica_notification('json');
	}

	/**
	 * Test the api_friendica_notification() function without an authenticated user.
	 *
	 * @return void
	 */
	public function testApiFriendicaNotificationWithoutAuthenticatedUser()
	{
		$this->expectException(\Friendica\Network\HTTPException\ForbiddenException::class);
		$_SESSION['authenticated'] = false;
		api_friendica_notification('json');
	}

	/**
	 * Test the api_friendica_notification() function with empty result
	 *
	 * @return void
	 */
	public function testApiFriendicaNotificationWithEmptyResult()
	{
		$this->app->argv = ['api', 'friendica', 'notification'];
		$this->app->argc = count($this->app->argv);
		$_SESSION['uid'] = 41;
		$result          = api_friendica_notification('json');
		self::assertEquals(['note' => false], $result);
	}

	/**
	 * Test the api_friendica_notification() function with an XML result.
	 *
	 * @return void
	 */
	public function testApiFriendicaNotificationWithXmlResult()
	{
		$this->app->argv = ['api', 'friendica', 'notification'];
		$this->app->argc = count($this->app->argv);
		$result          = api_friendica_notification('xml');
		$dateRel = Temporal::getRelativeDate('2020-01-01 12:12:02');
		$assertXml=<<<XML
<?xml version="1.0"?>
<notes>
  <note id="1" hash="" type="8" name="Reply to" url="http://localhost/display/1" photo="http://localhost/" date="2020-01-01 12:12:02" msg="A test reply from an item" uid="42" uri-id="" link="http://localhost/notification/1" iid="4" parent="" parent-uri-id="" seen="0" verb="" otype="item" name_cache="Reply to" msg_cache="A test reply from an item" timestamp="1577880722" date_rel="{$dateRel}" msg_html="A test reply from an item" msg_plain="A test reply from an item"/>
</notes>
XML;
		self::assertXmlStringEqualsXmlString($assertXml, $result);
	}

	/**
	 * Test the api_friendica_notification() function with an JSON result.
	 *
	 * @return void
	 */
	public function testApiFriendicaNotificationWithJsonResult()
	{
		$this->app->argv = ['api', 'friendica', 'notification'];
		$this->app->argc = count($this->app->argv);
		$result          = json_encode(api_friendica_notification('json'));
		self::assertJson($result);
	}

	/**
	 * Test the api_friendica_notification_seen() function.
	 *
	 * @return void
	 */
	public function testApiFriendicaNotificationSeen()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_friendica_direct_messages_setseen() function.
	 *
	 * @return void
	 */
	public function testApiFriendicaDirectMessagesSetseen()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_friendica_direct_messages_search() function.
	 *
	 * @return void
	 */
	public function testApiFriendicaDirectMessagesSearch()
	{
		$this->markTestIncomplete();
	}

	/**
	 * Test the api_saved_searches_list() function.
	 *
	 * @return void
	 */
	public function testApiSavedSearchesList()
	{
		$result = api_saved_searches_list('json');
		self::assertEquals(1, $result['terms'][0]['id']);
		self::assertEquals(1, $result['terms'][0]['id_str']);
		self::assertEquals('Saved search', $result['terms'][0]['name']);
		self::assertEquals('Saved search', $result['terms'][0]['query']);
	}
}

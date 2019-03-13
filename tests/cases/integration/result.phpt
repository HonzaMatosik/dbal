<?php declare(strict_types = 1);

/**
 * @testCase
 * @dataProvider? ../../databases.ini
 */

namespace NextrasTests\Dbal;

use Nextras\Dbal\Drivers\IResultAdapter;
use Nextras\Dbal\Drivers\Sqlite3\Sqlite3Driver;
use Nextras\Dbal\InvalidStateException;
use Nextras\Dbal\NotSupportedException;
use Nextras\Dbal\Platforms\SqlitePlatform;
use Nextras\Dbal\Platforms\SqlServerPlatform;
use Nextras\Dbal\Utils\DateTimeImmutable;
use Tester\Assert;


require_once __DIR__ . '/../../bootstrap.php';


class ResultIntegrationTest extends IntegrationTestCase
{
	public function testEmptyResult()
	{
		$this->lockConnection($this->connection);
		$result = $this->connection->query('SELECT * FROM books WHERE 1=2');
		Assert::equal([], iterator_to_array($result));
	}


	public function testSetupNormalization()
	{
		$this->initData($this->connection);

		$result = $this->connection->query('SELECT * FROM tag_followers ORDER BY tag_id, author_id');

		$result->setValueNormalization(false); // test reenabling
		$result->setValueNormalization(true);

		if ($this->connection->getPlatform() instanceof SqlitePlatform) {
			$result->setValueNormalizationType('created_at', IResultAdapter::TYPE_DATETIME);
		}

		$follower = $result->fetch();

		Assert::same(1, $follower->tag_id);
		Assert::same(1, $follower->author_id);
		Assert::type(DateTimeImmutable::class, $follower->created_at);
		Assert::same('2014-01-01 00:10:00', $follower->created_at->format('Y-m-d H:i:s'));

		$result->setValueNormalization(false);
		$follower = $result->fetch();

		if ($this->connection->getPlatform() instanceof SqlServerPlatform || $this->connection->getPlatform() instanceof SqlitePlatform) {
			Assert::same(2, $follower->tag_id);
			Assert::same(2, $follower->author_id);
		} else {
			Assert::same('2', $follower->tag_id);
			Assert::same('2', $follower->author_id);
		}
		Assert::type('string', $follower->created_at);
	}


	public function testSeek()
	{
		$this->initData($this->connection);
		$result = $this->connection->query('SELECT * FROM books');

		if ($this->connection->getDriver() instanceof Sqlite3Driver) {
			Assert::exception(function () use ($result) {
				$result->seek(10);
			}, NotSupportedException::class);
		} else {
			Assert::exception(function () use ($result) {
				$result->seek(10);
			}, InvalidStateException::class);
		}
	}


	public function testResultType()
	{
		$this->lockConnection($this->connection);
		Assert::null($this->connection->query('INSERT INTO tags %values', ['name' => "Test"])->fetch());
	}
}


$test = new ResultIntegrationTest();
$test->run();

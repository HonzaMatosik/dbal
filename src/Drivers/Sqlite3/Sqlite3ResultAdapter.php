<?php declare(strict_types = 1);

/**
 * This file is part of the Nextras\Dbal library.
 * @license    MIT
 * @link       https://github.com/nextras/dbal
 */

namespace Nextras\Dbal\Drivers\Sqlite3;

use Nextras\Dbal\Drivers\IResultAdapter;
use Nextras\Dbal\NotSupportedException;
use SQLite3Result;


class Sqlite3ResultAdapter implements IResultAdapter
{
	/** @var SQLite3Result */
	private $result;

	protected static $types = [
		SQLITE3_INTEGER => self::TYPE_INT,
		SQLITE3_TEXT => self::TYPE_STRING,
		SQLITE3_BLOB => self::TYPE_STRING,
		SQLITE3_NULL => self::TYPE_INT,
	];


	public function __construct(SQLite3Result $result)
	{
		$this->result = $result;
	}


	public function __destruct()
	{
		$this->result->finalize();
	}


	public function seek(int $index)
	{
		if ($index !== 0) {
			throw new NotSupportedException("Only seeking at the beginning is supported.");
		}
		$this->result->reset();
	}


	public function fetch()
	{
		return $this->result->fetchArray(SQLITE3_ASSOC) ?: null;
	}


	public function getTypes(): array
	{
		$types = [];
		$count = $this->result->numColumns();

		for ($i = 0; $i < $count; $i ++) {
			$nativeType = $this->result->columnType($i);
			$types[$this->result->columnName($i)] = [
				0 => self::$types[$nativeType] ?? self::TYPE_AS_IS,
				1 => $nativeType,
			];
		}

		return $types;
	}


	public function getRowsCount(): int
	{
		throw new NotSupportedException();
	}
}

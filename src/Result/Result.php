<?php declare(strict_types = 1);

/**
 * This file is part of the Nextras\Dbal library.
 * @license    MIT
 * @link       https://github.com/nextras/dbal
 */

namespace Nextras\Dbal\Result;

use DateTimeZone;
use Nextras\Dbal\Drivers\IDriver;
use Nextras\Dbal\Drivers\IResultAdapter;
use Nextras\Dbal\InvalidArgumentException;
use Nextras\Dbal\Utils\DateTimeImmutable;


class Result implements \SeekableIterator, \Countable
{
	/** @var IResultAdapter */
	private $adapter;

	/** @var int */
	private $iteratorIndex;

	/** @var Row|null */
	private $iteratorRow;

	/** @var IDriver */
	private $driver;

	/** @var array<string, null> list of columns which should be casted to int */
	private $toIntColumns;

	/** @var array<string, null> list of columns which should be casted to float */
	private $toFloatColumns;

	/** @var array<string, null> list of columns which should be casted to string */
	private $toStringColumns;

	/** @var array<string, null> list of columns which should be casted to bool */
	private $toBoolColumns;

	/** @var array<string, null> list of columns which should be casted to DateTime */
	private $toDateTimeColumns;

	/** @var array<string, mixed> list of columns which should be casted using driver-specific logic */
	private $toDriverColumns;

	/** @var DateTimeZone */
	private $applicationTimeZone;


	public function __construct(IResultAdapter $adapter, IDriver $driver)
	{
		$this->adapter = $adapter;
		$this->driver = $driver;
		$this->applicationTimeZone = new DateTimeZone(date_default_timezone_get());
		$this->initColumnConversions();
	}


	public function getAdapter(): IResultAdapter
	{
		return $this->adapter;
	}


	/**
	 * Enables and disables value normalization.
	 */
	public function setValueNormalization(bool $enabled = false)
	{
		if ($enabled === true) {
			$this->initColumnConversions();
		} else {
			$this->toIntColumns = [];
			$this->toFloatColumns = [];
			$this->toStringColumns = [];
			$this->toBoolColumns = [];
			$this->toDateTimeColumns = [];
			$this->toDriverColumns = [];
		}
	}


	/**
	 * Sets column conversion type.
	 * @param string     $column column name
	 * @param int        $type the result type; a binary combination of IResultAdapter::TYPE_*
	 * @param mixed|null $nativeType the original type for specic driver type conversion
	 */
	public function setValueNormalizationType(string $column, int $type, $nativeType = null)
	{
		unset(
			$this->toStringColumns[$column],
			$this->toIntColumns[$column],
			$this->toFloatColumns[$column],
			$this->toBoolColumns[$column],
			$this->toDateTimeColumns[$column],
			$this->toDriverColumns[$column]
		);

		if ($type & IResultAdapter::TYPE_STRING) {
			$this->toStringColumns[$column] = null;

		} elseif ($type & IResultAdapter::TYPE_INT) {
			$this->toIntColumns[$column] = null;

		} elseif ($type & IResultAdapter::TYPE_FLOAT) {
			$this->toFloatColumns[$column] = null;

		} elseif ($type & IResultAdapter::TYPE_BOOL) {
			$this->toBoolColumns[$column] = null;

		} elseif ($type & IResultAdapter::TYPE_DATETIME) {
			$this->toDateTimeColumns[$column] = null;
		}

		if ($type & IResultAdapter::TYPE_DRIVER_SPECIFIC) {
			$this->toDriverColumns[$column] = $nativeType;
		}
	}


	public function fetch(): ?Row
	{
		$data = $this->adapter->fetch();
		$row = ($data === null ? null : new Row($this->normalize($data)));
		$this->iteratorIndex++;
		return $this->iteratorRow = $row;
	}


	/**
	 * @return mixed|null
	 */
	public function fetchField(int $column = 0)
	{
		if ($row = $this->fetch()) { // = intentionally
			return $row->getNthField($column);
		}

		return null;
	}


	/**
	 * @return Row[]
	 */
	public function fetchAll(): array
	{
		return iterator_to_array($this);
	}


	public function fetchPairs(string $key = null, string $value = null): array
	{
		if ($key === null && $value === null) {
			throw new InvalidArgumentException('Result::fetchPairs() requires defined key or value.');
		}

		$return = [];
		$this->seek(0);

		if ($key === null) {
			while ($row = $this->fetch()) {
				$return[] = $row->{$value};
			}
		} elseif ($value === null) {
			while ($row = $this->fetch()) {
				$return[($row->{$key} instanceof DateTimeImmutable) ? (string) $row->{$key} : $row->{$key}] = $row;
			}
		} else {
			while ($row = $this->fetch()) {
				$return[($row->{$key} instanceof DateTimeImmutable) ? (string) $row->{$key} : $row->{$key}] = $row->{$value};
			}
		}

		return $return;
	}


	private function initColumnConversions()
	{
		$this->toIntColumns = [];
		$this->toFloatColumns = [];
		$this->toStringColumns = [];
		$this->toBoolColumns = [];
		$this->toDateTimeColumns = [];
		$this->toDriverColumns = [];

		$types = $this->adapter->getTypes();
		foreach ($types as $column => $typePair) {
			list($type, $nativeType) = $typePair;

			if ($type & IResultAdapter::TYPE_STRING) {
				$this->toStringColumns[$column] = null;

			} elseif ($type & IResultAdapter::TYPE_INT) {
				$this->toIntColumns[$column] = null;

			} elseif ($type & IResultAdapter::TYPE_FLOAT) {
				$this->toFloatColumns[$column] = null;

			} elseif ($type & IResultAdapter::TYPE_BOOL) {
				$this->toBoolColumns[$column] = null;

			} elseif ($type & IResultAdapter::TYPE_DATETIME) {
				$this->toDateTimeColumns[$column] = null;
			}

			if ($type & IResultAdapter::TYPE_DRIVER_SPECIFIC) {
				$this->toDriverColumns[$column] = $nativeType;
			}
		}
	}


	private function normalize(array $data): array
	{
		foreach ($this->toDriverColumns as $column => $nativeType) {
			if ($data[$column] !== null) {
				$data[$column] = $this->driver->convertToPhp($data[$column], $nativeType);
			}
		}

		foreach ($this->toIntColumns as $column => $_) {
			if ($data[$column] !== null) {
				$data[$column] = (int) $data[$column];
			}
		}

		foreach ($this->toFloatColumns as $column => $_) {
			if ($data[$column] !== null) {
				$data[$column] = (float) $data[$column];
			}
		}

		foreach ($this->toBoolColumns as $column => $_) {
			if ($data[$column] !== null) {
				$data[$column] = (bool) $data[$column];
			}
		}

		foreach ($this->toStringColumns as $column => $_) {
			if ($data[$column] !== null) {
				$data[$column] = (string) $data[$column];
			}
		}

		foreach ($this->toDateTimeColumns as $column => $_) {
			if ($data[$column] !== null) {
				$data[$column] = (new DateTimeImmutable($data[$column]))->setTimezone($this->applicationTimeZone);
			}
		}

		return $data;
	}


	// === SeekableIterator ============================================================================================


	public function key()
	{
		return $this->iteratorIndex;
	}


	public function current()
	{
		return $this->iteratorRow;
	}


	public function next()
	{
		$this->fetch();
	}


	public function valid()
	{
		return $this->iteratorRow !== null;
	}


	public function rewind()
	{
		$this->seek(0);
		$this->fetch();
	}


	public function seek($index)
	{
		$this->adapter->seek($index);
		$this->iteratorIndex = $index - 1;
	}


	// === Countable ===================================================================================================


	public function count()
	{
		return $this->adapter->getRowsCount();
	}
}

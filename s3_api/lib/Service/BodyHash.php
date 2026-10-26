<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

/**
 * Hashes a request body while it is read, with every algorithm the request
 * makes a claim in -- see PayloadIntegrity::algorithms().
 *
 * An upload can be 5 GiB and only passes through once, so the digests are
 * computed on the way into the temp file rather than by reading it back.
 */
final class BodyHash {

	/** @var array<string, \HashContext> */
	private array $contexts = [];

	/** Running CRC-64/NVME state, null when that one is not wanted. */
	private ?int $crc64 = null;

	/** @var list<list<int>>|null slicing-by-8 tables for CRC-64/NVME */
	private static ?array $crc64Tables = null;

	/**
	 * @param string[] $algorithms hash() names, plus `crc64nvme`
	 */
	public function __construct(array $algorithms) {
		foreach (array_unique($algorithms) as $algorithm) {
			if ($algorithm === 'crc64nvme') {
				$this->crc64 = -1;
			} else {
				$this->contexts[$algorithm] = hash_init($algorithm);
			}
		}
	}

	public function update(string $data): void {
		foreach ($this->contexts as $context) {
			hash_update($context, $data);
		}
		if ($this->crc64 !== null) {
			$this->crc64 = self::crc64Update($this->crc64, $data);
		}
	}

	/**
	 * The raw digests, by algorithm. Finalises the hashes: call once.
	 *
	 * @return array<string, string>
	 */
	public function digests(): array {
		$digests = [];
		foreach ($this->contexts as $algorithm => $context) {
			$digests[$algorithm] = hash_final($context, true);
		}
		if ($this->crc64 !== null) {
			// Big-endian, as S3's `x-amz-checksum-crc64nvme` has it.
			$digests['crc64nvme'] = pack('J', ~$this->crc64);
		}
		return $digests;
	}

	/**
	 * CRC-64/NVME, which PHP's hash extension does not have.
	 *
	 * Eight bytes per step (slicing-by-8) where the plain byte-at-a-time
	 * table loop would do one: in PHP that is the difference between about
	 * 40 and 100 MB/s, on a body of up to 5 GiB. PHP integers are signed, so
	 * every right shift that is not masked to a byte anyway is masked back to
	 * a logical one.
	 */
	private static function crc64Update(int $crc, string $data): int {
		[$t0, $t1, $t2, $t3, $t4, $t5, $t6, $t7] = self::$crc64Tables ??= self::crc64Tables();

		$length = strlen($data);
		$aligned = $length - $length % 8;
		if ($aligned > 0) {
			// 'P': 64 bit little-endian, the byte order the reflected CRC
			// consumes the data in.
			foreach (unpack('P*', substr($data, 0, $aligned)) as $word) {
				$x = $crc ^ $word;
				$crc = $t7[$x & 0xFF] ^ $t6[($x >> 8) & 0xFF] ^ $t5[($x >> 16) & 0xFF] ^ $t4[($x >> 24) & 0xFF]
					^ $t3[($x >> 32) & 0xFF] ^ $t2[($x >> 40) & 0xFF] ^ $t1[($x >> 48) & 0xFF] ^ $t0[($x >> 56) & 0xFF];
			}
		}

		for ($i = $aligned; $i < $length; $i++) {
			$crc = $t0[($crc ^ ord($data[$i])) & 0xFF] ^ (($crc >> 8) & 0x00FFFFFFFFFFFFFF);
		}

		return $crc;
	}

	/**
	 * @return list<list<int>>
	 */
	private static function crc64Tables(): array {
		// The reflected polynomial 0x9A6C9329AC4BC9B5. Its top bit is set, so
		// as a literal it would overflow to a float.
		$poly = 0x1A6C9329AC4BC9B5 | PHP_INT_MIN;

		$t0 = [];
		for ($i = 0; $i < 256; $i++) {
			$c = $i;
			for ($bit = 0; $bit < 8; $bit++) {
				$shifted = ($c >> 1) & PHP_INT_MAX;
				$c = ($c & 1) ? ($shifted ^ $poly) : $shifted;
			}
			$t0[] = $c;
		}

		// Table k: the CRC of a byte followed by k zero bytes.
		$tables = [$t0];
		for ($k = 1; $k < 8; $k++) {
			$tables[$k] = [];
			foreach ($tables[$k - 1] as $c) {
				$tables[$k][] = $t0[$c & 0xFF] ^ (($c >> 8) & 0x00FFFFFFFFFFFFFF);
			}
		}

		return $tables;
	}
}

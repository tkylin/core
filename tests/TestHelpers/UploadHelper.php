<?php
/**
 * ownCloud
 *
 * @author Artur Neumann <artur@jankaritech.com>
 * @copyright 2017 Artur Neumann artur@jankaritech.com
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License,
 * as published by the Free Software Foundation;
 * either version 3 of the License, or any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace TestHelpers;

use Psr\Http\Message\ResponseInterface;
use PHPUnit_Framework_Assert;

/**
 * Helper for Uploads
 *
 * @author Artur Neumann <artur@jankaritech.com>
 *
 */
class UploadHelper {
	/**
	 *
	 * @param string $baseUrl             URL of owncloud
	 *                                    e.g. http://localhost:8080
	 *                                    should include the subfolder
	 *                                    if owncloud runs in a subfolder
	 *                                    e.g. http://localhost:8080/owncloud-core
	 * @param string $user
	 * @param string $password
	 * @param string $source
	 * @param string $destination
	 * @param array  $headers
	 * @param int    $davPathVersionToUse (1|2)
	 * @param int    $chunkingVersion     (1|2|null)
	 *                                    if set to null chunking will not be used
	 * @param int    $noOfChunks          how many chunks do we want to upload
	 * @return ResponseInterface|NULL
	 */
	public static function upload(
		$baseUrl,
		$user,
		$password,
		$source,
		$destination,
		$headers = array(),
		$davPathVersionToUse = 1,
		$chunkingVersion = null,
		$noOfChunks = 1
	) {

		//simple upload with no chunking
		if (is_null($chunkingVersion)) {
			$data = file_get_contents($source);
			return WebDavHelper::makeDavRequest(
				$baseUrl,
				$user,
				$password,
				"PUT",
				$destination,
				$headers,
				$data,
				null,
				$davPathVersionToUse
			);
		} else {
			//prepare chunking
			$chunks = self::chunkFile($source, $noOfChunks);
			$chunkingId = 'chunking-' . (string)rand(1000, 9999);
			$v2ChunksDestination = '/uploads/' . $user . '/' . $chunkingId;
		}

		//prepare chunking version specific stuff
		if ($chunkingVersion === 1) {
			$headers['OC-Chunked'] = '1';
		} elseif ($chunkingVersion === 2) {
			WebDavHelper::makeDavRequest(
				$baseUrl,
				$user,
				$password,
				'MKCOL',
				$v2ChunksDestination,
				$headers, null, null,
				$davPathVersionToUse,
				"uploads"
			);
		}

		//upload chunks
		foreach ($chunks as $index => $chunk) {
			if ($chunkingVersion === 1) {
				$filename = $destination . "-" . $chunkingId . "-" .
					count($chunks) . '-' . ( string ) $index;
				$davRequestType = "files";
			} elseif ($chunkingVersion === 2) {
				$filename = $v2ChunksDestination . '/' . (string)($index);
				$davRequestType = "uploads";
			}
			$result = WebDavHelper::makeDavRequest(
				$baseUrl,
				$user,
				$password,
				"PUT",
				$filename,
				$headers,
				$chunk,
				null,
				$davPathVersionToUse,
				$davRequestType
			);
		}
		//finish upload for new chunking
		if ($chunkingVersion === 2) {
			$source = $v2ChunksDestination . '/.file';
			$finalDestination = $baseUrl . "/" .
				WebDavHelper::getDavPath($user, $davPathVersionToUse) .
				$destination;
			$result = WebDavHelper::makeDavRequest(
				$baseUrl,
				$user,
				$password,
				'MOVE',
				$source,
				['Destination' => $finalDestination ],
				null, null,
				$davPathVersionToUse,
				"uploads"
			);
		}
		return $result;
	}

	/**
	 * cut the file in multiple chunks
	 * returns an array of chunks with the content of the file
	 *
	 * @param string $file
	 * @param int $noOfChunks
	 * @return array $string
	 */
	public static function chunkFile($file, $noOfChunks = 1) {
		$size = filesize($file);
		$chunkSize = ceil($size / $noOfChunks);
		$chunks = [];
		$fp = fopen($file, 'r');
		while (!feof($fp) && ftell($fp) < $size) {
			$chunks[] = fread($fp, $chunkSize);
		}
		fclose($fp);
		if (count($chunks) === 0) {
			// chunk an empty file
			$chunks[] = '';
		}
		return $chunks;
	}

	/**
	 * creates a File with a specific size
	 *
	 * @param string $name full path of the file to create
	 * @param int $size
	 * @return void
	 */
	public static function createFileSpecificSize($name, $size) {
		if (file_exists($name)) {
			unlink($name);
		}
		$file = fopen($name, 'w');
		fseek($file, max($size - 1, 0), SEEK_CUR);
		if ($size) {
			fwrite($file, 'a'); // write a dummy char at SIZE position
		}
		fclose($file);
		PHPUnit_Framework_Assert::assertEquals(
			1, file_exists($name)
		);
		PHPUnit_Framework_Assert::assertEquals(
			$size, filesize($name)
		);
	}

	/**
	 * creates a File with a specific text content
	 *
	 * @param string $name full path of the file to create
	 * @param string $text
	 * @return void
	 */
	public static function createFileWithText($name, $text) {
		$file = fopen($name, 'w');
		fwrite($file, $text);
		fclose($file);
		PHPUnit_Framework_Assert::assertEquals(
			1, file_exists($name)
		);
	}
}

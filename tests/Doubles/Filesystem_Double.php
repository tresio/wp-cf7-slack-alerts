<?php
/**
 * Real-filesystem stand-in for $wp_filesystem.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts\Tests\Doubles;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Implements the handful of $wp_filesystem methods the updater uses, against
 * the real filesystem, so directory renames are genuinely exercised.
 */
class Filesystem_Double {

	/**
	 * Whether a path exists.
	 *
	 * @param string $path Path to check.
	 * @return bool
	 */
	public function exists( $path ) {
		return file_exists( $path );
	}

	/**
	 * Delete a file or directory.
	 *
	 * @param string $path      Path to delete.
	 * @param bool   $recursive Whether to recurse.
	 * @return bool
	 */
	public function delete( $path, $recursive = false ) {
		if ( ! file_exists( $path ) ) {
			return false;
		}

		if ( is_dir( $path ) ) {
			$items = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $items as $item ) {
				$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
			}

			return rmdir( $path );
		}

		return unlink( $path );
	}

	/**
	 * Move a path.
	 *
	 * @param string $from      Source.
	 * @param string $to        Destination.
	 * @param bool   $overwrite Whether to overwrite.
	 * @return bool
	 */
	public function move( $from, $to, $overwrite = false ) {
		return rename( $from, $to );
	}
}

<?php
/**
 * Image previews with the WordPress image editor (GD/Imagick).
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\WordPress;

use SW\Calendar\Application\Port\ImagePreviewInterface;

final class WpImagePreview implements ImagePreviewInterface {

	public const MAX_PIXELS = 40000000;
	public const SIZE       = 1024;

	public function createPreview( string $source, string $target, string $mime ): bool {
		$info = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid images are reported by the return value.
		if ( ! is_array( $info ) || $info[0] * $info[1] > self::MAX_PIXELS ) {
			return false;
		}
		// Re-encoding would destroy GIF animations; the original is used instead.
		if ( 'image/gif' === $mime && $this->is_animated_gif( $source ) ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$editor = wp_get_image_editor( $source, array( 'mime_type' => $mime ) );
		if ( is_wp_error( $editor ) ) {
			return false;
		}
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate();
		}
		$size = $editor->get_size();
		if ( (int) $size['width'] > self::SIZE || (int) $size['height'] > self::SIZE ) {
			$resized = $editor->resize( self::SIZE, self::SIZE, false );
			if ( is_wp_error( $resized ) ) {
				return false;
			}
		}
		$editor->set_quality( 82 );
		$saved = $editor->save( $target . '.img', $mime );
		if ( is_wp_error( $saved ) || ! isset( $saved['path'] ) || ! is_file( $saved['path'] ) ) {
			return false;
		}
		// The editor may add an extension; the caller expects exactly $target.
		return rename( $saved['path'], $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- private storage, same directory.
	}

	private function is_animated_gif( string $path ): bool {
		$content = (string) file_get_contents( $path, false, null, 0, 1048576 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return preg_match_all( '#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $content ) > 1;
	}
}

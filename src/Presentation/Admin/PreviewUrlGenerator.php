<?php
/**
 * URLs for template previews: no real endpoints, a bundled placeholder image for attachments.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Admin;

use SW\Calendar\Application\Port\UrlGeneratorInterface;

final class PreviewUrlGenerator implements UrlGeneratorInterface {

	public function __construct( private readonly string $image_url ) {
	}

	public function attachmentContent( int $attachmentId ): string {
		return $this->image_url;
	}

	public function attachmentPreview( int $attachmentId ): string {
		return $this->image_url;
	}

	public function eventDetail( int $viewId, int|string $occurrenceId ): string {
		return '#event-' . $occurrenceId;
	}

	public function calendar( int $viewId, array $filters ): string {
		return '#calendar';
	}

	public function month( int $viewId, string $month ): string {
		return '#month-' . $month;
	}

	public function page( int $viewId, int $page, ?string $month = null ): string {
		return '#page-' . $page;
	}
}
